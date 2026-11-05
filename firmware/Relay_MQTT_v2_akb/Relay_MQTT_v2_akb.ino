#define TINY_GSM_MODEM_SIM800

#include <TinyGsmClient.h>
#include <PubSubClient.h>
#include <PCF8574.h>
#include <SHA256.h>
#include <EEPROM.h>
#include <SoftwareSerial.h>

SoftwareSerial GSMport(D3, D4);
PCF8574 pcf8574(0x20);
const int RsRes = D5;

// Реле и PCF (точно как в v1)
const int reley_1 = D8, reley_2 = D6, reley_3 = D7, reley_4 = D0;
const int reley_kontrol_1 = P2, reley_kontrol_2 = P3, reley_kontrol_3 = P1, reley_kontrol_4 = P0;

struct settings_t {
  uint32_t id_i;
  char pin_sim[8];
  uint32_t bat_alarm_relay;
  uint32_t current_pin_failed_attempts;
  uint32_t global_pin_failed_attempts;
};
settings_t settings;

String idStr;
String username, password;
String clientID;

// Топики
String comTopic = "";
String statTopic = "stat";
String repTopic = "rep";

// === PACKED СТРУКТУРЫ ===
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
  uint32_t sl;      // уровень сигнала GSM: CSQ 0..31, 99 — нет данных
  uint32_t alarm;   // 0 = нет аварии, 1 = питание от АКБ / неожиданный LOW на реле N
  uint32_t bat_adc; // протокол v2: напряжение АКБ — среднее значение АЦП (A0, 0..1023); в вольты пересчитывает сервер
} __attribute__((packed));

const uint32_t PROTOCOL_VERSION = 2;  // v2: в stat добавлено поле bat_adc (40 байт)

struct rep_t {
  uint32_t id;
  uint32_t cmd_id;
  uint32_t success;
} __attribute__((packed));

TinyGsm modem(GSMport);
TinyGsmClient gsmClient(modem);
PubSubClient mqtt(gsmClient);

uint8_t last_r_states[4] = {0,0,0,0};
uint8_t current_state[4] = {0,0,0,0};

static unsigned long lastPublish = 0;
static unsigned long lastWatchdog = 0;
static unsigned long lastReconnectAttempt = 0;
static unsigned long first_cmd_ms = 0;
static uint32_t cmd_id_mem = 0;
static uint32_t alarmNow = 0;
bool first_cmd = true;
static uint32_t mqttReconnectFailCount = 0;
static unsigned long lastCheck = 0;

const unsigned long MAX_PUBLISH_INTERVAL = 300000;
const unsigned long SIGNAL_UPDATE_INTERVAL = 60000;   // опрос уровня сигнала GSM (AT+CSQ)
static unsigned long lastSignalUpdate = 0;
static uint32_t signalQuality = 99;                   // последнее значение CSQ, 99 — неизвестно
const unsigned long MQTT_RECONNECT_INTERVAL = 5000;
const unsigned long WATCHDOG_INTERVAL = 1000;
const uint32_t MAX_MQTT_FAILS_BEFORE_RESET = 5;

void pulseWatchdog() {
  // printf("pulseWatchdog: %lu ms", millis());
  digitalWrite(RsRes, HIGH);
  delay(150);
  digitalWrite(RsRes, LOW);
}

void triggerReset() {
  Serial.println("Перезапуск через WATCHDOG");
  while(1) {delay(1000);};  // просто ждём
}

bool rele_on(int num) {
  if (num == 1) {
    digitalWrite(reley_1, LOW); 
    delay(1000); 
    return (pcf8574.digitalRead(reley_kontrol_1) == HIGH); 
  }
  else {
      int pinESP = 0, pinPCF = 0;
      if (num == 2) { pinESP = reley_2; pinPCF = reley_kontrol_2; }
      else if (num == 3) { pinESP = reley_3; pinPCF = reley_kontrol_3; }
      else if (num == 4) { pinESP = reley_4; pinPCF = reley_kontrol_4; }
      else return 0;
      digitalWrite(pinESP, HIGH); 
      delay(1000); 
      return (pcf8574.digitalRead(pinPCF) == HIGH); 
  }
  return 0;
}

bool rele_off(int num) {
  if (num == 1) {
    digitalWrite(reley_1, HIGH); 
    delay(1000); 
    return (pcf8574.digitalRead(reley_kontrol_1) == LOW); 
  }
  else {
      int pinESP = 0, pinPCF = 0;
      if (num == 2) { pinESP = reley_2; pinPCF = reley_kontrol_2; }
      else if (num == 3) { pinESP = reley_3; pinPCF = reley_kontrol_3; }
      else if (num == 4) { pinESP = reley_4; pinPCF = reley_kontrol_4; }
      else return 0;
      digitalWrite(pinESP, LOW);
      delay(1000); 
      return (pcf8574.digitalRead(pinPCF) == LOW); 
  }
  return 0;
}

// ====================== SHA256 ======================
String sha256_first16_hex(const String& input) {
  SHA256 sha; sha.reset(); sha.update(input.c_str(), input.length());
  uint8_t hash[32]; sha.finalize(hash, 32);
  String hex = "";
  for (int i = 0; i < 16; i++) hex += String(hash[i] < 16 ? "0" : "") + String(hash[i], HEX);
  return hex;
}

// ====================== EEPROM ======================
void loadSettings() {
  EEPROM.begin(512);
  EEPROM.get(0, settings);
  if (String(settings.pin_sim).length() > 4 || settings.current_pin_failed_attempts > 10 || settings.global_pin_failed_attempts > 10 || settings.bat_alarm_relay > 4) {
    memset(&settings, 0, sizeof(settings_t));
    settings.id_i = 1;
    settings.bat_alarm_relay = 3; // 0-3
    strcpy(settings.pin_sim, "0000");
    EEPROM.put(0, settings); 
    EEPROM.commit();
    Serial.println("EEPROM сброшен → id=1, PIN=0000, счётчик попыток=0");
  }
  idStr = String(settings.id_i);
  username = "relay-" + idStr;
  password = sha256_first16_hex(username);
  comTopic = "com/" + idStr;
  Serial.printf("EEPROM прочитан → id=%u, PIN=%s, currentfailedAttempts=%u, globalfailedAttempts=%u, bat_alarm_relay_num:%u\n", 
                settings.id_i, settings.pin_sim, settings.current_pin_failed_attempts, settings.global_pin_failed_attempts, settings.bat_alarm_relay + 1);
}

// ====================== Настройка UART ======================
void enterSetupMode() {
  //offonroot
  Serial.println("Введите пароль:");
  String pass = "";
  int attempts = 0;
  while (attempts < 3) {
    while (!Serial.available()) delay(10);
    pass = Serial.readStringUntil('\n');
    pass.trim();
    if (pass == "offonroot") break;
    attempts++;
    Serial.println("Неверный пароль");
  }
  if (attempts == 3) { Serial.println("Выход из настроек"); return; }

  while (true) {
    Serial.println("1 - ID (текущее: " + idStr + ")");
    Serial.println("2 - PIN SIM (текущее: " + String(settings.pin_sim) + "), [ошибок ввода этого PIN: " + String(settings.current_pin_failed_attempts) + "]");
    Serial.println("3 - Сброс глобального счётчика ошибок ввода PIN (текущее: " + String(settings.global_pin_failed_attempts) + ")");
    Serial.println("4 - Выбор номера реле питания шкафа (текущее: " + String(settings.bat_alarm_relay + 1) + ")");
    Serial.println("9 - Выход");
    while (!Serial.available()) delay(10);
    char choice = Serial.read();

    if (choice == '1') {
      Serial.println("Новый ID: "); 
      String newIdStr;
      while (true) {
        newIdStr = Serial.readStringUntil('\n');
        if (!newIdStr.isEmpty()) {
          break; 
        }
        delay(10);
      } 
      newIdStr.trim();
      Serial.println("Повторите: "); 
      String confirm;
      while (true) {
        confirm = Serial.readStringUntil('\n');
        if (!confirm.isEmpty()) {
          break; 
        }
        delay(10);
      } 
      confirm.trim();
      if (newIdStr == confirm) {
        uint32_t newId = newIdStr.toInt();
        settings.id_i = newId;
        idStr = newIdStr;
        EEPROM.put(0, settings); EEPROM.commit();
        comTopic = "com/" + idStr;
        username = "relay-" + idStr;
        password = sha256_first16_hex(username);
        Serial.println("ID сохранён. Новый топик команд: " + comTopic);
      }
      else {
        Serial.println("ID не совпадают: " + newIdStr + ": " + confirm);
      }
    } else if (choice == '2') {
      Serial.println("Новый PIN (4 цифры): "); 
      String newPin;
      while (true) {
        newPin = Serial.readStringUntil('\n');
        if (!newPin.isEmpty()) {
          break; 
        }
        delay(10);
      } 
      newPin.trim();
      Serial.println("Повторите: "); 
      String confirm;
      while (true) {
        confirm = Serial.readStringUntil('\n');
        if (!confirm.isEmpty()) {
          break; 
        }
        delay(10);
      } 
      confirm.trim();
      if (newPin == confirm && newPin.length() == 4) {
        settings.current_pin_failed_attempts = 0;   // сбрасываем счётчик после смены PIN
        memset(settings.pin_sim, 0, 8);
        strncpy(settings.pin_sim, newPin.c_str(), 4);
        EEPROM.put(0, settings); EEPROM.commit();
        Serial.println("PIN сохранён, счётчик ошибок сброшен");
      }
      else {
        Serial.println("PIN не совпадают: " + newPin + ": " + confirm);
      }
    } else if (choice == '3') {
      Serial.println("Функция сброса глобального счётчика ошибок");
      Serial.println("ВНИМАНИЕ!"); 
      Serial.println("Плата не выполняет ввод PIN, если счётчик ошибок достиг значения 2"); 
      Serial.println("Во избежания блокировки SIM используйте эту функцию только после сброса счётчика попыток на самое SIM карте с помощью стороннего устройства");
      Serial.println(""); 
      Serial.println("Для сбора счётчика введи \"1\", для выхода из функции \"9\":"); 
      String choice3;
      while (true) {
        choice3 = Serial.readStringUntil('\n');
        if (!choice3.isEmpty()) {
          break; 
        }
        delay(10);
      } 
      choice3.trim();
      if (choice3.indexOf('9', 0) == -1) {
        Serial.println("Повторите: "); 
        String confirm;
        while (true) {
          confirm = Serial.readStringUntil('\n');
          if (!confirm.isEmpty()) {
            break; 
          }
          delay(10);
        } 
        confirm.trim();
        if (choice3 == confirm && choice3.indexOf('1', 0) != -1) {
          settings.global_pin_failed_attempts = 0;   // сбрасываем счётчик
          EEPROM.put(0, settings); EEPROM.commit();
          Serial.println("Глобальный счётчик ошибок сброшен");
        }
        else {
          Serial.println("Ввод не совпадает: " + choice3 + ": " + confirm);
        }
      }
    } else if (choice == '4') {
      Serial.println("Новый номер реле [1-4]: "); 
      String newRelay;
      while (true) {
        newRelay = Serial.readStringUntil('\n');
        if (!newRelay.isEmpty()) {
          break; 
        }
        delay(10);
      } 
      newRelay.trim();
      Serial.println("Повторите: "); 
      String confirm;
      while (true) {
        confirm = Serial.readStringUntil('\n');
        if (!confirm.isEmpty()) {
          break; 
        }
        delay(10);
      } 
      confirm.trim();
      if (newRelay == confirm && newRelay.length() == 1) {
        settings.bat_alarm_relay = newRelay.toInt() - 1;
        EEPROM.put(0, settings); EEPROM.commit();
        Serial.println("Номер реле сохранён");
      }
      else {
        Serial.println("Номер реле не совпадает: " + newRelay + ": " + confirm);
      }
    }
    else if (choice == '9') break;
  }
}

// ====================== GSM ======================
void initGSM() {
  delay(2000);  // лишняя пауза перед initGSM
  Serial.println("GSM init start");
  for (int attempt = 0; attempt < 3; attempt++) {
    pulseWatchdog();
    Serial.print("Попытка сети #"); Serial.println(attempt+1);
    modem.restart();
    modem.setBaud(9600);
    delay(9000);
    pulseWatchdog();

    int simStatus = modem.getSimStatus();
    delay(1000);
    simStatus = modem.getSimStatus();
    if (simStatus == SIM_ERROR) {
      Serial.println("SIM error, try getSimCCID to get status");
      modem.getSimCCID();
      delay(1000);
      simStatus = modem.getSimStatus();
    }
    Serial.print("SIM статус: ");
    if      (simStatus == SIM_READY) Serial.println("READY");
    else if (simStatus == SIM_LOCKED) Serial.println("SIM LOCKED");
    else if (simStatus == SIM_ANTITHEFT_LOCKED) Serial.println("SIM ANTITHEFT LOCKED");
    else if (simStatus == SIM_ERROR) Serial.println("SIM ERROR");
    else                     Serial.println("UNKNOWN (" + String(simStatus) + ")");

    if (simStatus == SIM_LOCKED) {
      Serial.println("SIM locked, try pin");
      if (settings.current_pin_failed_attempts > 0) {
        Serial.println("╔══════════════════════════════════════╗");
        Serial.println("║   ОШИБКА: Этот PIN был введён неверно ранее!");
        Serial.println("║   Автоматическая разблокировка ЗАБЛОКИРОВАНА!");
        Serial.println("║   Через настрйоки (i) укажите верный PIN");
        Serial.println("╚══════════════════════════════════════╝");
        triggerReset();
        return;
      }
      if (settings.global_pin_failed_attempts > 1) {
        Serial.println("╔══════════════════════════════════════╗");
        Serial.println("║   ОШИБКА: PIN был введён неверно" + String(settings.global_pin_failed_attempts) +"раз!");
        Serial.println("║   Автоматическая разблокировка ЗАБЛОКИРОВАНА!");
        Serial.println("║   С помощью внешнего устройства разблокируйте SIM карту");
        Serial.println("║   Через настрйоки (i) укажите верный PIN код и сбросьте глобальный счётчик неудачный попыток");
        Serial.println("╚══════════════════════════════════════╝");
        triggerReset();
        return;
      }
      if (modem.simUnlock(settings.pin_sim)) {
        Serial.println("SIM unlocked успешно");
        settings.current_pin_failed_attempts = 0;
        settings.global_pin_failed_attempts = 0;
      } else {
        Serial.println("SIM unlock fail (PIN: " + String(settings.pin_sim) + ")");
        settings.current_pin_failed_attempts++;
        settings.global_pin_failed_attempts++;
        EEPROM.put(0, settings); EEPROM.commit();
        Serial.printf("Неудачных попыток: %u\n", settings.current_pin_failed_attempts);
      }
    }

    pulseWatchdog();
    simStatus = modem.getSimStatus();
    Serial.print("SIM статус после simUnlock: ");
    if      (simStatus == SIM_READY) Serial.println("READY");
    else if (simStatus == SIM_LOCKED) Serial.println("SIM LOCKED");
    else if (simStatus == SIM_ANTITHEFT_LOCKED) Serial.println("SIM ANTITHEFT LOCKED");
    else if (simStatus == SIM_ERROR) Serial.println("SIM ERROR");
    else                     Serial.println("UNKNOWN (" + String(simStatus) + ")");

    if (simStatus != SIM_READY) continue;

    if (modem.waitForNetwork(60000)) {
      Serial.println("Network OK");
      break;
    }
    Serial.println("Network fail, retry");
    delay(5000);
  }

  if (!modem.waitForNetwork(1000)) {
    Serial.println("Network fail после 3 попыток → reset watchdog");
    triggerReset();
    return;
  }

  modem.gprsConnect("", "", "");
  //modem.gprsConnect("fixedip.msk", "", "");
  if (modem.isGprsConnected()) {
    Serial.println("GPRS connected");
  } else {
    Serial.println("GPRS fail после retry → reset watchdog");
    triggerReset();
  }
}

// ====================== СИГНАЛ И АКБ ======================
// Уровень сигнала запрашиваем у модема заранее (в loop), а не внутри publishStat:
// publishStat вызывается и из MQTT-callback, где AT-команда к модему нежелательна.
void updateSignalQuality() {
  int16_t csq = modem.getSignalQuality();
  signalQuality = (csq >= 0 && csq <= 31) ? (uint32_t)csq : 99;
  lastSignalUpdate = millis();
  Serial.printf("Сигнал GSM: CSQ=%u\n", signalQuality);
}

// Напряжение АКБ: среднее из 16 измерений АЦП (A0) — меньше шума
uint32_t readBatteryAdc() {
  uint32_t sum = 0;
  for (int i = 0; i < 16; i++) {
    sum += analogRead(A0);
    delay(2);
  }
  return sum / 16;
}

// ====================== MQTT ======================
void publishStat() {
  stat_t s = {
    .id = settings.id_i, 
    .version = PROTOCOL_VERSION,
    .r1 = current_state[0] == HIGH ? 1 : 0, 
    .r2 = current_state[1] == HIGH ? 1 : 0,
    .r3 = current_state[2] == HIGH ? 1 : 0,
    .r4 = current_state[3] == HIGH ? 1 : 0,
    .stat =  1,
    .sl = signalQuality,
    .alarm = alarmNow,
    .bat_adc = readBatteryAdc()
  };

  bool ret = mqtt.publish(statTopic.c_str(), (uint8_t*)&s, sizeof(stat_t), true);
  memcpy(last_r_states, current_state, 4);
  if (ret) {
    Serial.printf("stat опубликован (retain=true): CSQ=%u, АЦП АКБ=%u\n", s.sl, s.bat_adc);
  }
  else {
    Serial.println("stat publish error");
    reconnectMQTT();
  }
}

void publishRep(uint32_t cmd_id, uint32_t success) {
  rep_t r = {
    .id = settings.id_i, 
    .cmd_id = cmd_id, 
    .success = success
    };
  if (mqtt.publish(repTopic.c_str(), (uint8_t*)&r, sizeof(rep_t), false)) {
    Serial.printf("rep опубликован: success=%u\n", success);
  }
  else {
    Serial.println("rep publish error");
    reconnectMQTT();
  }
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  if (String(topic) != comTopic || length != sizeof(com_t)) return;

  com_t* cmd = (com_t*)payload;
  Serial.printf("COM: cmd_id=%u cmd=%u rel=%u\n", cmd->cmd_id, cmd->command, cmd->rel_number);

  //Qos 1, защита от повторной команды
  if (cmd->command == 0 || cmd_id_mem == cmd->cmd_id) return;

  //Сохраняем Id
  cmd_id_mem = cmd->cmd_id;

  // Защита: retained-команды перезапуска реле (3) и перезагрузки платы (5),
  // полученные сразу после подключения, не выполняем — иначе плата уйдёт в цикл перезагрузок.
  if (first_cmd && (millis() - first_cmd_ms < MAX_PUBLISH_INTERVAL) && (cmd->command == 3 || cmd->command == 5)) {
    first_cmd = false;
    return;
  }
  first_cmd = false;
  if (cmd->command == 5) {
    // Перезагрузка платы: подтверждаем команду, даём rep уйти в сеть,
    // затем перестаём «кормить» внешний WATCHDOG — он перезагрузит плату.
    Serial.printf("COM: перезагрузка платы по команде сервера (cmd_id=%u)\n", cmd->cmd_id);
    publishRep(cmd->cmd_id, 1);
    for (int i = 0; i < 20; i++) { mqtt.loop(); delay(100); }
    mqtt.disconnect();
    triggerReset();
    return;
  }
  if (cmd->command == 4) {
    current_state[0] = pcf8574.digitalRead(reley_kontrol_1);
    current_state[1] = pcf8574.digitalRead(reley_kontrol_2);
    current_state[2] = pcf8574.digitalRead(reley_kontrol_3);
    current_state[3] = pcf8574.digitalRead(reley_kontrol_4);
    publishStat();
    publishRep(cmd->cmd_id, 1);
    return;
  }

  bool ok = true;
  if (cmd->command == 1) { 
    if (cmd->rel_number == 0) {
      ok = rele_on(1);
      ok &= rele_on(2);
      ok &= rele_on(3);
      ok &= rele_on(4);
    }
    else {
      ok = rele_on(cmd->rel_number);
    }
  }
  else if (cmd->command == 2) {  
    if (cmd->rel_number == 0) {
      ok = rele_off(1);
      ok &= rele_off(2);
      ok &= rele_off(3);
      ok &= rele_off(4);
    }
    else {
      ok = rele_off(cmd->rel_number);
    }
  }
  else if (cmd->command == 3) {   
    if (cmd->rel_number == 0) {
      ok = rele_off(1);
      ok &= rele_off(2);
      ok &= rele_off(3);
      ok &= rele_off(4);
      if (ok) {
        delay(1000);
        ok = rele_on(1);
        ok &= rele_on(2);
        ok &= rele_on(3);
        ok &= rele_on(4);
      }
    }
    else {
      ok = rele_off(cmd->rel_number);
      delay(1000); 
      ok &= rele_on(cmd->rel_number);
    }
  }

  publishRep(cmd->cmd_id, ok ? 1 : 2);
}

bool reconnectMQTT() {

  if (!modem.isNetworkConnected()) {
    Serial.println("Network lost (SIM800L/SIM problem) → reset watchdog");
    triggerReset();
    return false;
  }

  clientID = "relay-id" + idStr + "-v1";

  Serial.println("MQTT params: username [" + username + "]");

  bool res = mqtt.connect(clientID.c_str(), username.c_str(), password.c_str());

  unsigned long timeout = millis() + 3000;
  while (millis() < timeout) {
    mqtt.loop();
    if (mqtt.connected()) break;
    delay(10);
  }

  if (!mqtt.connected()) {
    mqttReconnectFailCount++;
    Serial.printf("MQTT connect failed, state=%d, fails=%u/%u\n", 
                  mqtt.state(), mqttReconnectFailCount, MAX_MQTT_FAILS_BEFORE_RESET);

    if (mqttReconnectFailCount >= MAX_MQTT_FAILS_BEFORE_RESET) {
      Serial.println("Too many MQTT reconnect fails → reset watchdog");
      triggerReset();
    }
    return false;
  }

  Serial.println("MQTT connected");
  mqttReconnectFailCount = 0;        // сбрасываем счётчик

  delay(100);
  mqtt.loop();

  bool subOk = mqtt.subscribe(comTopic.c_str(), 1);
  if (subOk) {
    Serial.println("Подписка на com/" + idStr + " QoS1 УСПЕШНА");
    return true;
  } 
  else {
    Serial.print("MQTT subscribe com topic [");
    Serial.print(comTopic.c_str());
    Serial.println("] fail");
    Serial.printf("mqtt.state(): %u\n", mqtt.state());
    
    mqtt.disconnect();
    return false;
  }
  return false;
}

// ====================== SETUP ======================
void setup() {
  pinMode(reley_1, OUTPUT); digitalWrite(reley_1, LOW);
  pinMode(reley_2, OUTPUT); digitalWrite(reley_2, HIGH);
  pinMode(reley_3, OUTPUT); digitalWrite(reley_3, HIGH);
  pinMode(reley_4, OUTPUT); digitalWrite(reley_4, HIGH);

  Serial.begin(115200);
  delay(5000);
  Serial.println("\n=== Прошивка MQTT v2.2 (протокол v2: сигнал GSM + АЦП АКБ, команда 5 = перезагрузка) ===");

  pinMode(RsRes, OUTPUT); 
  pulseWatchdog();
  //   входы/выходы PCF
  pcf8574.pinMode(reley_kontrol_1, INPUT);
  pcf8574.pinMode(reley_kontrol_2, INPUT);
  pcf8574.pinMode(reley_kontrol_3, INPUT);
  pcf8574.pinMode(reley_kontrol_4, INPUT);
  Serial.print("Init pcf8574...");
  if (pcf8574.begin()) {		
    Serial.println("PCF OK");	
  }
  else {		
    Serial.println("PCF ERROR");	
    triggerReset();
  }

  loadSettings();
  Serial.print("id = ");
  Serial.println(idStr);

  Serial.println("Жду символ 'i' для входа в настройки (5 секунд)...");
  unsigned long t = millis();
  while (millis() - t < 5000) {
    if (Serial.available()) { 
      String s = Serial.readStringUntil('\n');
      if (s.c_str()[0] == 'i') { 
        enterSetupMode();
        break; 
      }
    }
    delay(10);
  }
  Serial.println("Нормальный запуск");

  GSMport.begin(9600);
  initGSM();

  //mqtt.setServer("ser.vc-its.off-on.ru", 1883);
  //mqtt.setServer("off-on.ru", 1883);
  //mqtt.setServer("v2918877.hosted-by-vdsina.ru", 1883);
  //mqtt.setServer("service.off-on.ru", 1883);
  mqtt.setServer(IPAddress(185, 209, 29, 25), 1883);
  //mqtt.setServer(IPAddress(195, 2, 80, 210), 1883);
  //mqtt.setServer(IPAddress(89,108,78,89), 1883);
  mqtt.setCallback(mqttCallback);
  mqtt.setSocketTimeout(10);
  mqtt.setKeepAlive(95);

  pulseWatchdog();
  reconnectMQTT();
  
  current_state[0] = pcf8574.digitalRead(reley_kontrol_1);
  current_state[1] = pcf8574.digitalRead(reley_kontrol_2);
  current_state[2] = pcf8574.digitalRead(reley_kontrol_3);
  current_state[3] = pcf8574.digitalRead(reley_kontrol_4);
  if (mqtt.connected())
  {
      updateSignalQuality();   // первый stat — уже с реальным уровнем сигнала
      publishStat();
      first_cmd_ms = millis();
  }
}

// ====================== LOOP ======================
void loop() {
  mqtt.loop();
  // watchdog
  if (millis() - lastWatchdog > WATCHDOG_INTERVAL) {
    if (!modem.isNetworkConnected()) {
      Serial.println("Network lost (SIM800L/SIM problem) → reset watchdog");
      triggerReset();
    }
    pulseWatchdog();         // нормальный пульс каждую секунду
    lastWatchdog = millis();
  }

  if (!mqtt.connected() || mqtt.state() != MQTT_CONNECTED) {
    if (millis() - lastReconnectAttempt > MQTT_RECONNECT_INTERVAL) {
      lastReconnectAttempt = millis();
      reconnectMQTT();
    }
  }
  else {
    if (lastSignalUpdate == 0 || millis() - lastSignalUpdate > SIGNAL_UPDATE_INTERVAL) {
      updateSignalQuality();
    }
    // публикация stat: по изменению ИЛИ каждые 300 сек
    if (millis() - lastCheck > 500) {
      current_state[0] = pcf8574.digitalRead(reley_kontrol_1);
      current_state[1] = pcf8574.digitalRead(reley_kontrol_2);
      current_state[2] = pcf8574.digitalRead(reley_kontrol_3);
      current_state[3] = pcf8574.digitalRead(reley_kontrol_4);

      bool changed = false;
      for (int i = 0; i < 4; i++) {
        if (current_state[i] != last_r_states[i]) {
          changed = true;
          break;
        }
      }

      if (changed || (millis() - lastPublish > MAX_PUBLISH_INTERVAL)) {
        if (!alarmNow) {
          if (last_r_states[settings.bat_alarm_relay] == HIGH && current_state[settings.bat_alarm_relay] == LOW) {
            alarmNow = 1;
          }
        }
        else {
          if (current_state[settings.bat_alarm_relay] == HIGH) {
            alarmNow = 0;
          }
        }
        publishStat();
        lastPublish = millis();
      }
      lastCheck = millis();
    }
  }
}
