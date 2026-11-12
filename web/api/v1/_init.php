<?php
/**
 * Внешний API для сторонних систем: вход по ключу, права клиента, общий формат ответа.
 * Описание — docs/API.md (в панели: «Пользователи» → «Описание API»).
 *
 * Ключ передаётся в заголовке «Authorization: Bearer <ключ>» или «X-API-Key: <ключ>».
 * Клиенты (ключи) создаёт администратор: «Пользователи» → «Пользователи API».
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/devices.php';

header('X-Content-Type-Options: nosniff');
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === '_init.php') {
    http_response_code(404);
    exit;
}

/** Команды устройству: имя во внешнем API ↔ код протокола */
const API_COMMANDS = ['on' => 1, 'off' => 2, 'restart' => 3, 'status' => 4, 'reboot' => 5];
const API_COMMAND_NAMES = [1 => 'on', 2 => 'off', 3 => 'restart', 4 => 'status', 5 => 'reboot'];

/** Ошибка: {"error": "текст", "code": "код"} */
function api_error(int $http, string $code, string $message): never
{
    relay_json(['error' => $message, 'code' => $code], $http);
}

/** Параметры запроса: GET, POST-форма или тело JSON */
function api_params(): array
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $p = $_GET;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $body = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($body)) {
                api_error(400, 'bad_json', 'Тело запроса — неверный JSON');
            }
            $p = array_merge($p, $body);
        } else {
            $p = array_merge($p, $_POST);
        }
    }
    return $p;
}

function api_param(string $key, $default = null)
{
    $v = api_params()[$key] ?? $default;
    return is_scalar($v) || $v === null ? $v : $default;
}

/** Время для ответа: ISO 8601 с часовым поясом сервера или null */
function api_time(?int $ts): ?string
{
    return $ts ? date('c', $ts) : null;
}

function api_token(): string
{
    $key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key !== '') {
        return $key;
    }
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp((string)$k, 'Authorization') === 0) {
                $auth = (string)$v;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m) ? $m[1] : '';
}

/** Права клиента: [устройство][реле 1–5] => может управлять */
function api_client_perms(mysqli $db, array $c): array
{
    $ctl = (int)$c['can_control'] === 1;
    $spec = trim((string)$c['devices']);
    if ($spec === '*' || $spec === '') {
        $ids = array_map(fn($r) => (int)$r['id'], relay_rows(mysqli_query($db, 'SELECT id FROM general_info UNION SELECT id FROM devices')));
    } else {
        try {
            $ids = relay_parse_id_list($spec, 20000);
        } catch (RuntimeException $e) {
            $ids = [];
        }
    }
    $perms = [];
    foreach ($ids as $id) {
        $perms[$id] = [1 => $ctl, 2 => $ctl, 3 => $ctl, 4 => $ctl, RELAY_LOCK => $ctl];
    }
    return $perms;
}

$connect = relay_db_connect();
if (!$connect) {
    api_error(503, 'db_unavailable', 'Нет соединения с базой данных');
}

$token = api_token();
if ($token === '') {
    header('WWW-Authenticate: Bearer');
    api_error(401, 'no_key', 'Нужен ключ API: заголовок «Authorization: Bearer <ключ>» или «X-API-Key: <ключ>»');
}
$res = @relay_q($connect, 'SELECT * FROM api_clients WHERE token_hash = ?', [hash('sha256', $token)]);
$client = $res instanceof mysqli_result ? mysqli_fetch_assoc($res) : null;
if (!$client || (int)$client['enabled'] !== 1) {
    usleep(300000); // замедляем подбор ключей
    header('WWW-Authenticate: Bearer error="invalid_token"');
    api_error(401, 'bad_key', $client ? 'Ключ API отключён администратором' : 'Неверный ключ API');
}
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$allow = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$client['ip_allow'])));
if ($allow && !in_array($ip, $allow, true)) {
    api_error(403, 'ip_denied', "Доступ с адреса $ip для этого ключа не разрешён");
}
// ограничение частоты: не больше rate_limit запросов в минуту на ключ
$minute = intdiv(time(), 60);
relay_q($connect, 'UPDATE api_clients SET rl_count = IF(rl_minute = ?, rl_count + 1, 1), rl_minute = ?,
                          requests = requests + 1, last_used_at = NOW(), last_ip = ? WHERE id = ?',
    [$minute, $minute, $ip, (int)$client['id']]);
$rl = mysqli_fetch_row(relay_q($connect, 'SELECT rl_count FROM api_clients WHERE id = ?', [(int)$client['id']]));
$limit = max(1, (int)$client['rate_limit']);
header("X-RateLimit-Limit: $limit");
header('X-RateLimit-Remaining: ' . max(0, $limit - (int)$rl[0]));
if ((int)$rl[0] > $limit) {
    header('Retry-After: ' . (60 - time() % 60));
    api_error(429, 'rate_limit', "Слишком много запросов: не больше $limit в минуту");
}

$client['perms'] = api_client_perms($connect, $client);
relay_api_client($client);
$login = 'api:' . $client['name'];   // так клиент подписан в журнале устройства

/** Нужно право управления */
function api_require_control(): void
{
    if ((int)relay_api_client()['can_control'] !== 1) {
        api_error(403, 'read_only', 'Ключ API только для чтения: управление не разрешено');
    }
}

/** Устройство, доступное клиенту, или 404 */
function api_device_or_404(mysqli $db, int $id): array
{
    $rows = $id > 0 && relay_can_view($id) ? device_rows($db, $id) : [];
    if (!$rows) {
        api_error(404, 'not_found', "Устройство $id не найдено или нет доступа");
    }
    return $rows[0];
}

/** Устройство в формате внешнего API (стабильный набор полей, см. docs/API.md) */
function api_device(array $d): array
{
    $relays = [];
    foreach ($d['relays'] as $r) {
        $relays[] = [
            'num' => $r['num'],
            'name' => $r['name'],
            'mode' => $r['lock'] ? 'lock' : ($r['wash'] ? 'wash' : 'normal'),
            'state' => $r['state'] === null ? null : ([0 => 'off', 1 => 'on', 2 => 'error'][$r['state']] ?? 'error'),
            'power' => $r['state'] === null || $r['state'] === 2 ? null : $r['state'] === 1,   // на устройство подаётся электропитание
            'changed_at' => api_time($r['ts']),
            'control' => (bool)$r['control'],
        ];
    }
    return [
        'id' => $d['id'],
        'name' => $d['name'],
        'address' => $d['address'],
        'owner' => $d['owner'],
        'sim_phone' => $d['sim_phone'] !== '' ? $d['sim_phone'] : null,
        'coordinates' => $d['geo'] ? ['lat' => $d['geo'][0], 'lon' => $d['geo'][1]] : null,
        'mount' => $d['mount'],
        'mount_changed_at' => api_time($d['mount_ts']),
        'online' => $d['online'],
        'state' => $d['state'],
        'last_data_at' => api_time($d['last_data_ts']),
        'version' => $d['dev_version'],
        'signal' => $d['signal_level'],
        'battery' => $d['bat_on'] ? [
            'voltage' => $d['bat_voltage'],
            'type' => $d['bat_type'],
            'adc' => $d['bat_adc'],
            'low' => $d['bat_low'],
            'at' => api_time($d['bat_adc_ts']),
        ] : null,
        'all_off' => (bool)$d['all_off'],
        'door_open' => $d['door_open'],
        'break_in' => $d['break_in'] ? ['at' => api_time($d['break_in']['ts']), 'source' => $d['break_in']['source']] : null,
        'lock' => $d['has_lock'] ? [
            'door' => $d['door_state'] === null ? null : ($d['door_state'] === 1 ? 'open' : 'closed'),
            'powered' => $d['lock_state'] === null ? null : $d['lock_state'] === 1,
            'control' => $d['lock_access'] === 'control',
        ] : null,
        'relays' => $relays,
    ];
}
