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
