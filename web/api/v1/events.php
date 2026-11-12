<?php
/**
 * GET /api/v1/events.php — журнал событий устройств (связь, реле, команды, тревоги, монтаж).
 *   id       — одно устройство (без него — все устройства ключа)
 *   after_id — только события новее этого id (для опроса изменений: передавайте last_id предыдущего ответа)
 *   limit    — сколько событий (1–500, по умолчанию 100)
 *   Ответ: {"events": [{id, device_id, at, type, level, message, user}], "last_id": N}
 *   События — от новых к старым; с after_id — от старых к новым.
 */
require __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error(405, 'method', 'Нужен GET');
}
$id = (int)api_param('id', 0);
$after = (int)api_param('after_id', 0);
$limit = max(1, min(500, (int)api_param('limit', 100)));
if ($id > 0) {
    api_device_or_404($connect, $id);
    $ids = [$id];
} else {
    $ids = array_keys(relay_user_perms());
}
if (!$ids) {
    relay_json(['events' => [], 'last_id' => $after]);
}
$in = implode(',', array_map('intval', $ids));
$rows = relay_rows(relay_q($connect,
    "SELECT id, device_id, UNIX_TIMESTAMP(ts) AS t, type, level, message, user_login FROM device_events
      WHERE device_id IN ($in) AND id > ? ORDER BY id " . ($after > 0 ? 'ASC' : 'DESC') . ' LIMIT ?', [$after, $limit]));
$events = array_map(fn($r) => [
    'id' => (int)$r['id'],
    'device_id' => (int)$r['device_id'],
    'at' => api_time((int)$r['t']),
    'type' => $r['type'],
    'level' => $r['level'],
    'message' => $r['message'],
    'user' => $r['user_login'],
], $rows);
$lastId = $after;
foreach ($events as $e) {
    $lastId = max($lastId, $e['id']);
}
relay_json(['events' => $events, 'last_id' => $lastId]);
