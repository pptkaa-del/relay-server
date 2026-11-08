<?php
/**
 * Общие функции сервера управления релейными платами.
 * Подключается всеми страницами, API и MQTT-подписчиком.
 *
 * Настройки читаются из /etc/relay-server/config.php (создаётся install.sh).
 */

const RELAY_VERSION       = '3.15';
const RELAY_CONFIG_FILE   = '/etc/relay-server/config.php';
const RELAY_USERS_FILE    = '/etc/relay-server/users.php';
const RELAY_OFFLINE_AFTER = 350; // секунд без stat → плата оффлайн (протокол, п.5)
const RELAY_LOCAL_BROKER  = 1;   // id записи собственного брокера в mqtt_brokers

require_once __DIR__ . '/../vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// php-mqtt/client в режиме MQTT 3.1.1 отвергает PUBLISH с байтом 0x00 в теле, а двоичные
// пакеты плат (uint32 little-endian) почти всегда его содержат. Поэтому сервер работает по MQTT 3.1;
// платы при этом подключаются по 3.1.1 — Mosquitto поддерживает обе версии одновременно.
const RELAY_MQTT_PROTOCOL = MqttClient::MQTT_3_1;

/** Коды команд протокола (топик com/{id}). */
const RELAY_COMMANDS = [
    0 => 'невалидная',
    1 => 'включить',
    2 => 'выключить',
    3 => 'перезапуск реле',
    4 => 'запрос статуса',
    5 => 'перезагрузка устройства',
];

/**
 * Адрес файла из /assets с меткой версии по времени его изменения: после обновления сервера
 * браузер сразу загружает новые скрипты и стили, а не берёт старые из кэша.
 */
function asset_url(string $file): string
{
    $mtime = @filemtime(__DIR__ . '/../assets/' . $file);
    return '/assets/' . $file . '?v=' . RELAY_VERSION . '-' . ($mtime ?: 0);
}

function relay_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = getenv('RELAY_CONFIG') ?: RELAY_CONFIG_FILE;
        if (!is_readable($file)) {
            http_response_code(500);
            die("Не найден файл настроек $file. Запустите install.sh.\n");
        }
        $cfg = require $file;
        date_default_timezone_set($cfg['timezone'] ?? 'Europe/Moscow');
    }
    return $cfg;
}

/** Новое соединение с БД. Часовой пояс сессии MySQL = часовой пояс PHP. */
function relay_db_connect(): ?mysqli
{
    $c = relay_config()['db'];
    mysqli_report(MYSQLI_REPORT_OFF);
    $connect = @mysqli_connect($c['host'], $c['user'], $c['pass'], $c['name'], $c['port'] ?? 3306);
    if (!$connect) {
        return null;
    }
    mysqli_set_charset($connect, 'utf8mb4');
    mysqli_query($connect, "SET time_zone = '" . date('P') . "'");
    return $connect;
}

function relay_rows($res): array
{
    $out = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[] = $r;
    }
    return $out;
}

/** Подготовленный запрос: relay_q($db, 'SELECT ... WHERE id = ?', [5]) → mysqli_result|bool */
function relay_q(mysqli $db, string $sql, array $params = [])
{
    if (!$params) {
        return mysqli_query($db, $sql);
    }
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) {
        throw new RuntimeException('SQL: ' . mysqli_error($db));
    }
    $types = '';
    foreach ($params as $p) {
        $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    return $res === false ? mysqli_stmt_errno($stmt) === 0 : $res;
}

/* ------------------------------------------------------------------ */
/* Авторизация (PHP-сессии, пароли хранятся в виде password_hash)      */
/* ------------------------------------------------------------------ */

function relay_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('relay_sid');
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Роли пользователей панели */
const RELAY_ROLES = [
    'admin' => 'Администратор',   // всё: устройства, их данные, монитор, наклейки, пользователи
    'user'  => 'Пользователь',    // только реле, выданные администратором (просмотр или управление)
];
/** Страницы и API, доступные пользователю (не администратору). Остальное — только администратору. */
const RELAY_USER_SCRIPTS = ['/index.php', '/device.php', '/api/devices.php', '/api/device_log.php', '/api/users.php'];
const RELAY_LOGIN_RE = '/^[A-Za-z0-9_.@-]{1,64}$/';
const RELAY_MIN_PASS = 6;

/**
 * Пользователи из /etc/relay-server/users.php (создаются install.sh и «sudo relay-device user-add»)
 * всегда администраторы, из панели не меняются. Остальные хранятся в таблице panel_users.
 */
function relay_file_users(): array
{
    static $users = null;
    if ($users === null) {
        $file = getenv('RELAY_USERS') ?: RELAY_USERS_FILE;
        $users = is_readable($file) ? (require $file) : [];
        if (!is_array($users)) {
            $users = [];
        }
    }
    return $users;
}

/** Своё соединение для проверки пользователя (до того, как страница подключится к БД). */
function relay_auth_db(): ?mysqli
{
    static $db = false;
    if ($db === false) {
        $db = relay_db_connect();
    }
    return $db;
}

/** Сведения о пользователе или null. pwv — отпечаток пароля: после его смены старые сессии закрываются. */
function relay_user_info(string $login, bool $withHash = false): ?array
{
    $file = relay_file_users();
    if (isset($file[$login])) {
        $u = ['login' => $login, 'name' => '', 'role' => 'admin', 'source' => 'file', 'enabled' => true, 'pwv' => ''];
        if ($withHash) {
            $u['hash'] = $file[$login];
        }
        return $u;
    }
    $db = relay_auth_db();
    if (!$db) {
        return null;
    }
    $st = mysqli_prepare($db, 'SELECT login, name, role, pass_hash, enabled FROM panel_users WHERE login = ?');
    if (!$st) {
        return null; // таблицы ещё нет (схема не обновлена)
    }
    mysqli_stmt_bind_param($st, 's', $login);
    $res = mysqli_stmt_execute($st) ? mysqli_stmt_get_result($st) : false;
    $r = $res ? mysqli_fetch_assoc($res) : null;
    if (!$r) {
        return null;
    }
    $u = ['login' => $r['login'], 'name' => (string)$r['name'], 'role' => $r['role'] === 'admin' ? 'admin' : 'user',
          'source' => 'db', 'enabled' => (bool)$r['enabled'], 'pwv' => substr(sha1($r['pass_hash']), 0, 16)];
    if ($withHash) {
        $u['hash'] = $r['pass_hash'];
    }
    return $u;
}

function relay_check_password(string $login, string $pass): bool
{
    $u = relay_user_info($login, true);
    if (!$u || !$u['enabled'] || !password_verify($pass, $u['hash'])) {
        return false;
    }
    if ($u['source'] === 'db') {
        @mysqli_query(relay_auth_db(), "UPDATE panel_users SET last_login_at = NOW() WHERE login = '"
            . mysqli_real_escape_string(relay_auth_db(), $login) . "'");
    }
    relay_session_start();
    $_SESSION['pwv'] = $u['pwv'];
    return true;
}

/** Текущий пользователь (массив relay_user_info) или null. Удалённый, отключённый или сменивший пароль — выходит. */
function relay_current_user_info(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    relay_session_start();
    $login = $_SESSION['login'] ?? null;
    $u = is_string($login) ? relay_user_info($login) : null;
    if ($login !== null && (!$u || !$u['enabled'] || ($u['pwv'] !== '' && ($_SESSION['pwv'] ?? '') !== $u['pwv']))) {
        $_SESSION = [];
        $u = null;
    }
    return $cache = $u;
}

function relay_current_user(): ?string
{
    return relay_current_user_info()['login'] ?? null;
}

function relay_current_role(): string
{
    return relay_current_user_info()['role'] ?? 'user';
}

function relay_is_admin(): bool
{
    return relay_current_role() === 'admin';
}

/** Отказ в доступе: JSON для API, страница для браузера. */
function relay_forbidden(string $msg = 'Недостаточно прав: раздел доступен только администратору'): never
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (str_contains($script, '/api/') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        relay_json(['error' => $msg], 403);
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Нет доступа</title>'
        . '<link rel="stylesheet" href="' . h(asset_url('app.css')) . '"><div class="auth"><div class="card auth-card"><h2>Нет доступа</h2>'
        . '<p class="muted">' . h($msg) . '.</p><p><a class="btn" href="/index.php">К устройствам</a></p></div></div>';
    exit;
}

/**
 * Права пользователя на реле: null — администратор (всё), иначе [device_id][relay_num] => true (управление) / false (просмотр).
 * relay_num 1–4 — реле, 5 — замок платы ver_5_1.
 */
function relay_user_perms(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    if (relay_is_admin()) {
        return $cache = null;
    }
    $perms = [];
    $db = relay_auth_db();
    $st = $db ? mysqli_prepare($db, 'SELECT device_id, relay_num, can_control FROM panel_user_relays WHERE login = ?') : false;
    if ($st) {
        $login = (string)relay_current_user();
        mysqli_stmt_bind_param($st, 's', $login);
        $res = mysqli_stmt_execute($st) ? mysqli_stmt_get_result($st) : false;
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $perms[(int)$r['device_id']][(int)$r['relay_num']] = (bool)$r['can_control'];
        }
    }
    return $cache = $perms;
}

/** Видит ли пользователь устройство (хотя бы одно реле) или конкретное реле. */
function relay_can_view(int $deviceId, ?int $relay = null): bool
{
    $p = relay_user_perms();
    if ($p === null) {
        return true;
    }
    return $relay === null ? !empty($p[$deviceId]) : isset($p[$deviceId][$relay]);
}

function relay_can_control(int $deviceId, int $relay): bool
{
    $p = relay_user_perms();
    return $p === null || !empty($p[$deviceId][$relay]);
}

/**
 * Проверка прав на команду. Реле 1–5 — управление этим реле; все реле (0) — управление реле 1–4;
 * запрос статуса — просмотр устройства; перезагрузка — управление хотя бы одним реле устройства.
 */
function relay_check_command_access(int $deviceId, int $command, int $relay): void
{
    if (relay_is_admin()) {
        return;
    }
    $p = relay_user_perms()[$deviceId] ?? [];
    $ok = match (true) {
        $command === 4 => (bool)$p,
        $command === 5 => in_array(true, $p, true),
        $relay === 0 => !empty($p[1]) && !empty($p[2]) && !empty($p[3]) && !empty($p[4]),
        default => !empty($p[$relay]),
    };
    if (!$ok) {
        relay_forbidden('Недостаточно прав для этой команды');
    }
}

/** Требует входа. Для AJAX/POST отдаёт 401, для обычных страниц — редирект на login.php.
 *  Пользователю (не администратору) доступны только RELAY_USER_SCRIPTS, права на реле проверяет каждый API. */
function relay_require_auth(): string
{
    $login = relay_current_user();
    if ($login !== null) {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if (!relay_is_admin() && !in_array($script, RELAY_USER_SCRIPTS, true) && !str_starts_with($script, '/aut/')) {
            relay_forbidden();
        }
        return $login;
    }
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        die('Требуется авторизация');
    }
    header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}

/* ------------------------------------------------------------------ */
/* Протокол (docs/PROTOCOL.md). Все поля uint32 little-endian.          */
/* ------------------------------------------------------------------ */

/** Разбирает payload по топику. Возвращает массив полей или null. */
function relay_decode(string $topic, string $payload): ?array
{
    $len = strlen($payload);
    if ($topic === 'stat' && $len >= 32) {
        $d = unpack('Vid/Vversion/Vr1/Vr2/Vr3/Vr4/Vstat/Vsl', substr($payload, 0, 32));
        $d['alarm'] = $len >= 36 ? unpack('V', substr($payload, 32, 4))[1] : 0;
        if ($len >= 40) { // протокол v2: АЦП напряжения АКБ
            $d['bat_adc'] = unpack('V', substr($payload, 36, 4))[1];
        }
        if ($len >= 48) { // протокол v3 (плата ver_5_1): замок и дверь
            $x = unpack('Vlock/Vdoor', substr($payload, 40, 8));
            $d['lock'] = $x['lock'];
            $d['door'] = $x['door'];
        }
        return ['type' => 'stat'] + $d;
    }
    if ($topic === 'rep' && $len >= 12) {
        return ['type' => 'rep'] + unpack('Vid/Vcmd_id/Vsuccess', $payload);
    }
    if (preg_match('~^com/(\d+)$~', $topic, $m) && $len === 12) {
        $d = unpack('Vcmd_id/Vcommand/Vrel_number', $payload);
        $d['command_name'] = RELAY_COMMANDS[$d['command']] ?? '?';
        return ['type' => 'com', 'id' => (int)$m[1]] + $d;
    }
    if ($len > 0 && $len <= 4096 && mb_check_encoding($payload, 'UTF-8') && !preg_match('/[\x00-\x08\x0E-\x1F]/', $payload)) {
        $json = json_decode($payload, true);
        return is_array($json) ? ['type' => 'json', 'data' => $json] : ['type' => 'text', 'text' => $payload];
    }
    return null;
}

/** Записывает сообщение в журнал mqtt_messages. Возвращает decoded. */
function relay_log_message(mysqli $db, int $brokerId, string $direction, string $topic, string $payload,
                           bool $retained, ?array $decoded = null, ?string $user = null): ?array
{
    $decoded ??= relay_decode($topic, $payload);
    $deviceId = isset($decoded['id']) ? (int)$decoded['id'] : null;
    $cmdId = isset($decoded['cmd_id']) ? (int)$decoded['cmd_id'] : null;
    relay_q($db,
        'INSERT INTO mqtt_messages (direction, broker_id, topic, device_id, cmd_id, payload_hex, decoded, retained, user_login)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$direction, $brokerId, $topic, $deviceId, $cmdId, bin2hex(substr($payload, 0, 8192)),
         $decoded === null ? null : json_encode($decoded, JSON_UNESCAPED_UNICODE), $retained ? 1 : 0, $user]);
    return $decoded;
}

/** Событие в журнале устройства (только для плат собственного брокера). */
function relay_event(mysqli $db, int $deviceId, string $type, string $message, string $level = 'info',
                     ?array $data = null, ?string $user = null): void
{
    relay_q($db, 'INSERT INTO device_events (device_id, type, level, message, data, user_login) VALUES (?, ?, ?, ?, ?, ?)',
        [$deviceId, $type, $level, mb_substr($message, 0, 500),
         $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE), $user]);
}

/* ------------------------------------------------------------------ */
/* Напряжение АКБ                                                      */
/* ------------------------------------------------------------------ */

/**
 * Типы АКБ: опорные точки калибровки (АЦП при low и при high вольтах), столбцы general_info с этими АЦП
 * и пороги цвета (ниже bad — красный, ниже warn — жёлтый).
 */
const RELAY_BAT_TYPES = [
    12 => ['low' => 6.0,  'high' => 15.0, 'col_low' => 'cal_adc_6',  'col_high' => 'cal_adc_15', 'bad' => 11.5, 'warn' => 12.2],
    24 => ['low' => 19.0, 'high' => 26.0, 'col_low' => 'cal_adc_19', 'col_high' => 'cal_adc_26', 'bad' => 23.0, 'warn' => 24.4],
];
const RELAY_BAT_V_LOW  = 6.0;  // АКБ 12 В (по умолчанию)
const RELAY_BAT_V_HIGH = 15.0;

function relay_bat_type($t): int
{
    return (int)$t === 24 ? 24 : 12;
}

/**
 * Пересчёт АЦП в вольты по двум точкам калибровки устройства: АКБ 12 В — АЦП при 6 В и 15 В, 24 В — при 19 В и 26 В.
 * null — нет данных или калибровки.
 */
function relay_battery_voltage($adc, $adcLow, $adcHigh, int $batType = 12): ?float
{
    if ($adc === null || $adcLow === null || $adcHigh === null || (int)$adcLow === (int)$adcHigh) {
        return null;
    }
    $t = RELAY_BAT_TYPES[relay_bat_type($batType)];
    $v = $t['low'] + ((int)$adc - (int)$adcLow) * ($t['high'] - $t['low']) / ((int)$adcHigh - (int)$adcLow);
    return round(max(0.0, $v), 2);
}

/** Значение калибровки из формы: '' → null, иначе 0..65535 */
function relay_cal_value($v): ?int
{
    $v = trim((string)$v);
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^\d{1,5}$/', $v) || (int)$v > 65535) {
        throw new RuntimeException('Значение АЦП — целое число от 0 до 65535');
    }
    return (int)$v;
}

/* ------------------------------------------------------------------ */
/* Брокеры                                                             */
/* ------------------------------------------------------------------ */

function relay_broker(mysqli $db, int $id): ?array
{
    $row = mysqli_fetch_assoc(relay_q($db, 'SELECT * FROM mqtt_brokers WHERE id = ?', [$id]));
    return $row ?: null;
}

/** Параметры подключения к брокеру (для локального — из config.php). */
function relay_broker_params(array $b): array
{
    if ((int)$b['is_local'] === 1) {
        $m = relay_config()['mqtt'];
        return ['host' => $m['host'], 'port' => (int)$m['port'], 'user' => $m['user'], 'pass' => $m['pass'],
                'tls' => false, 'verify' => false];
    }
    return ['host' => $b['host'], 'port' => (int)$b['port'], 'user' => (string)$b['username'],
            'pass' => (string)$b['password'], 'tls' => (bool)$b['use_tls'], 'verify' => (bool)$b['tls_verify']];
}

function relay_mqtt_client(array $broker, string $clientId, int $timeout = 5): MqttClient
{
    $p = relay_broker_params($broker);
    $settings = (new ConnectionSettings())
        ->setConnectTimeout($timeout)
        ->setSocketTimeout($timeout)
        ->setKeepAliveInterval(60);
    if ($p['user'] !== '') {
        $settings = $settings->setUsername($p['user'])->setPassword($p['pass'] !== '' ? $p['pass'] : null);
    }
    if ($p['tls']) {
        $settings = $settings->setUseTls(true)
            ->setTlsVerifyPeer($p['verify'])
            ->setTlsVerifyPeerName($p['verify'])
            ->setTlsSelfSignedAllowed(!$p['verify']);
    }
    $client = new MqttClient($p['host'], $p['port'], $clientId, RELAY_MQTT_PROTOCOL);
    $client->connect($settings, true);
    return $client;
}

/** Публикует сообщение в брокер и дожидается подтверждения (QoS 1). */
function relay_publish(array $broker, string $topic, string $payload, int $qos = 1, bool $retain = false): void
{
    $client = relay_mqtt_client($broker, 'relay_panel_' . bin2hex(random_bytes(4)));
    try {
        $client->publish($topic, $payload, $qos, $retain);
        if ($qos > 0) {
            $client->loop(true, true, 5); // ждём PUBACK, не дольше 5 с
        }
    } finally {
        $client->disconnect();
    }
}

/**
 * Разбирает список подписок «stat, rep com/#» (через запятую, пробел или перевод строки).
 * @return array{0: string[], 1: string[]} [верные фильтры, ошибки]
 */
/**
 * Список номеров устройств: «1, 3, 5-10», «1 - 10», «5–8» (тире), разделители — запятая, точка с запятой, пробел.
 * Возвращает уникальные номера по возрастанию; при ошибке — RuntimeException с понятным текстом.
 */
function relay_parse_id_list(string $s, int $max = 500): array
{
    $s = preg_replace('/\s*[-\x{2013}\x{2014}]\s*/u', '-', trim($s)); // «1 - 10», «1–10» → «1-10»
    $ids = [];
    foreach (preg_split('/[\s,;]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        if (!preg_match('/^(\d{1,10})(?:-(\d{1,10}))?$/', $part, $m)) {
            throw new RuntimeException("Неверный номер устройства: «{$part}». Пример: 1, 3, 5-10");
        }
        $a = (int)$m[1];
        $b = isset($m[2]) ? (int)$m[2] : $a;
        if ($a < 1 || $b > 2147483647) {
            throw new RuntimeException("Неверный номер или диапазон: «{$part}»");
        }
        if ($b < $a) {
            [$a, $b] = [$b, $a]; // «10-1» = «1-10»
        }
        if ($b - $a >= $max || count($ids) + ($b - $a + 1) > $max * 2) {
            throw new RuntimeException("Слишком много устройств: не больше $max за раз");
        }
        for ($i = $a; $i <= $b; $i++) {
            $ids[$i] = true;
        }
    }
    $ids = array_keys($ids);
    sort($ids);
    if (count($ids) > $max) {
        throw new RuntimeException("Слишком много устройств: не больше $max за раз");
    }
    return $ids;
}

/** Подходит ли топик под фильтр MQTT (+ — один уровень, # — всё дальше; $SYS не совпадает с # и +). */
function relay_topic_match(string $filter, string $topic): bool
{
    if ($filter === $topic) {
        return true;
    }
    if (str_starts_with($topic, '$') && !str_starts_with($filter, '$')) {
        return false;
    }
    $f = explode('/', $filter);
    $t = explode('/', $topic);
    foreach ($f as $i => $part) {
        if ($part === '#') {
            return true;
        }
        if (!array_key_exists($i, $t) || ($part !== '+' && $part !== $t[$i])) {
            return false;
        }
    }
    return count($f) === count($t);
}

/** Подписки без пересечений: фильтр, полностью покрытый другим (stat ⊂ #, com/1 ⊂ com/#), убирается — иначе сообщения дублировались бы. */
function relay_merge_filters(array $filters): array
{
    $filters = array_values(array_unique($filters));
    $out = [];
    foreach ($filters as $i => $a) {
        $covered = false;
        foreach ($filters as $j => $b) {
            if ($i !== $j && $a !== $b && str_ends_with($b, '#') && !str_contains($a, '#') && relay_topic_match($b, str_replace('+', 'x', $a))
                && !(str_starts_with($a, '$') && !str_starts_with($b, '$'))) {
                $covered = true;
                break;
            }
            if ($i !== $j && str_ends_with($a, '#') && str_ends_with($b, '#') && $a !== $b
                && str_starts_with(substr($a, 0, -1), substr($b, 0, -1))) {
                $covered = true; // com/1/# ⊂ com/#
                break;
            }
        }
        if (!$covered) {
            $out[] = $a;
        }
    }
    return $out;
}

function relay_parse_topics(string $s): array
{
    $ok = $err = [];
    foreach (preg_split('/[\s,;]+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) as $f) {
        $e = relay_topic_filter_error($f);
        $e === null ? $ok[] = $f : $err[] = "«{$f}»: $e";
    }
    $ok = array_values(array_unique($ok));
    // «#» и так включает всё (кроме $SYS): остальные фильтры дали бы дубли сообщений
    if (in_array('#', $ok, true)) {
        $ok = array_values(array_filter($ok, fn($f) => $f === '#' || str_starts_with($f, '$')));
    }
    return [$ok, $err];
}

/** Проверка фильтра подписки по правилам MQTT. null — фильтр верный. */
function relay_topic_filter_error(string $f): ?string
{
    if (strlen($f) > 1000 || str_contains($f, "\0")) {
        return 'слишком длинный или содержит недопустимые символы';
    }
    $levels = explode('/', $f);
    foreach ($levels as $i => $lvl) {
        if (str_contains($lvl, '#') && ($lvl !== '#' || $i !== count($levels) - 1)) {
            return 'знак # допускается только целым последним уровнем: «#» или «com/#»';
        }
        if (str_contains($lvl, '+') && $lvl !== '+') {
            return 'знак + допускается только целым уровнем: «com/+»';
        }
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* Регистрация плат (relay-device через sudo — нужны права root)       */
/* ------------------------------------------------------------------ */

const RELAY_DEVICE_CLI   = '/usr/local/sbin/relay-device-web';
const RELAY_DEVICE_QUEUE = '/var/lib/relay-server/queue';

/** Пароль MQTT платы — как в прошивке: hex первых 16 байт SHA256("relay-{id}") */
function relay_device_password(int $id): string
{
    return substr(hash('sha256', "relay-$id"), 0, 32);
}

/**
 * Выполняет «relay-device add|del …» с правами root. Веб-сервер работает от www-data, поэтому:
 *  1) основной способ — очередь: файл-запрос в /var/lib/relay-server/queue, его сразу обрабатывает
 *     служба systemd relay-device-queue (от root) и кладёт ответ;
 *  2) запасной — sudo relay-device-web (правило без аргументов; работает и с sudo-rs).
 * @return array{0: int, 1: string} [код возврата, вывод]
 */
function relay_device_cli(array $args): array
{
    $args = array_map(fn($a) => str_replace(["\r", "\n", "\0"], ' ', (string)$a), $args);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        return relay_run_process(array_merge([getenv('RELAY_DEVICE_CLI') ?: RELAY_DEVICE_CLI], $args));
    }
    $errors = [];
    $q = getenv('RELAY_DEVICE_QUEUE') ?: RELAY_DEVICE_QUEUE;
    if (is_dir($q) && is_writable($q)) {
        $res = relay_device_queue($q, $args, $timedOut);
        if (!$timedOut) {
            return $res;
        }
        $errors[] = 'служба relay-device-queue не ответила за 15 с';
    } else {
        $errors[] = "нет каталога очереди $q";
    }
    [$code, $out] = relay_run_process(array_merge(['/usr/bin/sudo', '-n', getenv('RELAY_DEVICE_CLI') ?: RELAY_DEVICE_CLI], $args));
    if ($code === 0 || !preg_match('/sudo|afraid|password|not allowed/i', $out)) {
        return [$code, $out];
    }
    $errors[] = "sudo: $out";
    return [126, implode('; ', $errors)];
}

/** Запрос через очередь; $timedOut = true, если служба не ответила. */
function relay_device_queue(string $q, array $args, ?bool &$timedOut): array
{
    $timedOut = false;
    $id = bin2hex(random_bytes(16));
    $tmp = "$q/.$id.tmp";
    if (@file_put_contents($tmp, implode("\n", $args) . "\n") === false || !@rename($tmp, "$q/$id.req")) {
        @unlink($tmp);
        $timedOut = true;
        return [1, 'не удалось записать запрос'];
    }
    $res = "$q/$id.res";
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $res);
        if (is_file($res) && ($data = @file_get_contents($res)) !== false && str_contains($data, "\n")) {
            @unlink($res);
            [$code, $out] = explode("\n", $data, 2) + [1 => ''];
            return [(int)$code, trim($out)];
        }
        usleep(200000);
    }
    @unlink("$q/$id.req");
    $timedOut = true;
    return [1, 'нет ответа'];
}

function relay_run_process(array $cmd): array
{
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return [127, 'не удалось запустить ' . $cmd[0]];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), trim($out)];
}

/* ------------------------------------------------------------------ */
/* Команды платам                                                      */
/* ------------------------------------------------------------------ */

function relay_next_cmd_id(mysqli $db): int
{
    $row = mysqli_fetch_assoc(mysqli_query($db, 'SELECT MAX(cmd_id) AS m FROM commands'));
    $next = (int)($row['m'] ?? 0) + 1;
    if ($next > 2147483647) { // cmd_id INT: начинаем сначала
        mysqli_query($db, 'DELETE FROM commands');
        $next = 1;
    }
    return $next;
}

const RELAY_LOCK = 5; // замок платы ver_5_1 — «реле 5» в командах 1–3

/** Реле 4 устройства управляет замком двери (настройка «Реле 4 — замок»). */
function relay_relay4_lock(mysqli $db, int $deviceId): bool
{
    static $cache = [];   // [device_id => [значение, время]] — подписчик работает долго, поэтому кэш на 30 с
    if (!isset($cache[$deviceId]) || time() - $cache[$deviceId][1] > 30) {
        $res = relay_q($db, 'SELECT relay4_lock FROM general_info WHERE id = ?', [$deviceId]);
        $r = $res instanceof mysqli_result ? mysqli_fetch_row($res) : null;
        $cache[$deviceId] = [$r !== null && (int)$r[0] === 1, time()];
    }
    return $cache[$deviceId][0];
}

/** Названия команд замку на реле 4: включено — закрыто, выключено — открыто. */
const RELAY4_LOCK_COMMANDS = [1 => 'Замок: закрыть', 2 => 'Замок: открыть надолго', 3 => 'Замок: открыть'];

function relay_command_label(int $command, int $relay, bool $relay4Lock = false): string
{
    if ($relay === 4 && $relay4Lock && isset(RELAY4_LOCK_COMMANDS[$command])) {
        return RELAY4_LOCK_COMMANDS[$command];
    }
    if ($relay === RELAY_LOCK) {
        return [1 => 'замок: подать питание', 2 => 'замок: снять питание', 3 => 'замок: открыть (импульс)'][$command] ?? "замок: команда $command";
    }
    $name = RELAY_COMMANDS[$command] ?? "команда $command";
    return in_array($command, [1, 2, 3], true) ? $name . ' · ' . ($relay ? "реле $relay" : 'все реле') : $name;
}

/**
 * Отправляет команду плате в com/{id}.
 *  - собственный брокер: запись в commands (статус обновит подписчик по rep) и событие в журнале платы;
 *  - сторонний брокер: только журнал сообщений, cmd_id случайный (чтобы не совпасть с чужим сервером).
 * Команды 1 и 2 — с retain (плата восстановит состояние после переподключения), 3/4/5 — без retain.
 *
 * @return int cmd_id
 */
function relay_send_command(mysqli $db, int $deviceId, int $command, int $relay, ?string $user = null,
                            int $brokerId = RELAY_LOCAL_BROKER): int
{
    if ($deviceId <= 0 || $command < 1 || $command > 5 || $relay < 0 || $relay > RELAY_LOCK
        || ($relay === RELAY_LOCK && $command > 3)) {
        throw new RuntimeException('Неверные параметры команды');
    }
    if ($command >= 4) {
        $relay = 0;
    }
    $broker = relay_broker($db, $brokerId);
    if (!$broker) {
        throw new RuntimeException('Брокер не найден');
    }
    $local = (int)$broker['is_local'] === 1;

    if ($local) {
        $exists = relay_q($db, 'SELECT 1 FROM devices WHERE id = ?', [$deviceId]);
        if (!$exists || mysqli_num_rows($exists) === 0) {
            throw new RuntimeException("Устройство $deviceId ещё ни разу не выходило на связь");
        }
        $cmdId = relay_next_cmd_id($db);
        relay_q($db, "INSERT INTO commands (cmd_id, device_id, command, relay_num, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())",
            [$cmdId, $deviceId, $command, $relay]);
    } else {
        $cmdId = random_int(1000000, 2147483647);
    }

    $topic = "com/$deviceId";
    $payload = pack('VVV', $cmdId, $command, $relay);
    // retain только для реле 1–4: плата восстановит их состояние после переподключения.
    // Команды замку не сохраняются в брокере — иначе замок срабатывал бы при каждом переподключении.
    $retain = in_array($command, [1, 2], true) && $relay !== RELAY_LOCK;
    // счётчик нажатий по пользователям: «Перезапуск реле» (3) и «Перезагрузить устройство» (5);
    // «Открыть» замку (реле 4 — замок, замок платы) не считается
    if ($user !== null && (($command === 3 && $relay !== RELAY_LOCK && !($relay === 4 && relay_relay4_lock($db, $deviceId))) || $command === 5)) {
        @relay_q($db, 'INSERT INTO relay_restart_clicks (login, command, device_id, relay_num, broker_id) VALUES (?, ?, ?, ?, ?)',
            [$user, $command, $deviceId, $relay, $brokerId]);
    }
    if ($local) {
        // событие пишем до отправки: плата может ответить раньше, чем завершится publish
        relay_event($db, $deviceId, $command === 5 ? 'reboot' : 'command', 'Команда: ' . relay_command_label($command, $relay, relay_relay4_lock($db, $deviceId)), 'info',
            ['cmd_id' => $cmdId, 'command' => $command, 'relay' => $relay], $user);
    }
    // журнал пишем до отправки: сторонний брокер вернёт копию сообщения (подписка на #) раньше,
    // чем завершится publish, и подписчик должен узнать в ней нашу команду
    relay_log_message($db, $brokerId, 'out', $topic, $payload, $retain, null, $user);
    $logId = mysqli_insert_id($db);
    try {
        relay_publish($broker, $topic, $payload, 1, $retain);
    } catch (Throwable $e) {
        relay_q($db, 'DELETE FROM mqtt_messages WHERE id = ?', [$logId]);
        if ($local) {
            relay_q($db, "UPDATE commands SET status = 'error' WHERE cmd_id = ? AND status = 'pending'", [$cmdId]);
            relay_event($db, $deviceId, 'command', relay_command_label($command, $relay, relay_relay4_lock($db, $deviceId)) . ' — ошибка отправки: ' . $e->getMessage(), 'error', ['cmd_id' => $cmdId], $user);
        }
        throw new RuntimeException('Ошибка MQTT: ' . $e->getMessage());
    }
    if ($local) {
        // плата может ответить (rep) раньше, чем мы дойдём до этой строки — не затираем её результат
        relay_q($db, "UPDATE commands SET sent_at = NOW(), status = IF(status = 'pending', 'sent', status) WHERE cmd_id = ?", [$cmdId]);
    }
    return $cmdId;
}

/** Состояние команды: pending|sent|success|error|unknown */
function relay_command_status(mysqli $db, int $cmdId, int $brokerId = RELAY_LOCAL_BROKER): string
{
    $broker = relay_broker($db, $brokerId);
    if ($broker && (int)$broker['is_local'] === 1) {
        $row = mysqli_fetch_assoc(relay_q($db, 'SELECT status FROM commands WHERE cmd_id = ?', [$cmdId]));
        return $row['status'] ?? 'unknown';
    }
    $row = mysqli_fetch_assoc(relay_q($db,
        "SELECT decoded FROM mqtt_messages WHERE broker_id = ? AND cmd_id = ? AND topic = 'rep' ORDER BY id DESC LIMIT 1",
        [$brokerId, $cmdId]));
    if (!$row) {
        return 'sent';
    }
    $d = json_decode($row['decoded'], true);
    return ($d['success'] ?? 0) == 1 ? 'success' : 'error';
}

/* ------------------------------------------------------------------ */
/* Вывод                                                               */
/* ------------------------------------------------------------------ */

function relay_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
