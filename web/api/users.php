<?php
/**
 * Пользователи панели.
 *   GET  ?action=list                                   — список (администратор)
 *   POST action=add     login, name, role, password, perms — добавить (администратор)
 *   POST action=update  login, name, role, enabled, [password], perms — изменить (администратор)
 *   perms — JSON {"<id устройства>": {"<реле 1–5>": "view" | "control"}}: какие реле пользователь видит и какими управляет
 *   (для роли «Пользователь»; администратор видит и управляет всем)
 *   POST action=delete  login                           — удалить (администратор)
 *   POST action=passwd  old, password                   — сменить свой пароль (любой пользователь)
 *
 * Пользователи из /etc/relay-server/users.php (созданы при установке) — администраторы,
 * из панели не меняются: sudo relay-device user-add|user-del.
 */
require __DIR__ . '/../conectbd.php';

$action = $_REQUEST['action'] ?? 'list';
if ($action !== 'list' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}
if ($action !== 'passwd' && relay_current_role() !== 'admin') {
    relay_json(['error' => 'Управлять пользователями может только администратор'], 403);
}
if (!mysqli_query($connect, 'SELECT 1 FROM panel_users LIMIT 0')) {
    relay_json(['error' => 'Нет таблицы пользователей — обновите сервер: sudo relay-update'], 500);
}

$str = fn($k, $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
$role = function () {
    $r = (string)($_POST['role'] ?? '');
    if (!isset(RELAY_ROLES[$r])) {
        relay_json(['error' => 'Неизвестная роль'], 400);
    }
    return $r;
};
$password = function (string $key = 'password', bool $required = true) {
    $p = (string)($_POST[$key] ?? '');
    if ($p === '' && !$required) {
        return null;
    }
    if (mb_strlen($p) < RELAY_MIN_PASS) {
        relay_json(['error' => 'Пароль должен быть не короче ' . RELAY_MIN_PASS . ' символов'], 400);
    }
    if (mb_strlen($p) > 200) {
        relay_json(['error' => 'Слишком длинный пароль'], 400);
    }
    return password_hash($p, PASSWORD_DEFAULT);
};
/** Учётная запись из панели (не из файла) или ошибка */
$dbUser = function (string $l) use ($connect) {
    if (isset(relay_file_users()[$l])) {
        relay_json(['error' => "Пользователь $l создан при установке сервера и меняется только на сервере: sudo relay-device user-add $l"], 400);
    }
    $u = mysqli_fetch_assoc(relay_q($connect, 'SELECT * FROM panel_users WHERE login = ?', [$l]));
    if (!$u) {
        relay_json(['error' => 'Пользователь не найден'], 404);
    }
    return $u;
};
/** Права на реле из формы → [[device_id, relay_num, can_control], …] */
$permsFromPost = function () use ($connect) {
    $raw = json_decode((string)($_POST['perms'] ?? '{}'), true);
    if (!is_array($raw)) {
        relay_json(['error' => 'Неверный формат прав на реле'], 400);
    }
    $known = [];
    foreach (relay_rows(mysqli_query($connect, 'SELECT id FROM general_info')) as $r) {
        $known[(int)$r['id']] = true;
    }
    $out = [];
    foreach ($raw as $dev => $relays) {
        if (!isset($known[(int)$dev]) || !is_array($relays)) {
            continue;
        }
        foreach ($relays as $num => $mode) {
            if ((int)$num >= 1 && (int)$num <= RELAY_LOCK && in_array($mode, ['view', 'control'], true)) {
                $out[] = [(int)$dev, (int)$num, $mode === 'control' ? 1 : 0];
            }
        }
    }
    return $out;
};
$savePerms = function (string $l, string $role, array $perms) use ($connect) {
    relay_q($connect, 'DELETE FROM panel_user_relays WHERE login = ?', [$l]);
    if ($role === 'admin') {
        return; // администратору права на реле не нужны
    }
    foreach ($perms as [$dev, $num, $ctl]) {
        relay_q($connect, 'INSERT INTO panel_user_relays (login, device_id, relay_num, can_control) VALUES (?, ?, ?, ?)', [$l, $dev, $num, $ctl]);
    }
};

/** Останется ли хотя бы один включённый администратор, если у $except забрать права */
$adminsLeft = function (string $except) use ($connect) {
    $n = count(relay_file_users());
    $r = mysqli_fetch_row(relay_q($connect, "SELECT COUNT(*) FROM panel_users WHERE role = 'admin' AND enabled = 1 AND login <> ?", [$except]));
    return $n + (int)$r[0];
};

switch ($action) {
    case 'list':
        $users = [];
        foreach (array_keys(relay_file_users()) as $l) {
            $users[] = ['login' => (string)$l, 'name' => '', 'role' => 'admin', 'enabled' => true, 'system' => true,
                        'created_at' => null, 'created_by' => null, 'last_login_ts' => null];
        }
        $rows = relay_rows(mysqli_query($connect, 'SELECT login, name, role, enabled, UNIX_TIMESTAMP(created_at) AS created_ts, created_by,
                                                          UNIX_TIMESTAMP(last_login_at) AS last_login_ts FROM panel_users ORDER BY login'));
        $perms = [];
        foreach (relay_rows(mysqli_query($connect, 'SELECT login, device_id, relay_num, can_control FROM panel_user_relays')) as $p) {
            $perms[$p['login']][(int)$p['device_id']][(int)$p['relay_num']] = $p['can_control'] ? 'control' : 'view';
        }
        foreach ($rows as $r) {
            $users[] = ['login' => $r['login'], 'name' => $r['name'], 'role' => $r['role'] === 'admin' ? 'admin' : 'user',
                        'enabled' => (bool)$r['enabled'], 'system' => false,
                        'created_ts' => (int)$r['created_ts'], 'created_by' => $r['created_by'],
                        'last_login_ts' => $r['last_login_ts'] === null ? null : (int)$r['last_login_ts'],
                        'perms' => (object)($perms[$r['login']] ?? [])];
        }
        // устройства и их реле — для выбора прав
        $devices = [];
        foreach (relay_rows(mysqli_query($connect, "SELECT g.id, COALESCE(NULLIF(g.name_obj, ''), CONCAT('Устройство ', g.id)) AS name,
                    g.name_reley_1, g.name_reley_2, g.name_reley_3, g.name_reley_4, COALESCE(g.relay4_lock, 0) AS relay4_lock, COALESCE(g.relays_disabled, 0) AS relays_disabled,
                    (d.door_state IS NOT NULL OR COALESCE(d.version, 0) >= 3) AS has_lock
               FROM general_info g LEFT JOIN devices d ON d.id = g.id ORDER BY g.id")) as $d) {
            $relays = [];
            for ($i = 1; $i <= 4; $i++) {
                $relays[] = ['num' => $i, 'name' => ($d["name_reley_$i"] ?? '') ?: "Реле $i", 'lock' => $i === 4 && (int)$d['relay4_lock'] === 1,
                             'off' => (bool)(((int)$d['relays_disabled'] >> ($i - 1)) & 1)];
            }
            if ((int)$d['has_lock'] === 1) {
                $relays[] = ['num' => RELAY_LOCK, 'name' => 'Замок (плата ver_5_1)', 'lock' => true];
            }
            $devices[] = ['id' => (int)$d['id'], 'name' => $d['name'], 'relays' => $relays];
        }
        relay_json(['users' => $users, 'roles' => RELAY_ROLES, 'me' => $login, 'min_pass' => RELAY_MIN_PASS, 'devices' => $devices]);

    case 'add':
        $l = trim((string)($_POST['login'] ?? ''));
        if (!preg_match(RELAY_LOGIN_RE, $l)) {
            relay_json(['error' => 'Логин: от 1 до 64 символов — латиница, цифры, _ . @ -'], 400);
        }
        $exists = isset(relay_file_users()[$l])
            || mysqli_fetch_row(relay_q($connect, 'SELECT 1 FROM panel_users WHERE login = ?', [$l]));
        if ($exists) {
            relay_json(['error' => "Пользователь $l уже есть"], 409);
        }
        $newRole = $role();
        $perms = $permsFromPost();
        relay_q($connect, 'INSERT INTO panel_users (login, name, role, pass_hash, created_by) VALUES (?, ?, ?, ?, ?)',
            [$l, $str('name', 100), $newRole, $password(), $login]);
        $savePerms($l, $newRole, $perms);
        relay_json(['ok' => true, 'login' => $l]);

    case 'update':
        $l = (string)($_POST['login'] ?? '');
        $u = $dbUser($l);
        $newRole = $role();
        $enabled = ($_POST['enabled'] ?? '1') === '1' ? 1 : 0;
        if ($l === $login && ($newRole !== $u['role'] || !$enabled)) {
            relay_json(['error' => 'Свою роль и доступ изменить нельзя — попросите другого администратора'], 400);
        }
        if ($u['role'] === 'admin' && ($newRole !== 'admin' || !$enabled) && $adminsLeft($l) === 0) {
            relay_json(['error' => 'Должен остаться хотя бы один администратор'], 400);
        }
        $hash = $password('password', false);
        $perms = $permsFromPost();
        relay_q($connect, 'UPDATE panel_users SET name = ?, role = ?, enabled = ?, pass_hash = COALESCE(?, pass_hash) WHERE login = ?',
            [$str('name', 100), $newRole, $enabled, $hash, $l]);
        $savePerms($l, $newRole, $perms);
        relay_json(['ok' => true]);

    case 'delete':
        $l = (string)($_POST['login'] ?? '');
        if ($l === $login) {
            relay_json(['error' => 'Нельзя удалить самого себя'], 400);
        }
        $u = $dbUser($l);
        if ($u['role'] === 'admin' && $adminsLeft($l) === 0) {
            relay_json(['error' => 'Должен остаться хотя бы один администратор'], 400);
        }
        relay_q($connect, 'DELETE FROM panel_users WHERE login = ?', [$l]);
        relay_json(['ok' => true]);

    case 'passwd':
        $me = relay_user_info($login, true);
        if (!$me || $me['source'] !== 'db') {
            relay_json(['error' => "Ваш пользователь создан при установке сервера. Сменить пароль: sudo relay-device user-add $login"], 400);
        }
        if (!password_verify((string)($_POST['old'] ?? ''), $me['hash'])) {
            usleep(500000);
            relay_json(['error' => 'Текущий пароль указан неверно'], 400);
        }
        $hash = $password();
        relay_q($connect, 'UPDATE panel_users SET pass_hash = ? WHERE login = ?', [$hash, $login]);
        $_SESSION['pwv'] = substr(sha1($hash), 0, 16); // эта сессия остаётся, остальные закрываются
        relay_json(['ok' => true]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
