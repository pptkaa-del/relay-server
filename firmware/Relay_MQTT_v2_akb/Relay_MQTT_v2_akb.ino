// =============================================================================
//  Релейный блок ESP8266 (WeMos D1 mini) + SIM800L — прошивка MQTT v2.3
//  Протокол обмена с сервером: docs/PROTOCOL.md (версия протокола 2)
//
//  Библиотеки: PubSubClient 2.8+, TinyGSM 0.12+, PCF8574_library 2.4+ (Renzo Mischianti),
//              Crypto 0.4+ (Rhys Weatherley). Плата: «LOLIN(WEMOS) D1 R2 & mini».
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
// =============================================================================
#define TINY_GSM_MODEM_SIM800

#include <ESP8266WiFi.h>
#include <TinyGsmClient.h>
#include <PubSubClient.h>
#include <PCF8574.h>
#include <SHA256.h>
#include <EEPROM.h>
#include <SoftwareSerial.h>

#define FW_VERSION "2.3"

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
const char DEFAULT_SERVER_HOST[] = "185.209.29.25";
const uint16_t DEFAULT_SERVER_PORT = 1883;

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

TinyGsm modem(GSMport);
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

void loadSettings() {
  EEPROM.begin(512);
  EEPROM.get(0, settings);

  size_t pinLen = strnlen(settings.pin_sim, sizeof(settings.pin_sim));
  bool baseValid = settings.id_i != 0 && settings.id_i != 0xFFFFFFFF && pinLen <= 4
                   && settings.current_pin_failed_attempts <= 10 && settings.global_pin_failed_attempts <= 10
                   && settings.bat_alarm_relay <= 3;
  if (!baseValid) {
    memset(&settings, 0, sizeof(settings_t));
    settings.id_i = 1;
    settings.bat_alarm_relay = 3;
    strcpy(settings.pin_sim, "0000");
    Serial.println("EEPROM сброшен → id=1, PIN=0000, счётчики попыток=0");
  }
  // поля v2.3: после обновления со старой прошивки там мусор — заполняем значениями по умолчанию
  if (!baseValid || settings.magic != SETTINGS_MAGIC || !isValidHost(settings.server_host)
      || settings.server_port == 0 || !isPrintableField(settings.apn, sizeof(settings.apn))) {
    settings.magic = SETTINGS_MAGIC;
    memset(settings.server_host, 0, sizeof(settings.server_host));
    strncpy(settings.server_host, DEFAULT_SERVER_HOST, sizeof(settings.server_host) - 1);
    settings.server_port = DEFAULT_SERVER_PORT;
    memset(settings.apn, 0, sizeof(settings.apn));
    Serial.println("Настройки сервера по умолчанию: " + String(DEFAULT_SERVER_HOST) + ":" + String(DEFAULT_SERVER_PORT));
    saveSettings();
  }
  applySettings();
  Serial.printf("EEPROM прочитан → id=%u, PIN=%s, ошибок PIN: %u/%u, реле питания: %u, сервер %s:%u, APN \"%s\"\n",
                settings.id_i, settings.pin_sim, settings.current_pin_failed_attempts, settings.global_pin_failed_attempts,
                settings.bat_alarm_relay + 1, settings.server_host, settings.server_port, settings.apn);
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
  Serial.println("1 - ID (текущее: " + idStr + ")");
  Serial.println("2 - PIN SIM (текущее: " + String(settings.pin_sim) + "), [ошибок ввода этого PIN: " + String(settings.current_pin_failed_attempts) + "]");
  Serial.println("3 - Сброс глобального счётчика ошибок ввода PIN (текущее: " + String(settings.global_pin_failed_attempts) + ")");
  Serial.println("4 - Выбор номера реле питания шкафа (текущее: " + String(settings.bat_alarm_relay + 1) + ")");
  Serial.println("5 - Адрес сервера MQTT, домен или IP (текущее: " + String(settings.server_host) + ")");
  Serial.println("6 - Порт сервера MQTT (текущее: " + String(settings.server_port) + ")");
  Serial.println("7 - APN оператора (текущее: " + String(strlen(settings.apn) ? settings.apn : "по умолчанию SIM") + ")");
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

    if (choice == '1') {
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

// Ожидание регистрации в сети частями по 10 с, чтобы WATCHDOG получал импульсы
bool waitForNetworkWithWatchdog(unsigned long timeoutMs) {
  unsigned long start = millis();
  while (millis() - start < timeoutMs) {
    pulseWatchdog();
    if (modem.waitForNetwork(10000L)) return true;
  }
  return false;
}

bool connectGprs() {
  Serial.println("GPRS: подключение (APN \"" + String(settings.apn) + "\")...");
  pulseWatchdog();
  modem.gprsConnect(settings.apn, "", "");
  pulseWatchdog();
  if (modem.isGprsConnected()) {
    Serial.println("GPRS connected");
    return true;
  }
  Serial.println("GPRS fail");
  return false;
}

void initGSM() {
  waitWithWatchdog(2000);
  Serial.println("GSM init start");
  bool networkOk = false;
  for (int attempt = 0; attempt < 3 && !networkOk; attempt++) {
    pulseWatchdog();
    Serial.print("Попытка сети #");
    Serial.println(attempt + 1);
    modem.restart();
    modem.setBaud(9600);
    waitWithWatchdog(9000);

    int simStatus = modem.getSimStatus();
    waitWithWatchdog(1000);
    simStatus = modem.getSimStatus();
    if (simStatus == SIM_ERROR) {
      Serial.println("SIM error, try getSimCCID to get status");
      modem.getSimCCID();
      waitWithWatchdog(1000);
      simStatus = modem.getSimStatus();
    }
    Serial.print("SIM статус: ");
    Serial.println(simStatusName(simStatus));

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

    if (waitForNetworkWithWatchdog(60000)) {
      Serial.println("Network OK");
      networkOk = true;
    } else {
      Serial.println("Network fail, retry");
      waitWithWatchdog(5000);
    }
  }

  if (!networkOk) {
    Serial.println("Network fail после 3 попыток → reset watchdog");
    triggerReset();
  }
  if (!connectGprs() && !connectGprs()) {
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
    if (mqttReconnectFailCount >= MAX_MQTT_FAILS_BEFORE_RESET) {
      Serial.println("Too many MQTT reconnect fails → reset watchdog");
      triggerReset();
    }
    return false;
  }

  Serial.println("MQTT connected");
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
  waitWithWatchdog(5000);
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

  GSMport.begin(9600);
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
