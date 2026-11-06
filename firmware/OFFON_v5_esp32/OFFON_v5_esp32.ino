// =============================================================================
//  Плата «OFFON reley ver_5_1»: ESP32-WROOM-32 + модуль SIM7670 + ATtiny85 (внешний WATCHDOG)
//  Прошивка MQTT v3.0 — по образцу Relay_MQTT_v2_akb v2.3, протокол обмена v3 (docs/PROTOCOL.md)
//
//  Плата Arduino IDE: «ESP32 Dev Module» (пакет esp32 by Espressif 2.x или 3.x).
//  Библиотеки: PubSubClient 2.8+, TinyGSM 0.12+, Crypto 0.4+ (Rhys Weatherley).
//  Для ATtiny85 отдельная прошивка: firmware/OFFON_v5_watchdog_attiny85.
//
//  Назначение выводов восстановлено по разводке OFFON_reley_ver_5_1.lay6:
//    реле 1..4 (ключ катушки, HIGH = катушка под током) .... IO26, IO25, IO33, IO32
//    контроль выходов 1..4 (оптрон + светодиод, HIGH = есть напряжение) .. IO23, IO22, IO21, IO19
//    модуль SIM7670: «tx» модуля → IO4 (RX ESP), «rx» модуля ← IO2 (TX ESP); «pkey» не разведён
//    замок (оптрон → MOSFET, HIGH = замок под напряжением) ..... IO18
//    геркон (между 3V3 и входом, замкнут = HIGH) ................. IO27
//    напряжение питания/АКБ (делитель) ........................... IO34 (АЦП)
//    импульсы внешнему WATCHDOG (транзистор → PB2 ATtiny85) ....... IO14
//    разъём «add port»: IO16, IO17 (не используются)
//  Выходы реле подключены к контактам COM + NC: нагрузка ВКЛЮЧЕНА, когда катушка обесточена.
//  Это задаёт RELAY_COIL_ON_MEANS_OUTPUT_ON (ниже); при другом подключении поменяйте на 1.
// =============================================================================
#define TINY_GSM_MODEM_A7672X  // SIM7670 / A7670 (AT-команды A76xx). При проблемах попробуйте TINY_GSM_MODEM_SIM7600
#define TINY_GSM_RX_BUFFER 1024

#include <WiFi.h>
#include <TinyGsmClient.h>
#include <PubSubClient.h>
#include <SHA256.h>
#include <EEPROM.h>

#define FW_VERSION "3.0-esp32"

// =============================================================================
//  ЗАВОДСКИЕ НАСТРОЙКИ — начальные значения параметров меню настройки.
//  Плата использует их при первом запуске (EEPROM пустая), после «Сброса к заводским настройкам»
//  (пункт 8 меню) и один раз после прошивки, если APPLY_DEFAULTS_ON_FLASH = 1.
// =============================================================================
#define DEFAULT_DEVICE_ID     1                // пункт 1: ID платы, 1..4294967295, без ведущих нулей
#define DEFAULT_SIM_PIN       "0000"           // пункт 2: PIN SIM-карты (только при USE_SIM_PIN 1)
#define DEFAULT_ALARM_RELAY   4                // пункт 4: реле контроля питания шкафа, 1..4
#define DEFAULT_SERVER_HOST   "185.209.29.25"  // пункт 5: адрес сервера MQTT, домен или IP
#define DEFAULT_SERVER_PORT   1883             // пункт 6: порт сервера MQTT
#define DEFAULT_APN           ""               // пункт 7: APN оператора, "" — по умолчанию SIM-карты

#define APPLY_DEFAULTS_ON_FLASH 0   // 1 — применить значения выше один раз после прошивки этой сборки
#define USE_SIM_PIN             0   // 1 — вводить PIN SIM-карты (пункты меню 2 и 3), 0 — SIM без PIN

// =============================================================================
//  АППАРАТНЫЕ НАСТРОЙКИ ПЛАТЫ ver_5_1
// =============================================================================
#define RELAY_COIL_ON_MEANS_OUTPUT_ON 0   // 0: выход включён при обесточенной катушке (контакты COM+NC)
#define REED_CLOSED_LEVEL             HIGH  // уровень входа IO27 при замкнутом герконе (дверь закрыта)
#define LOCK_PULSE_MS                 3000  // «открыть замок» (команда 3 для реле 5): длительность импульса
#define MODEM_BAUD                    115200
#define TCP_CONNECT_TIMEOUT_S         20    // ожидание TCP-соединения с сервером (ограничивает паузу WATCHDOG)

const int PIN_MODEM_RX = 4;    // ← «tx» модуля SIM7670
const int PIN_MODEM_TX = 2;    // → «rx» модуля SIM7670
const int PIN_WDT_PULSE = 14;  // → транзистор → PB2 ATtiny85
const int PIN_LOCK = 18;
const int PIN_REED = 27;
const int PIN_BAT_ADC = 34;

struct RelayPin {
  int coil;      // выход на ключ катушки
  int feedback;  // вход контроля напряжения на выходе (оптрон)
};
const RelayPin RELAYS[4] = {{26, 23}, {25, 22}, {33, 21}, {32, 19}};
const uint32_t LOCK_RELAY_NUMBER = 5;  // замок в командах протокола — «реле 5»

static_assert(DEFAULT_DEVICE_ID >= 1 && DEFAULT_DEVICE_ID <= 4294967295ULL, "DEFAULT_DEVICE_ID: число от 1 до 4294967295");
static_assert(sizeof(DEFAULT_SIM_PIN) == 5, "DEFAULT_SIM_PIN: ровно 4 цифры");
static_assert(DEFAULT_ALARM_RELAY >= 1 && DEFAULT_ALARM_RELAY <= 4, "DEFAULT_ALARM_RELAY: 1..4");
static_assert(sizeof(DEFAULT_SERVER_HOST) > 1 && sizeof(DEFAULT_SERVER_HOST) <= 64, "DEFAULT_SERVER_HOST: 1..63 символа");
static_assert(DEFAULT_SERVER_PORT >= 1 && DEFAULT_SERVER_PORT <= 65535, "DEFAULT_SERVER_PORT: 1..65535");
static_assert(sizeof(DEFAULT_APN) <= 32, "DEFAULT_APN: до 31 символа");

// ====================== НАСТРОЙКИ (EEPROM) ======================
// Структура совпадает с прошивками Relay_MQTT_v2_akb v2.3 (ESP8266)
const uint32_t SETTINGS_MAGIC = 0x52454C33;
const int EEPROM_SIZE = 512;

struct settings_t {
  uint32_t id_i;
  char pin_sim[8];
  uint32_t bat_alarm_relay;              // 0..3 — индекс реле контроля питания шкафа
  uint32_t current_pin_failed_attempts;
  uint32_t global_pin_failed_attempts;
  uint32_t magic;
  char server_host[64];
  uint16_t server_port;
  char apn[32];
  uint32_t build_stamp;
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
  uint32_t sl;       // уровень сигнала: CSQ 0..31, 99 — нет данных
  uint32_t alarm;    // 0 — нет аварии, 1 — питание от АКБ
  uint32_t bat_adc;  // напряжение питания/АКБ: мВ на входе IO34 (среднее), в вольты пересчитывает сервер
  uint32_t lock;     // v3: 1 — замок под напряжением
  uint32_t door;     // v3: 0 — дверь закрыта (геркон замкнут), 1 — открыта
} __attribute__((packed));

struct rep_t {
  uint32_t id;
  uint32_t cmd_id;
  uint32_t success;
} __attribute__((packed));

const uint32_t PROTOCOL_VERSION = 3;

HardwareSerial SerialAT(2);
TinyGsm modem(SerialAT);
TinyGsmClient gsmClient(modem);
PubSubClient mqtt(gsmClient);

// ====================== СОСТОЯНИЕ ======================
uint8_t last_r_states[4] = {0, 0, 0, 0};  // отправлено в последнем stat (1 — выход включён)
uint8_t current_state[4] = {0, 0, 0, 0};
uint32_t lastLock = 0, lastDoor = 0, lockState = 0, doorState = 0;

unsigned long lastPublish = 0, lastWatchdog = 0, lastReconnectAttempt = 0, lastCheck = 0;
unsigned long lastSignalUpdate = 0, lastNetworkCheck = 0, subscribedAt = 0, rebootAt = 0;
unsigned long lockOffAt = 0;           // != 0 — выключить замок (конец импульса «открыть»)
uint32_t cmd_id_mem = 0, alarmNow = 0, signalQuality = 99;
uint32_t mqttReconnectFailCount = 0, networkFailCount = 0;
bool first_cmd = true, statPending = false;

const unsigned long MAX_PUBLISH_INTERVAL = 300000;
const unsigned long RELAY_POLL_INTERVAL = 500;
const unsigned long SIGNAL_UPDATE_INTERVAL = 60000;
const unsigned long NETWORK_CHECK_INTERVAL = 30000;
const unsigned long MQTT_RECONNECT_INTERVAL = 5000;
const unsigned long WATCHDOG_INTERVAL = 1000;
const unsigned long RETAINED_GUARD_MS = 10000;
const unsigned long REBOOT_DELAY_MS = 2000;
const uint32_t MAX_MQTT_FAILS_BEFORE_RESET = 5;
const uint32_t MAX_NETWORK_FAILS_BEFORE_RESET = 3;

// ====================== WATCHDOG (ATtiny85) ======================
void pulseWatchdog() {
  digitalWrite(PIN_WDT_PULSE, HIGH);
  delay(20);
  digitalWrite(PIN_WDT_PULSE, LOW);
  lastWatchdog = millis();
}

void serviceWatchdog() {
  if (millis() - lastWatchdog >= WATCHDOG_INTERVAL) pulseWatchdog();
}

void waitWithWatchdog(unsigned long ms) {
  unsigned long start = millis();
  while (millis() - start < ms) {
    serviceWatchdog();
    delay(10);
  }
}

// Перестаём подавать импульсы: ATtiny85 сбросит ESP32 (EN), при повторе — отключит питание ESP32 и модема
void triggerReset() {
  Serial.println("Перезапуск через WATCHDOG (ATtiny85)");
  unsigned long start = millis();
  while (1) {
    delay(1000);
    if (millis() - start > 120000UL) {  // страховка: WATCHDOG не сработал
      Serial.println("WATCHDOG не сработал за 120 с → программная перезагрузка ESP32");
      ESP.restart();
    }
  }
}

// ====================== РЕЛЕ, ЗАМОК, ГЕРКОН ======================
// Есть ли напряжение на выходе реле. Реле 1 контролируется через выпрямитель от 230 В —
// оптрон даёт импульсы 100 Гц, поэтому смотрим вход 25 мс и считаем «есть», если был HIGH.
bool readFeedback(int idx) {
  for (int i = 0; i < 25; i++) {
    if (digitalRead(RELAYS[idx].feedback) == HIGH) return true;
    delay(1);
  }
  return false;
}

void setCoil(int idx, bool outputOn) {
  bool coilOn = (outputOn == (RELAY_COIL_ON_MEANS_OUTPUT_ON != 0));
  digitalWrite(RELAYS[idx].coil, coilOn ? HIGH : LOW);
}

// Включает/выключает выход реле 1..4 и через 1 с проверяет напряжение на выходе
bool setRelay(uint32_t num, bool on) {
  if (num < 1 || num > 4) return false;
  setCoil(num - 1, on);
  waitWithWatchdog(1000);
  return readFeedback(num - 1) == on;
}

void setLock(bool on) {
  digitalWrite(PIN_LOCK, on ? HIGH : LOW);
  lockState = on ? 1 : 0;
}

bool readDoorOpen() {
  int closedVotes = 0;
  for (int i = 0; i < 5; i++) {  // антидребезг геркона
    if (digitalRead(PIN_REED) == REED_CLOSED_LEVEL) closedVotes++;
    delay(2);
  }
  return closedVotes < 3;
}

void readRelayStates() {
  for (int i = 0; i < 4; i++) current_state[i] = readFeedback(i) ? 1 : 0;
  doorState = readDoorOpen() ? 1 : 0;
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
  if (n >= size) return false;
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

uint32_t buildStamp() {
  const char* b = __DATE__ " " __TIME__;
  uint32_t h = 2166136261u;
  while (*b) {
    h ^= (uint8_t)*b++;
    h *= 16777619u;
  }
  return h;
}

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

  if (!baseValid || !v23Valid) {
    applyFactoryDefaults();
    saveSettings();
    Serial.println("EEPROM пуста или повреждена → заводские настройки из прошивки");
  } else if (APPLY_DEFAULTS_ON_FLASH && settings.build_stamp != buildStamp()) {
    applyFactoryDefaults();
    saveSettings();
    Serial.println("Новая прошивка с APPLY_DEFAULTS_ON_FLASH=1 → применены заводские настройки из прошивки");
  }
  applySettings();
  Serial.printf("EEPROM прочитан → id=%u, реле питания: %u, сервер %s:%u, APN \"%s\"\n",
                (unsigned)settings.id_i, (unsigned)(settings.bat_alarm_relay + 1), settings.server_host,
                (unsigned)settings.server_port, settings.apn);
}

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
#if USE_SIM_PIN
  Serial.println("2 - PIN SIM (текущее: " + String(settings.pin_sim) + "), [ошибок ввода этого PIN: " + String(settings.current_pin_failed_attempts) + "]");
  Serial.println("3 - Сброс глобального счётчика ошибок ввода PIN (текущее: " + String(settings.global_pin_failed_attempts) + ")");
#endif
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
#if USE_SIM_PIN
    } else if (choice == '2') {
      if (readConfirmed("Новый PIN (4 цифры): ", v)) {
        if (v.length() == 4 && isDigits(v)) {
          settings.current_pin_failed_attempts = 0;
          memset(settings.pin_sim, 0, sizeof(settings.pin_sim));
          strncpy(settings.pin_sim, v.c_str(), 4);
          saveSettings();
          Serial.println("PIN сохранён, счётчик ошибок сброшен");
        } else {
          Serial.println("PIN должен состоять из 4 цифр");
        }
      }
    } else if (choice == '3') {
      Serial.println("ВНИМАНИЕ! Используйте только после разблокировки SIM-карты на стороннем устройстве");
      if (readConfirmed("Для сброса счётчика введите \"1\", для выхода — \"9\":", v)) {
        if (v == "1") {
          settings.global_pin_failed_attempts = 0;
          saveSettings();
          Serial.println("Глобальный счётчик ошибок сброшен");
        } else {
          Serial.println("Счётчик не изменён");
        }
      }
#endif
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
      Serial.printf("  ID %u, реле питания %u, сервер %s:%u, APN \"%s\"\n", (unsigned)DEFAULT_DEVICE_ID,
                    (unsigned)DEFAULT_ALARM_RELAY, DEFAULT_SERVER_HOST, (unsigned)DEFAULT_SERVER_PORT, DEFAULT_APN);
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

// ====================== GSM (SIM7670) ======================
const char* simStatusName(int s) {
  if (s == SIM_READY) return "READY";
  if (s == SIM_LOCKED) return "SIM LOCKED";
  if (s == SIM_ANTITHEFT_LOCKED) return "SIM ANTITHEFT LOCKED";
  if (s == SIM_ERROR) return "SIM ERROR";
  return "UNKNOWN";
}

bool waitForNetworkWithWatchdog(unsigned long timeoutMs) {
  unsigned long start = millis();
  while (millis() - start < timeoutMs) {
    pulseWatchdog();
    if (modem.waitForNetwork(10000L)) return true;
  }
  return false;
}

bool connectGprs() {
  Serial.println("Мобильный интернет: подключение (APN \"" + String(settings.apn) + "\")...");
  pulseWatchdog();
  modem.gprsConnect(settings.apn, "", "");
  pulseWatchdog();
  if (modem.isGprsConnected()) {
    Serial.println("Мобильный интернет подключён");
    return true;
  }
  Serial.println("Мобильный интернет: ошибка подключения");
  return false;
}

#if USE_SIM_PIN
void unlockSim(int& simStatus) {
  Serial.println("SIM locked, try pin");
  if (settings.current_pin_failed_attempts > 0) {
    Serial.println("ОШИБКА: этот PIN уже был введён неверно. Через настройки (i) укажите верный PIN");
    triggerReset();
  }
  if (settings.global_pin_failed_attempts > 1) {
    Serial.println("ОШИБКА: PIN введён неверно " + String(settings.global_pin_failed_attempts) + " раз. Разблокируйте SIM на стороннем устройстве и сбросьте счётчик (пункт 3)");
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
  }
  pulseWatchdog();
  simStatus = modem.getSimStatus();
}
#endif

void initGSM() {
  Serial.println("SIM7670 init start");
  bool networkOk = false;
  for (int attempt = 0; attempt < 3 && !networkOk; attempt++) {
    pulseWatchdog();
    Serial.printf("Попытка сети #%d\n", attempt + 1);
    modem.restart();
    waitWithWatchdog(5000);

    int simStatus = modem.getSimStatus();
    if (simStatus == SIM_ERROR) {
      waitWithWatchdog(2000);
      simStatus = modem.getSimStatus();
    }
    Serial.print("SIM статус: ");
    Serial.println(simStatusName(simStatus));

    if (simStatus == SIM_LOCKED) {
#if USE_SIM_PIN
      unlockSim(simStatus);
#else
      Serial.println("SIM-карта защищена PIN-кодом: отключите запрос PIN или соберите прошивку с USE_SIM_PIN 1");
      triggerReset();
#endif
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
    Serial.println("Мобильный интернет недоступен после повтора → reset watchdog");
    triggerReset();
  }
}

// ====================== СИГНАЛ И ПИТАНИЕ ======================
void updateSignalQuality() {
  int16_t csq = modem.getSignalQuality();
  signalQuality = (csq >= 0 && csq <= 31) ? (uint32_t)csq : 99;
  lastSignalUpdate = millis();
  Serial.printf("Сигнал: CSQ=%u\n", (unsigned)signalQuality);
}

// Напряжение на входе делителя: мВ (калиброванное значение АЦП ESP32), среднее из 32 измерений.
// Сервер пересчитывает его в вольты АКБ по двум точкам калибровки (значения при 6 В и при 15 В).
uint32_t readBatteryAdc() {
  uint32_t sum = 0;
  for (int i = 0; i < 32; i++) sum += analogReadMilliVolts(PIN_BAT_ADC);
  return sum / 32;
}

void updateAlarm() {
  uint32_t r = settings.bat_alarm_relay;
  if (!alarmNow) {
    if (last_r_states[r] == 1 && current_state[r] == 0) alarmNow = 1;
  } else if (current_state[r] == 1) {
    alarmNow = 0;
  }
}

// ====================== MQTT ======================
bool publishStat() {
  stat_t s = {
    .id = settings.id_i,
    .version = PROTOCOL_VERSION,
    .r1 = current_state[0],
    .r2 = current_state[1],
    .r3 = current_state[2],
    .r4 = current_state[3],
    .stat = 1,
    .sl = signalQuality,
    .alarm = alarmNow,
    .bat_adc = readBatteryAdc(),
    .lock = lockState,
    .door = doorState
  };
  if (mqtt.publish(STAT_TOPIC, (const uint8_t*)&s, sizeof(stat_t), true)) {
    memcpy(last_r_states, current_state, 4);
    lastLock = lockState;
    lastDoor = doorState;
    lastPublish = millis();
    statPending = false;
    Serial.printf("stat: реле %u%u%u%u, CSQ=%u, АЦП=%u мВ, авария=%u, замок=%u, дверь=%s\n",
                  (unsigned)s.r1, (unsigned)s.r2, (unsigned)s.r3, (unsigned)s.r4, (unsigned)s.sl,
                  (unsigned)s.bat_adc, (unsigned)s.alarm, (unsigned)s.lock, s.door ? "открыта" : "закрыта");
    return true;
  }
  Serial.println("stat publish error");
  statPending = true;
  return false;
}

bool publishRep(uint32_t cmd_id, uint32_t success) {
  rep_t r = {.id = settings.id_i, .cmd_id = cmd_id, .success = success};
  if (mqtt.publish(REP_TOPIC, (const uint8_t*)&r, sizeof(rep_t), false)) {
    Serial.printf("rep: cmd_id=%u success=%u\n", (unsigned)cmd_id, (unsigned)success);
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

// Замок («реле 5»): 1 — подать напряжение, 2 — снять, 3 — импульс «открыть» на LOCK_PULSE_MS
bool lockCommand(uint32_t command) {
  if (command == 1) { setLock(true); lockOffAt = 0; }
  else if (command == 2) { setLock(false); lockOffAt = 0; }
  else if (command == 3) {
    setLock(true);
    lockOffAt = millis() + LOCK_PULSE_MS;
    if (lockOffAt == 0) lockOffAt = 1;
  } else return false;
  statPending = true;
  return true;
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  if (strcmp(topic, comTopic.c_str()) != 0 || length != sizeof(com_t)) return;

  com_t cmd;
  memcpy(&cmd, payload, sizeof(cmd));
  Serial.printf("COM: cmd_id=%u cmd=%u rel=%u\n", (unsigned)cmd.cmd_id, (unsigned)cmd.command, (unsigned)cmd.rel_number);

  if (cmd.command == 0 || cmd_id_mem == cmd.cmd_id) return;
  cmd_id_mem = cmd.cmd_id;

  bool justSubscribed = first_cmd && (millis() - subscribedAt < RETAINED_GUARD_MS);
  first_cmd = false;
  if (justSubscribed && (cmd.command == 3 || cmd.command == 5)) {
    Serial.println("COM: retained-команда перезапуска после подключения — пропущена");
    return;
  }
  // retained-команда замку после подключения — тоже старая: замок не трогаем
  if (justSubscribed && cmd.rel_number == LOCK_RELAY_NUMBER) {
    Serial.println("COM: retained-команда замку после подключения — пропущена");
    return;
  }

  bool ok = true;
  if (cmd.rel_number == LOCK_RELAY_NUMBER && cmd.command >= 1 && cmd.command <= 3) {
    publishRep(cmd.cmd_id, lockCommand(cmd.command) ? 1 : 2);
    return;
  }
  switch (cmd.command) {
    case 1: ok = forEachRelay(cmd.rel_number, true); break;
    case 2: ok = forEachRelay(cmd.rel_number, false); break;
    case 3:
      ok = forEachRelay(cmd.rel_number, false);
      if (ok) {
        waitWithWatchdog(1000);
        ok = forEachRelay(cmd.rel_number, true);
      }
      break;
    case 4:
      readRelayStates();
      updateAlarm();
      publishStat();
      publishRep(cmd.cmd_id, 1);
      return;
    case 5:  // перезагрузка: ответ сейчас, перезапуск ESP32 и модема из loop() через 2 с
      Serial.printf("COM: перезагрузка платы по команде сервера (cmd_id=%u)\n", (unsigned)cmd.cmd_id);
      publishRep(cmd.cmd_id, 1);
      rebootAt = millis() + REBOOT_DELAY_MS;
      if (rebootAt == 0) rebootAt = 1;
      return;
    default:
      ok = false;
      break;
  }
  publishRep(cmd.cmd_id, ok ? 1 : 2);
}

const char* mqttStateText(int s) {
  switch (s) {
    case -4: return "нет ответа сервера (таймаут)";
    case -3: return "соединение потеряно";
    case -2: return "не удалось подключиться: неверный адрес/порт сервера, порт закрыт или нет интернета";
    case -1: return "отключено";
    case 1: return "сервер не поддерживает протокол";
    case 2: return "сервер отклонил clientID";
    case 3: return "сервер недоступен";
    case 4: return "неверный логин или пароль — проверьте ID платы";
    case 5: return "нет доступа — плата не добавлена на сервер";
    default: return "";
  }
}

void countFailure() {
  mqttReconnectFailCount++;
  Serial.printf("Ошибок подключения: %u/%u\n", (unsigned)mqttReconnectFailCount, (unsigned)MAX_MQTT_FAILS_BEFORE_RESET);
  if (mqttReconnectFailCount >= MAX_MQTT_FAILS_BEFORE_RESET) {
    Serial.println("Too many reconnect fails → reset watchdog");
    triggerReset();
  }
}

bool reconnectMQTT() {
  if (!modem.isGprsConnected()) {
    Serial.println("Мобильный интернет отключён → переподключение");
    if (!connectGprs()) {
      countFailure();
      return false;
    }
  }

  Serial.println("MQTT: подключение к " + String(settings.server_host) + ":" + String(settings.server_port) + ", логин " + username);
  pulseWatchdog();
  // TCP-соединение открываем сами с ограниченным таймаутом (PubSubClient ждал бы до 75 с без импульсов WATCHDOG)
  if (!gsmClient.connected() && !gsmClient.connect(settings.server_host, settings.server_port, TCP_CONNECT_TIMEOUT_S)) {
    Serial.printf("TCP: сервер не отвечает за %d с — проверьте адрес и порт (пункты 5–6 меню)\n", TCP_CONNECT_TIMEOUT_S);
    countFailure();
    return false;
  }
  pulseWatchdog();
  if (!mqtt.connect(clientID.c_str(), username.c_str(), password.c_str())) {
    int st = mqtt.state();
    Serial.printf("MQTT connect failed, state=%d (%s)\n", st, mqttStateText(st));
    gsmClient.stop();
    countFailure();
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
  statPending = true;
  return true;
}

// ====================== SETUP ======================
void setup() {
  // Выходы: катушки реле в положение «выход включён» (при RELAY_COIL_ON_MEANS_OUTPUT_ON 0 — катушки обесточены)
  for (int i = 0; i < 4; i++) {
    pinMode(RELAYS[i].coil, OUTPUT);
    setCoil(i, true);
    pinMode(RELAYS[i].feedback, INPUT_PULLDOWN);
  }
  pinMode(PIN_LOCK, OUTPUT);
  setLock(false);
  pinMode(PIN_REED, INPUT_PULLDOWN);
  pinMode(PIN_WDT_PULSE, OUTPUT);
  pulseWatchdog();
  analogReadResolution(12);
  analogSetPinAttenuation(PIN_BAT_ADC, ADC_11db);

  WiFi.mode(WIFI_OFF);  // радио ESP32 не используется
  btStop();

  Serial.begin(115200);
  waitWithWatchdog(3000);
  Serial.println("\n=== OFFON reley ver_5_1 (ESP32 + SIM7670), прошивка MQTT v" FW_VERSION ", протокол v3 ===");

  loadSettings();
  Serial.println("id = " + idStr);

  Serial.println("Жду символ 'i' для входа в настройки (5 секунд)...");
  unsigned long t = millis();
  while (millis() - t < 5000) {
    serviceWatchdog();
    if (Serial.available() && Serial.read() == 'i') {
      while (Serial.available()) Serial.read();
      enterSetupMode();
      break;
    }
    delay(10);
  }
  Serial.println("Нормальный запуск");

  SerialAT.begin(MODEM_BAUD, SERIAL_8N1, PIN_MODEM_RX, PIN_MODEM_TX);
  initGSM();

  mqtt.setServer(settings.server_host, settings.server_port);
  mqtt.setCallback(mqttCallback);
  mqtt.setSocketTimeout(10);
  mqtt.setKeepAlive(95);

  readRelayStates();
  memcpy(last_r_states, current_state, 4);
  lastDoor = doorState;
  updateSignalQuality();
  reconnectMQTT();
  lastReconnectAttempt = millis();
  lastNetworkCheck = millis();
}

// ====================== LOOP ======================
void loop() {
  mqtt.loop();
  serviceWatchdog();

  if (rebootAt && (long)(millis() - rebootAt) >= 0) {
    mqtt.disconnect();
    Serial.println("Перезагрузка по команде сервера");
    delay(200);
    ESP.restart();  // модем перезапускается в initGSM() после старта
  }
  if (lockOffAt && (long)(millis() - lockOffAt) >= 0) {
    lockOffAt = 0;
    setLock(false);
    statPending = true;
  }

  if (millis() - lastNetworkCheck > NETWORK_CHECK_INTERVAL) {
    lastNetworkCheck = millis();
    if (modem.isNetworkConnected()) {
      networkFailCount = 0;
    } else if (++networkFailCount >= MAX_NETWORK_FAILS_BEFORE_RESET) {
      Serial.println("Network lost → reset watchdog");
      triggerReset();
    } else {
      Serial.printf("Нет регистрации в сети (%u/%u)\n", (unsigned)networkFailCount, (unsigned)MAX_NETWORK_FAILS_BEFORE_RESET);
    }
  }

  if (!mqtt.connected()) {
    if (millis() - lastReconnectAttempt > MQTT_RECONNECT_INTERVAL) {
      reconnectMQTT();
      lastReconnectAttempt = millis();
    }
    return;
  }

  if (lastSignalUpdate == 0 || millis() - lastSignalUpdate > SIGNAL_UPDATE_INTERVAL) updateSignalQuality();

  // stat: при изменении выходов, двери или замка, после подключения или не реже раза в 5 минут
  if (millis() - lastCheck > RELAY_POLL_INTERVAL) {
    lastCheck = millis();
    readRelayStates();
    bool changed = memcmp(current_state, last_r_states, 4) != 0 || doorState != lastDoor || lockState != lastLock;
    if (changed || statPending || millis() - lastPublish > MAX_PUBLISH_INTERVAL) {
      updateAlarm();
      publishStat();
    }
  }
}
