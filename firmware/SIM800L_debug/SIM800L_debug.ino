/*
  SIM800L_debug — отладочная прошивка: что происходит с модемом SIM800L и почему плата не выходит на связь.
  Wemos D1 mini (ESP8266), подключение как на релейной плате:
      SIM800L TX → D3 (приём ESP),  SIM800L RX → D4 (передача ESP),  GND — общий.
      Питание модема 3.7–4.2 В, ток до 2 А (рядом с модемом конденсатор 470–1000 мкФ).
      D5 — импульсы внешнему сторожевому таймеру платы (как в рабочей прошивке), чтобы плату не перезагружало.

  Библиотеки: только из ядра ESP8266 (SoftwareSerial, EEPROM, BearSSL) — ничего доустанавливать не нужно.
  Плата в Arduino IDE: «LOLIN(WEMOS) D1 R2 & mini». Монитор порта: 115200, окончание строки «NL & CR» или «NL».

  Настройки (ID, PIN, сервер, APN) берутся из EEPROM рабочей прошивки v2.3 — только чтение, ничего не записывается.
  После старта прошивка проверяет всё по шагам и пишет итог; затем каждые 10 с показывает состояние модема.
  Команды в мониторе порта — «help». Строки, начинающиеся с «AT», уходят в модем как есть.
*/
#include <SoftwareSerial.h>
#include <EEPROM.h>
#include <bearssl/bearssl_hash.h>

#define FW_DEBUG_VERSION "1.0"

const int PIN_MODEM_RX = D3;   // сюда — TX модема
const int PIN_MODEM_TX = D4;   // отсюда — RX модема
const int PIN_WATCHDOG = D5;   // импульсы внешнему WATCHDOG

// если в EEPROM нет настроек рабочей прошивки
#define DEF_ID   1
#define DEF_PIN  ""            // PIN SIM-карты, "" — не вводить
#define DEF_HOST "185.209.29.25"
#define DEF_PORT 1883
#define DEF_APN  ""            // "" — APN по умолчанию SIM-карты

SoftwareSerial modem(PIN_MODEM_RX, PIN_MODEM_TX);

/* ---------- Настройки рабочей прошивки v2.3 (тот же формат EEPROM) ---------- */
const uint32_t SETTINGS_MAGIC = 0x52454C33;  // "REL3"
struct settings_t {
  uint32_t id_i;
  char pin_sim[8];
  uint32_t bat_alarm_relay;
  uint32_t current_pin_failed_attempts;
  uint32_t global_pin_failed_attempts;
  uint32_t magic;
  char server_host[64];
  uint16_t server_port;
  char apn[32];
  uint32_t build_stamp;
};

struct cfg_t {
  uint32_t id;
  char pin[8];
  char host[64];
  uint16_t port;
  char apn[32];
  const char* source;
} cfg;

static bool printableField(const char* s, size_t n) {
  size_t len = strnlen(s, n);
  if (len >= n) return false;
  for (size_t i = 0; i < len; i++) if ((uint8_t)s[i] < 32 || (uint8_t)s[i] > 126) return false;
  return true;
}

void loadSettings() {
  settings_t st;
  EEPROM.begin(512);
  EEPROM.get(0, st);
  EEPROM.end();  // ничего не записываем
  cfg.id = DEF_ID; strncpy(cfg.pin, DEF_PIN, sizeof(cfg.pin) - 1); strncpy(cfg.host, DEF_HOST, sizeof(cfg.host) - 1);
  cfg.port = DEF_PORT; strncpy(cfg.apn, DEF_APN, sizeof(cfg.apn) - 1); cfg.source = "значения из этой прошивки (EEPROM пуста)";
  bool base = st.id_i != 0 && st.id_i != 0xFFFFFFFF && strnlen(st.pin_sim, sizeof(st.pin_sim)) <= 4 && printableField(st.pin_sim, sizeof(st.pin_sim));
  if (!base) return;
  cfg.id = st.id_i;
  memset(cfg.pin, 0, sizeof(cfg.pin)); strncpy(cfg.pin, st.pin_sim, sizeof(cfg.pin) - 1);
  cfg.source = "EEPROM рабочей прошивки (ID, PIN; сервер и APN — по умолчанию)";
  if (st.magic == SETTINGS_MAGIC && printableField(st.server_host, sizeof(st.server_host)) && st.server_host[0] && st.server_port
      && printableField(st.apn, sizeof(st.apn))) {
    memset(cfg.host, 0, sizeof(cfg.host)); strncpy(cfg.host, st.server_host, sizeof(cfg.host) - 1);
    cfg.port = st.server_port;
    memset(cfg.apn, 0, sizeof(cfg.apn)); strncpy(cfg.apn, st.apn, sizeof(cfg.apn) - 1);
    cfg.source = "EEPROM рабочей прошивки v2.3";
  }
}

/* ---------- WATCHDOG: импульс 150 мс раз в секунду, без задержек ---------- */
uint32_t wdNext = 0;
bool wdHigh = false;
void wdService() {
  uint32_t now = millis();
  if ((int32_t)(now - wdNext) < 0) return;
  wdHigh = !wdHigh;
  digitalWrite(PIN_WATCHDOG, wdHigh ? HIGH : LOW);
  wdNext = now + (wdHigh ? 150 : 850);
}

/* ---------- Вывод ---------- */
const char* ts() {
  static char b[16];
  uint32_t m = millis();
  snprintf(b, sizeof(b), "%5lu.%03lu", (unsigned long)(m / 1000), (unsigned long)(m % 1000));
  return b;
}
void say(const char* s) { Serial.printf("[%s] %s\n", ts(), s); }
void head(const char* s) { Serial.printf("\n[%s] ===== %s =====\n", ts(), s); }
void good(const char* s) { Serial.printf("[%s]   OK   %s\n", ts(), s); }
void warn(const char* s) { Serial.printf("[%s]   !!   %s\n", ts(), s); }
void fail(const char* s) { Serial.printf("[%s]  ОШИБКА %s\n", ts(), s); }

/* ---------- События модема, которые объясняют «пропадание» ---------- */
struct { uint32_t restarts, underVolt, overVolt, powerDown, simLost, gprsDeact; } ev = {0, 0, 0, 0, 0, 0};
bool modemSeen = false;     // модем уже отвечал — значит «RDY» после этого = перезагрузка

void onLine(const char* l) {
  Serial.printf("[%s]   <- %s\n", ts(), l);
  if (!strcmp(l, "RDY") || !strcmp(l, "Call Ready") || !strncmp(l, "+CFUN: 1", 8)) {
    if (!strcmp(l, "RDY") && modemSeen) { ev.restarts++; warn("МОДЕМ ПЕРЕЗАГРУЗИЛСЯ (RDY). Частая причина — просадка питания при передаче: нужен источник 2 А и конденсатор у модема"); }
  } else if (strstr(l, "UNDER-VOLTAGE")) {
    ev.underVolt++; warn("модем сообщает о НИЗКОМ напряжении питания (UNDER-VOLTAGE) — поднимите питание до 3.8–4.2 В");
  } else if (strstr(l, "OVER-VOLTAGE")) {
    ev.overVolt++; warn("модем сообщает о ВЫСОКОМ напряжении питания (OVER-VOLTAGE) — больше 4.4 В опасно");
  } else if (strstr(l, "POWER DOWN")) {
    ev.powerDown++; warn("модем ВЫКЛЮЧИЛСЯ (POWER DOWN)");
  } else if (strstr(l, "+CPIN: NOT READY") || strstr(l, "+CPIN: NOT INSERTED")) {
    ev.simLost++; warn("модем потерял SIM-карту — проверьте держатель и контакты");
  } else if (strstr(l, "+PDP: DEACT")) {
    ev.gprsDeact++; warn("оператор разорвал GPRS-сессию (+PDP: DEACT)");
  }
}

/* ---------- Чтение строк модема ---------- */
char line[256];
size_t lineLen = 0;
bool modemLine() {
  while (modem.available()) {
    char c = (char)modem.read();
    if (c == '\r') continue;
    if (c == '\n') {
      if (!lineLen) continue;
      line[lineLen] = 0; lineLen = 0;
      return true;
    }
    if (lineLen < sizeof(line) - 1) line[lineLen++] = c;
  }
  return false;
}
void idle() { wdService(); yield(); }
void pump() { while (modemLine()) onLine(line); }   // показать всё, что модем прислал сам
void waitMs(uint32_t ms) { uint32_t t = millis(); while (millis() - t < ms) { idle(); pump(); } }

/* ---------- AT-команда: 1 — успех, 0 — ошибка, -1 — нет ответа ---------- */
char resp[600];
int at(const char* cmd, uint32_t timeout = 2000, const char* okWord = "OK", const char* fail1 = "ERROR", const char* fail2 = nullptr) {
  pump();
  resp[0] = 0;
  if (*cmd) {  // пустая команда — только дождаться ответа (после отправки данных)
    Serial.printf("[%s]   -> %s\n", ts(), cmd);
    modem.print(cmd); modem.print("\r");
  }
  uint32_t t = millis();
  while (millis() - t < timeout) {
    idle();
    if (!modemLine()) continue;
    if (!strcmp(line, cmd)) continue;  // эхо команды
    onLine(line);
    size_t rl = strlen(resp);
    if (rl + strlen(line) + 2 < sizeof(resp)) { strcat(resp, line); strcat(resp, "\n"); }
    if (strstr(line, okWord)) return 1;
    if ((fail1 && strstr(line, fail1)) || (fail2 && strstr(line, fail2))) return 0;
  }
  Serial.printf("[%s]      (нет ответа за %lu мс)\n", ts(), (unsigned long)timeout);
  return -1;
}
// строка ответа, начинающаяся с prefix (например «+CSQ:»), или nullptr
const char* respLine(const char* prefix) {
  static char b[128];
  const char* p = strstr(resp, prefix);
  if (!p) return nullptr;
  size_t n = strcspn(p, "\n");
  if (n >= sizeof(b)) n = sizeof(b) - 1;
  memcpy(b, p, n); b[n] = 0;
  return b;
}

/* ---------- Шаги проверки ---------- */
enum { ST_SKIP, ST_OK, ST_WARN, ST_FAIL };
const char* STEP_NAMES[] = {"Связь с модемом", "Питание модема", "SIM-карта", "Сигнал сети", "Регистрация в сети",
                            "GPRS (интернет)", "TCP до сервера", "MQTT (вход на сервер)"};
const int STEPS = 8;
int stepStatus[STEPS];
char stepNote[STEPS][120];
void setStep(int i, int st, const char* note) {
  stepStatus[i] = st;
  strncpy(stepNote[i], note, sizeof(stepNote[i]) - 1); stepNote[i][sizeof(stepNote[i]) - 1] = 0;
  (st == ST_OK ? good : st == ST_WARN ? warn : fail)(note);
}

long modemBaud = 0;
int lastCsq = -1, lastReg = -1, lastMv = -1;

bool findBaud() {
  const long bauds[] = {9600, 115200, 57600, 38400, 19200, 4800};
  for (long b : bauds) {
    modem.begin(b);
    waitMs(100);
    Serial.printf("[%s]   пробую скорость %ld…\n", ts(), b);
    for (int i = 0; i < 3; i++) if (at("AT", 700) == 1) { modemBaud = b; modemSeen = true; return true; }
  }
  modem.begin(9600);
  return false;
}

int readCsq() {
  if (at("AT+CSQ") != 1) return -1;
  const char* l = respLine("+CSQ:");
  int rssi = -1, ber = 0;
  if (l && sscanf(l, "+CSQ: %d,%d", &rssi, &ber) >= 1) return rssi;
  return -1;
}
int readReg() {
  if (at("AT+CREG?") != 1) return -1;
  const char* l = respLine("+CREG:");
  int n = 0, stat = -1;
  if (l && sscanf(l, "+CREG: %d,%d", &n, &stat) == 2) return stat;
  return -1;
}
int readMv() {
  if (at("AT+CBC") != 1) return -1;
  const char* l = respLine("+CBC:");
  int bcs = 0, bcl = 0, mv = -1;
  if (l && sscanf(l, "+CBC: %d,%d,%d", &bcs, &bcl, &mv) == 3) return mv;
  return -1;
}
const char* regText(int s) {
  switch (s) {
    case 0: return "не зарегистрирован и не ищет сеть";
    case 1: return "зарегистрирован (домашняя сеть)";
    case 2: return "ищет сеть…";
    case 3: return "регистрация ОТКЛОНЕНА оператором";
    case 4: return "неизвестно";
    case 5: return "зарегистрирован (роуминг)";
    default: return "нет ответа";
  }
}

/* ---------- MQTT: пароль устройства — hex первых 16 байт SHA256("relay-<ID>") ---------- */
void devicePassword(uint32_t id, char* out33) {
  char user[24];
  snprintf(user, sizeof(user), "relay-%lu", (unsigned long)id);
  br_sha256_context ctx;
  uint8_t h[32];
  br_sha256_init(&ctx);
  br_sha256_update(&ctx, user, strlen(user));
  br_sha256_out(&ctx, h);
  for (int i = 0; i < 16; i++) sprintf(out33 + i * 2, "%02x", h[i]);
  out33[32] = 0;
}

size_t putStr(uint8_t* p, const char* s) {
  size_t n = strlen(s);
  p[0] = n >> 8; p[1] = n & 0xFF;
  memcpy(p + 2, s, n);
  return n + 2;
}
// пакет MQTT 3.1.1 CONNECT с логином и паролем устройства
size_t mqttConnectPacket(uint8_t* buf) {
  char client[32], user[24], pass[33];
  snprintf(client, sizeof(client), "relay-id%lu-v1", (unsigned long)cfg.id);
  snprintf(user, sizeof(user), "relay-%lu", (unsigned long)cfg.id);
  devicePassword(cfg.id, pass);
  uint8_t body[160];
  size_t n = 0;
  n += putStr(body + n, "MQTT");
  body[n++] = 4;         // MQTT 3.1.1
  body[n++] = 0xC2;      // username + password + clean session
  body[n++] = 0; body[n++] = 95;  // keepAlive 95 с — как в рабочей прошивке
  n += putStr(body + n, client);
  n += putStr(body + n, user);
  n += putStr(body + n, pass);
  buf[0] = 0x10; buf[1] = (uint8_t)n;   // n < 128 — длина одним байтом
  memcpy(buf + 2, body, n);
  Serial.printf("[%s]   MQTT: clientID %s, логин %s, пароль %s\n", ts(), client, user, pass);
  return n + 2;
}

// ждать приглашение «>» команды AT+CIPSEND
bool waitPrompt(uint32_t timeout) {
  uint32_t t = millis();
  while (millis() - t < timeout) {
    idle();
    while (modem.available()) {
      char c = (char)modem.read();
      if (c == '>') return true;
      if (c == '\n') { if (lineLen) { line[lineLen] = 0; lineLen = 0; onLine(line); if (strstr(line, "ERROR")) return false; } }
      else if (c != '\r' && lineLen < sizeof(line) - 1) line[lineLen++] = c;
    }
  }
  return false;
}

// CONNACK: 0x20 0x02 <флаги> <код>; -1 — не пришёл, -2 — сервер закрыл соединение
int readConnack(uint32_t timeout) {
  uint8_t win[4] = {0, 0, 0, 0};
  char txt[16]; size_t tl = 0;
  uint32_t t = millis();
  while (millis() - t < timeout) {
    idle();
    while (modem.available()) {
      uint8_t c = (uint8_t)modem.read();
      win[0] = win[1]; win[1] = win[2]; win[2] = win[3]; win[3] = c;
      if (win[0] == 0x20 && win[1] == 0x02) return win[3];
      // текстовые сообщения модема в потоке данных («CLOSED»)
      if (c >= 32 && c < 127) { if (tl < sizeof(txt) - 1) txt[tl++] = (char)c; txt[tl] = 0; if (strstr(txt, "CLOSED")) return -2; }
      else tl = 0;
    }
  }
  return -1;
}

void summary();
/* ---------- Полная проверка ---------- */
void diagnose() {
  for (int i = 0; i < STEPS; i++) { stepStatus[i] = ST_SKIP; stepNote[i][0] = 0; }
  char b[160];
  Serial.printf("\n[%s] ######## ПРОВЕРКА SIM800L ########\n", ts());
  Serial.printf("[%s] устройство ID %lu, сервер %s:%u, APN \"%s\", PIN %s — %s\n", ts(), (unsigned long)cfg.id, cfg.host, cfg.port,
                cfg.apn, cfg.pin[0] ? "задан" : "не задан", cfg.source);

  // 1. Связь с модемом
  head("1. Связь с модемом");
  if (!findBaud()) {
    setStep(0, ST_FAIL, "модем НЕ ОТВЕЧАЕТ ни на одной скорости");
    say("Проверьте: питание модема 3.7–4.2 В / 2 А (не от 3.3 В платы!), TX модема → D3, RX модема → D4, общий GND.");
    say("Светодиод NET на модеме: не горит — нет питания; мигает раз в секунду — ищет сеть; раз в 3 с — в сети.");
    return summary();
  }
  snprintf(b, sizeof(b), "модем отвечает, скорость %ld бод%s", modemBaud, modemBaud == 9600 ? "" : " — рабочая прошивка ждёт 9600! команда «baud» исправит");
  setStep(0, modemBaud == 9600 ? ST_OK : ST_WARN, b);
  at("ATE0");                 // без эха — ответы читать проще
  at("AT+CMEE=2");            // ошибки текстом
  at("ATI");
  at("AT+GMR");
  at("AT+CFUN?");

  // 2. Питание
  head("2. Питание модема");
  lastMv = readMv();
  if (lastMv < 0) setStep(1, ST_WARN, "модем не сообщил напряжение (AT+CBC)");
  else {
    snprintf(b, sizeof(b), "напряжение на модеме %d мВ", lastMv);
    if (lastMv < 3400) { strcat(b, " — СЛИШКОМ НИЗКОЕ: модем будет выключаться при передаче"); setStep(1, ST_FAIL, b); }
    else if (lastMv < 3650) { strcat(b, " — низковато: при передаче возможны перезагрузки модема"); setStep(1, ST_WARN, b); }
    else if (lastMv > 4400) { strcat(b, " — СЛИШКОМ ВЫСОКОЕ: больше 4.4 В опасно для модема"); setStep(1, ST_FAIL, b); }
    else setStep(1, ST_OK, b);
  }

  // 3. SIM
  head("3. SIM-карта");
  int r = at("AT+CPIN?", 5000);
  const char* l = respLine("+CPIN:");
  if (r != 1 || !l) {
    setStep(2, ST_FAIL, strstr(resp, "SIM not inserted") || strstr(resp, "NOT INSERTED") ? "SIM-карта НЕ ВСТАВЛЕНА или нет контакта"
                                                                                      : "модем не видит SIM-карту (AT+CPIN? — ошибка)");
    say("Проверьте: карта вставлена до щелчка, контакты чистые, карта формата micro-SIM, не «мёртвая» (проверьте в телефоне).");
    return summary();
  }
  if (strstr(l, "SIM PUK")) { setStep(2, ST_FAIL, "SIM ЗАБЛОКИРОВАНА (нужен PUK) — разблокируйте её в телефоне"); return summary(); }
  if (strstr(l, "SIM PIN")) {
    at("AT+SPIC");
    const char* sp = respLine("+SPIC:");
    int pin1 = -1;
    if (sp) sscanf(sp, "+SPIC: %d", &pin1);
    snprintf(b, sizeof(b), "SIM запрашивает PIN, осталось попыток: %d", pin1);
    say(b);
    if (!cfg.pin[0]) { setStep(2, ST_FAIL, "SIM запрашивает PIN, а PIN не задан — «pin 1234» или снимите PIN в телефоне"); return summary(); }
    if (pin1 >= 0 && pin1 < 2) { setStep(2, ST_FAIL, "осталась последняя попытка PIN — не трачу её; проверьте PIN в телефоне"); return summary(); }
    char cmd[24];
    snprintf(cmd, sizeof(cmd), "AT+CPIN=%s", cfg.pin);
    at(cmd, 5000);
    if (at("AT+CPIN?", 10000, "READY", "SIM PIN", "ERROR") != 1) { setStep(2, ST_FAIL, "PIN НЕ ПОДОШЁЛ — проверьте PIN (команда «pin XXXX»)"); return summary(); }
    waitMs(3000);
  }
  at("AT+CCID");
  at("AT+CIMI");
  setStep(2, ST_OK, "SIM-карта готова (READY)");

  // 4. Сигнал
  head("4. Сигнал сети");
  lastCsq = readCsq();
  if (lastCsq < 0) setStep(3, ST_WARN, "модем не сообщил уровень сигнала");
  else if (lastCsq == 99 || lastCsq == 0) setStep(3, ST_FAIL, "НЕТ СИГНАЛА (CSQ 99/0): проверьте антенну и разъём, место установки");
  else {
    snprintf(b, sizeof(b), "уровень сигнала %d/31 (%d дБм)%s", lastCsq, -113 + 2 * lastCsq,
             lastCsq < 10 ? " — СЛАБЫЙ: возможны обрывы, нужна антенна получше" : lastCsq < 15 ? " — средний" : " — хороший");
    setStep(3, lastCsq < 10 ? ST_WARN : ST_OK, b);
  }

  // 5. Регистрация
  head("5. Регистрация в сети (до 90 с)");
  uint32_t t0 = millis();
  lastReg = -1;
  while (millis() - t0 < 90000) {
    lastReg = readReg();
    if (lastReg == 1 || lastReg == 5 || lastReg == 3) break;
    snprintf(b, sizeof(b), "  сеть: %s, прошло %lu с", regText(lastReg), (unsigned long)((millis() - t0) / 1000));
    say(b);
    if ((millis() - t0) / 1000 % 10 < 3) readCsq();
    waitMs(3000);
  }
  at("AT+COPS?");
  if (lastReg == 1 || lastReg == 5) {
    snprintf(b, sizeof(b), "%s за %lu с", regText(lastReg), (unsigned long)((millis() - t0) / 1000));
    setStep(4, ST_OK, b);
  } else {
    snprintf(b, sizeof(b), "НЕТ РЕГИСТРАЦИИ: %s", regText(lastReg));
    setStep(4, ST_FAIL, b);
    say(lastReg == 3 ? "Оператор отклоняет SIM: карта не активирована, заблокирована или не поддерживает 2G в этом месте."
                     : "Модем не может зарегистрироваться: слабый сигнал / нет 2G (GSM) у оператора в этом месте / просадка питания.");
    return summary();
  }

  // 6. GPRS
  head("6. GPRS (интернет)");
  at("AT+CGATT?", 5000);
  if (!strstr(resp, "+CGATT: 1")) at("AT+CGATT=1", 30000);
  at("AT+CIPSHUT", 10000, "SHUT OK");
  at("AT+CIPMUX=0");
  at("AT+CIPMODE=0");
  char cmd[120];
  snprintf(cmd, sizeof(cmd), "AT+CSTT=\"%s\"", cfg.apn);
  if (at(cmd, 5000) != 1) { setStep(5, ST_FAIL, "модем не принял APN (AT+CSTT)"); return summary(); }
  if (at("AT+CIICR", 65000) != 1) {
    setStep(5, ST_FAIL, "GPRS НЕ ПОДКЛЮЧАЕТСЯ (AT+CIICR)");
    say("Причины: неверный APN (задайте «apn internet.oper.ru»), на SIM нет денег или не подключён интернет, слабый сигнал.");
    return summary();
  }
  at("AT+CIFSR", 5000, ".", "ERROR");
  const char* ip = strchr(resp, '.') ? resp : nullptr;
  snprintf(b, sizeof(b), "GPRS подключён, IP %.*s", ip ? (int)strcspn(resp, "\n") : 1, ip ? resp : "?");
  setStep(5, ST_OK, b);

  // 7. TCP
  head("7. TCP до сервера");
  snprintf(cmd, sizeof(cmd), "AT+CIPSTART=\"TCP\",\"%s\",\"%u\"", cfg.host, cfg.port);
  if (at(cmd, 45000, "CONNECT OK", "CONNECT FAIL", "ERROR") != 1) {
    snprintf(b, sizeof(b), "НЕ ОТКРЫВАЕТСЯ соединение с %s:%u", cfg.host, cfg.port);
    setStep(6, ST_FAIL, b);
    say("Причины: неверный адрес/порт сервера, сервер выключен или порт закрыт брандмауэром, оператор блокирует порт.");
    at("AT+CIPSHUT", 10000, "SHUT OK");
    return summary();
  }
  snprintf(b, sizeof(b), "соединение с %s:%u открыто", cfg.host, cfg.port);
  setStep(6, ST_OK, b);

  // 8. MQTT
  head("8. MQTT: вход на сервер с логином устройства");
  uint8_t pkt[200];
  size_t n = mqttConnectPacket(pkt);
  snprintf(cmd, sizeof(cmd), "AT+CIPSEND=%u", (unsigned)n);
  Serial.printf("[%s]   -> %s\n", ts(), cmd);
  modem.print(cmd); modem.print("\r");
  if (!waitPrompt(5000)) { setStep(7, ST_FAIL, "модем не дал отправить данные (AT+CIPSEND)"); at("AT+CIPSHUT", 10000, "SHUT OK"); return summary(); }
  modem.write(pkt, n);
  if (at("", 10000, "SEND OK", "SEND FAIL", "ERROR") != 1) { setStep(7, ST_FAIL, "данные не отправились (SEND FAIL)"); at("AT+CIPSHUT", 10000, "SHUT OK"); return summary(); }
  int rc = readConnack(15000);
  switch (rc) {
    case 0: setStep(7, ST_OK, "сервер ПРИНЯЛ устройство (CONNACK 0) — связь с сервером в порядке"); break;
    case 4: case 5:
      snprintf(b, sizeof(b), "сервер НЕ ПУСКАЕТ (CONNACK %d): устройство %lu не зарегистрировано — «sudo relay-device add %lu» или проверьте ID", rc,
               (unsigned long)cfg.id, (unsigned long)cfg.id);
      setStep(7, ST_FAIL, b); break;
    case 2: setStep(7, ST_FAIL, "сервер отклонил clientID (CONNACK 2)"); break;
    case 3: setStep(7, ST_FAIL, "брокер недоступен (CONNACK 3)"); break;
    case -2: setStep(7, ST_FAIL, "сервер ЗАКРЫЛ соединение без ответа — это не MQTT-брокер или порт не тот"); break;
    default: setStep(7, ST_FAIL, "сервер НЕ ОТВЕТИЛ на вход за 15 с — это не MQTT-брокер, порт не тот или данные теряются"); break;
  }
  if (rc == 0) {
    modem.print("AT+CIPSEND=2\r");
    if (waitPrompt(3000)) { uint8_t disc[2] = {0xE0, 0x00}; modem.write(disc, 2); at("", 5000, "SEND OK"); }
  }
  at("AT+CIPCLOSE", 5000, "CLOSE OK", "ERROR");
  at("AT+CIPSHUT", 10000, "SHUT OK");
  summary();
}

void summary() {
  Serial.printf("\n[%s] ######## ИТОГ ########\n", ts());
  int firstFail = -1;
  for (int i = 0; i < STEPS; i++) {
    const char* mark = stepStatus[i] == ST_OK ? "  OK  " : stepStatus[i] == ST_WARN ? "  !!  " : stepStatus[i] == ST_FAIL ? "ОШИБКА" : "  --  ";
    Serial.printf("  [%s] %d. %-24s %s\n", mark, i + 1, STEP_NAMES[i], stepStatus[i] == ST_SKIP ? "не проверялось" : stepNote[i]);
    if (stepStatus[i] == ST_FAIL && firstFail < 0) firstFail = i;
  }
  if (ev.restarts || ev.underVolt || ev.powerDown || ev.simLost || ev.gprsDeact)
    Serial.printf("  За время работы: перезагрузок модема %lu, низкое напряжение %lu, выключений %lu, потерь SIM %lu, разрывов GPRS %lu\n",
                  (unsigned long)ev.restarts, (unsigned long)ev.underVolt, (unsigned long)ev.powerDown, (unsigned long)ev.simLost, (unsigned long)ev.gprsDeact);
  if (firstFail < 0) Serial.println("  ВЫВОД: модем, SIM, сеть, GPRS и сервер в порядке. Если рабочая прошивка не выходит на связь — смотрите её журнал и ID.");
  else Serial.printf("  ВЫВОД: связь не устанавливается на шаге %d «%s»: %s\n", firstFail + 1, STEP_NAMES[firstFail], stepNote[firstFail]);
  if (ev.restarts || ev.underVolt) Serial.println("  ВНИМАНИЕ: модем перезагружался / сообщал о низком напряжении — начните с питания (2 А, конденсатор, короткие толстые провода).");
  Serial.println("  Дальше — наблюдение раз в 10 с («mon» — выключить). Повторить проверку — «diag». Команды — «help».\n");
}

/* ---------- Наблюдение раз в 10 с ---------- */
bool monitorOn = true;
uint32_t monNext = 0;
void monitorTick() {
  if (!monitorOn || !modemBaud || (int32_t)(millis() - monNext) < 0) return;
  monNext = millis() + 10000;
  lastCsq = readCsq();
  lastReg = readReg();
  lastMv = readMv();
  Serial.printf("[%s] СОСТОЯНИЕ: сигнал %d/31%s · сеть: %s · питание %d мВ · перезагрузок модема %lu · низкое напряжение %lu\n", ts(),
                lastCsq, lastCsq == 99 ? " (нет)" : "", regText(lastReg), lastMv, (unsigned long)ev.restarts, (unsigned long)ev.underVolt);
}

/* ---------- Команды из монитора порта ---------- */
void showCfg() {
  Serial.printf("  ID %lu, PIN \"%s\", сервер %s:%u, APN \"%s\" — %s\n", (unsigned long)cfg.id, cfg.pin, cfg.host, cfg.port, cfg.apn, cfg.source);
  Serial.println("  Изменения действуют до перезагрузки, EEPROM не меняется.");
}
void help() {
  Serial.println("\nКоманды:");
  Serial.println("  diag            — полная проверка по шагам");
  Serial.println("  mon             — вкл/выкл наблюдение раз в 10 с");
  Serial.println("  show            — текущие настройки");
  Serial.println("  id 12           — ID устройства (для проверки входа на сервер)");
  Serial.println("  pin 1234        — PIN SIM-карты (пусто: «pin»)");
  Serial.println("  apn internet    — APN оператора (пусто: «apn»)");
  Serial.println("  server host:1883 — адрес и порт сервера MQTT");
  Serial.println("  baud            — сохранить в модеме скорость 9600 (как ждёт рабочая прошивка)");
  Serial.println("  reset           — перезагрузить модем (AT+CFUN=1,1)");
  Serial.println("  AT...           — любая AT-команда в модем как есть (например AT+CSQ, AT+COPS=?)");
}

char cmdBuf[128];
size_t cmdLen = 0;
void handleCommand(char* c) {
  while (*c == ' ') c++;
  if (!*c) return;
  if ((c[0] == 'A' || c[0] == 'a') && (c[1] == 'T' || c[1] == 't')) {
    Serial.printf("[%s]   -> %s\n", ts(), c);
    modem.print(c); modem.print("\r");
    return;
  }
  if (!strcmp(c, "help") || !strcmp(c, "?")) help();
  else if (!strcmp(c, "diag") || !strcmp(c, "d")) diagnose();
  else if (!strcmp(c, "mon")) { monitorOn = !monitorOn; say(monitorOn ? "наблюдение включено" : "наблюдение выключено"); }
  else if (!strcmp(c, "show")) showCfg();
  else if (!strncmp(c, "id ", 3)) { cfg.id = strtoul(c + 3, nullptr, 10); cfg.source = "задано командой"; showCfg(); }
  else if (!strncmp(c, "pin", 3)) { memset(cfg.pin, 0, sizeof(cfg.pin)); strncpy(cfg.pin, c[3] ? c + 4 : "", 4); showCfg(); }
  else if (!strncmp(c, "apn", 3)) { memset(cfg.apn, 0, sizeof(cfg.apn)); strncpy(cfg.apn, c[3] ? c + 4 : "", sizeof(cfg.apn) - 1); showCfg(); }
  else if (!strncmp(c, "server ", 7)) {
    char* colon = strrchr(c + 7, ':');
    if (colon) { *colon = 0; cfg.port = (uint16_t)atoi(colon + 1); }
    memset(cfg.host, 0, sizeof(cfg.host)); strncpy(cfg.host, c + 7, sizeof(cfg.host) - 1);
    showCfg();
  }
  else if (!strcmp(c, "baud")) {
    if (at("AT+IPR=9600") == 1) { modem.begin(9600); modemBaud = 9600; waitMs(200); at("AT&W"); say("скорость модема 9600 сохранена"); }
  }
  else if (!strcmp(c, "reset")) { at("AT+CFUN=1,1", 15000); say("модем перезагружается — подождите 10–20 с («RDY», «Call Ready»)"); }
  else Serial.printf("Неизвестная команда «%s». Список — «help».\n", c);
}

void setup() {
  pinMode(PIN_WATCHDOG, OUTPUT);
  digitalWrite(PIN_WATCHDOG, LOW);
  Serial.begin(115200);
  waitMs(300);
  Serial.printf("\n\nSIM800L_debug %s — отладка модема (Wemos D1 mini, модем TX→D3, RX→D4, WATCHDOG D5)\n", FW_DEBUG_VERSION);
  loadSettings();
  showCfg();
  help();
  modem.begin(9600);
  say("жду 5 с: модему после включения нужно время («RDY», «Call Ready», «SMS Ready»)…");
  waitMs(5000);
  diagnose();
  monNext = millis() + 10000;
}

void loop() {
  idle();
  pump();
  while (Serial.available()) {
    char c = (char)Serial.read();
    if (c == '\r') continue;
    if (c == '\n') { cmdBuf[cmdLen] = 0; cmdLen = 0; handleCommand(cmdBuf); continue; }
    if (cmdLen < sizeof(cmdBuf) - 1) cmdBuf[cmdLen++] = c;
  }
  monitorTick();
}
