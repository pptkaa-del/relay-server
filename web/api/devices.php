<?php
/**
 * API устройств собственного брокера.
 *   GET  ?action=list                 — все платы + сводка
 *   GET  ?action=get&id=N             — одна плата (подробно)
 *   POST action=update id, name, address, coordinates, relay1..relay4 — названия и адрес
 *   POST action=command id, command, relay — команда плате (1..5)
 *   GET  ?action=command_status&cmd_id=N
 *   POST action=delete id             — убрать плату из панели (история сообщений сохраняется)
 */
require __DIR__ . '/../conectbd.php';

$action = $_REQUEST['action'] ?? 'list';
if (in_array($action, ['update', 'command', 'delete'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}

function device_rows(mysqli $db, ?int $onlyId = null): array
{
    $where = $onlyId ? 'WHERE x.id = ' . (int)$onlyId : '';
    $rows = relay_rows(mysqli_query($db, "
        SELECT x.id,
               COALESCE(NULLIF(g.name_obj, ''), d.name, CONCAT('Устройство ', x.id)) AS name,
               COALESCE(NULLIF(g.address, ''), d.location, '') AS address,
               COALESCE(g.coordinates, '') AS coordinates,
               g.name_reley_1, g.name_reley_2, g.name_reley_3, g.name_reley_4,
               d.status, d.version, d.signal_level, d.battery_power AS alarm, d.conn_state, d.msg_count,
               d.last_seen, UNIX_TIMESTAMP(d.last_seen) AS last_seen_ts,
               d.last_data_at, UNIX_TIMESTAMP(d.last_data_at) AS last_data_ts, d.last_data_topic,
               d.created_at, (d.id IS NOT NULL) AS registered
          FROM (SELECT id FROM general_info UNION SELECT id FROM devices) x
          LEFT JOIN general_info g ON g.id = x.id
          LEFT JOIN devices d ON d.id = x.id
          $where
         ORDER BY x.id"));
    $relays = [];
    foreach (relay_rows(mysqli_query($db, 'SELECT device_id, relay_num, state, UNIX_TIMESTAMP(updated_at) AS ts FROM relay_states'
             . ($onlyId ? ' WHERE device_id = ' . (int)$onlyId : ''))) as $r) {
        $relays[(int)$r['device_id']][(int)$r['relay_num']] = ['state' => (int)$r['state'], 'ts' => (int)$r['ts']];
    }
    $now = time();
    foreach ($rows as &$d) {
        $id = (int)$d['id'];
        $d['id'] = $id;
        $d['relays'] = [];
        for ($i = 1; $i <= 4; $i++) {
            $d['relays'][] = ['num' => $i, 'name' => ($d["name_reley_$i"] ?? '') ?: "Реле $i",
                              'state' => $relays[$id][$i]['state'] ?? null, 'ts' => $relays[$id][$i]['ts'] ?? null];
            unset($d["name_reley_$i"]);
        }
        foreach (['status', 'version', 'signal_level', 'alarm', 'msg_count', 'last_seen_ts', 'last_data_ts'] as $k) {
            $d[$k] = $d[$k] === null ? null : (int)$d[$k];
        }
        $d['registered'] = (bool)$d['registered'];
        $fresh = $d['last_seen_ts'] !== null && $now - $d['last_seen_ts'] <= RELAY_OFFLINE_AFTER;
        $d['online'] = $fresh && $d['status'] !== 0;
        $d['state'] = $d['last_seen_ts'] === null ? 'never'
            : (!$d['online'] ? 'offline' : ($d['status'] === 2 ? 'error' : ($d['alarm'] === 1 ? 'alarm' : 'online')));
    }
    return $rows;
}

switch ($action) {
    case 'list':
        $devices = device_rows($connect);
        $sum = ['total' => count($devices), 'online' => 0, 'offline' => 0, 'alarm' => 0, 'never' => 0];
        foreach ($devices as $d) {
            if ($d['state'] === 'never') $sum['never']++;
            elseif ($d['online']) $sum['online']++;
            else $sum['offline']++;
            if ($d['online'] && $d['alarm'] === 1) $sum['alarm']++;
        }
        $last = mysqli_fetch_assoc(mysqli_query($connect, "SELECT MAX(ts) AS ts, UNIX_TIMESTAMP(MAX(ts)) AS t FROM mqtt_messages WHERE broker_id = 1 AND direction = 'in'"));
        relay_json(['devices' => $devices, 'summary' => $sum, 'last_message_ts' => $last['t'] ? (int)$last['t'] : null, 'now' => time()]);

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        $rows = device_rows($connect, $id);
        if (!$rows) {
            relay_json(['error' => 'Плата не найдена'], 404);
        }
        $d = $rows[0];
        // последнее поступление данных: последнее входящее сообщение платы
        $m = mysqli_fetch_assoc(relay_q($connect,
            "SELECT id, ts, UNIX_TIMESTAMP(ts) AS t, topic, decoded, retained, payload_hex FROM mqtt_messages
              WHERE broker_id = 1 AND device_id = ? AND direction = 'in' ORDER BY id DESC LIMIT 1", [$id]));
        if ($m) {
            $m['t'] = (int)$m['t'];
            $m['retained'] = (bool)$m['retained'];
            $m['decoded'] = $m['decoded'] ? json_decode($m['decoded'], true) : null;
        }
        $d['last_message'] = $m ?: null;
        $d['last_command'] = mysqli_fetch_assoc(relay_q($connect,
            'SELECT cmd_id, command, relay_num, status, created_at, confirmed_at FROM commands WHERE device_id = ? AND command <> 4 ORDER BY cmd_id DESC LIMIT 1', [$id])) ?: null;
        $d['stats'] = mysqli_fetch_assoc(relay_q($connect,
            "SELECT SUM(ts > NOW() - INTERVAL 1 DAY) AS day, SUM(ts > NOW() - INTERVAL 1 HOUR) AS hour, COUNT(*) AS kept
               FROM mqtt_messages WHERE broker_id = 1 AND device_id = ? AND direction = 'in'", [$id]));
        relay_json(['device' => $d, 'now' => time()]);

    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            relay_json(['error' => 'Не указана плата'], 400);
        }
        $f = fn($k, $max = 200) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $name = $f('name') ?: "Устройство $id";
        relay_q($connect, 'INSERT INTO general_info (id, name_obj, address, coordinates, name_reley_1, name_reley_2, name_reley_3, name_reley_4)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE name_obj = VALUES(name_obj), address = VALUES(address), coordinates = VALUES(coordinates),
                             name_reley_1 = VALUES(name_reley_1), name_reley_2 = VALUES(name_reley_2),
                             name_reley_3 = VALUES(name_reley_3), name_reley_4 = VALUES(name_reley_4)',
            [$id, $name, $f('address', 300), $f('coordinates', 100),
             $f('relay1', 60) ?: 'Реле 1', $f('relay2', 60) ?: 'Реле 2', $f('relay3', 60) ?: 'Реле 3', $f('relay4', 60) ?: 'Реле 4']);
        relay_q($connect, 'UPDATE devices SET name = ?, location = ? WHERE id = ?', [$name, $f('address', 100), $id]);
        relay_event($connect, $id, 'info', 'Изменены название/адрес/подписи реле', 'info', null, $login);
        relay_json(['ok' => true]);

    case 'command':
        try {
            $cmdId = relay_send_command($connect, (int)($_POST['id'] ?? 0), (int)($_POST['command'] ?? 0), (int)($_POST['relay'] ?? 0), $login);
            relay_json(['ok' => true, 'cmd_id' => $cmdId]);
        } catch (RuntimeException $e) {
            relay_json(['error' => $e->getMessage()], 400);
        }

    case 'command_status':
        relay_json(['status' => relay_command_status($connect, (int)($_GET['cmd_id'] ?? 0))]);

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        relay_q($connect, 'DELETE FROM devices WHERE id = ?', [$id]);      // каскадом: relay_states, commands, device_last_update
        relay_q($connect, 'DELETE FROM general_info WHERE id = ?', [$id]);
        relay_q($connect, 'DELETE FROM device_events WHERE device_id = ?', [$id]);
        relay_json(['ok' => true, 'note' => "Доступ платы к брокеру закрывается командой: sudo relay-device del $id"]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
