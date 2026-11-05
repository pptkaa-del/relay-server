<?php
/**
 * Журналы платы.
 *   GET ?id=N&kind=events|messages|commands[&before=ID][&from=YYYY-MM-DD][&to=YYYY-MM-DD][&limit=100][&format=csv]
 * Постраничный вывод — от новых к старым (before = id последней показанной записи).
 */
require __DIR__ . '/../conectbd.php';

$id = (int)($_GET['id'] ?? 0);
$kind = $_GET['kind'] ?? 'events';
$before = (int)($_GET['before'] ?? 0);
$csv = ($_GET['format'] ?? '') === 'csv';
$limit = $csv ? 50000 : min(500, max(1, (int)($_GET['limit'] ?? 100)));
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] . ' 00:00:00' : null;
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] . ' 23:59:59' : null;

if ($id <= 0) {
    relay_json(['error' => 'Не указана плата'], 400);
}

$where = [];
$params = [];
$add = function (string $cond, $val) use (&$where, &$params) { $where[] = $cond; $params[] = $val; };

switch ($kind) {
    case 'events':
        $add('device_id = ?', $id);
        $before && $add('id < ?', $before);
        $from && $add('ts >= ?', $from);
        $to && $add('ts <= ?', $to);
        $sql = 'SELECT id, ts, type, level, message, user_login FROM device_events WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT $limit";
        $cols = ['ts' => 'Время', 'type' => 'Тип', 'level' => 'Уровень', 'message' => 'Событие', 'user_login' => 'Пользователь'];
        break;
    case 'messages':
        $add('broker_id = ?', RELAY_LOCAL_BROKER);
        $add('device_id = ?', $id);
        $before && $add('id < ?', $before);
        $from && $add('ts >= ?', $from);
        $to && $add('ts <= ?', $to);
        $sql = 'SELECT id, ts, direction, topic, decoded, retained, payload_hex, user_login FROM mqtt_messages WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT $limit";
        $cols = ['ts' => 'Время', 'direction' => 'Направление', 'topic' => 'Топик', 'decoded' => 'Данные', 'payload_hex' => 'HEX', 'user_login' => 'Пользователь'];
        break;
    case 'commands':
        $add('device_id = ?', $id);
        $before && $add('cmd_id < ?', $before);
        $from && $add('created_at >= ?', $from);
        $to && $add('created_at <= ?', $to);
        $sql = 'SELECT cmd_id AS id, created_at AS ts, command, relay_num, status, sent_at, confirmed_at FROM commands WHERE ' . implode(' AND ', $where) . " ORDER BY cmd_id DESC LIMIT $limit";
        $cols = ['id' => 'cmd_id', 'ts' => 'Создана', 'command' => 'Команда', 'status' => 'Статус', 'sent_at' => 'Отправлена', 'confirmed_at' => 'Ответ платы'];
        break;
    default:
        relay_json(['error' => 'Неизвестный журнал'], 400);
}

$rows = relay_rows(relay_q($connect, $sql, $params));
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    if ($kind === 'messages') {
        $r['decoded'] = $r['decoded'] ? json_decode($r['decoded'], true) : null;
        $r['retained'] = (bool)$r['retained'];
    }
    if ($kind === 'commands') {
        $r['label'] = relay_command_label((int)$r['command'], (int)$r['relay_num']);
    }
}
unset($r);

if ($csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"device-$id-$kind-" . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, чтобы Excel открыл UTF-8
    fputcsv($out, array_values($cols), ';');
    foreach ($rows as $r) {
        $line = [];
        foreach (array_keys($cols) as $k) {
            $v = $k === 'command' ? ($r['label'] ?? $r[$k]) : ($r[$k] ?? '');
            $line[] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
        }
        fputcsv($out, $line, ';');
    }
    exit;
}

relay_json(['rows' => $rows, 'more' => count($rows) === $limit]);
