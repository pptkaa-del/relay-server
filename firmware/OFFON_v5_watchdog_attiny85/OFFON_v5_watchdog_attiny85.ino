// =============================================================================
//  Плата «OFFON reley ver_5_1» — внешний WATCHDOG на ATtiny85
//
//  Arduino IDE: пакет ATTinyCore (Spence Konde), плата «ATtiny25/45/85 (No bootloader)»,
//  Chip ATtiny85, Clock 8 MHz (internal); сначала «Записать загрузчик» (выставит фьюзы), затем
//  «Загрузить через программатор» (USBasp или Arduino as ISP).
//
//  Подключение (по разводке OFFON_reley_ver_5_1.lay6):
//    PB2 (ножка 7) ← транзистор ← IO14 ESP32: импульс ESP32 = короткий LOW на PB2 (вход с подтяжкой)
//    PB4 (ножка 3) → транзистор → EN ESP32:   HIGH = ESP32 удерживается в сбросе
//    PB3 (ножка 2) → затвор MOSFET в «земле» ESP32 и модуля SIM7670: HIGH = питание включено
//    PB0, PB1, PB5 (RESET) не используются.
//
//  Логика:
//    * ESP32 подаёт импульс примерно раз в секунду (прошивка OFFON_v5_esp32);
//    * нет импульсов дольше HEARTBEAT_TIMEOUT_MS → сброс ESP32 через EN;
//    * после сброса импульсов снова нет → полное отключение питания ESP32 и модема на POWER_OFF_MS;
//    * импульсы появились — счётчик неудач обнуляется.
//    * собственный WDT ATtiny85 (8 с) перезапускает и сам сторожевой таймер, если он зависнет.
//
//  HEARTBEAT_TIMEOUT_MS должен быть больше самой долгой паузы без импульсов в прошивке ESP32
//  (подключение к мобильному интернету на плохой связи, до ~60 с) и меньше 120 с — через 120 с
//  ESP32 перезагрузится программно сама (страховка в triggerReset()).
// =============================================================================
#include <avr/wdt.h>

const uint8_t PIN_HEARTBEAT = PB2;
const uint8_t PIN_ESP_RESET = PB4;
const uint8_t PIN_POWER = PB3;

const unsigned long HEARTBEAT_TIMEOUT_MS = 90000UL;  // нет импульсов 90 с → сброс
const unsigned long RESET_PULSE_MS = 300;             // длительность сброса EN
const unsigned long POWER_OFF_MS = 10000UL;           // отключение питания ESP32 + SIM7670
const unsigned long BOOT_DELAY_MS = 1000;             // пауза перед первым включением питания

unsigned long lastBeat = 0;
uint8_t lastLevel = HIGH;
uint8_t failures = 0;

void feedingDelay(unsigned long ms) {  // пауза с обслуживанием собственного WDT
  unsigned long start = millis();
  while (millis() - start < ms) {
    wdt_reset();
    delay(10);
  }
}

void resetEsp() {
  digitalWrite(PIN_ESP_RESET, HIGH);
  feedingDelay(RESET_PULSE_MS);
  digitalWrite(PIN_ESP_RESET, LOW);
}

void powerCycle() {
  digitalWrite(PIN_POWER, LOW);   // ESP32 и модем обесточены
  feedingDelay(POWER_OFF_MS);
  digitalWrite(PIN_POWER, HIGH);
}

void setup() {
  wdt_disable();
  pinMode(PIN_ESP_RESET, OUTPUT);
  digitalWrite(PIN_ESP_RESET, LOW);
  pinMode(PIN_POWER, OUTPUT);
  digitalWrite(PIN_POWER, LOW);
  pinMode(PIN_HEARTBEAT, INPUT_PULLUP);
  delay(BOOT_DELAY_MS);
  digitalWrite(PIN_POWER, HIGH);  // включаем ESP32 и модем
  wdt_enable(WDTO_8S);
  lastLevel = digitalRead(PIN_HEARTBEAT);
  lastBeat = millis();
}

void loop() {
  wdt_reset();

  uint8_t level = digitalRead(PIN_HEARTBEAT);
  if (level != lastLevel) {  // любой фронт — импульс от ESP32
    lastLevel = level;
    lastBeat = millis();
    failures = 0;
  }

  if (millis() - lastBeat > HEARTBEAT_TIMEOUT_MS) {
    if (failures == 0) {
      resetEsp();      // первая неудача — перезапуск ESP32 (модем перезапустит сама прошивка)
    } else {
      powerCycle();    // повторная — полное отключение питания ESP32 и модема
    }
    if (failures < 255) failures++;
    lastLevel = digitalRead(PIN_HEARTBEAT);
    lastBeat = millis();
  }
  delay(2);
}
