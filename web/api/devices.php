<?php
/**
 * API устройств собственного брокера.
 *   GET  ?action=list                 — все платы + сводка
 *   GET  ?action=get&id=N             — одна плата (подробно)
 *   POST action=update id, name, address, coordinates, relay1..relay4 — названия и адрес
 *   POST action=command id, command, relay — команда плате (1..5)
 *   GET  ?action=command_status&cmd_id=N
 *   POST action=add id, name, address, relay1..relay4 — зарегистрировать новую плату (брокер + панель)
 *   GET  ?action=pending              — платы, которые пытались подключиться, но не зарегистрированы
 *   POST action=pending_ignore id     — скрыть такую плату из списка
 *   GET  ?action=battery&id=N&hours=24 — история напряжения АКБ
 *   POST action=delete id             — удалить плату: закрыть доступ к брокеру и убрать из панели
 *   POST action=bulk_relays ids («1, 3, 5-10»), relay1..relay4, relay4_lock ('' | 1 | 0) — массовое изменение устройств:
 *        названия реле (пустое — не менять) и «Реле 4 — замок двери» ('' — не менять)
 *   POST action=mqtt_block id, minutes (0 — до отмены) — не принимать сообщения от устройства (версия 1 перезагрузится)
 *   POST action=mqtt_unblock id       — снова принимать сообщения
 *   POST action=ack_break_in id       — сбросить тревогу «взлом двери» (администратор или управляющий замком)
 *   GET  ?action=export_repair[&mount=repair|not_mounted|all] — таблица CSV (Excel) устройств «демонтировано (в ремонт)»:
 *        ID, название, адрес, координаты (администратор)
 *   Монтаж (add/update: mount = mounted | not_mounted | repair; bulk_relays: mount, '' — не менять):
 *        не смонтированные и демонтированные без связи не считаются «нет связи»; новое устройство — «не смонтировано»
 *
 * Администратор видит всё. Пользователь — только выданные ему реле (panel_user_relays): list/get/battery
 * отдают только их, command проверяет право управления. add/update/delete/pending — только администратор.
 * Калибровка АКБ: bat_type 12 (cal_low/cal_high — АЦП при 6 и 15 В) или 24 (при 19 и 26 В).
 */
require __DIR__ . '/../conectbd.php';
require __DIR__ . '/../lib/devices.php';

$action = $_REQUEST['action'] ?? 'list';
if (in_array($action, ['add', 'update', 'command', 'delete', 'pending_ignore', 'ack_break_in', 'bulk_relays', 'mqtt_block', 'mqtt_unblock'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}
if (in_array($action, ['add', 'update', 'delete', 'pending', 'pending_ignore', 'bulk_relays', 'export_repair'], true) && !relay_is_admin()) {
    relay_forbidden('Добавлять устройства и изменять их данные может только администратор');
}

/** Номер SIM-карты из формы → «+79997777777» ('' — не указан). 8 в начале заменяется на +7; пробелы, скобки, дефисы убираются. */
function sim_phone_from_post(): string
{
    $raw = trim((string)($_POST['sim_phone'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 11 && ($d[0] === '7' || $d[0] === '8')) {
        $d = substr($d, 1);
    }
    if (!preg_match('/^\d{10}$/', $d)) {
        relay_json(['error' => 'Номер SIM-карты: +7 и 10 цифр, например +79997777777'], 400);
    }
    return '+7' . $d;
}

/** Версия устройства из формы: '' — по данным устройства (NULL), 1, 11 («1-1»: в поле сигнала — АЦП АКБ), 2, 3 */
function hw_version_from_post(): ?int
{
    $v = (string)($_POST['hw_version'] ?? '');
    if ($v === '') {
        return null;
    }
    if (!in_array($v, ['1', '11', '2', '3'], true)) {
        relay_json(['error' => 'Версия устройства: 1, 1-1, 2 или 3'], 400);
    }
    return (int)$v;
}

/** Режим реле 4 из формы: relay4_mode = normal | lock | wash (или старый флажок relay4_lock) → [замок, омывание] */
function relay4_mode_from_post(): array
{
    $m = (string)($_POST['relay4_mode'] ?? (($_POST['relay4_lock'] ?? '0') === '1' ? 'lock' : 'normal'));
    return [$m === 'lock' ? 1 : 0, $m === 'wash' ? 1 : 0];
}

/** Монтаж из формы: mount = mounted | not_mounted | repair (или флажки not_mounted / repair = 1) → 0 | 1 | 2 */
function mount_from_post(int $default): int
{
    $m = (string)($_POST['mount'] ?? '');
    if ($m === '' && (isset($_POST['not_mounted']) || isset($_POST['repair']))) {
        $m = ($_POST['repair'] ?? '0') === '1' ? 'repair' : (($_POST['not_mounted'] ?? '0') === '1' ? 'not_mounted' : 'mounted');
    }
    if ($m === '') {
        return $default;
    }
    $v = array_search($m, RELAY_MOUNT, true);
    if ($v === false) {
        relay_json(['error' => 'Монтаж: mounted, not_mounted или repair'], 400);
    }
    return $v;
}

/** Сохранить состояние монтажа; при изменении — дата и запись в журнал */
function save_mount(mysqli $db, int $id, int $mount, ?string $login): void
{
    $res = relay_q($db, 'SELECT COALESCE(mount_state, 0) FROM general_info WHERE id = ?', [$id]);
    $row = $res instanceof mysqli_result ? mysqli_fetch_row($res) : null;
    if ($row && (int)$row[0] === $mount) {
        return;
    }
    relay_q($db, 'UPDATE general_info SET mount_state = ?, mount_changed_at = NOW() WHERE id = ?', [$mount, $id]);
    relay_event($db, $id, 'info', 'Монтаж: ' . RELAY_MOUNT_LABEL[RELAY_MOUNT[$mount]], 'info', null, $login);
}

/** Отключённые реле из формы (relay1_off … relay4_off = 1) → битовая маска */
function disabled_from_post(): int
{
    $m = 0;
    for ($i = 1; $i <= 4; $i++) {
        if (($_POST["relay{$i}_off"] ?? '0') === '1') {
            $m |= 1 << ($i - 1);
        }
    }
    return $m;
}

/** Тип АКБ и калибровка из формы → [bat_type, столбец_low => АЦП, столбец_high => АЦП] */
function bat_from_post(): array
{
    $type = relay_bat_type($_POST['bat_type'] ?? 24); // по умолчанию АКБ 24 В
    $t = RELAY_BAT_TYPES[$type];
    try {
        $lo = relay_cal_value($_POST['cal_low'] ?? '');
        $hi = relay_cal_value($_POST['cal_high'] ?? '');
    } catch (RuntimeException $e) {
        relay_json(['error' => $e->getMessage()], 400);
    }
    if ($lo !== null && $lo === $hi) {
        relay_json(['error' => "Значения АЦП при {$t['low']} В и при {$t['high']} В должны различаться"], 400);
    }
    return [$type, $t['col_low'], $lo, $t['col_high'], $hi];
}

switch ($action) {
    case 'list':
        $devices = device_rows($connect);
        $sum = relay_devices_summary($devices);
        $last = mysqli_fetch_assoc(mysqli_query($connect, "SELECT MAX(ts) AS ts, UNIX_TIMESTAMP(MAX(ts)) AS t FROM mqtt_messages WHERE broker_id = 1 AND direction = 'in'"));
        relay_json(['devices' => $devices, 'summary' => $sum, 'last_message_ts' => $last['t'] ? (int)$last['t'] : null, 'now' => time(),
                    'is_admin' => relay_is_admin()]);

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        $rows = device_rows($connect, $id);
        if (!$rows) {
            relay_json(['error' => 'Устройство не найдено'], 404);
        }
        $d = $rows[0];
        // последнее поступление данных: последнее входящее сообщение платы
        $m = mysqli_fetch_assoc(relay_q($connect,
            "SELECT id, ts, UNIX_TIMESTAMP(ts) AS t, topic, decoded, retained, payload_hex FROM mqtt_messages
              WHERE broker_id = 1 AND device_id = ? AND direction = 'in' ORDER BY id DESC LIMIT 1", [$id]));
        if ($m) {
            $m['t'] = (int)$m['t'];
            $m['retained'] = (bool)$m['retained'];
            $m['decoded'] = $m['decoded'] ? json_decode($m['decoded'], true) : null;
        }
        $d['last_message'] = $m ?: null;
        $d['last_command'] = mysqli_fetch_assoc(relay_q($connect,
            'SELECT cmd_id, command, relay_num, status, created_at, confirmed_at FROM commands WHERE device_id = ? AND command <> 4 ORDER BY cmd_id DESC LIMIT 1', [$id])) ?: null;
        $d['stats'] = mysqli_fetch_assoc(relay_q($connect,
            "SELECT SUM(ts > NOW() - INTERVAL 1 DAY) AS day, SUM(ts > NOW() - INTERVAL 1 HOUR) AS hour, COUNT(*) AS kept
               FROM mqtt_messages WHERE broker_id = 1 AND device_id = ? AND direction = 'in'", [$id]));
        // соседние устройства (по номеру, только доступные пользователю) — кнопки «предыдущее / следующее»
        $ids = array_map(fn($r) => (int)$r['id'], relay_rows(mysqli_query($connect, 'SELECT id FROM general_info UNION SELECT id FROM devices ORDER BY id')));
        $ids = array_values(array_filter($ids, fn($x) => relay_can_view($x)));
        $pos = array_search($id, $ids, true);
        $d['prev_id'] = $pos !== false && $pos > 0 ? $ids[$pos - 1] : null;
        $d['next_id'] = $pos !== false && $pos < count($ids) - 1 ? $ids[$pos + 1] : null;
        $d['position'] = $pos === false ? null : [$pos + 1, count($ids)];
        relay_json(['device' => $d, 'now' => time()]);

    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            relay_json(['error' => 'Не указано устройство'], 400);
        }
        $f = fn($k, $max = 200) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $name = $f('name') ?: "Устройство $id";
        [$batType, $colLo, $calLo, $colHi, $calHi] = bat_from_post();
        [$lock4, $wash4] = relay4_mode_from_post();
        $off = disabled_from_post();
        $sim = sim_phone_from_post();
        $owner = mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', (string)($_POST['owner'] ?? ''))), 0, 200); // для кого установлено
        relay_q($connect, 'INSERT INTO general_info (id, name_obj, address, coordinates, name_reley_1, name_reley_2, name_reley_3, name_reley_4)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE name_obj = VALUES(name_obj), address = VALUES(address), coordinates = VALUES(coordinates),
                             name_reley_1 = VALUES(name_reley_1), name_reley_2 = VALUES(name_reley_2),
                             name_reley_3 = VALUES(name_reley_3), name_reley_4 = VALUES(name_reley_4)',
            [$id, $name, $f('address', 300), $f('coordinates', 100),
             $f('relay1', 60) ?: 'Реле 1', $f('relay2', 60) ?: 'Реле 2', $f('relay3', 60) ?: 'Реле 3', $f('relay4', 60) ?: 'Реле 4']);
        $hw = hw_version_from_post();
        $batOn = ($_POST['bat_monitor'] ?? '1') === '1' ? 1 : 0;
        $lockUnused = ($_POST['lock_unused'] ?? '0') === '1' ? 1 : 0;
        // версия 3 (выбранная или по данным устройства) — у платы свой замок, «реле 4 — замок» не ставим
        $rvRes = relay_q($connect, 'SELECT version FROM devices WHERE id = ?', [$id]);
        $rv = $rvRes instanceof mysqli_result ? mysqli_fetch_row($rvRes) : null;
        if (($hw ?? (int)($rv[0] ?? 0)) >= 3) {
            $lock4 = 0;
        }
        relay_q($connect, "UPDATE general_info SET bat_type = ?, relay4_lock = ?, relay4_wash = ?, relays_disabled = ?, sim_phone = ?, owner = ?, hw_version = ?, bat_monitor = ?, lock_unused = ?,
                                  `$colLo` = ?, `$colHi` = ? WHERE id = ?",
            [$batType, $lock4, $wash4, $off, $sim, $owner, $hw, $batOn, $lockUnused, $calLo, $calHi, $id]);
        relay_q($connect, 'UPDATE devices SET name = ?, location = ? WHERE id = ?', [$name, $f('address', 100), $id]);
        if (isset($_POST['mount']) || isset($_POST['not_mounted']) || isset($_POST['repair'])) {
            save_mount($connect, $id, mount_from_post(0), $login);
        }
        relay_event($connect, $id, 'info', 'Изменены данные устройства: название, адрес, подписи реле, АКБ ' . $batType . ' В'
            . ($lock4 ? ', реле 4 — замок' : '') . ($wash4 ? ', реле 4 — омывание' : '') . ($off ? ', отключены реле ' . implode(', ', array_filter([1, 2, 3, 4], fn($i) => ($off >> ($i - 1)) & 1)) : ''),
            'info', null, $login);
        relay_json(['ok' => true]);

    case 'command':
        relay_check_command_access((int)($_POST['id'] ?? 0), (int)($_POST['command'] ?? 0), (int)($_POST['relay'] ?? 0));
        // отключённым реле команды не отправляются (и «всем реле», если хоть одно отключено); реле 4 — омывание
        $cmdErr = relay_command_error($connect, (int)($_POST['id'] ?? 0), (int)($_POST['command'] ?? 0), (int)($_POST['relay'] ?? 0));
        if ($cmdErr !== null) {
            relay_json(['error' => $cmdErr], 400);
        }
        try {
            $cmdId = relay_send_command($connect, (int)($_POST['id'] ?? 0), (int)($_POST['command'] ?? 0), (int)($_POST['relay'] ?? 0), $login);
            relay_json(['ok' => true, 'cmd_id' => $cmdId]);
        } catch (RuntimeException $e) {
            relay_json(['error' => $e->getMessage()], 400);
        }

    case 'command_status':
        relay_json(['status' => relay_command_status($connect, (int)($_GET['cmd_id'] ?? 0))]);

    case 'add':
        $raw = trim((string)($_POST['id'] ?? ''));
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $raw) || (int)$raw > 4294967295) {
            relay_json(['error' => 'ID устройства — целое число от 1 без ведущих нулей (как в меню настройки устройства)'], 400);
        }
        $id = (int)$raw;
        $exists = relay_q($connect, 'SELECT 1 FROM general_info WHERE id = ? UNION SELECT 1 FROM devices WHERE id = ?', [$id, $id]);
        if ($exists && mysqli_num_rows($exists) > 0) {
            relay_json(['error' => "Устройство #$id уже есть в панели"], 409);
        }
        $f = fn($k, $max) => mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', (string)($_POST[$k] ?? ''))), 0, $max);
        $name = $f('name', 200) ?: "Устройство $id";
        $address = $f('address', 300);
        [$batType, $colLo, $calLo, $colHi, $calHi] = bat_from_post();
        [$lock4, $wash4] = relay4_mode_from_post();
        $off = disabled_from_post();
        $sim = sim_phone_from_post();
        $owner = mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', (string)($_POST['owner'] ?? ''))), 0, 200); // для кого установлено
        $hwAdd = hw_version_from_post();
        $batAdd = ($_POST['bat_monitor'] ?? '1') === '1' ? 1 : 0;
        $lockUnusedAdd = ($_POST['lock_unused'] ?? '0') === '1' ? 1 : 0;
        if ($hwAdd === 3) {
            $lock4 = 0; // у платы ver_5_1 свой замок
        }
        // 1. учётная запись MQTT + ACL + reload брокера (нужен root → sudo relay-device add)
        [$code, $out] = relay_device_cli(['add', (string)$id, $name, $address]);
        if ($code !== 0) {
            relay_json(['error' => "Не удалось зарегистрировать устройство в брокере: $out"
                . ($code === 126 ? '. Обновите сервер командой «sudo relay-update»; пока можно добавить устройство на сервере: sudo relay-device add ' . $id : ''),
                'hint' => "sudo relay-device add $id"], 500);
        }
        // 2. подписи реле (relay-device создаёт стандартные «Реле N»)
        $relays = [];
        for ($i = 1; $i <= 4; $i++) {
            $relays[$i] = $f("relay$i", 60) ?: "Реле $i";
        }
        relay_q($connect, "UPDATE general_info SET name_reley_1 = ?, name_reley_2 = ?, name_reley_3 = ?, name_reley_4 = ?,
                                  bat_type = ?, relay4_lock = ?, relay4_wash = ?, relays_disabled = ?, sim_phone = ?, owner = ?, hw_version = ?, bat_monitor = ?, lock_unused = ?,
                                  `$colLo` = ?, `$colHi` = ? WHERE id = ?",
            [$relays[1], $relays[2], $relays[3], $relays[4], $batType, $lock4, $wash4, $off, $sim, $owner, $hwAdd, $batAdd, $lockUnusedAdd, $calLo, $calHi, $id]);
        relay_q($connect, 'DELETE FROM pending_devices WHERE id = ?', [$id]);
        relay_event($connect, $id, 'info', 'Устройство зарегистрировано в панели', 'info', null, $login);
        // новое устройство по умолчанию «не смонтировано»
        $mountAdd = mount_from_post(1);
        relay_q($connect, 'UPDATE general_info SET mount_state = ?, mount_changed_at = NOW() WHERE id = ?', [$mountAdd, $id]);
        relay_json(['ok' => true, 'id' => $id, 'mqtt' => [
            'username' => "relay-$id", 'password' => relay_device_password($id), 'topic' => "com/$id",
        ]]);

    case 'bulk_relays':
        try {
            $ids = relay_parse_id_list((string)($_POST['ids'] ?? ''));
        } catch (RuntimeException $e) {
            relay_json(['error' => $e->getMessage()], 400);
        }
        if (!$ids) {
            relay_json(['error' => 'Укажите номера устройств, например: 1, 3, 5-10'], 400);
        }
        $set = [];
        $params = [];
        $names = [];
        for ($i = 1; $i <= 4; $i++) {
            $v = mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', (string)($_POST["relay$i"] ?? ''))), 0, 60);
            if ($v !== '') {
                $set[] = "name_reley_$i = ?";
                $params[] = $v;
                $names[] = "реле $i — «{$v}»";
            }
        }
        $ownerNew = mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', (string)($_POST['owner'] ?? ''))), 0, 200);
        if ($ownerNew !== '') {
            $set[] = 'owner = ?';
            $params[] = $ownerNew;
            $names[] = "для кого установлено — «{$ownerNew}»";
        }
        $hwMode = (string)($_POST['hw_version'] ?? 'keep');
        if (in_array($hwMode, ['auto', '1', '11', '2', '3'], true)) {
            $set[] = 'hw_version = ?';
            $params[] = $hwMode === 'auto' ? null : (int)$hwMode;
            $names[] = $hwMode === 'auto' ? 'версия — по данным устройства' : 'версия ' . ($hwMode === '11' ? '1-1' : $hwMode);
        }
        $batMode = (string)($_POST['bat_monitor'] ?? '');
        if ($batMode === '1' || $batMode === '0') {
            $set[] = 'bat_monitor = ?';
            $params[] = (int)$batMode;
            $names[] = $batMode === '1' ? 'контроль АКБ включён' : 'контроль АКБ выключен';
        }
        $unusedMode = (string)($_POST['lock_unused'] ?? '');
        if ($unusedMode === '1' || $unusedMode === '0') {
            $set[] = 'lock_unused = ?';
            $params[] = (int)$unusedMode;
            $names[] = $unusedMode === '1' ? 'замок платы ver_5_1 не используется' : 'замок платы ver_5_1 используется';
        }
        $mountMode = (string)($_POST['mount'] ?? '');
        $mountBulk = $mountMode === '' ? null : mount_from_post(0);
        if ($mountBulk !== null) {
            $names[] = 'монтаж — ' . RELAY_MOUNT_LABEL[RELAY_MOUNT[$mountBulk]];
        }
        $r4Mode = (string)($_POST['relay4_mode'] ?? '');
        $lockMode = (string)($_POST['relay4_lock'] ?? '');   // старый вариант: 1 / 0
        if ($r4Mode === '' && ($lockMode === '1' || $lockMode === '0')) {
            $r4Mode = $lockMode === '1' ? 'lock' : 'normal';
        }
        if (in_array($r4Mode, ['normal', 'lock', 'wash'], true)) {
            $set[] = 'relay4_lock = ?';
            $params[] = $r4Mode === 'lock' ? 1 : 0;
            $set[] = 'relay4_wash = ?';
            $params[] = $r4Mode === 'wash' ? 1 : 0;
            $names[] = ['normal' => 'реле 4 — обычное реле', 'lock' => 'реле 4 — замок двери', 'wash' => 'реле 4 — омывание'][$r4Mode];
        }
        if (!$set && $mountBulk === null) {
            relay_json(['error' => 'Укажите, что изменить: для кого установлено, названия реле, версию, контроль АКБ, режим реле 4 или монтаж'], 400);
        }
        $updated = $missing = [];
        foreach ($ids as $id) {
            $res = relay_q($connect, 'SELECT 1 FROM general_info WHERE id = ?', [$id]);
            if (!$res || mysqli_num_rows($res) === 0) {
                $missing[] = $id;
                continue;
            }
            if ($set) {
                relay_q($connect, 'UPDATE general_info SET ' . implode(', ', $set) . ' WHERE id = ?', [...$params, $id]);
            }
            if ($mountBulk !== null) {
                relay_q($connect, 'UPDATE general_info SET mount_changed_at = IF(COALESCE(mount_state, 0) <> ?, NOW(), mount_changed_at), mount_state = ? WHERE id = ?',
                    [$mountBulk, $mountBulk, $id]);
            }
            relay_event($connect, $id, 'info', 'Массовое изменение: ' . implode(', ', $names), 'info', null, $login);
            $updated[] = $id;
        }
        relay_json(['ok' => true, 'updated' => $updated, 'missing' => $missing]);

    case 'mqtt_block':
    case 'mqtt_unblock':
        $id = (int)($_POST['id'] ?? 0);
        $rows = device_rows($connect, $id);
        if (!$rows || !$rows[0]['can_reboot']) {
            relay_forbidden('Недостаточно прав для этого устройства');
        }
        $d = $rows[0];
        if ($action === 'mqtt_block') {
            if (!in_array($d['dev_version'], [1, RELAY_V11], true)) {
                relay_json(['error' => 'Только для устройств версии 1: у остальных есть команда «Перезагрузить устройство»'], 400);
            }
            $min = (int)($_POST['minutes'] ?? 5);
            $min = in_array($min, [0, 2, 5, 15, 60], true) ? $min : 5;
            [$code, $out] = relay_device_cli(['block', (string)$id]);
            if ($code !== 0) {
                relay_json(['error' => "Не удалось отключить устройство в брокере: $out"], 500);
            }
            relay_q($connect, 'UPDATE general_info SET mqtt_block_at = NOW(),
                                      mqtt_block_until = IF(? = 0, NULL, NOW() + INTERVAL ? MINUTE) WHERE id = ?', [$min, $min, $id]);
            relay_event($connect, $id, 'reboot', 'Сообщения MQTT от устройства не принимаются' . ($min ? " ($min мин)" : ' (до отмены)')
                . ' — устройство потеряет связь и перезагрузится', 'warning', null, $login);
            relay_q($connect, 'DELETE FROM pending_devices WHERE id = ?', [$id]);
        } else {
            [$code, $out] = relay_device_cli(['unblock', (string)$id]);
            if ($code !== 0) {
                relay_json(['error' => "Не удалось вернуть доступ устройства к брокеру: $out"], 500);
            }
            relay_q($connect, 'UPDATE general_info SET mqtt_block_at = NULL, mqtt_block_until = NULL WHERE id = ?', [$id]);
            relay_event($connect, $id, 'info', 'Сообщения MQTT от устройства снова принимаются', 'info', null, $login);
        }
        relay_json(['ok' => true]);

    case 'ack_break_in':
        $id = (int)($_POST['id'] ?? 0);
        $rows = device_rows($connect, $id);
        if (!$rows || !$rows[0]['can_ack']) {
            relay_forbidden('Сбросить тревогу может администратор или пользователь, управляющий замком');
        }
        relay_q($connect, 'UPDATE devices SET break_in_at = NULL, break_in_source = NULL WHERE id = ?', [$id]);
        relay_event($connect, $id, 'info', 'Тревога «взлом двери» сброшена', 'info', null, $login);
        relay_json(['ok' => true]);

    case 'pending':
        $rows = relay_rows(mysqli_query($connect,
            "SELECT p.id, p.client_id, p.ip, p.attempts, UNIX_TIMESTAMP(p.first_seen) AS first_ts, UNIX_TIMESTAMP(p.last_seen) AS last_ts
               FROM pending_devices p
              WHERE p.ignored = 0 AND p.last_seen > NOW() - INTERVAL 7 DAY
                AND p.id NOT IN (SELECT id FROM general_info)
              ORDER BY p.last_seen DESC LIMIT 50"));
        foreach ($rows as &$r) {
            foreach (['id', 'attempts', 'first_ts', 'last_ts'] as $k) {
                $r[$k] = (int)$r[$k];
            }
        }
        relay_json(['pending' => $rows]);

    case 'pending_ignore':
        relay_q($connect, 'UPDATE pending_devices SET ignored = 1 WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        relay_json(['ok' => true]);

    case 'battery':
        $id = (int)($_GET['id'] ?? 0);
        $hours = min(24 * 31, max(1, (int)($_GET['hours'] ?? 24)));
        if (!relay_can_view($id)) {
            relay_forbidden('Нет доступа к этому устройству');
        }
        $g = mysqli_fetch_assoc(relay_q($connect, 'SELECT g.bat_type, g.cal_adc_6, g.cal_adc_15, g.cal_adc_19, g.cal_adc_26, COALESCE(g.bat_monitor, 1) AS bat_monitor,
                                                         COALESCE(g.hw_version, d.version) AS dev_version
                                                    FROM general_info g LEFT JOIN devices d ON d.id = g.id WHERE g.id = ?', [$id])) ?: [];
        if (!(int)($g['bat_monitor'] ?? 1) || (isset($g['dev_version']) && (int)$g['dev_version'] === 1)) {
            relay_json(['points' => [], 'calibrated' => false, 'hours' => $hours, 'bat_off' => true]); // контроль АКБ выключен
        }
        $batType = relay_bat_type($g['bat_type'] ?? 12);
        $bt = RELAY_BAT_TYPES[$batType];
        $cal = ['lo' => $g[$bt['col_low']] ?? null, 'hi' => $g[$bt['col_high']] ?? null];
        $field = (int)($g['dev_version'] ?? 0) === RELAY_V11 ? '$.sl' : '$.bat_adc'; // версия 1-1: АЦП АКБ в поле сигнала
        $rows = relay_rows(relay_q($connect,
            "SELECT UNIX_TIMESTAMP(ts) AS t, CAST(JSON_VALUE(decoded, '$field') AS UNSIGNED) AS adc
               FROM mqtt_messages
              WHERE broker_id = 1 AND device_id = ? AND topic = 'stat' AND direction = 'in'
                AND ts > NOW() - INTERVAL ? HOUR AND JSON_VALUE(decoded, '$field') IS NOT NULL
              ORDER BY id", [$id, $hours]));
        $points = [];
        foreach ($rows as $r) {
            $points[] = [(int)$r['t'], (int)$r['adc'], relay_battery_voltage((int)$r['adc'], $cal['lo'], $cal['hi'], $batType)];
        }
        relay_json(['points' => $points, 'calibrated' => isset($cal['lo'], $cal['hi']), 'hours' => $hours,
                    'bat_type' => $batType, 'v_low' => $bt['low'], 'v_high' => $bt['high']]);

    case 'export_repair':
        // таблица для Excel: CSV в UTF-8 с BOM, разделитель «;»
        $which = (string)($_GET['mount'] ?? 'repair');
        $states = $which === 'all' ? [1, 2] : [$which === 'not_mounted' ? 1 : 2];
        $rows = relay_rows(relay_q($connect, 'SELECT id, name_obj, address, coordinates, owner, sim_phone, mount_state, mount_changed_at
                                                 FROM general_info WHERE COALESCE(mount_state, 0) IN (' . implode(',', $states) . ') ORDER BY id'));
        $title = $which === 'all' ? 'not-mounted-and-repair' : ($which === 'not_mounted' ? 'not-mounted' : 'repair');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="devices-' . $title . '-' . date('Y-m-d') . '.csv"; filename*=UTF-8\'\'' . rawurlencode(($which === 'not_mounted' ? 'не смонтированы' : 'в ремонте') . ' ' . date('Y-m-d') . '.csv'));
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $cell = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v; // не формула в Excel
        fputcsv($out, ['ID', 'Название', 'Адрес', 'Координаты', 'Широта', 'Долгота', 'Для кого установлено', 'Состояние', 'С какого времени'], ';');
        foreach ($rows as $r) {
            $geo = parse_coords((string)$r['coordinates']);
            fputcsv($out, [$r['id'], $cell($r['name_obj']), $cell($r['address']), $cell($r['coordinates']),
                           $geo ? str_replace('.', ',', (string)$geo[0]) : '', $geo ? str_replace('.', ',', (string)$geo[1]) : '',
                           $cell($r['owner']),
                           RELAY_MOUNT_LABEL[RELAY_MOUNT[(int)$r['mount_state']] ?? 'mounted'], $r['mount_changed_at'] ?? ''], ';');
        }
        fclose($out);
        exit;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            relay_json(['error' => 'Не указано устройство'], 400);
        }
        [$code, $out] = relay_device_cli(['del', (string)$id]);
        relay_q($connect, 'DELETE FROM devices WHERE id = ?', [$id]);      // каскадом: relay_states, commands, device_last_update
        relay_q($connect, 'DELETE FROM general_info WHERE id = ?', [$id]);
        relay_q($connect, 'DELETE FROM device_events WHERE device_id = ?', [$id]);
        @relay_q($connect, 'DELETE FROM panel_user_relays WHERE device_id = ?', [$id]);
        relay_json(['ok' => true, 'note' => $code === 0
            ? 'Доступ устройства к брокеру закрыт.'
            : "Не удалось закрыть доступ к брокеру автоматически ($out). Выполните на сервере: sudo relay-device del $id"]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
