<?php
/**
 * GET /api/v1/devices.php — устройства, доступные ключу, и сводка.
 *   ids   — только эти устройства: «1, 3, 5-10»
 *   state — только в этом состоянии: online | offline | never | power_off | error | not_mounted | repair
 *   mount — только с этим монтажом: mounted | not_mounted | repair
 * GET /api/v1/devices.php?id=N — одно устройство (то же, что device.php?id=N)
 */
require __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error(405, 'method', 'Нужен GET');
}
$id = (int)api_param('id', 0);
if ($id > 0) {
    relay_json(['device' => api_device(api_device_or_404($connect, $id)), 'server_time' => api_time(time())]);
}

$devices = device_rows($connect);
$ids = trim((string)api_param('ids', ''));
if ($ids !== '') {
    try {
        $want = array_flip(relay_parse_id_list($ids, 20000));
    } catch (RuntimeException $e) {
        api_error(400, 'bad_ids', $e->getMessage());
    }
    $devices = array_values(array_filter($devices, fn($d) => isset($want[$d['id']])));
}
$state = (string)api_param('state', '');
if ($state !== '') {
    $devices = array_values(array_filter($devices, fn($d) => $d['state'] === $state));
}
$mount = (string)api_param('mount', '');
if ($mount !== '') {
    if (!in_array($mount, RELAY_MOUNT, true)) {
        api_error(400, 'bad_mount', 'mount: mounted, not_mounted или repair');
    }
    $devices = array_values(array_filter($devices, fn($d) => $d['mount'] === $mount));
}

relay_json([
    'devices' => array_map('api_device', $devices),
    'summary' => relay_devices_summary($devices),
    'server_time' => api_time(time()),
]);
