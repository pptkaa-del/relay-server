<?php
/**
 * JSON API страницы «Монитор». Все запросы принимают broker=ID (по умолчанию 1 — собственный брокер).
 *   GET  ?action=messages[&after=ID][&before=ID][&device=N][&topic=...]  — журнал сообщений
 *   GET  ?action=devices                     — платы брокера по последним stat
 *   POST action=command  device, command, relay
 *   GET  ?action=command_status&cmd_id=N
 *   POST action=publish  topic, payload, format(text|hex), retain
 */
require __DIR__ . '/../conectbd.php';

$action = $_REQUEST['action'] ?? '';
$brokerId = max(1, (int)($_REQUEST['broker'] ?? RELAY_LOCAL_BROKER));
if (in_array($action, ['command', 'publish'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}
$broker = relay_broker($connect, $brokerId);
if (!$broker) {
    relay_json(['error' => 'Брокер не найден'], 404);
}

switch ($action) {
    case 'messages':
        $after = max(0, (int)($_GET['after'] ?? 0));
        $before = max(0, (int)($_GET['before'] ?? 0));
        $device = (int)($_GET['device'] ?? 0);
        $topic = trim((string)($_GET['topic'] ?? ''));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 200)));
        $where = ['broker_id = ?'];
        $params = [$brokerId];
        if ($after) { $where[] = 'id > ?'; $params[] = $after; }
        if ($before) { $where[] = 'id < ?'; $params[] = $before; }
        if ($device) { $where[] = 'device_id = ?'; $params[] = $device; }
        if ($topic !== '') { $where[] = 'topic LIKE ?'; $params[] = str_replace(['%', '_', '*'], ['\%', '\_', '%'], $topic); }
        $msgs = relay_rows(relay_q($connect,
            'SELECT id, ts, direction, topic, device_id, payload_hex, decoded, retained, user_login FROM mqtt_messages WHERE '
            . implode(' AND ', $where) . " ORDER BY id DESC LIMIT $limit", $params));
        foreach ($msgs as &$m) {
            $m['id'] = (int)$m['id'];
            $m['device_id'] = $m['device_id'] === null ? null : (int)$m['device_id'];
            $m['retained'] = (bool)$m['retained'];
            $m['decoded'] = $m['decoded'] === null ? null : json_decode($m['decoded'], true);
        }
        relay_json(['messages' => $msgs, 'broker' => [
            'id' => (int)$broker['id'], 'status' => $broker['status'], 'last_error' => $broker['last_error'],
        ]]);

    case 'devices':
        // Платы, приславшие stat в этот брокер: последнее состояние и время
        $rows = relay_rows(relay_q($connect,
            "SELECT m.device_id AS id, m.decoded, UNIX_TIMESTAMP(m.ts) AS ts, g.name_obj
               FROM mqtt_messages m
               JOIN (SELECT device_id, MAX(id) AS mid FROM mqtt_messages
                      WHERE broker_id = ? AND topic = 'stat' AND device_id IS NOT NULL GROUP BY device_id) x ON x.mid = m.id
               LEFT JOIN general_info g ON g.id = m.device_id AND ? = 1
              ORDER BY m.device_id", [$brokerId, (int)$broker['is_local']]));
        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode($r['decoded'], true) ?: [];
            $fresh = $now - (int)$r['ts'] <= RELAY_OFFLINE_AFTER;
            $out[] = [
                'id' => (int)$r['id'],
                'name' => $r['name_obj'] ?: 'Плата ' . $r['id'],
                'ts' => (int)$r['ts'],
                'relays' => [$d['r1'] ?? null, $d['r2'] ?? null, $d['r3'] ?? null, $d['r4'] ?? null],
                'signal' => $d['sl'] ?? null,
                'alarm' => $d['alarm'] ?? 0,
                'state' => !$fresh || ($d['stat'] ?? 1) === 0 ? 'offline' : (($d['stat'] ?? 1) === 2 ? 'error' : 'online'),
            ];
        }
        relay_json(['devices' => $out]);

    case 'command':
        try {
            $cmdId = relay_send_command($connect, (int)($_POST['device'] ?? 0), (int)($_POST['command'] ?? 0),
                (int)($_POST['relay'] ?? 0), $login, $brokerId);
            relay_json(['ok' => true, 'cmd_id' => $cmdId]);
        } catch (RuntimeException $e) {
            relay_json(['error' => $e->getMessage()], 400);
        }

    case 'command_status':
        relay_json(['status' => relay_command_status($connect, (int)($_GET['cmd_id'] ?? 0), $brokerId)]);

    case 'publish':
        $topic = trim((string)($_POST['topic'] ?? ''));
        $raw = (string)($_POST['payload'] ?? '');
        $retain = !empty($_POST['retain']);
        if ($topic === '' || strlen($topic) > 255 || strpbrk($topic, "#+\0") !== false) {
            relay_json(['error' => 'Недопустимый топик (без # и +)'], 400);
        }
        if (($_POST['format'] ?? 'text') === 'hex') {
            $hex = preg_replace('/[^0-9a-fA-F]/', '', $raw);
            if (strlen($hex) % 2 !== 0) {
                relay_json(['error' => 'HEX должен содержать чётное число символов'], 400);
            }
            $payload = (string)hex2bin($hex);
        } else {
            $payload = $raw;
        }
        try {
            relay_publish($broker, $topic, $payload, 1, $retain);
        } catch (Throwable $e) {
            relay_json(['error' => 'Ошибка MQTT: ' . $e->getMessage()], 502);
        }
        relay_log_message($connect, $brokerId, 'out', $topic, $payload, $retain, null, $login);
        relay_json(['ok' => true]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
