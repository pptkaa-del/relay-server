<?php
/**
 * MQTT-подписчик (systemd: relay-mqtt-subscriber.service).
 *
 * Держит соединения со всеми включёнными брокерами из таблицы mqtt_brokers
 * (список перечитывается каждые 10 с — брокеры можно добавлять из веб-монитора).
 *
 *  - каждое входящее сообщение пишется в mqtt_messages (страница «Монитор»);
 *  - собственный брокер (is_local = 1):
 *      stat → devices / relay_states / device_last_update + события в device_events;
 *      rep  → статус команды в commands; если плата числится оффлайн — шлёт check (4);
 *      раз в 30 с: платы без stat дольше 350 с помечаются оффлайн (событие «нет связи»);
 *  - сторонние брокеры только журналируются (их платы видны в мониторе по последним stat).
 *
 * Логи пишутся в stdout → journalctl -u relay-mqtt-subscriber -f
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib/bootstrap.php';

use PhpMqtt\Client\MqttClient;

$connect = null;

function writeLog(string $msg, string $level = 'INFO'): void
{
    echo date('Y-m-d H:i:s') . " [$level] $msg\n";
}

/** Проверяет соединение с БД и переподключается при необходимости. */
function db(): mysqli
{
    global $connect;
    static $checkedAt = 0.0;
    if ($connect instanceof mysqli) {
        if (microtime(true) - $checkedAt < 5) {
            return $connect;
        }
        try {
            if (@mysqli_query($connect, 'SELECT 1')) {
                $checkedAt = microtime(true);
                return $connect;
            }
        } catch (Throwable) {
        }
        writeLog('соединение с БД потеряно, переподключение...', 'WARN');
        @mysqli_close($connect);
    }
    for ($i = 0; $i < 10; $i++) {
        $connect = relay_db_connect();
        if ($connect) {
            $checkedAt = microtime(true);
            return $connect;
        }
        writeLog('не удалось подключиться к БД: ' . mysqli_connect_error(), 'ERROR');
        sleep(3);
    }
    writeLog('БД недоступна, выход (systemd перезапустит сервис)', 'ERROR');
    exit(1);
}

const STATE_NAMES = [0 => 'выкл', 1 => 'вкл', 2 => 'ошибка'];

/* ------------------------------------------------------------------ */
/* Обработка сообщений собственного брокера                            */
/* ------------------------------------------------------------------ */

/**
 * Дверь открылась. Если за последние 3 минуты не было команды «Открыть» / «Открыть надолго»
 * (реле 4-замку, всем реле или замку платы ver_5_1) и перезагрузки — это взлом: тревога держится,
 * пока её не сбросят в панели.
 */
function checkBreakIn(mysqli $c, int $id, string $source): void
{
    if ($source === 'door') { // плата ver_5_1 с отметкой «замок не используется» — датчик двери не учитываем
        $u = relay_q($c, 'SELECT 1 FROM general_info WHERE id = ? AND lock_unused = 1', [$id]);
        if ($u && mysqli_num_rows($u) > 0) {
            return;
        }
    }
    $cmd = relay_q($c, "SELECT 1 FROM commands WHERE device_id = ? AND created_at > NOW() - INTERVAL 180 SECOND
                          AND (command = 5 OR (command IN (2, 3) AND relay_num IN (0, 4, 5))) LIMIT 1", [$id]);
    if ($cmd && mysqli_num_rows($cmd) > 0) {
        return; // дверь открыли командой из панели
    }
    relay_q($c, 'UPDATE devices SET break_in_at = NOW(), break_in_source = ? WHERE id = ?', [$source, $id]);
    relay_event($c, $id, 'alarm', 'ВЗЛОМ ДВЕРИ: дверь открыта без команды «Открыть»'
        . ($source === 'door' ? ' (датчик двери)' : ' (замок, реле 4)'), 'error', ['source' => $source]);
    writeLog("ВЗЛОМ ДВЕРИ: устройство $id ($source)", 'WARN');
}

/** Тревога «АКБ ниже 6 В»: событие при переходе через порог (флаг devices.bat_low — чтобы не повторять). */
function checkBatLow(mysqli $c, int $id): void
{
    $v = relay_device_voltage($c, $id);
    $res = relay_q($c, 'SELECT bat_low FROM devices WHERE id = ?', [$id]);
    $was = $res instanceof mysqli_result ? (int)(mysqli_fetch_row($res)[0] ?? 0) : 0;
    $low = $v !== null && $v < RELAY_BAT_LOW_V;
    if ($low && !$was) {
        relay_q($c, 'UPDATE devices SET bat_low = 1 WHERE id = ?', [$id]);
        relay_event($c, $id, 'alarm', sprintf('Тревога: напряжение АКБ ниже %.0f В (%.1f В)', RELAY_BAT_LOW_V, $v), 'error', ['voltage' => $v]);
        writeLog("АКБ: устройство $id — " . sprintf('%.1f', $v) . ' В', 'WARN');
    } elseif (!$low && $was) {
        relay_q($c, 'UPDATE devices SET bat_low = 0 WHERE id = ?', [$id]);
        relay_event($c, $id, 'alarm_clear', $v === null ? 'Тревога АКБ снята' : sprintf('Напряжение АКБ восстановлено (%.1f В)', $v), 'success');
    }
}

function handleStat(array $d, bool $retained): void
{
    $c = db();
    $ts = date('Y-m-d H:i:s');
    $id = (int)$d['id'];
    $version = (int)$d['version'];
    $stat = (int)$d['stat'];
    $sl = (int)$d['sl'];
    $alarm = (int)$d['alarm'];
    $r = [1 => (int)$d['r1'], 2 => (int)$d['r2'], 3 => (int)$d['r3'], 4 => (int)$d['r4']];

    $current = mysqli_fetch_assoc(relay_q($c, 'SELECT status, name, battery_power, conn_state, lock_state, door_state FROM devices WHERE id = ?', [$id]));

    // 1. Новое устройство
    if (!$current) {
        $name = "Устройство $id";
        $location = '';
        $gen = mysqli_fetch_assoc(relay_q($c, 'SELECT name_obj, address FROM general_info WHERE id = ?', [$id]));
        if ($gen) {
            $name = ($gen['name_obj'] ?? '') !== '' ? $gen['name_obj'] : $name;
            $location = (string)$gen['address'];
        } else {
            relay_q($c, "INSERT INTO general_info (id, name_obj, address, coordinates, name_reley_1, name_reley_2, name_reley_3, name_reley_4)
                         VALUES (?, ?, '', '', 'Реле 1', 'Реле 2', 'Реле 3', 'Реле 4')", [$id, $name]);
        }
        $conn = $retained ? 'unknown' : ($stat === 0 ? 'offline' : 'online');
        relay_q($c, 'INSERT INTO devices (id, name, location, last_seen, status, version, signal_level, battery_power,
                                          last_data_at, last_data_topic, conn_state, msg_count)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 1)',
            [$id, $name, $location, $ts, $stat, $version, $sl, $alarm, 'stat', $conn]);
        for ($i = 1; $i <= 4; $i++) {
            relay_q($c, 'INSERT IGNORE INTO relay_states (device_id, relay_num, state, updated_at) VALUES (?, ?, ?, ?)', [$id, $i, $r[$i], $ts]);
        }
        relay_q($c, 'INSERT IGNORE INTO device_last_update (device_id, device_name, last_update, last_change) VALUES (?, ?, ?, ?)', [$id, $name, $ts, $ts]);
        if (isset($d['bat_adc'])) {
            relay_q($c, 'UPDATE devices SET bat_adc = ?, bat_adc_at = NOW() WHERE id = ?', [(int)$d['bat_adc'], $id]);
        }
        if (isset($d['door'])) {
            relay_q($c, 'UPDATE devices SET lock_state = ?, door_state = ? WHERE id = ?', [(int)$d['lock'], (int)$d['door'], $id]);
        }
        relay_q($c, 'DELETE FROM pending_devices WHERE id = ?', [$id]);
        relay_event($c, $id, 'created', 'Устройство впервые вышло на связь', 'success', $d);
        writeLog("STAT: создано новое устройство $id");
        return;
    }

    relay_q($c, 'UPDATE devices SET last_data_at = NOW(), last_data_topic = ?, msg_count = msg_count + 1 WHERE id = ?', ['stat', $id]);
    if (isset($d['bat_adc'])) {
        relay_q($c, 'UPDATE devices SET bat_adc = ?, bat_adc_at = NOW() WHERE id = ?', [(int)$d['bat_adc'], $id]);
    }
    // протокол v3 (плата ver_5_1): дверь (геркон) и замок
    if (isset($d['door'])) {
        $door = (int)$d['door'];
        $lock = (int)$d['lock'];
        if (!$retained && $current['door_state'] !== null && (int)$current['door_state'] !== $door) {
            $door === 1
                ? relay_event($c, $id, 'door', 'Дверь открыта', 'warning')
                : relay_event($c, $id, 'door', 'Дверь закрыта', 'info');
            if ($door === 1) {
                checkBreakIn($c, $id, 'door');
            }
        }
        if (!$retained && $current['lock_state'] !== null && (int)$current['lock_state'] !== $lock) {
            relay_event($c, $id, 'lock', $lock === 1 ? 'Замок под напряжением' : 'Замок обесточен', 'info');
        }
        relay_q($c, 'UPDATE devices SET lock_state = ?, door_state = ? WHERE id = ?', [$lock, $door, $id]);
    }

    // 2. Retained-сообщение (старое состояние из брокера): обновляем данные, но не last_seen
    if ($retained) {
        relay_q($c, 'UPDATE devices SET version = ?, signal_level = ?, battery_power = ? WHERE id = ?', [$version, $sl, $alarm, $id]);
        for ($i = 1; $i <= 4; $i++) {
            relay_q($c, 'UPDATE relay_states SET state = ? WHERE device_id = ? AND relay_num = ?', [$r[$i], $id, $i]);
        }
        writeLog("STAT: retained от $id (last_seen не обновлён)");
        return;
    }

    // 3. Сравниваем с текущим состоянием → device_last_update и события
    $cur = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach (relay_rows(relay_q($c, 'SELECT relay_num, state FROM relay_states WHERE device_id = ?', [$id])) as $row) {
        $cur[(int)$row['relay_num']] = (int)$row['state'];
    }
    $changes = $prev = $new = [];
    if ((int)$current['status'] !== $stat) {
        $changes[] = 'status'; $prev[] = (int)$current['status']; $new[] = $stat;
    }
    $relayText = [];
    $lock4 = relay_relay4_lock($c, $id);   // реле 4 — замок: вкл — дверь закрыта, выкл — открыта
    $doorName = fn($v) => [0 => 'открыта', 1 => 'закрыта', 2 => 'ошибка'][$v] ?? $v;
    for ($i = 1; $i <= 4; $i++) {
        if ($cur[$i] !== $r[$i]) {
            $changes[] = "relay_$i"; $prev[] = $cur[$i]; $new[] = $r[$i];
            $relayText[] = $i === 4 && $lock4
                ? 'дверь (замок, реле 4): ' . $doorName($cur[$i]) . ' → ' . $doorName($r[$i])
                : ($i === 4 && relay_relay4_wash($c, $id) ? 'омывание (реле 4): ' . (STATE_NAMES[$cur[$i]] ?? $cur[$i]) . ' → ' . (STATE_NAMES[$r[$i]] ?? $r[$i])
                : "реле $i: " . (STATE_NAMES[$cur[$i]] ?? $cur[$i]) . ' → ' . (STATE_NAMES[$r[$i]] ?? $r[$i]));
        }
    }
    $doorOpened = $lock4 && $cur[4] === 1 && $r[4] === 0;
    $name = (string)$current['name'];
    if ($changes) {
        $type = in_array('status', $changes, true) ? 'status' : 'relay';
        relay_q($c, 'INSERT INTO device_last_update
                (device_id, device_name, last_update, last_change, change_type, changed_fields, previous_value, new_value)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE device_name = VALUES(device_name), last_update = VALUES(last_update),
                last_change = VALUES(last_change), change_type = VALUES(change_type),
                changed_fields = VALUES(changed_fields), previous_value = VALUES(previous_value), new_value = VALUES(new_value)',
            [$id, $name, $ts, $ts, $type, implode(',', $changes), implode(',', $prev), implode(',', $new)]);
    } else {
        relay_q($c, 'UPDATE device_last_update SET last_update = ?, device_name = ? WHERE device_id = ?', [$ts, $name, $id]);
    }
    if ($relayText) {
        relay_event($c, $id, 'relay', implode(', ', $relayText), 'info', ['before' => array_values($cur), 'after' => array_values($r)]);
    }
    if ($doorOpened) {
        checkBreakIn($c, $id, 'relay4');
    }

    // признак «питание от АКБ» (поле alarm) сохраняется в devices.battery_power, но событий и оповещений не создаёт

    // Связь
    $newConn = $stat === 0 ? 'offline' : 'online';
    if ($current['conn_state'] !== $newConn) {
        $newConn === 'online'
            ? relay_event($c, $id, 'online', 'Устройство на связи' . (relay_is_v11($c, $id) ? '' : ' (сигнал ' . $sl . ')'), 'success')
            : relay_event($c, $id, 'offline', 'Устройство сообщило о переходе в оффлайн (stat = 0)', 'warning');
    }
    if ($stat === 2 && (int)$current['status'] !== 2) {
        relay_event($c, $id, 'info', 'Устройство сообщило об ошибке (stat = 2)', 'error');
    }

    relay_q($c, 'UPDATE devices SET last_seen = ?, status = ?, version = ?, signal_level = ?, battery_power = ?, conn_state = ? WHERE id = ?',
        [$ts, $stat, $version, $sl, $alarm, $newConn, $id]);
    for ($i = 1; $i <= 4; $i++) {
        relay_q($c, 'INSERT INTO relay_states (device_id, relay_num, state, updated_at) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = VALUES(updated_at)', [$id, $i, $r[$i], $ts]);
    }
    checkBatLow($c, $id);
    writeLog("STAT: $id r=" . implode('', $r) . " stat=$stat sl=$sl alarm=$alarm");
}

function handleRep(array $d): void
{
    $c = db();
    $id = (int)$d['id'];
    $cmdId = (int)$d['cmd_id'];
    $ok = (int)$d['success'] === 1;
    relay_q($c, 'UPDATE devices SET last_data_at = NOW(), last_data_topic = ?, msg_count = msg_count + 1 WHERE id = ?', ['rep', $id]);

    $cmd = mysqli_fetch_assoc(relay_q($c, 'SELECT command, relay_num FROM commands WHERE cmd_id = ? AND device_id = ?', [$cmdId, $id]));
    relay_q($c, "UPDATE commands SET status = ?, confirmed_at = NOW(), result = ? WHERE cmd_id = ? AND device_id = ?",
        [$ok ? 'success' : 'error', $ok ? 1 : 2, $cmdId, $id]);
    writeLog("REP: устройство $id, cmd_id=$cmdId, " . ($ok ? 'success' : 'error'));

    if ($cmd) {
        $label = relay_command_label((int)$cmd['command'], (int)$cmd['relay_num'], relay_relay4_lock($c, $id), relay_relay4_wash($c, $id));
        if ((int)$cmd['command'] === 5) {
            relay_event($c, $id, 'reboot', $ok ? 'Устройство приняло команду перезагрузки и перезапускается' : 'Устройство отклонило перезагрузку', $ok ? 'warning' : 'error', ['cmd_id' => $cmdId]);
        } elseif ((int)$cmd['command'] !== 4) {
            relay_event($c, $id, 'reply', ($ok ? 'Выполнено: ' : 'Не выполнено: ') . $label, $ok ? 'success' : 'error', ['cmd_id' => $cmdId]);
        }
    }

    $row = mysqli_fetch_assoc(relay_q($c, 'SELECT status FROM devices WHERE id = ?', [$id]));
    if ($row && (int)$row['status'] === 0) {
        writeLog("REP: устройство $id числится оффлайн → отправляем check (4)", 'WARN');
        try {
            relay_send_command($c, $id, 4, 0, 'subscriber');
        } catch (Throwable $e) {
            writeLog('REP: ошибка отправки check: ' . $e->getMessage(), 'ERROR');
        }
    }
}

/** Платы без stat дольше RELAY_OFFLINE_AFTER → оффлайн (одно событие на каждый обрыв). */
/** @var array<int, true> устройства панели, которых брокер не пускает, — восстановить учётную запись */
$healQueue = [];
/** @var array<int, int> когда последний раз пытались восстановить (не чаще раза в 10 минут) */
$healedAt = [];

function checkTimeouts(): void
{
    global $healQueue;
    $c = db();
    // учётные записи MQTT устройств панели, которых брокер не пускал, — одной командой
    if ($healQueue) {
        $ids = array_keys($healQueue);
        $healQueue = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            [$code, $out] = relay_device_cli(['add-many', implode(',', $chunk)]);
            foreach ($chunk as $hid) {
                $code === 0
                    ? relay_event($c, $hid, 'info', 'Брокер не пускал устройство: учётная запись MQTT восстановлена автоматически', 'warning')
                    : relay_event($c, $hid, 'info', 'Брокер не пускает устройство (not authorised), восстановить учётную запись не удалось: '
                        . mb_substr($out, 0, 200) . '. Выполните: sudo relay-device add ' . $hid, 'error');
            }
            writeLog('MQTT: восстановление учётных записей (' . implode(',', $chunk) . '): ' . ($code === 0 ? 'готово' : $out), $code === 0 ? 'INFO' : 'ERROR');
        }
    }
    // истёк срок «не принимать сообщения» — возвращаем устройству доступ к брокеру
    foreach (relay_rows(@relay_q($c, 'SELECT id FROM general_info WHERE mqtt_block_at IS NOT NULL
                                        AND mqtt_block_until IS NOT NULL AND mqtt_block_until <= NOW()') ?: null) as $b) {
        $bid = (int)$b['id'];
        [$code, $out] = relay_device_cli(['unblock', (string)$bid]);
        if ($code === 0) {
            relay_q($c, 'UPDATE general_info SET mqtt_block_at = NULL, mqtt_block_until = NULL WHERE id = ?', [$bid]);
            relay_event($c, $bid, 'info', 'Срок «не принимать сообщения» истёк — сообщения от устройства снова принимаются', 'info');
            writeLog("MQTT: устройство $bid снова допущено к брокеру");
        } else {
            writeLog("MQTT: не удалось вернуть доступ устройству $bid: $out", 'ERROR');
        }
    }
    $rows = relay_rows(relay_q($c, "SELECT id, last_seen FROM devices
                                    WHERE conn_state = 'online' AND last_seen < NOW() - INTERVAL ? SECOND", [RELAY_OFFLINE_AFTER]));
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        relay_q($c, "UPDATE devices SET conn_state = 'offline' WHERE id = ?", [$id]);
        $v = relay_device_voltage($c, $id);
        $v !== null && $v < RELAY_BAT_LOW_V
            // перед пропаданием связи АКБ была ниже 6 В — устройство отключилось по питанию
            ? relay_event($c, $id, 'offline', sprintf('Отключено по питанию: АКБ %.1f В, данных нет с ', $v) . date('d.m H:i:s', strtotime($row['last_seen'])), 'error')
            : relay_event($c, $id, 'offline', 'Нет связи: данных от устройства нет с ' . date('d.m H:i:s', strtotime($row['last_seen'])), 'warning');
        writeLog("TIMEOUT: устройство $id — нет данных более " . RELAY_OFFLINE_AFTER . ' с', 'WARN');
    }
}

function cleanupLogs(): void
{
    $c = db();
    $cfg = relay_config();
    $days = (int)($cfg['log_retention_days'] ?? 14);
    $evDays = (int)($cfg['events_retention_days'] ?? 180);
    relay_q($c, 'DELETE FROM mqtt_messages WHERE ts < NOW() - INTERVAL ? DAY', [$days]);
    $n = mysqli_affected_rows($c);
    relay_q($c, 'DELETE FROM device_events WHERE ts < NOW() - INTERVAL ? DAY', [$evDays]);
    $n2 = mysqli_affected_rows($c);
    if ($n > 0 || $n2 > 0) {
        writeLog("очистка: сообщений $n (старше $days дн.), событий $n2 (старше $evDays дн.)");
    }
}

/**
 * Сообщения журнала брокера ($SYS/broker/log/N, нужен «log_dest topic» в mosquitto):
 * «Client relay-id12-v1 disconnected, not authorised.» — плата 12 не зарегистрирована.
 */
function handleBrokerLog(string $line): void
{
    static $lastIp = null;
    if (preg_match('/New connection from ([0-9a-fA-F:.\[\]]+?):\d+ on port/', $line, $m)) {
        $lastIp = $m[1];
        return;
    }
    if (!preg_match('/Client (relay-id([1-9]\d{0,9})-v\d+) disconnected, not authorised/', $line, $m)) {
        return;
    }
    $id = (int)$m[2];
    if ($id > 4294967295) {
        return;
    }
    $c = db();
    $blocked = relay_q($c, 'SELECT 1 FROM general_info WHERE id = ? AND mqtt_block_at IS NOT NULL', [$id]);
    if ($blocked && mysqli_num_rows($blocked) > 0) {
        return; // «не принимать сообщения» — отказ брокера ожидаем
    }
    $known = relay_q($c, 'SELECT 1 FROM general_info WHERE id = ?', [$id]);
    if ($known && mysqli_num_rows($known) > 0) {
        // устройство есть в панели, но брокер его не пускает — нет учётной записи MQTT: восстанавливаем
        // (не чаще раза в 10 минут на устройство)
        global $healQueue, $healedAt;
        if (!isset($healedAt[$id]) || time() - $healedAt[$id] > 600) {
            $healedAt[$id] = time();
            $healQueue[$id] = true; // восстановим пачкой в checkTimeouts — не задерживаем обработку сообщений
        }
        return; // устройство зарегистрировано — в «незарегистрированные» не добавляем
    }
    relay_q($c, 'INSERT INTO pending_devices (id, client_id, ip, attempts, first_seen, last_seen) VALUES (?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), ip = COALESCE(VALUES(ip), ip),
                                         attempts = attempts + 1, last_seen = NOW()', [$id, $m[1], $lastIp]);
    $lastIp = null;
}

function onMessage(array $broker, string $topic, string $message, bool $retained): void
{
    if (str_starts_with($topic, '$SYS/')) {
        if ((int)$broker['is_local'] === 1 && str_starts_with($topic, '$SYS/broker/log/')) {
            try { handleBrokerLog($message); } catch (Throwable $e) { writeLog('журнал брокера: ' . $e->getMessage(), 'ERROR'); }
        }
        return; // служебные сообщения брокера в журнал не пишем
    }
    $local = (int)$broker['is_local'] === 1;
    // Свои команды com/{id} журналирует отправитель; retained-копии при переподключении не дублируем
    if ($local && str_starts_with($topic, 'com/')) {
        return;
    }
    try {
        if (!$local && str_starts_with($topic, 'com/') && strlen($message) === 12) {
            // эхо нашей же команды, отправленной из монитора, повторно не журналируем
            $cmdId = unpack('V', $message)[1];
            $echo = relay_q(db(), "SELECT 1 FROM mqtt_messages WHERE broker_id = ? AND cmd_id = ? AND direction = 'out'
                                   AND topic = ? AND ts > NOW() - INTERVAL 5 MINUTE LIMIT 1", [(int)$broker['id'], $cmdId, $topic]);
            if ($echo && mysqli_num_rows($echo) > 0) {
                return;
            }
        }
        $decoded = relay_log_message(db(), (int)$broker['id'], 'in', $topic, $message, $retained);
        if (!$local || !$decoded) {
            return;
        }
        if ($decoded['type'] === 'stat') {
            handleStat($decoded, $retained);
        } elseif ($decoded['type'] === 'rep') {
            handleRep($decoded);
        }
    } catch (Throwable $e) {
        writeLog("ошибка обработки [{$broker['name']}] [$topic]: " . $e->getMessage(), 'ERROR');
    }
}

/* ------------------------------------------------------------------ */
/* Соединения с брокерами                                              */
/* ------------------------------------------------------------------ */

/** @var array<int, array{cfg: array, hash: string, client: ?MqttClient, retry_at: float, backoff: int}> */
$conns = [];

function setBrokerStatus(int $id, string $status, ?string $error = null): void
{
    $sql = $status === 'connected'
        ? 'UPDATE mqtt_brokers SET status = ?, last_error = NULL, last_connected_at = NOW(), status_at = NOW() WHERE id = ?'
        : 'UPDATE mqtt_brokers SET status = ?, last_error = ?, status_at = NOW() WHERE id = ?';
    $params = $status === 'connected' ? [$status, $id] : [$status, $error === null ? null : mb_substr($error, 0, 500), $id];
    // updated_at не трогаем, иначе подписчик сочтёт настройки изменёнными
    relay_q(db(), str_replace('WHERE id', ', updated_at = updated_at WHERE id', $sql), $params);
}

function disconnectBroker(int $id): void
{
    global $conns;
    if (!empty($conns[$id]['client'])) {
        try {
            $conns[$id]['client']->disconnect();
        } catch (Throwable) {
        }
    }
    $conns[$id]['client'] = null;
}

/* ------------------------------------------------------------------ */
/* Переадресация: сообщения брокера-источника по фильтру топиков        */
/* публикуются в брокер-получатель (таблица mqtt_forwards)              */
/* ------------------------------------------------------------------ */

/** @var array<int, array> правила по id */
$forwards = [];
/** @var array<string, float> недавно переадресованные сообщения «брокер|топик|md5» — защита от петель A→B→A */
$fwdRecent = [];
/** @var array<int, array{n: int, err: ?string, at: ?string}> счётчики для записи в БД */
$fwdStats = [];

function syncForwards(): void
{
    global $forwards, $fwdStats;
    // сохраняем накопленные счётчики
    foreach ($fwdStats as $rid => $st) {
        relay_q(db(), 'UPDATE mqtt_forwards SET fwd_count = fwd_count + ?, last_fwd_at = COALESCE(?, last_fwd_at), last_error = ?,
                       updated_at = updated_at WHERE id = ?', [$st['n'], $st['at'], $st['err'], $rid]);
    }
    $fwdStats = [];
    $res = @mysqli_query(db(), 'SELECT f.* FROM mqtt_forwards f JOIN mqtt_brokers s ON s.id = f.src_broker_id JOIN mqtt_brokers d ON d.id = f.dst_broker_id
                                 WHERE f.enabled = 1 AND s.enabled = 1 AND d.enabled = 1');
    $new = [];
    foreach (relay_rows($res ?: null) as $f) {
        $new[(int)$f['id']] = $f;
    }
    if (array_keys($new) !== array_keys($forwards)) {
        writeLog('переадресация: правил ' . count($new));
    }
    $forwards = $new;
}

/** Фильтры, которые нужно дополнительно подписать на брокере-источнике ради переадресации */
function forwardFilters(int $brokerId): array
{
    global $forwards;
    $out = [];
    foreach ($forwards as $f) {
        if ((int)$f['src_broker_id'] === $brokerId) {
            $out[] = (string)$f['topic_filter'];
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

function forwardMessage(int $srcId, string $topic, string $message, bool $retained): void
{
    global $forwards, $fwdRecent, $fwdStats, $conns;
    if (!$forwards || str_starts_with($topic, '$')) {
        return;
    }
    $now = microtime(true);
    $hash = md5($message);
    // это копия, которую мы сами только что переслали в этот брокер, — дальше не отправляем
    if (isset($fwdRecent["$srcId|$topic|$hash"]) && $now - $fwdRecent["$srcId|$topic|$hash"] < 10) {
        return;
    }
    foreach ($forwards as $rid => $f) {
        if ((int)$f['src_broker_id'] !== $srcId || !relay_topic_match((string)$f['topic_filter'], $topic)) {
            continue;
        }
        $dst = (int)$f['dst_broker_id'];
        $dstTopic = $f['dst_prefix'] . $topic;
        $st = &$fwdStats[$rid];
        $st ??= ['n' => 0, 'err' => null, 'at' => null];
        $client = $conns[$dst]['client'] ?? null;
        if ($client === null) {
            $st['err'] = 'брокер-получатель не подключён';
            unset($st);
            continue;
        }
        try {
            $fwdRecent["$dst|$dstTopic|$hash"] = $now;
            $client->publish($dstTopic, $message, 1, $retained && (int)$f['keep_retain'] === 1);
            $st['n']++;
            $st['at'] = date('Y-m-d H:i:s');
            $st['err'] = null;
        } catch (Throwable $e) {
            $st['err'] = mb_substr($e->getMessage(), 0, 500);
            writeLog("переадресация #$rid → «{$conns[$dst]['cfg']['name']}» $dstTopic: " . $e->getMessage(), 'ERROR');
        }
        unset($st);
    }
    if (count($fwdRecent) > 2000) {
        $fwdRecent = array_filter($fwdRecent, fn($t) => $now - $t < 10);
    }
}

/* ------------------------------------------------------------------ */
/* Симуляция устройств (таблица mqtt_simulations, Монитор → «Симуляция»): */
/* сервер публикует stat от имени устройства и отвечает на com/{id}     */
/* так же, как настоящая плата (прошивка v2.3 / ver_5_1)                */
/* ------------------------------------------------------------------ */

/** @var array<string, array> "брокер|устройство" → состояние симуляции */
$sims = [];

function simKey(int $broker, int $device): string
{
    return "$broker|$device";
}

/** Читает симуляции из БД: новые запускает, удалённые останавливает, изменённые в панели (dirty) — публикует. */
function syncSims(): void
{
    global $sims;
    $res = @mysqli_query(db(), 'SELECT s.* FROM mqtt_simulations s JOIN mqtt_brokers b ON b.id = s.broker_id WHERE b.enabled = 1');
    $seen = [];
    foreach (relay_rows($res ?: null) as $row) {
        $k = simKey((int)$row['broker_id'], (int)$row['device_id']);
        $seen[$k] = true;
        $fromDb = [
            'row_id' => (int)$row['id'], 'broker' => (int)$row['broker_id'], 'device' => (int)$row['device_id'],
            'version' => in_array((int)$row['version'], [1, 11, 2, 3], true) ? (int)$row['version'] : 2, 'interval' => max(5, (int)$row['interval_sec']),
            'sl' => (int)$row['signal_level'], 'bat' => (int)$row['bat_adc'],
        ];
        $relays = array_map('intval', explode(',', $row['relays'] . ',0,0,0,0'));
        if (!isset($sims[$k])) {
            $sims[$k] = $fromDb + ['relays' => array_slice($relays, 0, 4), 'lock' => (int)$row['lock_state'], 'door' => (int)$row['door_state'],
                                   'next_stat' => 0.0, 'reboot_until' => 0.0, 'restart' => [], 'last_cmd_id' => null];
            writeLog("симуляция: запущено устройство {$fromDb['device']} (брокер #{$fromDb['broker']})");
            if ((int)$row['dirty']) {
                relay_q(db(), 'UPDATE mqtt_simulations SET dirty = 0 WHERE id = ?', [$fromDb['row_id']]);
            }
            continue;
        }
        $sims[$k] = $fromDb + $sims[$k];
        if ((int)$row['dirty']) { // реле или дверь переключены вручную в панели — «как будто на месте»
            $sims[$k]['relays'] = array_slice($relays, 0, 4);
            $sims[$k]['lock'] = (int)$row['lock_state'];
            $sims[$k]['door'] = (int)$row['door_state'];
            $sims[$k]['next_stat'] = 0.0;
            relay_q(db(), 'UPDATE mqtt_simulations SET dirty = 0 WHERE id = ?', [$fromDb['row_id']]);
        }
    }
    foreach (array_keys($sims) as $k) {
        if (!isset($seen[$k])) {
            writeLog("симуляция: остановлено устройство {$sims[$k]['device']}");
            unset($sims[$k]);
        }
    }
}

/** Брокеру с симуляцией нужна подписка на команды com/+ */
function simFilters(int $brokerId): array
{
    global $sims;
    foreach ($sims as $s) {
        if ($s['broker'] === $brokerId) {
            return ['com/+'];
        }
    }
    return [];
}

function simPublish(array $sim, string $topic, string $payload, bool $retain): bool
{
    global $conns;
    $client = $conns[$sim['broker']]['client'] ?? null;
    if ($client === null) {
        return false;
    }
    try {
        $client->publish($topic, $payload, 1, $retain);
        return true;
    } catch (Throwable $e) {
        writeLog("симуляция {$sim['device']}: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

function simStat(string $k): void
{
    global $sims;
    $s = &$sims[$k];
    // небольшие колебания сигнала и АЦП АКБ, как у живой платы
    $sl = max(5, min(31, $s['sl'] + random_int(-2, 2)));
    $bat = max(0, $s['bat'] + random_int(-3, 3));
    // версия 1-1: пакет версии 1 (36 байт), но в поле сигнала — АЦП АКБ
    $p = $s['version'] === RELAY_V11
        ? pack('V*', $s['device'], 1, ...$s['relays']) . pack('V*', 1, $bat, 0)
        : pack('V*', $s['device'], $s['version'], ...$s['relays']) . pack('V*', 1, $sl, 0);
    if ($s['version'] >= 2 && $s['version'] !== RELAY_V11) {
        $p .= pack('V', $bat); // версия 1 (первоначальная прошивка) — 36 байт, без АКБ
    }
    if ($s['version'] === 3) {
        $p .= pack('V*', $s['lock'], $s['door']);
    }
    if (simPublish($s, 'stat', $p, true)) {
        relay_q(db(), 'UPDATE mqtt_simulations SET stat_count = stat_count + 1, last_stat_at = NOW() WHERE id = ?', [$s['row_id']]);
        $s['next_stat'] = microtime(true) + $s['interval'];
    } else {
        $s['next_stat'] = microtime(true) + 3; // брокер ещё не подключён — повторим
    }
    unset($s);
}

function simSave(string $k, string $label): void
{
    global $sims;
    $s = $sims[$k];
    relay_q(db(), 'UPDATE mqtt_simulations SET relays = ?, lock_state = ?, door_state = ?, cmd_count = cmd_count + 1, last_cmd_at = NOW(), last_cmd = ?
                    WHERE id = ? AND dirty = 0', [implode(',', $s['relays']), $s['lock'], $s['door'], mb_substr($label, 0, 120), $s['row_id']]);
}

/** Команда com/{id} симулируемому устройству: выполнить, ответить rep, опубликовать stat. true — команда для симуляции. */
function simCommand(int $brokerId, string $topic, string $message, bool $retained): bool
{
    global $sims;
    if (!preg_match('~^com/(\d+)$~', $topic, $m) || strlen($message) !== 12) {
        return false;
    }
    $k = simKey($brokerId, (int)$m[1]);
    if (!isset($sims[$k])) {
        return false;
    }
    $s = &$sims[$k];
    $cmdId = unpack('V', substr($message, 0, 4))[1];
    $cmd = unpack('V', substr($message, 4, 4))[1];
    $relay = unpack('V', substr($message, 8, 4))[1];
    $now = microtime(true);
    if ($now < $s['reboot_until'] || $cmdId === $s['last_cmd_id']) {
        unset($s);
        return true; // перезагружается или повтор той же команды
    }
    // как прошивка: retained-команды 3/5 и команды замку после подписки не выполняются
    if ($retained && ($cmd === 3 || $cmd === 5 || $relay === RELAY_LOCK)) {
        unset($s);
        return true;
    }
    $s['last_cmd_id'] = $cmdId;
    $ok = true;
    $targets = $relay === 0 ? [1, 2, 3, 4] : [$relay];
    if (in_array($cmd, [1, 2, 3], true) && $relay === RELAY_LOCK) {
        if ($s['version'] !== 3) {
            $ok = false; // у платы v2 замка нет
        } elseif ($cmd !== 3) {
            $s['lock'] = $cmd === 1 ? 1 : 0;
        }
    } elseif (in_array($cmd, [1, 2], true) && $relay <= 4) {
        foreach ($targets as $i) {
            $s['relays'][$i - 1] = $cmd === 1 ? 1 : 0;
        }
    } elseif ($cmd === 3 && $relay <= 4) {
        foreach ($targets as $i) { // выкл → через 1 с вкл
            $s['relays'][$i - 1] = 0;
            $s['restart'][$i] = $now + 1.0;
        }
    } elseif ($cmd === 5) {
        $s['reboot_until'] = $now + 15; // плата перезагружается ~15 с и снова публикует stat
    } elseif ($cmd !== 4) {
        $ok = false;
    }
    simPublish($s, 'rep', pack('VVV', $s['device'], $cmdId, $ok ? 1 : 2), false);
    $label = relay_command_label($cmd, $relay) . ($ok ? '' : ' — отклонена');
    unset($s);
    simSave($k, $label);
    writeLog("симуляция {$sims[$k]['device']}: $label (cmd_id=$cmdId)");
    if ($cmd !== 5) {
        simStat($k);
    }
    return true;
}

/** Таймеры симуляций: периодический stat, окончание перезапуска реле и перезагрузки. */
function simTick(): void
{
    global $sims;
    $now = microtime(true);
    foreach (array_keys($sims) as $k) {
        $s = &$sims[$k];
        if ($s['reboot_until'] > 0 && $now >= $s['reboot_until']) {
            $s['reboot_until'] = 0.0;
            $s['next_stat'] = 0.0;
        }
        $changed = false;
        foreach ($s['restart'] as $i => $at) {
            if ($now >= $at) {
                $s['relays'][$i - 1] = 1;
                unset($s['restart'][$i]);
                $changed = true;
            }
        }
        $due = $s['reboot_until'] === 0.0 && ($changed || $now >= $s['next_stat']);
        unset($s);
        if ($changed) {
            simSave($k, 'перезапуск реле завершён');
        }
        if ($due) {
            simStat($k);
        }
    }
}

function syncBrokers(): void
{
    global $conns;
    syncForwards();
    syncSims();
    $rows = relay_rows(relay_q(db(), 'SELECT * FROM mqtt_brokers'));
    $seen = [];
    foreach ($rows as $b) {
        $id = (int)$b['id'];
        $seen[$id] = true;
        // при изменении фильтров переадресации брокер переподключается с новыми подписками
        $hash = md5(json_encode([$b['host'], $b['port'], $b['use_tls'], $b['tls_verify'], $b['username'], $b['password'], $b['topics'], $b['enabled'],
                                 // итоговые подписки: если «#» уже есть, фильтры переадресации и симуляции переподключения не требуют
                                 relay_merge_filters(array_merge(relay_parse_topics((string)$b['topics'] ?: '#')[0], forwardFilters($id), simFilters($id)))]));
        if (!(int)$b['enabled']) {
            if (isset($conns[$id])) {
                disconnectBroker($id);
                unset($conns[$id]);
                writeLog("брокер «{$b['name']}» отключён");
            }
            if ($b['status'] !== 'disabled') {
                setBrokerStatus($id, 'disabled');
            }
            continue;
        }
        if (isset($conns[$id]) && $conns[$id]['hash'] === $hash) {
            $conns[$id]['cfg'] = $b; // имя могло измениться
            continue;
        }
        if (isset($conns[$id])) {
            writeLog("брокер «{$b['name']}»: настройки изменены, переподключение");
            disconnectBroker($id);
        }
        $conns[$id] = ['cfg' => $b, 'hash' => $hash, 'client' => null, 'retry_at' => 0.0, 'backoff' => 2];
        setBrokerStatus($id, 'connecting');
    }
    foreach (array_keys($conns) as $id) {
        if (!isset($seen[$id])) {
            disconnectBroker($id);
            unset($conns[$id]);
            writeLog("брокер #$id удалён");
        }
    }
}

function connectBroker(int $id): void
{
    global $conns;
    $b = $conns[$id]['cfg'];
    $p = relay_broker_params($b);
    try {
        $client = relay_mqtt_client($b, 'relay_server_' . $id . '_' . substr(md5(gethostname() . __DIR__), 0, 8), (int)$b['is_local'] ? 10 : 5);
        [$topics, $bad] = relay_parse_topics((string)$b['topics'] ?: '#');
        if ($bad) {
            // неверный фильтр брокер не принимает и разрывает соединение — пропускаем его
            writeLog("«{$b['name']}»: пропущены неверные подписки: " . implode('; ', $bad), 'WARN');
        }
        if (!$topics) {
            throw new RuntimeException('нет ни одной верной подписки: ' . implode('; ', $bad));
        }
        if ((int)$b['is_local'] === 1) {
            $topics[] = '$SYS/broker/log/N'; // попытки подключения незарегистрированных плат
        }
        // свои подписки брокера (журнал и обработка) + фильтры переадресации (только пересылка)
        $own = $topics;
        $topics = relay_merge_filters(array_merge($topics, forwardFilters($id), simFilters($id)));
        foreach ($topics as $t) {
            $client->subscribe($t, function ($topic, $message, $retained) use ($id, $t, $own) {
                global $conns;
                // php-mqtt сопоставляет «#» и с $SYS/… (по стандарту MQTT — не должно), из-за чего
                // служебное сообщение пришло бы дважды: $SYS принимаем только от подписки на $SYS
                if (str_starts_with($topic, '$') !== str_starts_with($t, '$')) {
                    return;
                }
                try { // команда симулируемому устройству: ответить как плата (и дальше — обычная обработка)
                    simCommand($id, $topic, $message, (bool)$retained);
                } catch (Throwable $e) {
                    writeLog('симуляция: ' . $e->getMessage(), 'ERROR');
                }
                foreach ($own as $o) {
                    if (relay_topic_match($o, $topic)) {
                        onMessage($conns[$id]['cfg'], $topic, $message, (bool)$retained);
                        break;
                    }
                }
                try {
                    forwardMessage($id, $topic, $message, (bool)$retained);
                } catch (Throwable $e) {
                    writeLog('переадресация: ' . $e->getMessage(), 'ERROR');
                }
            }, 1);
        }
        $conns[$id]['client'] = $client;
        $conns[$id]['backoff'] = 2;
        setBrokerStatus($id, 'connected');
        writeLog("подключено: «{$b['name']}» {$p['host']}:{$p['port']} (" . implode(', ', $topics) . ')');
        if ($bad) {
            setBrokerStatus($id, 'connected');
            relay_q(db(), 'UPDATE mqtt_brokers SET last_error = ?, updated_at = updated_at WHERE id = ?',
                ['Пропущены неверные подписки: ' . implode('; ', $bad), $id]);
        }
    } catch (Throwable $e) {
        $conns[$id]['client'] = null;
        $conns[$id]['retry_at'] = microtime(true) + $conns[$id]['backoff'];
        $conns[$id]['backoff'] = min(60, $conns[$id]['backoff'] * 2);
        setBrokerStatus($id, 'error', $e->getMessage());
        writeLog("«{$b['name']}» {$p['host']}:{$p['port']}: " . $e->getMessage() . " (повтор через {$conns[$id]['backoff']} с)", 'ERROR');
    }
}

/* ------------------------------------------------------------------ */
/* Главный цикл                                                        */
/* ------------------------------------------------------------------ */

relay_config(); // часовой пояс — до первой строки лога
writeLog('=== MQTT подписчик v' . RELAY_VERSION . ' запущен (PID ' . getmypid() . ') ===');

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
    pcntl_signal(SIGINT, function () use (&$stop) { $stop = true; });
}

$lastSync = $lastTimeout = $lastCleanup = $lastSims = 0.0;
$started = microtime(true);
while (!$stop) {
    $now = microtime(true);
    try {
        if ($now - $lastSync >= 10) {
            syncBrokers();
            $lastSync = $lastSims = $now;
        } elseif ($now - $lastSims >= 2) {
            syncSims(); // симуляции подхватываются быстрее, чем настройки брокеров
            $lastSims = $now;
        }
        simTick();
        foreach (array_keys($conns) as $id) {
            if ($conns[$id]['client'] === null) {
                if ($now >= $conns[$id]['retry_at']) {
                    connectBroker($id);
                }
                continue;
            }
            try {
                $conns[$id]['client']->loopOnce($started);
            } catch (Throwable $e) {
                $name = $conns[$id]['cfg']['name'];
                writeLog("«{$name}»: соединение потеряно: " . $e->getMessage(), 'ERROR');
                disconnectBroker($id);
                $conns[$id]['retry_at'] = microtime(true) + 2;
                setBrokerStatus($id, 'error', $e->getMessage());
            }
        }
        if ($now - $lastTimeout >= 30 && $now - $started > 60) {
            checkTimeouts();
            $lastTimeout = $now;
        }
        if ($now - $lastCleanup >= 3600) {
            cleanupLogs();
            $lastCleanup = $now;
        }
    } catch (Throwable $e) {
        writeLog('ошибка главного цикла: ' . $e->getMessage(), 'ERROR');
        sleep(2);
    }
    usleep(20000);
}
foreach (array_keys($conns) as $id) {
    disconnectBroker($id);
}
writeLog('остановлен');
