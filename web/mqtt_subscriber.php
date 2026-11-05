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

    $current = mysqli_fetch_assoc(relay_q($c, 'SELECT status, name, battery_power, conn_state FROM devices WHERE id = ?', [$id]));

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
        relay_q($c, 'DELETE FROM pending_devices WHERE id = ?', [$id]);
        relay_event($c, $id, 'created', 'Плата впервые вышла на связь', 'success', $d);
        writeLog("STAT: создано новое устройство $id");
        return;
    }

    relay_q($c, 'UPDATE devices SET last_data_at = NOW(), last_data_topic = ?, msg_count = msg_count + 1 WHERE id = ?', ['stat', $id]);
    if (isset($d['bat_adc'])) {
        relay_q($c, 'UPDATE devices SET bat_adc = ?, bat_adc_at = NOW() WHERE id = ?', [(int)$d['bat_adc'], $id]);
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
    for ($i = 1; $i <= 4; $i++) {
        if ($cur[$i] !== $r[$i]) {
            $changes[] = "relay_$i"; $prev[] = $cur[$i]; $new[] = $r[$i];
            $relayText[] = "реле $i: " . (STATE_NAMES[$cur[$i]] ?? $cur[$i]) . ' → ' . (STATE_NAMES[$r[$i]] ?? $r[$i]);
        }
    }
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

    // Авария «питание от АКБ»
    $wasAlarm = (int)$current['battery_power'];
    if ($alarm !== $wasAlarm) {
        $alarm === 1
            ? relay_event($c, $id, 'alarm', 'Авария: питание от АКБ', 'error')
            : relay_event($c, $id, 'alarm_clear', 'Основное питание восстановлено', 'success');
    }

    // Связь
    $newConn = $stat === 0 ? 'offline' : 'online';
    if ($current['conn_state'] !== $newConn) {
        $newConn === 'online'
            ? relay_event($c, $id, 'online', 'Плата на связи (сигнал ' . $sl . ')', 'success')
            : relay_event($c, $id, 'offline', 'Плата сообщила о переходе в оффлайн (stat = 0)', 'warning');
    }
    if ($stat === 2 && (int)$current['status'] !== 2) {
        relay_event($c, $id, 'info', 'Плата сообщила об ошибке (stat = 2)', 'error');
    }

    relay_q($c, 'UPDATE devices SET last_seen = ?, status = ?, version = ?, signal_level = ?, battery_power = ?, conn_state = ? WHERE id = ?',
        [$ts, $stat, $version, $sl, $alarm, $newConn, $id]);
    for ($i = 1; $i <= 4; $i++) {
        relay_q($c, 'INSERT INTO relay_states (device_id, relay_num, state, updated_at) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = VALUES(updated_at)', [$id, $i, $r[$i], $ts]);
    }
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
        $label = relay_command_label((int)$cmd['command'], (int)$cmd['relay_num']);
        if ((int)$cmd['command'] === 5) {
            relay_event($c, $id, 'reboot', $ok ? 'Плата приняла команду перезагрузки и перезапускается' : 'Плата отклонила перезагрузку', $ok ? 'warning' : 'error', ['cmd_id' => $cmdId]);
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
function checkTimeouts(): void
{
    $c = db();
    $rows = relay_rows(relay_q($c, "SELECT id, last_seen FROM devices
                                    WHERE conn_state = 'online' AND last_seen < NOW() - INTERVAL ? SECOND", [RELAY_OFFLINE_AFTER]));
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        relay_q($c, "UPDATE devices SET conn_state = 'offline' WHERE id = ?", [$id]);
        relay_event($c, $id, 'offline', 'Нет связи: данных от платы нет с ' . date('d.m H:i:s', strtotime($row['last_seen'])), 'warning');
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
    $known = relay_q($c, 'SELECT 1 FROM general_info WHERE id = ?', [$id]);
    if ($known && mysqli_num_rows($known) > 0) {
        // плата есть в панели, но брокер её не пускает — скорее всего, нет учётной записи MQTT
        static $warned = [];
        if (!isset($warned[$id]) || time() - $warned[$id] > 3600) {
            $warned[$id] = time();
            relay_event($c, $id, 'info', 'Брокер не пускает плату (not authorised). Проверьте ID в плате или выполните: sudo relay-device add ' . $id, 'error');
        }
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

function syncBrokers(): void
{
    global $conns;
    $rows = relay_rows(relay_q(db(), 'SELECT * FROM mqtt_brokers'));
    $seen = [];
    foreach ($rows as $b) {
        $id = (int)$b['id'];
        $seen[$id] = true;
        $hash = md5(json_encode([$b['host'], $b['port'], $b['use_tls'], $b['tls_verify'], $b['username'], $b['password'], $b['topics'], $b['enabled']]));
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
        foreach ($topics as $t) {
            $client->subscribe($t, function ($topic, $message, $retained) use ($id, $t) {
                global $conns;
                // php-mqtt сопоставляет «#» и с $SYS/… (по стандарту MQTT — не должно), из-за чего
                // служебное сообщение пришло бы дважды: $SYS принимаем только от подписки на $SYS
                if (str_starts_with($topic, '$') !== str_starts_with($t, '$')) {
                    return;
                }
                onMessage($conns[$id]['cfg'], $topic, $message, (bool)$retained);
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

$lastSync = $lastTimeout = $lastCleanup = 0.0;
$started = microtime(true);
while (!$stop) {
    $now = microtime(true);
    try {
        if ($now - $lastSync >= 10) {
            syncBrokers();
            $lastSync = $now;
        }
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
