<?php
/**
 * POST /api/v1/command.php — команда устройству.
 *   id      — устройство
 *   command — on | off | restart | status | reboot (или код 1–5)
 *   relay   — 1–4 — реле, 5 — замок платы ver_5_1, 0 — все реле (для on/off/restart); для status/reboot не нужен
 *   wait    — ждать ответа устройства до N секунд (0–30, по умолчанию 0 — не ждать)
 *   Ответ: {"ok": true, "cmd_id": 123, "status": "sent" | "success" | "error" | "pending"}
 *
 * GET /api/v1/command.php?cmd_id=N — состояние команды: pending | sent | success | error | unknown
 */
require __DIR__ . '/_init.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Состояние команды и её устройство (только своих устройств) */
function api_command_info(mysqli $db, int $cmdId): array
{
    $res = relay_q($db, 'SELECT cmd_id, device_id, command, relay_num, status, UNIX_TIMESTAMP(created_at) AS created,
                               UNIX_TIMESTAMP(confirmed_at) AS confirmed FROM commands WHERE cmd_id = ?', [$cmdId]);
    $c = $res instanceof mysqli_result ? mysqli_fetch_assoc($res) : null;
    if (!$c || !relay_can_view((int)$c['device_id'])) {
        api_error(404, 'not_found', "Команда $cmdId не найдена");
    }
    return [
        'cmd_id' => (int)$c['cmd_id'],
        'device_id' => (int)$c['device_id'],
        'command' => API_COMMAND_NAMES[(int)$c['command']] ?? (string)$c['command'],
        'relay' => (int)$c['relay_num'],
        'status' => $c['status'],
        'created_at' => api_time($c['created'] ? (int)$c['created'] : null),
        'confirmed_at' => api_time($c['confirmed'] ? (int)$c['confirmed'] : null),
    ];
}

if ($method === 'GET') {
    $cmdId = (int)api_param('cmd_id', 0);
    if ($cmdId <= 0) {
        api_error(400, 'bad_cmd_id', 'Укажите cmd_id');
    }
    relay_json(['command' => api_command_info($connect, $cmdId)]);
}
if ($method !== 'POST') {
    api_error(405, 'method', 'Нужен POST (отправить команду) или GET (состояние команды)');
}

$id = (int)api_param('id', 0);
api_device_or_404($connect, $id);
$cmdRaw = strtolower(trim((string)api_param('command', '')));
$command = API_COMMANDS[$cmdRaw] ?? (ctype_digit($cmdRaw) ? (int)$cmdRaw : 0);
if ($command < 1 || $command > 5) {
    api_error(400, 'bad_command', 'command: on, off, restart, status или reboot');
}
$relay = $command >= 4 ? 0 : (int)api_param('relay', -1);
if ($relay < 0 || $relay > RELAY_LOCK) {
    api_error(400, 'bad_relay', 'relay: 1–4 — реле, 5 — замок платы ver_5_1, 0 — все реле');
}
if ($command !== 4) {
    api_require_control();
}
relay_check_command_access($id, $command, $relay);   // 403, если реле не выдано
$err = relay_command_error($connect, $id, $command, $relay);
if ($err !== null) {
    api_error(409, 'not_allowed', $err);
}
try {
    $cmdId = relay_send_command($connect, $id, $command, $relay, $login);
} catch (RuntimeException $e) {
    api_error(409, 'send_failed', $e->getMessage());
}
// ждём ответа устройства (rep) — по желанию клиента
$wait = max(0, min(30, (int)api_param('wait', 0)));
$status = relay_command_status($connect, $cmdId);
for ($end = microtime(true) + $wait; $wait && !in_array($status, ['success', 'error'], true) && microtime(true) < $end;) {
    usleep(500000);
    $status = relay_command_status($connect, $cmdId);
}
relay_json(['ok' => true, 'cmd_id' => $cmdId, 'status' => $status]);
