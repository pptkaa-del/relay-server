// =============================================================================
//  Релейный блок ESP8266 (WeMos D1 mini) + SIM800L — прошивка MQTT v2.4
//  Протокол обмена с сервером: docs/PROTOCOL.md (версия протокола 2)
//
//  Библиотеки: PubSubClient 2.8+, TinyGSM 0.12+, PCF8574_library 2.4+ (Renzo Mischianti),
//              Crypto 0.4+ (Rhys Weatherley). Плата: «LOLIN(WEMOS) D1 R2 & mini».
//
//  v2.4 — модем SIM800L:
//   * скорость связи с модемом определяется автоматически (4800…115200), рабочая скорость
//     задаётся в меню (пункт 0) и сохраняется в модеме (AT+IPR, AT&W);
//   * ошибки и события модема выводятся в монитор порта с пояснением: ошибки AT-команд (+CME ERROR),
//     низкое/высокое напряжение, самопроизвольная перезагрузка модема, потеря SIM, разрыв GPRS;
//     при неудачах — отчёт о состоянии модема (сигнал, сеть, оператор, напряжение, GPRS);
//     полный журнал AT-команд — MODEM_AT_LOG 1;
//   * быстрее выход на связь: модем не перезагружается при первом запуске (он уже ищет сеть
//     с момента включения питания), вместо фиксированных пауз — опрос готовности SIM и сети,
//     отказ оператора в регистрации прерывает ожидание сразу; время выхода на связь выводится.
//
//  v2.3 — исправления и оптимизация:
//   * адрес сервера MQTT, порт и APN задаются в меню настройки (пункты 5–7) и хранятся в EEPROM;
//   * одна неудачная AT-команда больше не перезагружает плату: проверка сети раз в 30 с,
//     перезагрузка только после 3 неудач подряд;
//   * при обрыве GPRS плата переподключает GPRS, а не уходит сразу в перезагрузку;
//   * после каждого (пере)подключения к брокеру публикуется stat (сервер сразу видит плату на связи);
//   * WATCHDOG «кормится» и во время долгих операций (переключение реле, поиск сети, меню);
//   * публикации не вызывают переподключение изнутри MQTT-callback (повторный вход в PubSubClient);
//   * перезагрузка по команде 5 выполняется из loop(), после отправки ответа;
//   * защита от retained-команд 3/5 — только первые 10 с после подписки (раньше — 5 минут,
//     из-за чего «перезапуск реле» вскоре после включения платы молча игнорировался);
//   * проверка ввода в меню: ID без ведущих нулей (иначе логин/топик не совпадали с сервером),
//     PIN из цифр, номер реле 1–4 (значение 5 приводило к выходу за границы массива);
//   * Wi-Fi выключен (не используется): меньше потребление от АКБ и шум АЦП.
//   * начальные (заводские) параметры задаются в блоке «ЗАВОДСКИЕ НАСТРОЙКИ» ниже;
//     пункт меню 8 — сброс к заводским настройкам с полной очисткой EEPROM.
// =============================================================================
#define TINY_GSM_MODEM_SIM800

#include <ESP8266WiFi.h>
#include <TinyGsmClient.h>
#include <PubSubClient.h>
#include <PCF8574.h>
#include <SHA256.h>
#include <EEPROM.h>
#include <SoftwareSerial.h>

#define FW_VERSION "2.4"

// =============================================================================
//  ЗАВОДСКИЕ НАСТРОЙКИ — начальные значения параметров меню настройки.
//  Плата использует их:
//    * при первом запуске (EEPROM пустая или повреждена);
//    * после «Сброса к заводским настройкам» (пункт 8 меню);
//    * один раз после прошивки, если APPLY_DEFAULTS_ON_FLASH = 1.
//  Задав значения здесь перед прошивкой, можно не вводить их вручную через меню.
// =============================================================================
#define DEFAULT_DEVICE_ID     1                // пункт 1: ID платы, 1..4294967295, без ведущих нулей
#define DEFAULT_SIM_PIN       "0000"           // пункт 2: PIN SIM-карты, ровно 4 цифры
#define DEFAULT_ALARM_RELAY   4                // пункт 4: реле контроля питания шкафа, 1..4
#define DEFAULT_SERVER_HOST   "185.209.29.25"  // пункт 5: адрес сервера MQTT, домен или IP
#define DEFAULT_SERVER_PORT   1883             // пункт 6: порт сервера MQTT
#define DEFAULT_APN           ""               // пункт 7: APN оператора, "" — по умолчанию SIM-карты
#define DEFAULT_MODEM_BAUD    9600             // пункт 0: скорость связи с модемом: 4800, 9600, 19200, 38400, 57600, 115200

// Журнал обмена с модемом в мониторе порта: 0 — только ошибки и события модема, 1 — все AT-команды и ответы
#define MODEM_AT_LOG 0

// 0 — при перепрошивке настройки платы из EEPROM сохраняются (обычное обновление прошивки);
// 1 — после прошивки ЭТОЙ сборки плата один раз применит значения выше, даже если уже была настроена
//     (удобно при выпуске: задать DEFAULT_DEVICE_ID и прошить). Повторные перезагрузки их не трогают.
#define APPLY_DEFAULTS_ON_FLASH 0

static_assert(DEFAULT_DEVICE_ID >= 1 && DEFAULT_DEVICE_ID <= 4294967295ULL, "DEFAULT_DEVICE_ID: число от 1 до 4294967295");
static_assert(sizeof(DEFAULT_SIM_PIN) == 5, "DEFAULT_SIM_PIN: ровно 4 цифры");
static_assert(DEFAULT_ALARM_RELAY >= 1 && DEFAULT_ALARM_RELAY <= 4, "DEFAULT_ALARM_RELAY: 1..4");
static_assert(sizeof(DEFAULT_SERVER_HOST) > 1 && sizeof(DEFAULT_SERVER_HOST) <= 64, "DEFAULT_SERVER_HOST: 1..63 символа");
static_assert(DEFAULT_SERVER_PORT >= 1 && DEFAULT_SERVER_PORT <= 65535, "DEFAULT_SERVER_PORT: 1..65535");
static_assert(sizeof(DEFAULT_APN) <= 32, "DEFAULT_APN: до 31 символа");
static_assert(DEFAULT_MODEM_BAUD == 4800 || DEFAULT_MODEM_BAUD == 9600 || DEFAULT_MODEM_BAUD == 19200 || DEFAULT_MODEM_BAUD == 38400
              || DEFAULT_MODEM_BAUD == 57600 || DEFAULT_MODEM_BAUD == 115200, "DEFAULT_MODEM_BAUD: 4800, 9600, 19200, 38400, 57600 или 115200");

SoftwareSerial GSMport(D3, D4);
PCF8574 pcf8574(0x20);
const int RsRes = D5;  // импульсы внешнему WATCHDOG

// Реле и входы контроля на PCF8574 (как в v1)
const int reley_1 = D8, reley_2 = D6, reley_3 = D7, reley_4 = D0;
const int reley_kontrol_1 = P2, reley_kontrol_2 = P3, reley_kontrol_3 = P1, reley_kontrol_4 = P0;

struct RelayPin {
  int esp;         // выход ESP, управляющий реле
  uint8_t pcf;     // вход PCF8574 контроля состояния
  bool activeLow;  // реле 1 включается низким уровнем, реле 2–4 — высоким
};
const RelayPin RELAYS[4] = {
  {reley_1, (uint8_t)reley_kontrol_1, true},
  {reley_2, (uint8_t)reley_kontrol_2, false},
  {reley_3, (uint8_t)reley_kontrol_3, false},
  {reley_4, (uint8_t)reley_kontrol_4, false},
};

// ====================== НАСТРОЙКИ (EEPROM) ======================
// Поля до magic совпадают с прошивками v2.0–v2.2, поэтому сохранённые ID и PIN не теряются.
const uint32_t SETTINGS_MAGIC = 0x52454C33;  // "REL3": заполнены поля v2.3
const int EEPROM_SIZE = 512;

struct settings_t {
  uint32_t id_i;
  char pin_sim[8];
  uint32_t bat_alarm_relay;              // 0..3 — индекс реле контроля питания шкафа
  uint32_t current_pin_failed_attempts;
  uint32_t global_pin_failed_attempts;
  // --- v2.3 ---
  uint32_t magic;
  char server_host[64];                  // домен или IP сервера MQTT
  uint16_t server_port;
  char apn[32];                          // APN оператора (пусто — по умолчанию SIM-карты)
  uint32_t build_stamp;                  // отпечаток сборки, к которой применялись заводские настройки
  // --- v2.4 ---
  uint32_t modem_baud;                   // скорость связи с модемом (неверное значение → DEFAULT_MODEM_BAUD)
};
settings_t settings;

String idStr, username, password, clientID, comTopic;
const char STAT_TOPIC[] = "stat";
const char REP_TOPIC[] = "rep";

// ====================== ПАКЕТЫ ПРОТОКОЛА (uint32 little-endian) ======================
struct com_t {
  uint32_t cmd_id;
  uint32_t command;
  uint32_t rel_number;
} __attribute__((packed));

struct stat_t {
  uint32_t id;
  uint32_t version;
  uint32_t r1, r2, r3, r4;
  uint32_t stat;
  uint32_t sl;       // уровень сигнала GSM: CSQ 0..31, 99 — нет данных
  uint32_t alarm;    // 0 — нет аварии, 1 — питание от АКБ
  uint32_t bat_adc;  // напряжение АКБ: среднее значение АЦП A0 (0..1023), в вольты пересчитывает сервер
} __attribute__((packed));

struct rep_t {
  uint32_t id;
  uint32_t cmd_id;
  uint32_t success;
} __attribute__((packed));

const uint32_t PROTOCOL_VERSION = 2;

// ====================== ЖУРНАЛ МОДЕМА ======================
// Прослойка между TinyGSM и портом модема: видит все команды и ответы, выводит ошибки и события SIM800L.
struct ModemEvents {
  uint32_t errors, underVoltage, overVoltage, powerDown, restarts, simLost, gprsLost;
} modemEv = {0, 0, 0, 0, 0, 0, 0};
bool modemReady = false;        // модем уже отвечал: «RDY» после этого — самопроизвольная перезагрузка
char modemLastCpin[32] = "";    // последний ответ «+CPIN: …» (причина, если SIM не готова)

const char* modemHint(const char* cmd) {
  if (!strncmp(cmd, "AT+CPIN", 7)) return "SIM-карта: нет карты, нет контакта или неверный PIN";
  if (!strncmp(cmd, "AT+CSTT", 7)) return "GPRS: модем не принял APN";
  if (!strncmp(cmd, "AT+CIICR", 8)) return "GPRS не поднялся: неверный APN, нет денег / интернета на SIM, слабый сигнал";
  if (!strncmp(cmd, "AT+CIFSR", 8)) return "GPRS: оператор не выдал IP-адрес";
  if (!strncmp(cmd, "AT+CGATT", 8)) return "GPRS: модем не подключается к пакетной сети (нет 2G-интернета у оператора?)";
  if (!strncmp(cmd, "AT+SAPBR", 8)) return "GPRS: не открылся канал (APN, баланс)";
  if (!strncmp(cmd, "AT+CIPSTART", 11)) return "нет соединения с сервером: адрес/порт, сервер выключен, DNS не разрешил имя";
  if (!strncmp(cmd, "AT+CIPSEND", 10)) return "соединение с сервером разорвано при отправке";
  if (!strncmp(cmd, "AT+CDNSCFG", 10)) return "модем не принял DNS-серверы";
  return nullptr;
}

class ModemStream : public Stream {
 public:
  explicit ModemStream(Stream& s) : port(s) {}
  int available() override { return port.available(); }
  int peek() override { return port.peek(); }
  void flush() override { port.flush(); }
  int read() override {
    int c = port.read();
    if (c >= 0) rx((char)c);
    return c;
  }
  size_t write(uint8_t c) override {
    tx((char)c);
    return port.write(c);
  }
  size_t write(const uint8_t* b, size_t n) override {
    for (size_t i = 0; i < n; i++) tx((char)b[i]);
    return port.write(b, n);
  }
  using Stream::write;

 private:
  Stream& port;
  char line[120];
  size_t lineLen = 0;
  char txBuf[64];
  size_t txLen = 0;
  char cmd[64] = "";

  void tx(char c) {
    if (c == '\r' || c == '\n') {
      txBuf[txLen] = 0;
      if (txLen >= 2 && (txBuf[0] == 'A' || txBuf[0] == 'a') && (txBuf[1] == 'T' || txBuf[1] == 't')) {
        memcpy(cmd, txBuf, txLen + 1);
        if (MODEM_AT_LOG) Serial.printf("  >> %s\n", cmd);
      }
      txLen = 0;
    } else if (txLen < sizeof(txBuf) - 1) {
      txBuf[txLen++] = c;
    }
  }
  void rx(char c) {
    if (c == '\r') return;
    if (c != '\n') {
      if (lineLen < sizeof(line) - 1) line[lineLen++] = (c >= 0x20 && c < 0x7F) ? c : '.';
      return;
    }
    if (!lineLen) return;
    line[lineLen] = 0;
    lineLen = 0;
    onLine(line);
  }
  void onLine(const char* l) {
    if (MODEM_AT_LOG) Serial.printf("  << %s\n", l);
    if (!strncmp(l, "+CPIN:", 6) || strstr(l, "SIM not inserted") || strstr(l, "SIM failure")) {
      strncpy(modemLastCpin, l, sizeof(modemLastCpin) - 1);
      modemLastCpin[sizeof(modemLastCpin) - 1] = 0;
    }
    if (strstr(l, "UNDER-VOLTAGE")) {
      modemEv.underVoltage++;
      Serial.printf("SIM800L: НИЗКОЕ НАПРЯЖЕНИЕ ПИТАНИЯ (%s) — нужно 3.8–4.2 В, источник 2 А, конденсатор у модема\n", l);
    } else if (strstr(l, "OVER-VOLTAGE")) {
      modemEv.overVoltage++;
      Serial.printf("SIM800L: ВЫСОКОЕ НАПРЯЖЕНИЕ ПИТАНИЯ (%s) — больше 4.4 В опасно для модема\n", l);
    } else if (strstr(l, "POWER DOWN")) {
      modemEv.powerDown++;
      Serial.printf("SIM800L: модем ВЫКЛЮЧИЛСЯ (%s)\n", l);
    } else if (!strcmp(l, "RDY")) {
      if (modemReady) {
        modemEv.restarts++;
        Serial.println("SIM800L: модем ПЕРЕЗАГРУЗИЛСЯ сам (RDY) — обычно из-за просадки питания при передаче");
      }
    } else if (!strcmp(l, "+CPIN: NOT INSERTED") || (modemReady && !strcmp(l, "+CPIN: NOT READY"))) {
      modemEv.simLost++;
      Serial.printf("SIM800L: SIM-карта не видна (%s) — проверьте карту и держатель\n", l);
    } else if (strstr(l, "+PDP: DEACT")) {
      modemEv.gprsLost++;
      Serial.println("SIM800L: оператор разорвал GPRS-сессию (+PDP: DEACT)");
    } else if (strstr(l, "+CME ERROR") || strstr(l, "+CMS ERROR") || !strcmp(l, "ERROR")) {
      modemEv.errors++;
      // одна и та же ошибка при повторных опросах (например, AT+CPIN? раз в секунду) — не чаще раза в 10 с
      static char lastErr[96] = "";
      static unsigned long lastErrAt = 0;
      char key[96];
      snprintf(key, sizeof(key), "%s|%s", cmd, l);
      if (!strcmp(key, lastErr) && millis() - lastErrAt < 10000) return;
      strcpy(lastErr, key);
      lastErrAt = millis();
      const char* h = modemHint(cmd);
      Serial.printf("SIM800L ОШИБКА: %s → %s%s%s\n", cmd[0] ? cmd : "(команда неизвестна)", l, h ? " — " : "", h ? h : "");
    }
  }
};
ModemStream modemStream(GSMport);

bool simAbsent() { return strstr(modemLastCpin, "NOT INSERTED") || strstr(modemLastCpin, "not inserted"); }

TinyGsm modem(modemStream);
TinyGsmClient gsmClient(modem);
PubSubClient mqtt(gsmClient);

// ====================== СОСТОЯНИЕ ======================
uint8_t last_r_states[4] = {0, 0, 0, 0};  // состояние, отправленное в последнем stat
uint8_t current_state[4] = {0, 0, 0, 0};

unsigned long lastPublish = 0;
unsigned long lastWatchdog = 0;
unsigned long lastReconnectAttempt = 0;
unsigned long lastCheck = 0;
unsigned long lastSignalUpdate = 0;
unsigned long lastNetworkCheck = 0;
unsigned long subscribedAt = 0;
unsigned long rebootAt = 0;           // != 0 — запланирована перезагрузка по команде 5
uint32_t cmd_id_mem = 0;
uint32_t alarmNow = 0;
uint32_t signalQuality = 99;
uint32_t mqttReconnectFailCount = 0;
uint32_t networkFailCount = 0;
bool first_cmd = true;
bool statPending = false;             // отправить stat при первой возможности

const unsigned long MAX_PUBLISH_INTERVAL = 300000;   // stat не реже раза в 5 минут
const unsigned long RELAY_POLL_INTERVAL = 500;
const unsigned long SIGNAL_UPDATE_INTERVAL = 60000;  // AT+CSQ раз в минуту
const unsigned long NETWORK_CHECK_INTERVAL = 30000;  // AT+CREG? раз в 30 секунд
const unsigned long MQTT_RECONNECT_INTERVAL = 5000;
const unsigned long WATCHDOG_INTERVAL = 1000;
const unsigned long RETAINED_GUARD_MS = 10000;       // окно «retained-команд» после подписки
const unsigned long REBOOT_DELAY_MS = 2000;          // время на отправку rep перед перезагрузкой
const uint32_t MAX_MQTT_FAILS_BEFORE_RESET = 5;
const uint32_t MAX_NETWORK_FAILS_BEFORE_RESET = 3;

// ====================== WATCHDOG ======================
void pulseWatchdog() {
  digitalWrite(RsRes, HIGH);
  delay(150);
  digitalWrite(RsRes, LOW);
  lastWatchdog = millis();
}

// Импульс WATCHDOG, если с прошлого прошло больше секунды
void serviceWatchdog() {
  if (millis() - lastWatchdog >= WATCHDOG_INTERVAL) pulseWatchdog();
}

// Пауза, во время которой WATCHDOG продолжает получать импульсы
void waitWithWatchdog(unsigned long ms) {
  unsigned long start = millis();
  while (millis() - start < ms) {
    serviceWatchdog();
    delay(10);
  }
}

void triggerReset() {
  Serial.println("Перезапуск через WATCHDOG");
  unsigned long start = millis();
  while (1) {
    delay(1000);  // перестаём «кормить» внешний WATCHDOG — он перезагрузит плату
    // страховка: если внешний WATCHDOG не сработал (неисправен/не установлен) — программная перезагрузка
    if (millis() - start > 120000UL) {
      Serial.println("WATCHDOG не сработал за 120 с → программная перезагрузка ESP");
      ESP.restart();
    }
  }
}

// ====================== РЕЛЕ ======================
// Включает/выключает реле 1..4 и через 1 с проверяет вход контроля. true — состояние подтверждено.
bool setRelay(uint32_t num, bool on) {
  if (num < 1 || num > 4) return false;
  const RelayPin& r = RELAYS[num - 1];
  digitalWrite(r.esp, (on != r.activeLow) ? HIGH : LOW);
  waitWithWatchdog(1000);
  return pcf8574.digitalRead(r.pcf) == (on ? HIGH : LOW);
}

bool rele_on(uint32_t num) { return setRelay(num, true); }
bool rele_off(uint32_t num) { return setRelay(num, false); }

void readRelayStates() {
  for (int i = 0; i < 4; i++) current_state[i] = pcf8574.digitalRead(RELAYS[i].pcf);
}

// ====================== SHA256 ======================
String sha256_first16_hex(const String& input) {
  SHA256 sha;
  sha.reset();
  sha.update(input.c_str(), input.length());
  uint8_t hash[32];
  sha.finalize(hash, 32);
  static const char HEXCH[] = "0123456789abcdef";
  char out[33];
  for (int i = 0; i < 16; i++) {
    out[i * 2] = HEXCH[hash[i] >> 4];
    out[i * 2 + 1] = HEXCH[hash[i] & 0x0F];
  }
  out[32] = '\0';
  return String(out);
}

// ====================== ПРОВЕРКА ВВОДА ======================
bool isDigits(const String& s) {
  if (s.length() == 0) return false;
  for (unsigned int i = 0; i < s.length(); i++)
    if (s[i] < '0' || s[i] > '9') return false;
  return true;
}

// ID: 1..4294967295 без ведущих нулей (сервер сравнивает строки «relay-12», «com/12»)
bool parseId(const String& s, uint32_t& out) {
  if (!isDigits(s) || s[0] == '0' || s.length() > 10) return false;
  unsigned long long v = strtoull(s.c_str(), nullptr, 10);
  if (v == 0 || v > 4294967295ULL) return false;
  out = (uint32_t)v;
  return true;
}

bool isValidHost(const char* h) {
  size_t n = strnlen(h, sizeof(settings.server_host));
  if (n == 0 || n >= sizeof(settings.server_host)) return false;
  for (size_t i = 0; i < n; i++) {
    char c = h[i];
    if (!(isalnum((unsigned char)c) || c == '.' || c == '-')) return false;
  }
  return true;
}

bool isValidBaud(uint32_t b) {
  return b == 4800 || b == 9600 || b == 19200 || b == 38400 || b == 57600 || b == 115200;
}

bool isPrintableField(const char* s, size_t size) {
  size_t n = strnlen(s, size);
  if (n >= size) return false;  // нет завершающего нуля
  for (size_t i = 0; i < n; i++)
    if ((unsigned char)s[i] < 0x20 || (unsigned char)s[i] > 0x7E) return false;
  return true;
}

// ====================== EEPROM ======================
void saveSettings() {
  EEPROM.put(0, settings);
  EEPROM.commit();
}

void applySettings() {
  idStr = String(settings.id_i);
  username = "relay-" + idStr;
  password = sha256_first16_hex(username);
  comTopic = "com/" + idStr;
  clientID = "relay-id" + idStr + "-v1";
}

// Отпечаток сборки (дата и время компиляции): по нему APPLY_DEFAULTS_ON_FLASH узнаёт новую прошивку
uint32_t buildStamp() {
  const char* b = __DATE__ " " __TIME__;
  uint32_t h = 2166136261u;  // FNV-1a
  while (*b) {
    h ^= (uint8_t)*b++;
    h *= 16777619u;
  }
  return h;
}

// Заводские настройки из блока «ЗАВОДСКИЕ НАСТРОЙКИ» в начале файла
void applyFactoryDefaults() {
  memset(&settings, 0, sizeof(settings_t));
  settings.id_i = DEFAULT_DEVICE_ID;
  strncpy(settings.pin_sim, DEFAULT_SIM_PIN, sizeof(settings.pin_sim) - 1);
  settings.bat_alarm_relay = DEFAULT_ALARM_RELAY - 1;
  settings.magic = SETTINGS_MAGIC;
  strncpy(settings.server_host, DEFAULT_SERVER_HOST, sizeof(settings.server_host) - 1);
  settings.server_port = DEFAULT_SERVER_PORT;
  strncpy(settings.apn, DEFAULT_APN, sizeof(settings.apn) - 1);
  settings.build_stamp = buildStamp();
  settings.modem_baud = DEFAULT_MODEM_BAUD;
}

void loadSettings() {
  EEPROM.begin(EEPROM_SIZE);
  EEPROM.get(0, settings);

  size_t pinLen = strnlen(settings.pin_sim, sizeof(settings.pin_sim));
  bool baseValid = settings.id_i != 0 && settings.id_i != 0xFFFFFFFF && pinLen <= 4
                   && settings.current_pin_failed_attempts <= 10 && settings.global_pin_failed_attempts <= 10
                   && settings.bat_alarm_relay <= 3;
  bool v23Valid = settings.magic == SETTINGS_MAGIC && isValidHost(settings.server_host)
                  && settings.server_port != 0 && isPrintableField(settings.apn, sizeof(settings.apn));

  if (!baseValid) {
    applyFactoryDefaults();
    saveSettings();
    Serial.println("EEPROM пуста или повреждена → заводские настройки из прошивки");
  } else if (APPLY_DEFAULTS_ON_FLASH && settings.build_stamp != buildStamp()) {
    applyFactoryDefaults();
    saveSettings();
    Serial.println("Новая прошивка с APPLY_DEFAULTS_ON_FLASH=1 → применены заводские настройки из прошивки");
  } else if (!v23Valid) {
    // обновление с прошивки до v2.3: ID, PIN и реле питания сохраняем, новые поля — заводские
    settings.magic = SETTINGS_MAGIC;
    memset(settings.server_host, 0, sizeof(settings.server_host));
    strncpy(settings.server_host, DEFAULT_SERVER_HOST, sizeof(settings.server_host) - 1);
    settings.server_port = DEFAULT_SERVER_PORT;
    memset(settings.apn, 0, sizeof(settings.apn));
    strncpy(settings.apn, DEFAULT_APN, sizeof(settings.apn) - 1);
    settings.build_stamp = buildStamp();
    settings.modem_baud = DEFAULT_MODEM_BAUD;
    saveSettings();
    Serial.println("Настройки сервера по умолчанию: " + String(settings.server_host) + ":" + String(settings.server_port));
  }
  if (!isValidBaud(settings.modem_baud)) {  // обновление с v2.3: поля скорости ещё нет
    settings.modem_baud = DEFAULT_MODEM_BAUD;
    saveSettings();
  }
  applySettings();
  Serial.printf("EEPROM прочитан → id=%u, PIN=%s, ошибок PIN: %u/%u, реле питания: %u, сервер %s:%u, APN \"%s\", модем %u бод\n",
                settings.id_i, settings.pin_sim, settings.current_pin_failed_attempts, settings.global_pin_failed_attempts,
                settings.bat_alarm_relay + 1, settings.server_host, settings.server_port, settings.apn, settings.modem_baud);
}

// Полный сброс: вся EEPROM заполняется 0xFF (как чистая), затем записываются заводские настройки
void factoryReset() {
  for (int i = 0; i < EEPROM_SIZE; i++) EEPROM.write(i, 0xFF);
  EEPROM.commit();
  applyFactoryDefaults();
  saveSettings();
  Serial.println("EEPROM очищена, записаны заводские настройки. Перезагрузка...");
  delay(500);
  ESP.restart();
}

// ====================== МЕНЮ НАСТРОЙКИ (UART 115200) ======================
// Строка из монитора порта. Работает и с «новой строкой», и без неё (конец ввода — пауза 1,5 с).
String readLine() {
  String s;
  unsigned long lastChar = millis();
  while (true) {
    serviceWatchdog();
    while (Serial.available()) {
      char c = Serial.read();
      lastChar = millis();
      if (c == '\r') continue;
      if (c == '\n') {
        s.trim();
        if (s.length()) return s;
        continue;
      }
      if (s.length() < 80) s += c;
    }
    if (s.length() && millis() - lastChar > 1500) {
      s.trim();
      if (s.length()) return s;
    }
    delay(10);
  }
}

// Запрос значения с подтверждением повторным вводом
bool readConfirmed(const char* prompt, String& out) {
  Serial.println(prompt);
  String first = readLine();
  Serial.println("Повторите: ");
  String second = readLine();
  if (first != second) {
    Serial.println("Ввод не совпадает: " + first + " / " + second);
    return false;
  }
  out = first;
  return true;
}

void printMenu() {
  Serial.println();
  Serial.println("0 - Скорость связи с модемом (текущее: " + String(settings.modem_baud) + " бод)");
  Serial.println("1 - ID (текущее: " + idStr + ")");
  Serial.println("2 - PIN SIM (текущее: " + String(settings.pin_sim) + "), [ошибок ввода этого PIN: " + String(settings.current_pin_failed_attempts) + "]");
  Serial.println("3 - Сброс глобального счётчика ошибок ввода PIN (текущее: " + String(settings.global_pin_failed_attempts) + ")");
  Serial.println("4 - Выбор номера реле питания шкафа (текущее: " + String(settings.bat_alarm_relay + 1) + ")");
  Serial.println("5 - Адрес сервера MQTT, домен или IP (текущее: " + String(settings.server_host) + ")");
  Serial.println("6 - Порт сервера MQTT (текущее: " + String(settings.server_port) + ")");
  Serial.println("7 - APN оператора (текущее: " + String(strlen(settings.apn) ? settings.apn : "по умолчанию SIM") + ")");
  Serial.println("8 - Сброс к заводским настройкам (полная очистка EEPROM)");
  Serial.println("9 - Выход");
}

void enterSetupMode() {
  Serial.println("Введите пароль:");
  int attempts = 0;
  while (attempts < 3) {
    if (readLine() == "offonroot") break;
    attempts++;
    Serial.println("Неверный пароль");
  }
  if (attempts == 3) {
    Serial.println("Выход из настроек");
    return;
  }

  while (true) {
    printMenu();
    String line = readLine();
    char choice = line[0];
    String v;

    if (choice == '0') {
      if (readConfirmed("Скорость связи с модемом: 4800, 9600, 19200, 38400, 57600 или 115200 (обычно 9600): ", v)) {
        uint32_t b = isDigits(v) && v.length() <= 6 ? (uint32_t)v.toInt() : 0;
        if (isValidBaud(b)) {
          settings.modem_baud = b;
          saveSettings();
          Serial.println("Скорость сохранена: " + String(b) + " бод. При запуске модем будет переведён на неё (AT+IPR)");
          if (b > 57600) Serial.println("Внимание: на 115200 программный порт ESP8266 может терять данные от модема");
        } else {
          Serial.println("Неверная скорость");
        }
      }
    } else if (choice == '1') {
      if (readConfirmed("Новый ID (целое число без ведущих нулей): ", v)) {
        uint32_t newId;
        if (parseId(v, newId)) {
          settings.id_i = newId;
          saveSettings();
          applySettings();
          Serial.println("ID сохранён. Логин MQTT: " + username + ", топик команд: " + comTopic);
        } else {
          Serial.println("Неверный ID: нужно число от 1 до 4294967295 без ведущих нулей");
        }
      }
    } else if (choice == '2') {
      if (readConfirmed("Новый PIN (4 цифры): ", v)) {
        if (v.length() == 4 && isDigits(v)) {
          settings.current_pin_failed_attempts = 0;  // сбрасываем счётчик после смены PIN
          memset(settings.pin_sim, 0, sizeof(settings.pin_sim));
          strncpy(settings.pin_sim, v.c_str(), 4);
          saveSettings();
          Serial.println("PIN сохранён, счётчик ошибок сброшен");
        } else {
          Serial.println("PIN должен состоять из 4 цифр");
        }
      }
    } else if (choice == '3') {
      Serial.println("Функция сброса глобального счётчика ошибок");
      Serial.println("ВНИМАНИЕ!");
      Serial.println("Плата не выполняет ввод PIN, если счётчик ошибок достиг значения 2");
      Serial.println("Во избежание блокировки SIM используйте эту функцию только после сброса счётчика попыток на самой SIM-карте с помощью стороннего устройства");
      if (readConfirmed("Для сброса счётчика введите \"1\", для выхода — \"9\":", v)) {
        if (v == "1") {
          settings.global_pin_failed_attempts = 0;
          saveSettings();
          Serial.println("Глобальный счётчик ошибок сброшен");
        } else {
          Serial.println("Счётчик не изменён");
        }
      }
    } else if (choice == '4') {
      if (readConfirmed("Новый номер реле [1-4]: ", v)) {
        if (v.length() == 1 && v[0] >= '1' && v[0] <= '4') {
          settings.bat_alarm_relay = v.toInt() - 1;
          saveSettings();
          Serial.println("Номер реле сохранён");
        } else {
          Serial.println("Номер реле — цифра от 1 до 4");
        }
      }
    } else if (choice == '5') {
      if (readConfirmed("Адрес сервера MQTT (например 185.209.29.25 или mqtt.example.ru): ", v)) {
        if (v.length() > 0 && v.length() < sizeof(settings.server_host) && isValidHost(v.c_str())) {
          memset(settings.server_host, 0, sizeof(settings.server_host));
          strncpy(settings.server_host, v.c_str(), sizeof(settings.server_host) - 1);
          saveSettings();
          Serial.println("Адрес сервера сохранён: " + v);
        } else {
          Serial.println("Неверный адрес: допустимы латинские буквы, цифры, точка и дефис (до 63 символов)");
        }
      }
    } else if (choice == '6') {
      if (readConfirmed("Порт сервера MQTT (обычно 1883): ", v)) {
        long port = isDigits(v) && v.length() <= 5 ? v.toInt() : 0;
        if (port >= 1 && port <= 65535) {
          settings.server_port = (uint16_t)port;
          saveSettings();
          Serial.println("Порт сохранён: " + String(port));
        } else {
          Serial.println("Порт — число от 1 до 65535");
        }
      }
    } else if (choice == '7') {
      if (readConfirmed("APN оператора (например internet.mts.ru; \"-\" — по умолчанию SIM): ", v)) {
        if (v == "-") v = "";
        if (v.length() < sizeof(settings.apn) && isPrintableField(v.c_str(), sizeof(settings.apn))) {
          memset(settings.apn, 0, sizeof(settings.apn));
          strncpy(settings.apn, v.c_str(), sizeof(settings.apn) - 1);
          saveSettings();
          Serial.println("APN сохранён");
        } else {
          Serial.println("APN — до 31 латинского символа");
        }
      }
    } else if (choice == '8') {
      Serial.println("ВНИМАНИЕ! Будут стёрты ВСЕ настройки платы (вся EEPROM) и записаны заводские значения из прошивки:");
      Serial.printf("  ID %u, PIN %s, реле питания %u, сервер %s:%u, APN \"%s\", модем %u бод\n", (uint32_t)DEFAULT_DEVICE_ID, DEFAULT_SIM_PIN,
                    (uint32_t)DEFAULT_ALARM_RELAY, DEFAULT_SERVER_HOST, (uint32_t)DEFAULT_SERVER_PORT, DEFAULT_APN, (uint32_t)DEFAULT_MODEM_BAUD);
      Serial.println("После сброса плата перезагрузится.");
      if (readConfirmed("Для подтверждения введите RESET (другой ввод — отмена):", v) && v == "RESET") {
        factoryReset();
      } else {
        Serial.println("Сброс отменён");
      }
    } else if (choice == '9') {
      break;
    }
  }
}

// ====================== GSM ======================
const char* simStatusName(int s) {
  if (s == SIM_READY) return "READY";
  if (s == SIM_LOCKED) return "SIM LOCKED";
  if (s == SIM_ANTITHEFT_LOCKED) return "SIM ANTITHEFT LOCKED";
  if (s == SIM_ERROR) return "SIM ERROR";
  return "UNKNOWN";
}

const char* regStatusName(int r) {
  switch (r) {
    case REG_UNREGISTERED: return "не зарегистрирован, сеть не ищет";
    case REG_SEARCHING: return "ищет сеть";
    case REG_DENIED: return "оператор ОТКАЗАЛ в регистрации";
    case REG_OK_HOME: return "зарегистрирован (домашняя сеть)";
    case REG_OK_ROAMING: return "зарегистрирован (роуминг)";
    default: return "неизвестно";
  }
}

// Отчёт о состоянии модема — при неудачах, чтобы было видно, почему плата не выходит на связь
void modemReport(const char* why) {
  pulseWatchdog();
  Serial.printf("---- SIM800L: %s ----\n", why);
  int16_t csq = modem.getSignalQuality();
  Serial.printf("  сигнал: CSQ %d%s\n", csq, csq == 99 || csq <= 0 ? " — НЕТ СИГНАЛА (антенна?)" : csq < 10 ? " — слабый" : "");
  int reg = modem.getRegistrationStatus();
  Serial.printf("  сеть: %s, оператор: %s\n", regStatusName(reg), modem.getOperator().c_str());
  int mv = modem.getBattVoltage();
  Serial.printf("  питание модема: %d мВ%s\n", mv, mv > 0 && mv < 3500 ? " — НИЗКОЕ (нужно 3.8–4.2 В, 2 А)" : mv > 4400 ? " — ВЫСОКОЕ" : "");
  pulseWatchdog();
  Serial.printf("  SIM: %s, GPRS: %s\n", modemLastCpin[0] ? modemLastCpin : "?", modem.isGprsConnected() ? "подключён" : "нет");
  Serial.printf("  события: ошибок %u, низкое напряжение %u, перезагрузок модема %u, выключений %u, потерь SIM %u, разрывов GPRS %u\n",
                modemEv.errors, modemEv.underVoltage, modemEv.restarts, modemEv.powerDown, modemEv.simLost, modemEv.gprsLost);
  Serial.println("--------------------------------");
}

// Поиск скорости модема (сначала рабочая из настроек), затем перевод модема на рабочую скорость
const uint32_t MODEM_BAUDS[] = {9600, 115200, 57600, 38400, 19200, 4800};
uint32_t modemBaud = 0;

bool modemTryBaud(uint32_t b, uint32_t timeoutMs) {
  GSMport.begin(b);
  waitWithWatchdog(30);
  return modem.testAT(timeoutMs);
}

bool modemFindBaud() {
  const uint32_t want = settings.modem_baud;
  // сразу после включения модему нужно 2–4 с: ждём ответа на рабочей скорости до 6 с
  if (modemTryBaud(want, 1500) || modemTryBaud(want, 1500) || modemTryBaud(want, 1500)) {
    modemBaud = want;
  } else {
    for (uint32_t b : MODEM_BAUDS) {
      if (b == want) continue;
      serviceWatchdog();
      Serial.printf("Модем: нет ответа на %u бод, пробую %u…\n", (unsigned)want, (unsigned)b);
      if (modemTryBaud(b, 800)) { modemBaud = b; break; }
    }
    if (!modemBaud) {
      GSMport.begin(want);
      Serial.println("SIM800L НЕ ОТВЕЧАЕТ ни на одной скорости: проверьте питание модема (3.8–4.2 В, 2 А), TX модема → D3, RX → D4, общий GND");
      return false;
    }
    Serial.printf("Модем отвечает на %u бод → перевожу на %u бод\n", (unsigned)modemBaud, (unsigned)want);
    modem.sendAT(GF("+IPR="), want);
    modem.waitResponse();
    if (modemTryBaud(want, 1500)) {
      modem.sendAT(GF("&W"));  // запомнить скорость в модеме
      modem.waitResponse();
      modemBaud = want;
    } else if (modemTryBaud(modemBaud, 1500)) {
      Serial.printf("Модем не перешёл на %u бод — работаю на %u бод\n", (unsigned)want, (unsigned)modemBaud);
    } else {
      return false;
    }
  }
  modemReady = true;
  Serial.printf("Модем отвечает, скорость %u бод\n", (unsigned)modemBaud);
  return true;
}

// Перезагрузка модема: его «RDY» и «+CPIN: NOT READY» в это время — не сбой
bool modemRestart() {
  modemReady = false;
  bool ok = modem.restart();
  modemReady = true;
  return ok;
}

// Ожидание готовности SIM вместо фиксированной паузы (после включения/перезагрузки модема SIM готова через 1–5 с)
int waitSimStatus(unsigned long timeoutMs) {
  unsigned long start = millis();
  int st = SIM_ERROR;
  while (millis() - start < timeoutMs) {
    pulseWatchdog();
    st = modem.getSimStatus(2000);
    if (st == SIM_READY || st == SIM_LOCKED) return st;
    if (simAbsent()) return st;  // карты нет — ждать бессмысленно
    waitWithWatchdog(500);
  }
  return st;
}

// Ожидание регистрации в сети частями по 10 с, чтобы WATCHDOG получал импульсы; отказ оператора — сразу выход
bool waitForNetworkWithWatchdog(unsigned long timeoutMs) {
  unsigned long start = millis();
  while (millis() - start < timeoutMs) {
    pulseWatchdog();
    if (modem.waitForNetwork(10000L)) return true;
    pulseWatchdog();
    int reg = modem.getRegistrationStatus();
    int16_t csq = modem.getSignalQuality();
    Serial.printf("Сеть: %s, CSQ %d, прошло %lu с\n", regStatusName(reg), csq, (millis() - start) / 1000);
    if (reg == REG_DENIED) {
      Serial.println("Оператор отклоняет SIM: карта не активирована/заблокирована или нет 2G-сети этого оператора");
      return false;
    }
  }
  return false;
}

bool connectGprs() {
  Serial.println("GPRS: подключение (APN \"" + String(settings.apn) + "\")...");
  pulseWatchdog();
  unsigned long t = millis();
  modem.gprsConnect(settings.apn, "", "");
  pulseWatchdog();
  if (modem.isGprsConnected()) {
    Serial.printf("GPRS connected за %lu мс, IP %s\n", millis() - t, modem.getLocalIP().c_str());
    return true;
  }
  Serial.println("GPRS fail — причина в строках «SIM800L ОШИБКА» выше (APN, баланс, интернет на SIM, сигнал)");
  return false;
}

void initGSM() {
  Serial.println("GSM init start");
  bool networkOk = false;
  for (int attempt = 0; attempt < 3 && !networkOk; attempt++) {
    pulseWatchdog();
    Serial.print("Попытка сети #");
    Serial.println(attempt + 1);
    if (!modemFindBaud()) {
      waitWithWatchdog(3000);
      continue;
    }
    // первая попытка — без перезагрузки модема: он ищет сеть с момента включения питания
    // и часто уже зарегистрирован; перезагрузка (AT+CFUN=1,1) — только на повторных попытках
    // init()/restart() возвращают false и при неготовой SIM — причину SIM разбираем ниже
    if (attempt == 0) modem.init();
    else modemRestart();
    pulseWatchdog();
    if (!modem.testAT(2000)) {
      Serial.println("Модем перестал отвечать после инициализации — питание модема?");
      continue;
    }
    modem.sendAT(GF("+CMEE=2"));  // ошибки модема текстом (+CME ERROR: …)
    modem.waitResponse();
    String info = modem.getModemInfo();
    Serial.println("Модем: " + info);

    int simStatus = waitSimStatus(10000);
    Serial.print("SIM статус: ");
    Serial.println(simStatusName(simStatus));
    if (simStatus == SIM_ERROR) {
      Serial.printf("SIM не готова (%s): %s\n", modemLastCpin[0] ? modemLastCpin : "нет ответа",
                    simAbsent() ? "карта не вставлена или нет контакта" : "проверьте карту и питание модема");
    }

    if (simStatus == SIM_LOCKED) {
      Serial.println("SIM locked, try pin");
      if (settings.current_pin_failed_attempts > 0) {
        Serial.println("╔══════════════════════════════════════╗");
        Serial.println("║   ОШИБКА: Этот PIN был введён неверно ранее!");
        Serial.println("║   Автоматическая разблокировка ЗАБЛОКИРОВАНА!");
        Serial.println("║   Через настройки (i) укажите верный PIN");
        Serial.println("╚══════════════════════════════════════╝");
        triggerReset();
      }
      if (settings.global_pin_failed_attempts > 1) {
        Serial.println("╔══════════════════════════════════════╗");
        Serial.println("║   ОШИБКА: PIN был введён неверно " + String(settings.global_pin_failed_attempts) + " раз!");
        Serial.println("║   Автоматическая разблокировка ЗАБЛОКИРОВАНА!");
        Serial.println("║   С помощью внешнего устройства разблокируйте SIM-карту");
        Serial.println("║   Через настройки (i) укажите верный PIN и сбросьте глобальный счётчик неудачных попыток");
        Serial.println("╚══════════════════════════════════════╝");
        triggerReset();
      }
      if (modem.simUnlock(settings.pin_sim)) {
        Serial.println("SIM unlocked успешно");
        if (settings.current_pin_failed_attempts || settings.global_pin_failed_attempts) {
          settings.current_pin_failed_attempts = 0;
          settings.global_pin_failed_attempts = 0;
          saveSettings();
        }
      } else {
        Serial.println("SIM unlock fail (PIN: " + String(settings.pin_sim) + ")");
        settings.current_pin_failed_attempts++;
        settings.global_pin_failed_attempts++;
        saveSettings();
        Serial.printf("Неудачных попыток: %u\n", settings.current_pin_failed_attempts);
      }
      pulseWatchdog();
      simStatus = modem.getSimStatus();
      Serial.print("SIM статус после simUnlock: ");
      Serial.println(simStatusName(simStatus));
    }

    if (simStatus != SIM_READY) continue;

    unsigned long t = millis();
    if (waitForNetworkWithWatchdog(60000)) {
      Serial.printf("Network OK за %lu мс, CSQ %d, оператор %s\n", millis() - t, modem.getSignalQuality(), modem.getOperator().c_str());
      networkOk = true;
    } else {
      modemReport("нет регистрации в сети");
      Serial.println("Network fail, retry");
    }
  }

  if (!networkOk) {
    Serial.println("Network fail после 3 попыток → reset watchdog");
    triggerReset();
  }
  if (!connectGprs() && !connectGprs()) {
    modemReport("GPRS не подключается");
    Serial.println("GPRS fail после повтора → reset watchdog");
    triggerReset();
  }
}

// ====================== СИГНАЛ И АКБ ======================
// AT+CSQ — только из loop(), не из MQTT-callback
void updateSignalQuality() {
  int16_t csq = modem.getSignalQuality();
  signalQuality = (csq >= 0 && csq <= 31) ? (uint32_t)csq : 99;
  lastSignalUpdate = millis();
  Serial.printf("Сигнал GSM: CSQ=%u\n", signalQuality);
}

// Напряжение АКБ: среднее из 16 измерений АЦП (A0)
uint32_t readBatteryAdc() {
  uint32_t sum = 0;
  for (int i = 0; i < 16; i++) {
    sum += analogRead(A0);
    delay(2);
  }
  return sum / 16;
}

// Признак аварии: реле контроля питания перешло из «вкл» в «выкл» → питание от АКБ
void updateAlarm() {
  uint32_t r = settings.bat_alarm_relay;  // 0..3, проверено в loadSettings
  if (!alarmNow) {
    if (last_r_states[r] == HIGH && current_state[r] == LOW) alarmNow = 1;
  } else if (current_state[r] == HIGH) {
    alarmNow = 0;
  }
}

// ====================== MQTT ======================
// Публикации не переподключаются сами: при ошибке соединение восстановит loop()
bool publishStat() {
  stat_t s = {
    .id = settings.id_i,
    .version = PROTOCOL_VERSION,
    .r1 = current_state[0] == HIGH ? 1u : 0u,
    .r2 = current_state[1] == HIGH ? 1u : 0u,
    .r3 = current_state[2] == HIGH ? 1u : 0u,
    .r4 = current_state[3] == HIGH ? 1u : 0u,
    .stat = 1,
    .sl = signalQuality,
    .alarm = alarmNow,
    .bat_adc = readBatteryAdc()
  };
  if (mqtt.publish(STAT_TOPIC, (const uint8_t*)&s, sizeof(stat_t), true)) {
    memcpy(last_r_states, current_state, 4);
    lastPublish = millis();
    statPending = false;
    Serial.printf("stat опубликован (retain=true): реле %u%u%u%u, CSQ=%u, АЦП АКБ=%u, авария=%u\n",
                  s.r1, s.r2, s.r3, s.r4, s.sl, s.bat_adc, s.alarm);
    return true;
  }
  Serial.println("stat publish error");
  statPending = true;
  return false;
}

bool publishRep(uint32_t cmd_id, uint32_t success) {
  rep_t r = {.id = settings.id_i, .cmd_id = cmd_id, .success = success};
  if (mqtt.publish(REP_TOPIC, (const uint8_t*)&r, sizeof(rep_t), false)) {
    Serial.printf("rep опубликован: cmd_id=%u success=%u\n", cmd_id, success);
    return true;
  }
  Serial.println("rep publish error");
  return false;
}

bool forEachRelay(uint32_t relNumber, bool on) {
  if (relNumber != 0) return setRelay(relNumber, on);
  bool ok = true;
  for (uint32_t n = 1; n <= 4; n++) ok &= setRelay(n, on);
  return ok;
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  if (strcmp(topic, comTopic.c_str()) != 0 || length != sizeof(com_t)) return;

  com_t cmd;
  memcpy(&cmd, payload, sizeof(cmd));  // буфер PubSubClient не выровнен
  Serial.printf("COM: cmd_id=%u cmd=%u rel=%u\n", cmd.cmd_id, cmd.command, cmd.rel_number);

  // QoS 1: повтор той же команды не выполняем
  if (cmd.command == 0 || cmd_id_mem == cmd.cmd_id) return;
  cmd_id_mem = cmd.cmd_id;

  // retained-команды 3 и 5, пришедшие сразу после подписки, — старые: не выполняем,
  // иначе плата уйдёт в цикл перезапусков (сервер отправляет 3 и 5 без retain)
  bool justSubscribed = first_cmd && (millis() - subscribedAt < RETAINED_GUARD_MS);
  first_cmd = false;
  if (justSubscribed && (cmd.command == 3 || cmd.command == 5)) {
    Serial.println("COM: retained-команда перезапуска после подключения — пропущена");
    return;
  }

  bool ok = true;
  switch (cmd.command) {
    case 1:
      ok = forEachRelay(cmd.rel_number, true);
      break;
    case 2:
      ok = forEachRelay(cmd.rel_number, false);
      break;
    case 3:
      ok = forEachRelay(cmd.rel_number, false);
      if (ok) {
        waitWithWatchdog(1000);
        ok = forEachRelay(cmd.rel_number, true);
      }
      break;
    case 4:  // запрос статуса
      readRelayStates();
      updateAlarm();
      publishStat();
      publishRep(cmd.cmd_id, 1);
      return;
    case 5:  // перезагрузка платы: ответ сейчас, перезагрузка из loop() через 2 с
      Serial.printf("COM: перезагрузка платы по команде сервера (cmd_id=%u)\n", cmd.cmd_id);
      publishRep(cmd.cmd_id, 1);
      rebootAt = millis() + REBOOT_DELAY_MS;
      if (rebootAt == 0) rebootAt = 1;
      return;
    default:
      ok = false;  // неизвестная команда
      break;
  }
  publishRep(cmd.cmd_id, ok ? 1 : 2);
}

const char* mqttStateText(int s) {
  switch (s) {
    case -4: return "нет ответа сервера (таймаут)";
    case -3: return "соединение потеряно";
    case -2: return "не удалось подключиться: неверный адрес/порт сервера, порт закрыт или нет GPRS";
    case -1: return "отключено";
    case 1: return "сервер не поддерживает протокол";
    case 2: return "сервер отклонил clientID";
    case 3: return "сервер недоступен";
    case 4: return "неверный логин или пароль — проверьте ID платы";
    case 5: return "нет доступа — плата не добавлена на сервер";
    default: return "";
  }
}

bool reconnectMQTT() {
  if (!modem.isGprsConnected()) {
    Serial.println("GPRS отключён → переподключение GPRS");
    if (!connectGprs()) {
      mqttReconnectFailCount++;
      Serial.printf("Ошибок подключения: %u/%u\n", mqttReconnectFailCount, MAX_MQTT_FAILS_BEFORE_RESET);
      if (mqttReconnectFailCount >= MAX_MQTT_FAILS_BEFORE_RESET) {
        modemReport("GPRS не восстанавливается");
        Serial.println("Too many reconnect fails → reset watchdog");
        triggerReset();
      }
      return false;
    }
  }

  Serial.println("MQTT: подключение к " + String(settings.server_host) + ":" + String(settings.server_port) + ", логин " + username);
  pulseWatchdog();
  if (!mqtt.connect(clientID.c_str(), username.c_str(), password.c_str())) {
    mqttReconnectFailCount++;
    int st = mqtt.state();
    Serial.printf("MQTT connect failed, state=%d (%s), fails=%u/%u\n", st, mqttStateText(st),
                  mqttReconnectFailCount, MAX_MQTT_FAILS_BEFORE_RESET);
    if (st == -2 && mqttReconnectFailCount == 2) modemReport("нет соединения с сервером");
    if (mqttReconnectFailCount >= MAX_MQTT_FAILS_BEFORE_RESET) {
      modemReport("перед перезагрузкой");
      Serial.println("Too many MQTT reconnect fails → reset watchdog");
      triggerReset();
    }
    return false;
  }

  static bool firstOnline = true;
  if (firstOnline) {
    firstOnline = false;
    Serial.printf("MQTT connected — на связи через %lu с после включения\n", millis() / 1000);
  } else {
    Serial.println("MQTT connected");
  }
  mqttReconnectFailCount = 0;

  if (!mqtt.subscribe(comTopic.c_str(), 1)) {
    Serial.println("MQTT subscribe [" + comTopic + "] fail, state=" + String(mqtt.state()));
    mqtt.disconnect();
    return false;
  }
  Serial.println("Подписка на " + comTopic + " QoS1 УСПЕШНА");
  subscribedAt = millis();
  first_cmd = true;
  statPending = true;  // сразу сообщаем серверу текущее состояние
  return true;
}

// ====================== SETUP ======================
void setup() {
  // Начальное состояние реле: реле 1 — LOW, 2–4 — HIGH (все включены), как в предыдущих версиях
  pinMode(reley_1, OUTPUT); digitalWrite(reley_1, LOW);
  pinMode(reley_2, OUTPUT); digitalWrite(reley_2, HIGH);
  pinMode(reley_3, OUTPUT); digitalWrite(reley_3, HIGH);
  pinMode(reley_4, OUTPUT); digitalWrite(reley_4, HIGH);
  pinMode(RsRes, OUTPUT);
  pulseWatchdog();

  // Wi-Fi не используется: выключаем радио (меньше потребление от АКБ и шум АЦП)
  WiFi.mode(WIFI_OFF);
  WiFi.forceSleepBegin();
  delay(1);

  Serial.begin(115200);
  waitWithWatchdog(300);  // модем тем временем включается и ищет сеть сам
  Serial.println("\n=== Прошивка MQTT v" FW_VERSION " (протокол v2: сигнал GSM + АЦП АКБ, команда 5 = перезагрузка) ===");

  pcf8574.pinMode(reley_kontrol_1, INPUT);
  pcf8574.pinMode(reley_kontrol_2, INPUT);
  pcf8574.pinMode(reley_kontrol_3, INPUT);
  pcf8574.pinMode(reley_kontrol_4, INPUT);
  Serial.print("Init pcf8574...");
  if (pcf8574.begin()) {
    Serial.println("PCF OK");
  } else {
    Serial.println("PCF ERROR");
    triggerReset();
  }

  loadSettings();
  Serial.println("id = " + idStr);

  Serial.println("Жду символ 'i' для входа в настройки (5 секунд)...");
  unsigned long t = millis();
  while (millis() - t < 5000) {
    serviceWatchdog();
    if (Serial.available() && Serial.read() == 'i') {
      while (Serial.available()) Serial.read();  // остаток строки
      enterSetupMode();
      break;
    }
    delay(10);
  }
  Serial.println("Нормальный запуск");

  GSMport.begin(settings.modem_baud);
  initGSM();

  // PubSubClient хранит указатель на строку — settings.server_host живёт всё время работы
  mqtt.setServer(settings.server_host, settings.server_port);
  mqtt.setCallback(mqttCallback);
  mqtt.setSocketTimeout(10);
  mqtt.setKeepAlive(95);

  readRelayStates();
  memcpy(last_r_states, current_state, 4);
  updateSignalQuality();
  reconnectMQTT();
  lastReconnectAttempt = millis();
  lastNetworkCheck = millis();
}

// ====================== LOOP ======================
void loop() {
  mqtt.loop();
  serviceWatchdog();

  // перезагрузка по команде 5 — после того как rep ушёл в сеть
  if (rebootAt && (long)(millis() - rebootAt) >= 0) {
    mqtt.disconnect();
    triggerReset();
  }

  // проверка регистрации в сети: раз в 30 с, перезагрузка после 3 неудач подряд
  if (millis() - lastNetworkCheck > NETWORK_CHECK_INTERVAL) {
    lastNetworkCheck = millis();
    if (modem.isNetworkConnected()) {
      networkFailCount = 0;
    } else if (++networkFailCount >= MAX_NETWORK_FAILS_BEFORE_RESET) {
      modemReport("сеть пропала");
      Serial.println("Network lost (SIM800L/SIM problem) → reset watchdog");
      triggerReset();
    } else {
      Serial.printf("Нет регистрации в сети (%u/%u)\n", networkFailCount, MAX_NETWORK_FAILS_BEFORE_RESET);
    }
  }

  if (!mqtt.connected()) {
    if (millis() - lastReconnectAttempt > MQTT_RECONNECT_INTERVAL) {
      reconnectMQTT();
      lastReconnectAttempt = millis();
    }
    return;
  }

  if (lastSignalUpdate == 0 || millis() - lastSignalUpdate > SIGNAL_UPDATE_INTERVAL) {
    updateSignalQuality();
  }

  // stat: при изменении реле, после подключения или не реже раза в 5 минут
  if (millis() - lastCheck > RELAY_POLL_INTERVAL) {
    lastCheck = millis();
    readRelayStates();
    bool changed = memcmp(current_state, last_r_states, 4) != 0;
    if (changed || statPending || millis() - lastPublish > MAX_PUBLISH_INTERVAL) {
      updateAlarm();
      publishStat();
    }
  }
}
