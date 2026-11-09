<?php
/**
 * Журналы платы.
 *   GET ?id=N&kind=events|relays|messages|commands[&before=ID][&from=YYYY-MM-DD][&to=YYYY-MM-DD][&limit=20|…|5000][&format=csv]
 *   relays — изменения состояния реле: строка на каждое реле (время, реле, было, стало); реле 4 — замок: «дверь открыта/закрыта»
 * Постраничный вывод — от новых к старым (before = id последней показанной записи).
 */
require __DIR__ . '/../conectbd.php';

$id = (int)($_GET['id'] ?? 0);
$kind = $_GET['kind'] ?? 'events';
$before = (int)($_GET['before'] ?? 0);
$csv = ($_GET['format'] ?? '') === 'csv';
$limit = $csv ? 50000 : min(5000, max(1, (int)($_GET['limit'] ?? 100))); // 5000 — «все»
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] . ' 00:00:00' : null;
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] . ' 23:59:59' : null;

if ($id <= 0) {
    relay_json(['error' => 'Не указано устройство'], 400);
}
// пользователь видит журналы только своих устройств; MQTT-сообщения (в них все реле) — только администратор
if (!relay_can_view($id)) {
    relay_forbidden('Нет доступа к этому устройству');
}
if ($kind === 'messages' && !relay_is_admin()) {
    relay_forbidden('Журнал MQTT-сообщений доступен только администратору');
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
    case 'relays':
        $add('device_id = ?', $id);
        $add('type = ?', 'relay');
        $before && $add('id < ?', $before);
        $from && $add('ts >= ?', $from);
        $to && $add('ts <= ?', $to);
        $sql = 'SELECT id, ts, message, data FROM device_events WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT $limit";
        $cols = ['ts' => 'Время', 'relay' => 'Реле', 'name' => 'Название', 'from' => 'Было', 'to' => 'Стало'];
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
        if (!relay_is_admin()) { // только команды своим реле, запросы статуса и перезагрузки
            $mine = array_map('intval', array_keys(relay_user_perms()[$id] ?? []));
            $where[] = '(command IN (4, 5) OR relay_num IN (' . ($mine ? implode(',', $mine) : '-1') . '))';
        }
        $from && $add('created_at >= ?', $from);
        $to && $add('created_at <= ?', $to);
        $sql = 'SELECT cmd_id AS id, created_at AS ts, command, relay_num, status, sent_at, confirmed_at FROM commands WHERE ' . implode(' AND ', $where) . " ORDER BY cmd_id DESC LIMIT $limit";
        $cols = ['id' => 'cmd_id', 'ts' => 'Создана', 'command' => 'Команда', 'status' => 'Статус', 'sent_at' => 'Отправлена', 'confirmed_at' => 'Ответ устройства'];
        break;
    default:
        relay_json(['error' => 'Неизвестный журнал'], 400);
}

$rows = relay_rows(relay_q($connect, $sql, $params));
$more = count($rows) === $limit;
if ($kind === 'relays') {
    // событие «реле» содержит состояния до и после (data.before / data.after) — разворачиваем в строку на каждое реле.
    // Лимит — по строкам изменений; одно событие (несколько реле сразу) между страницами не делится.
    $g = mysqli_fetch_assoc(relay_q($connect, 'SELECT name_reley_1, name_reley_2, name_reley_3, name_reley_4, COALESCE(relay4_lock, 0) AS relay4_lock
                                                  FROM general_info WHERE id = ?', [$id])) ?: [];
    $lock4 = (int)($g['relay4_lock'] ?? 0) === 1;
    $mine = relay_is_admin() ? null : (relay_user_perms()[$id] ?? []);
    $state = fn(int $n, $v) => $v === null ? '—' : ($n === 4 && $lock4
        ? ([0 => 'дверь открыта', 1 => 'дверь закрыта', 2 => 'ошибка'][(int)$v] ?? (string)$v)
        : ([0 => 'выкл', 1 => 'вкл', 2 => 'ошибка'][(int)$v] ?? (string)$v));
    $expand = function (array $r) use ($g, $lock4, $mine, $state): array {
        $d = $r['data'] ? json_decode($r['data'], true) : null;
        $b = $d['before'] ?? null;
        $a = $d['after'] ?? null;
        $res = [];
        if (!is_array($b) || !is_array($a)) {
            return $res;
        }
        for ($n = 1; $n <= 4; $n++) {
            $was = $b[$n - 1] ?? null;
            $now = $a[$n - 1] ?? null;
            if ($was === $now || ($mine !== null && !isset($mine[$n]))) {
                continue; // реле не менялось или пользователю не выдано
            }
            $res[] = ['id' => (int)$r['id'], 'ts' => $r['ts'], 'relay' => $n, 'name' => ($g["name_reley_$n"] ?? '') ?: "Реле $n",
                      'lock' => $n === 4 && $lock4, 'from' => $state($n, $was), 'to' => $state($n, $now),
                      'to_state' => $now === null ? null : (int)$now];
        }
        return $res;
    };
    $out = [];
    $more = false;
    $events = $rows;
    for ($guard = 0; $guard < 50; $guard++) {
        foreach ($events as $r) {
            $part = $expand($r);
            if ($out && count($out) + count($part) > $limit) {
                $more = true;
                break 2;
            }
            array_push($out, ...$part);
        }
        if (count($events) < $limit || count($out) >= $limit) {
            $more = $more || count($events) === $limit && count($out) >= $limit;
            break;
        }
        // строк меньше лимита (события без видимых пользователю реле) — дочитываем следующие события
        $p2 = $params;
        $last = (int)end($events)['id'];
        $sql2 = preg_replace('/ ORDER BY id DESC LIMIT \d+$/', '', $sql) . ' AND id < ? ORDER BY id DESC LIMIT ' . $limit;
        $p2[] = $last;
        $events = relay_rows(relay_q($connect, $sql2, $p2));
        if (!$events) {
            break;
        }
    }
    $rows = $out;
}
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    if ($kind === 'messages') {
        $r['decoded'] = $r['decoded'] ? json_decode($r['decoded'], true) : null;
        $r['retained'] = (bool)$r['retained'];
    }
    if ($kind === 'commands') {
        $r['label'] = relay_command_label((int)$r['command'], (int)$r['relay_num'], relay_relay4_lock($connect, $id));
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

relay_json(['rows' => $rows, 'more' => $more]);
