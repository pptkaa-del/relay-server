<?php
/**
 * Пользователи внешнего API (ключи для сторонних систем) — только администратор.
 *   GET  ?action=list
 *   POST action=add    name, note, can_control (0|1), devices ('*' | «1, 3, 5-10»), ip_allow, rate_limit → ключ (показывается один раз)
 *   POST action=update id, name, note, can_control, devices, ip_allow, rate_limit, enabled
 *   POST action=regen  id — новый ключ (старый перестаёт работать)
 *   POST action=delete id
 * Ключ хранится только в виде SHA-256; в списке видны последние 4 символа.
 */
require __DIR__ . '/../conectbd.php';

$action = $_REQUEST['action'] ?? 'list';
if ($action !== 'list' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}
if (!relay_is_admin()) {
    relay_forbidden('Пользователей API создаёт только администратор');
}
if (!@mysqli_query($connect, 'SELECT 1 FROM api_clients LIMIT 0')) {
    relay_json(['error' => 'Нет таблицы пользователей API — обновите сервер: sudo relay-update'], 500);
}

/** Новый ключ: «ofs_» + 48 шестнадцатеричных символов (192 бита) */
function new_token(): string
{
    return 'ofs_' . bin2hex(random_bytes(24));
}

/** Поля клиента из формы (с проверкой) */
function client_fields(): array
{
    $name = trim((string)($_POST['name'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,48}$/', $name)) {
        relay_json(['error' => 'Имя пользователя API: латиница, цифры, _ . - (до 48 символов), например crm или scada-1'], 400);
    }
    $devices = trim((string)($_POST['devices'] ?? '*'));
    if ($devices === '' || $devices === '*' || mb_strtolower($devices) === 'все') {
        $devices = '*';
    } else {
        try {
            $ids = relay_parse_id_list($devices, 20000);
        } catch (RuntimeException $e) {
            relay_json(['error' => 'Устройства: ' . $e->getMessage()], 400);
        }
        if (!$ids) {
            relay_json(['error' => 'Устройства: «*» — все или номера, например 1, 3, 5-10'], 400);
        }
        $devices = mb_substr(preg_replace('/\s+/', ' ', $devices), 0, 2000);
    }
    $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($_POST['ip_allow'] ?? '')))));
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            relay_json(['error' => "Неверный IP-адрес: «{$ip}»"], 400);
        }
    }
    $rate = (int)($_POST['rate_limit'] ?? 120);
    if ($rate < 1 || $rate > 6000) {
        relay_json(['error' => 'Ограничение: от 1 до 6000 запросов в минуту'], 400);
    }
    return [
        'name' => $name,
        'note' => mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', (string)($_POST['note'] ?? ''))), 0, 300),
        'can_control' => ($_POST['can_control'] ?? '0') === '1' ? 1 : 0,
        'devices' => $devices,
        'ip_allow' => implode(', ', $ips),
        'rate_limit' => $rate,
    ];
}

function client_row(array $r): array
{
    return [
        'id' => (int)$r['id'], 'name' => $r['name'], 'note' => $r['note'], 'token_hint' => $r['token_hint'],
        'can_control' => (int)$r['can_control'] === 1, 'devices' => $r['devices'], 'ip_allow' => $r['ip_allow'],
        'rate_limit' => (int)$r['rate_limit'], 'enabled' => (int)$r['enabled'] === 1, 'created_by' => $r['created_by'],
        'created_ts' => $r['created_ts'] ? (int)$r['created_ts'] : null, 'last_used_ts' => $r['last_used_ts'] ? (int)$r['last_used_ts'] : null,
        'last_ip' => $r['last_ip'], 'requests' => (int)$r['requests'],
    ];
}

$exists = function (string $name, int $exceptId = 0) use ($connect): bool {
    $res = relay_q($connect, 'SELECT 1 FROM api_clients WHERE name = ? AND id <> ?', [$name, $exceptId]);
    return $res instanceof mysqli_result && mysqli_num_rows($res) > 0;
};

switch ($action) {
    case 'list':
        $rows = relay_rows(mysqli_query($connect, 'SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts, UNIX_TIMESTAMP(last_used_at) AS last_used_ts
                                                     FROM api_clients ORDER BY name'));
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        relay_json(['clients' => array_map('client_row', $rows),
                    'base_url' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'сервер') . '/api/v1']);

    case 'add':
        $f = client_fields();
        if ($exists($f['name'])) {
            relay_json(['error' => "Пользователь API «{$f['name']}» уже есть"], 409);
        }
        $token = new_token();
        relay_q($connect, 'INSERT INTO api_clients (name, note, token_hash, token_hint, can_control, devices, ip_allow, rate_limit, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$f['name'], $f['note'], hash('sha256', $token), substr($token, -4), $f['can_control'], $f['devices'], $f['ip_allow'], $f['rate_limit'], $login]);
        relay_json(['ok' => true, 'id' => mysqli_insert_id($connect), 'token' => $token]);

    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        $f = client_fields();
        if ($exists($f['name'], $id)) {
            relay_json(['error' => "Пользователь API «{$f['name']}» уже есть"], 409);
        }
        relay_q($connect, 'UPDATE api_clients SET name = ?, note = ?, can_control = ?, devices = ?, ip_allow = ?, rate_limit = ?, enabled = ? WHERE id = ?',
            [$f['name'], $f['note'], $f['can_control'], $f['devices'], $f['ip_allow'], $f['rate_limit'], ($_POST['enabled'] ?? '1') === '1' ? 1 : 0, $id]);
        relay_json(['ok' => true]);

    case 'regen':
        $id = (int)($_POST['id'] ?? 0);
        $res = relay_q($connect, 'SELECT 1 FROM api_clients WHERE id = ?', [$id]);
        if (!$res instanceof mysqli_result || mysqli_num_rows($res) === 0) {
            relay_json(['error' => 'Пользователь API не найден'], 404);
        }
        $token = new_token();
        relay_q($connect, 'UPDATE api_clients SET token_hash = ?, token_hint = ? WHERE id = ?', [hash('sha256', $token), substr($token, -4), $id]);
        relay_json(['ok' => true, 'token' => $token]);

    case 'delete':
        relay_q($connect, 'DELETE FROM api_clients WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        relay_json(['ok' => true]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
