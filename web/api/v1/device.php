<?php
/**
 * GET /api/v1/device.php?id=N — одно устройство: состояние, реле, АКБ, замок и последняя команда.
 */
require __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error(405, 'method', 'Нужен GET');
}
$id = (int)api_param('id', 0);
$dev = api_device(api_device_or_404($connect, $id));
$lc = mysqli_fetch_assoc(relay_q($connect,
    'SELECT cmd_id, command, relay_num, status, UNIX_TIMESTAMP(created_at) AS created, UNIX_TIMESTAMP(confirmed_at) AS confirmed
       FROM commands WHERE device_id = ? AND command <> 4 ORDER BY cmd_id DESC LIMIT 1', [$id]));
$dev['last_command'] = $lc ? [
    'cmd_id' => (int)$lc['cmd_id'],
    'command' => API_COMMAND_NAMES[(int)$lc['command']] ?? (string)$lc['command'],
    'relay' => (int)$lc['relay_num'],
    'status' => $lc['status'],
    'created_at' => api_time($lc['created'] ? (int)$lc['created'] : null),
    'confirmed_at' => api_time($lc['confirmed'] ? (int)$lc['confirmed'] : null),
] : null;
relay_json(['device' => $dev, 'server_time' => api_time(time())]);
