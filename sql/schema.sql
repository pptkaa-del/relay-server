SET NAMES utf8mb4;
-- Схема БД сервера управления релейными платами.
-- Выполняется install.sh в выбранной базе; все CREATE идемпотентны.

CREATE TABLE IF NOT EXISTS `devices` (
    `id` INT(11) PRIMARY KEY,
    `name` VARCHAR(120) DEFAULT NULL,
    `location` VARCHAR(100) DEFAULT NULL,
    `last_seen` TIMESTAMP NULL DEFAULT NULL,
    `status` TINYINT(1) DEFAULT 0,
    `version` INT(11) DEFAULT 1,
    `signal_level` INT(10) UNSIGNED DEFAULT NULL,
    `battery_power` TINYINT(1) DEFAULT 0,
    `last_alarm` TIMESTAMP NULL DEFAULT NULL,
    `alarm_type` TINYINT(1) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `relay_states` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `device_id` INT(11) NOT NULL,
    `relay_num` TINYINT(1) NOT NULL,
    `state` TINYINT(1) DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_device_relay` (`device_id`, `relay_num`),
    INDEX `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `commands` (
    `cmd_id` INT(11) NOT NULL PRIMARY KEY,
    `device_id` INT(11) NOT NULL,
    `command` TINYINT(1) DEFAULT NULL,
    `relay_num` TINYINT(1) DEFAULT NULL,
    `status` ENUM('pending','sent','success','error') DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `result` TINYINT(1) DEFAULT NULL,
    FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE,
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_device_id` (`device_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `general_info` (
    `id` INT(11) NOT NULL PRIMARY KEY,
    `name_obj` TEXT DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `coordinates` TEXT DEFAULT NULL,
    `name_reley_1` TEXT DEFAULT NULL,
    `name_reley_2` TEXT DEFAULT NULL,
    `name_reley_3` TEXT DEFAULT NULL,
    `name_reley_4` TEXT DEFAULT NULL,
    `data_creet` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `device_last_update` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `device_id` INT(11) NOT NULL UNIQUE,
    `device_name` VARCHAR(120) DEFAULT NULL,
    `last_update` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_change` TIMESTAMP NULL DEFAULT NULL,
    `change_type` ENUM('status', 'relay', 'connection', 'none') DEFAULT 'none',
    `changed_fields` TEXT DEFAULT NULL,
    `previous_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE,
    INDEX `idx_device_id` (`device_id`),
    INDEX `idx_last_change` (`last_change`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Журнал всех MQTT-сообщений (входящих и отправленных) для страницы «MQTT монитор».
-- Старые записи удаляет подписчик (log_retention_days в config.php).
CREATE TABLE IF NOT EXISTS `mqtt_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ts` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `direction` ENUM('in','out') NOT NULL,
    `topic` VARCHAR(255) NOT NULL,
    `device_id` INT(11) DEFAULT NULL,
    `payload_hex` MEDIUMTEXT,
    `decoded` TEXT DEFAULT NULL,
    `retained` TINYINT(1) NOT NULL DEFAULT 0,
    `user_login` VARCHAR(64) DEFAULT NULL,
    INDEX `idx_ts` (`ts`),
    INDEX `idx_device` (`device_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- v3.1: несколько брокеров, журнал событий по устройствам, последнее поступление данных
-- (все изменения идемпотентны — скрипт можно выполнять повторно при обновлении)
-- ---------------------------------------------------------------------------

-- Брокеры, к которым подключается сервер. Запись is_local = 1 — собственный брокер
-- (логин и пароль берутся из /etc/relay-server/config.php, поля host/username/password не используются).
CREATE TABLE IF NOT EXISTS `mqtt_brokers` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `host` VARCHAR(255) NOT NULL DEFAULT '',
    `port` INT(11) NOT NULL DEFAULT 1883,
    `use_tls` TINYINT(1) NOT NULL DEFAULT 0,
    `tls_verify` TINYINT(1) NOT NULL DEFAULT 1,
    `username` VARCHAR(255) DEFAULT NULL,
    `password` VARCHAR(255) DEFAULT NULL,
    `topics` VARCHAR(1000) NOT NULL DEFAULT '#',
    `is_local` TINYINT(1) NOT NULL DEFAULT 0,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('connecting','connected','error','disabled') NOT NULL DEFAULT 'connecting',
    `last_error` VARCHAR(500) DEFAULT NULL,
    `last_connected_at` TIMESTAMP NULL DEFAULT NULL,
    `status_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `mqtt_brokers` (`id`, `name`, `host`, `port`, `topics`, `is_local`)
SELECT 1, 'Локальный брокер', '127.0.0.1', 1883, '#', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `mqtt_brokers` WHERE `is_local` = 1);

ALTER TABLE `mqtt_messages`
    ADD COLUMN IF NOT EXISTS `broker_id` INT(11) NOT NULL DEFAULT 1 AFTER `direction`,
    ADD COLUMN IF NOT EXISTS `cmd_id` BIGINT DEFAULT NULL AFTER `device_id`;
CREATE INDEX IF NOT EXISTS `idx_broker` ON `mqtt_messages` (`broker_id`, `id`);
CREATE INDEX IF NOT EXISTS `idx_broker_device` ON `mqtt_messages` (`broker_id`, `device_id`, `id`);
CREATE INDEX IF NOT EXISTS `idx_cmd` ON `mqtt_messages` (`broker_id`, `cmd_id`);

-- Последнее поступление данных от платы (любое сообщение, включая rep и retained)
ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `last_data_at` TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `last_data_topic` VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `conn_state` ENUM('unknown','online','offline') NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS `msg_count` BIGINT UNSIGNED NOT NULL DEFAULT 0;

-- Журнал событий по устройствам: связь, реле, аварии, команды и ответы.
-- Хранится дольше журнала сообщений (events_retention_days в config.php).
CREATE TABLE IF NOT EXISTS `device_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ts` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `device_id` INT(11) NOT NULL,
    `type` ENUM('created','online','offline','relay','alarm','alarm_clear','command','reply','reboot','info') NOT NULL,
    `level` ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
    `message` VARCHAR(500) NOT NULL,
    `data` TEXT DEFAULT NULL,
    `user_login` VARCHAR(64) DEFAULT NULL,
    INDEX `idx_device_ts` (`device_id`, `id`),
    INDEX `idx_ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- исправление имени, записанного в неверной кодировке ранними версиями установщика
UPDATE `mqtt_brokers` SET `name` = 'Локальный брокер' WHERE `is_local` = 1 AND `name` LIKE 'Ð%';

-- ---------------------------------------------------------------------------
-- v3.2: напряжение АКБ, обнаружение незарегистрированных плат
-- ---------------------------------------------------------------------------

-- Последнее значение АЦП напряжения АКБ (поле bat_adc пакета stat, протокол v2)
ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `bat_adc` INT(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `bat_adc_at` TIMESTAMP NULL DEFAULT NULL;

-- Калибровка делителя АКБ: значения АЦП при 19 В и при 26 В (линейная зависимость)
ALTER TABLE `general_info`
    ADD COLUMN IF NOT EXISTS `cal_adc_19` INT(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `cal_adc_26` INT(11) DEFAULT NULL;

-- Платы, которые пытались подключиться к брокеру, но не зарегистрированы
-- (по сообщениям брокера «Client relay-id12-v1 disconnected, not authorised.»)
CREATE TABLE IF NOT EXISTS `pending_devices` (
    `id` INT(11) NOT NULL PRIMARY KEY,
    `client_id` VARCHAR(100) NOT NULL,
    `ip` VARCHAR(64) DEFAULT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
    `first_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ignored` TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- v3.3: плата OFFON ver_5_1 (ESP32) — протокол v3: замок и геркон двери
-- ---------------------------------------------------------------------------
ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `lock_state` TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `door_state` TINYINT(1) DEFAULT NULL;
ALTER TABLE `device_events`
    MODIFY `type` ENUM('created','online','offline','relay','alarm','alarm_clear','command','reply','reboot','info','door','lock') NOT NULL;

-- ---------------------------------------------------------------------------
-- v3.4: наклейки с серийным номером — журнал выпущенных номеров
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `serial_labels` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `sn` VARCHAR(32) NOT NULL,
    `device_id` INT(11) DEFAULT NULL,
    `note` VARCHAR(200) DEFAULT NULL,
    `user_login` VARCHAR(64) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_sn` (`sn`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- v3.5: калибровка АКБ по точкам 6 В и 15 В (вместо 19 В и 26 В)
-- ---------------------------------------------------------------------------
ALTER TABLE `general_info`
    ADD COLUMN IF NOT EXISTS `cal_adc_6` INT(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `cal_adc_15` INT(11) DEFAULT NULL;
-- уже введённую калибровку 19/26 В пересчитываем по той же прямой в точки 6/15 В (один раз:
-- только для плат, у которых новых значений ещё нет); отрицательный результат — калибровку надо ввести заново
UPDATE `general_info`
   SET `cal_adc_6`  = NULLIF(GREATEST(-1, ROUND(`cal_adc_19` + (6 - 19)  * (`cal_adc_26` - `cal_adc_19`) / 7)), -1),
       `cal_adc_15` = NULLIF(GREATEST(-1, ROUND(`cal_adc_19` + (15 - 19) * (`cal_adc_26` - `cal_adc_19`) / 7)), -1)
 WHERE `cal_adc_6` IS NULL AND `cal_adc_15` IS NULL
   AND `cal_adc_19` IS NOT NULL AND `cal_adc_26` IS NOT NULL AND `cal_adc_19` <> `cal_adc_26`;
UPDATE `general_info` SET `cal_adc_6` = NULL WHERE `cal_adc_15` IS NULL;
UPDATE `general_info` SET `cal_adc_15` = NULL WHERE `cal_adc_6` IS NULL;

-- ---------------------------------------------------------------------------
-- v3.6: пользователи панели, добавленные через веб-интерфейс
-- (пользователи из /etc/relay-server/users.php — администраторы, хранятся в файле)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `panel_users` (
    `login` VARCHAR(64) NOT NULL PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL DEFAULT '',
    `role` ENUM('admin', 'operator', 'viewer') NOT NULL DEFAULT 'operator',
    `pass_hash` VARCHAR(255) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` VARCHAR(64) DEFAULT NULL,
    `last_login_at` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- v3.7: тип АКБ (12 В — калибровка по 6 и 15 В, 24 В — по 19 и 26 В),
--       реле 4 как замок двери, права пользователей на отдельные реле,
--       переадресация MQTT-сообщений между брокерами
-- ---------------------------------------------------------------------------
ALTER TABLE `general_info`
    ADD COLUMN IF NOT EXISTS `bat_type` TINYINT(4) NOT NULL DEFAULT 12,
    ADD COLUMN IF NOT EXISTS `relay4_lock` TINYINT(1) NOT NULL DEFAULT 0;

-- роли: admin — всё; user — только реле, выданные администратором (просмотр или управление)
ALTER TABLE `panel_users` MODIFY `role` ENUM('admin', 'operator', 'viewer', 'user') NOT NULL DEFAULT 'user';

CREATE TABLE IF NOT EXISTS `panel_user_relays` (
    `login` VARCHAR(64) NOT NULL,
    `device_id` INT(11) NOT NULL,
    `relay_num` TINYINT(4) NOT NULL,          -- 1–4; 5 — замок платы ver_5_1
    `can_control` TINYINT(1) NOT NULL DEFAULT 0,  -- 0 — только видит, 1 — видит и управляет
    PRIMARY KEY (`login`, `device_id`, `relay_num`),
    INDEX `idx_device` (`device_id`),
    CONSTRAINT `fk_pur_user` FOREIGN KEY (`login`) REFERENCES `panel_users` (`login`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- прежние роли «Оператор» и «Просмотр» → «Пользователь» с доступом ко всем реле (управление / просмотр)
INSERT IGNORE INTO `panel_user_relays` (`login`, `device_id`, `relay_num`, `can_control`)
SELECT u.`login`, g.`id`, n.`num`, (u.`role` = 'operator')
  FROM `panel_users` u
  JOIN `general_info` g
  JOIN (SELECT 1 AS num UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) n
 WHERE u.`role` IN ('operator', 'viewer');
UPDATE `panel_users` SET `role` = 'user' WHERE `role` IN ('operator', 'viewer');

CREATE TABLE IF NOT EXISTS `mqtt_forwards` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL DEFAULT '',
    `src_broker_id` INT(11) NOT NULL,
    `topic_filter` VARCHAR(255) NOT NULL DEFAULT '#',
    `dst_broker_id` INT(11) NOT NULL,
    `dst_prefix` VARCHAR(100) NOT NULL DEFAULT '',   -- добавляется перед топиком: «site1/» + «stat» = «site1/stat»
    `keep_retain` TINYINT(1) NOT NULL DEFAULT 1,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `fwd_count` BIGINT NOT NULL DEFAULT 0,
    `last_fwd_at` DATETIME DEFAULT NULL,
    `last_error` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_fwd_src` FOREIGN KEY (`src_broker_id`) REFERENCES `mqtt_brokers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fwd_dst` FOREIGN KEY (`dst_broker_id`) REFERENCES `mqtt_brokers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- v3.8: отключённые реле (не показываются, команды им не отправляются),
--       тревога «взлом двери» (дверь открылась без команды «Открыть»)
-- ---------------------------------------------------------------------------
ALTER TABLE `general_info`
    ADD COLUMN IF NOT EXISTS `relays_disabled` TINYINT(4) NOT NULL DEFAULT 0;  -- биты 0–3: реле 1–4 отключены
ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `break_in_at` DATETIME DEFAULT NULL,             -- время взлома (NULL — тревоги нет)
    ADD COLUMN IF NOT EXISTS `break_in_source` VARCHAR(20) DEFAULT NULL;      -- relay4 — замок на реле 4, door — геркон платы ver_5_1

-- ---------------------------------------------------------------------------
-- v3.9: симуляция устройств из монитора — подписчик сам публикует stat от имени устройства
--       и отвечает rep на команды com/{id}, как настоящая плата
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mqtt_simulations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `broker_id` INT(11) NOT NULL,
    `device_id` INT(11) NOT NULL,
    `relays` VARCHAR(16) NOT NULL DEFAULT '0,0,0,0',   -- состояние реле 1–4
    `version` TINYINT(4) NOT NULL DEFAULT 2,             -- 2 — 40 байт, 3 — 48 байт (замок и дверь, плата ver_5_1)
    `lock_state` TINYINT(1) NOT NULL DEFAULT 0,
    `door_state` TINYINT(1) NOT NULL DEFAULT 0,
    `signal_level` TINYINT(4) NOT NULL DEFAULT 20,
    `bat_adc` INT(11) NOT NULL DEFAULT 600,
    `interval_sec` INT(11) NOT NULL DEFAULT 60,           -- как часто публиковать stat
    `dirty` TINYINT(1) NOT NULL DEFAULT 0,                -- изменено в панели: опубликовать stat сейчас
    `stat_count` INT(11) NOT NULL DEFAULT 0,
    `cmd_count` INT(11) NOT NULL DEFAULT 0,
    `last_stat_at` DATETIME DEFAULT NULL,
    `last_cmd_at` DATETIME DEFAULT NULL,
    `last_cmd` VARCHAR(120) DEFAULT NULL,
    `created_by` VARCHAR(64) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_sim` (`broker_id`, `device_id`),
    CONSTRAINT `fk_sim_broker` FOREIGN KEY (`broker_id`) REFERENCES `mqtt_brokers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- v3.10: номер SIM-карты устройства (11 цифр, вид 89997777777)
-- ---------------------------------------------------------------------------
ALTER TABLE `general_info`
    ADD COLUMN IF NOT EXISTS `sim_phone` VARCHAR(11) NOT NULL DEFAULT '';

-- ---------------------------------------------------------------------------
-- v3.11: номер SIM-карты в виде +79997777777 (было 89997777777)
-- ---------------------------------------------------------------------------
ALTER TABLE `general_info` MODIFY `sim_phone` VARCHAR(12) NOT NULL DEFAULT '';
UPDATE `general_info` SET `sim_phone` = CONCAT('+7', SUBSTRING(`sim_phone`, 2)) WHERE `sim_phone` REGEXP '^8[0-9]{10}$';

-- ---------------------------------------------------------------------------
-- v3.12: счётчик нажатий «Перезапуск реле» (команда 3) по пользователям.
--        «Открыть» замку (реле 4 — замок, замок платы ver_5_1) не считается.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `relay_restart_clicks` (
    `id` BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `login` VARCHAR(64) NOT NULL,
    `device_id` INT(11) NOT NULL,
    `relay_num` TINYINT(4) NOT NULL,          -- 1–4; 0 — «перезапуск всех»
    `broker_id` INT(11) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX `idx_login` (`login`, `created_at`),
    INDEX `idx_device` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- прежние нажатия — из журнала событий (один раз, пока таблица пуста)
INSERT INTO `relay_restart_clicks` (`login`, `device_id`, `relay_num`, `created_at`)
SELECT e.`user_login`, e.`device_id`, CAST(JSON_VALUE(e.`data`, '$.relay') AS UNSIGNED), e.`ts`
  FROM `device_events` e
  LEFT JOIN `general_info` g ON g.`id` = e.`device_id`
 WHERE e.`type` = 'command' AND e.`user_login` IS NOT NULL
   AND JSON_VALUE(e.`data`, '$.command') = '3'
   AND CAST(JSON_VALUE(e.`data`, '$.relay') AS UNSIGNED) <> 5
   AND NOT (CAST(JSON_VALUE(e.`data`, '$.relay') AS UNSIGNED) = 4 AND COALESCE(g.`relay4_lock`, 0) = 1)
   AND NOT EXISTS (SELECT 1 FROM `relay_restart_clicks`);

-- ---------------------------------------------------------------------------
-- v3.13: в счётчике нажатий — и «Перезагрузить устройство» (команда 5)
-- ---------------------------------------------------------------------------
ALTER TABLE `relay_restart_clicks`
    ADD COLUMN IF NOT EXISTS `command` TINYINT(4) NOT NULL DEFAULT 3 AFTER `login`;   -- 3 — перезапуск реле, 5 — перезагрузка устройства
-- прежние перезагрузки — из журнала событий (один раз)
INSERT INTO `relay_restart_clicks` (`login`, `command`, `device_id`, `relay_num`, `created_at`)
SELECT e.`user_login`, 5, e.`device_id`, 0, e.`ts`
  FROM `device_events` e
 WHERE e.`type` = 'reboot' AND e.`user_login` IS NOT NULL AND JSON_VALUE(e.`data`, '$.command') = '5'
   AND NOT EXISTS (SELECT 1 FROM `relay_restart_clicks` WHERE `command` = 5);

-- ---------------------------------------------------------------------------
-- v3.16: версия устройства и отключение контроля АКБ
--   hw_version: NULL — по данным устройства (поле version пакета stat);
--               1 — первоначальная плата и прошивка (без АКБ, в stat — уровень сигнала сети),
--               2 — старая плата и новая прошивка (АКБ), 3 — новая плата ver_5_1 и прошивка (АКБ, замок, дверь)
--   bat_monitor: 0 — контроль АКБ выключен (напряжение не показывается)
-- ---------------------------------------------------------------------------
ALTER TABLE `general_info`
    ADD COLUMN IF NOT EXISTS `hw_version` TINYINT(4) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `bat_monitor` TINYINT(1) NOT NULL DEFAULT 1;
