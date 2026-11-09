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
 *
 * Администратор видит всё. Пользователь — только выданные ему реле (panel_user_relays): list/get/battery
 * отдают только их, command проверяет право управления. add/update/delete/pending — только администратор.
 * Калибровка АКБ: bat_type 12 (cal_low/cal_high — АЦП при 6 и 15 В) или 24 (при 19 и 26 В).
 */
require __DIR__ . '/../conectbd.php';

$action = $_REQUEST['action'] ?? 'list';
if (in_array($action, ['add', 'update', 'command', 'delete', 'pending_ignore', 'ack_break_in', 'bulk_relays', 'mqtt_block', 'mqtt_unblock'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}
if (in_array($action, ['add', 'update', 'delete', 'pending', 'pending_ignore', 'bulk_relays'], true) && !relay_is_admin()) {
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

/** Координаты «55.7558, 37.6173» (также «55.7558 37.6173», «55,7558; 37,6173») → [широта, долгота] или null */
function parse_coords(string $s): ?array
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if (str_contains($s, ';') || preg_match('/^-?\d+,\d+\s+-?\d+,\d+$/', $s)) {
        $s = str_replace(',', '.', $s); // десятичная запятая
    }
    if (!preg_match_all('/-?\d+(?:\.\d+)?/', $s, $m) || count($m[0]) !== 2) {
        return null;
    }
    [$lat, $lon] = array_map('floatval', $m[0]);
    return abs($lat) <= 90 && abs($lon) <= 180 && !($lat == 0 && $lon == 0) ? [$lat, $lon] : null;
}

/** Тип АКБ и калибровка из формы → [bat_type, столбец_low => АЦП, столбец_high => АЦП] */
function bat_from_post(): array
{
    $type = relay_bat_type($_POST['bat_type'] ?? 12);
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

function device_rows(mysqli $db, ?int $onlyId = null): array
{
    $where = $onlyId ? 'WHERE x.id = ' . (int)$onlyId : '';
    $rows = relay_rows(mysqli_query($db, "
        SELECT x.id,
               COALESCE(NULLIF(g.name_obj, ''), d.name, CONCAT('Устройство ', x.id)) AS name,
               COALESCE(NULLIF(g.address, ''), d.location, '') AS address,
               COALESCE(g.coordinates, '') AS coordinates, g.cal_adc_6, g.cal_adc_15, g.cal_adc_19, g.cal_adc_26,
               COALESCE(g.bat_type, 12) AS bat_type, COALESCE(g.relay4_lock, 0) AS relay4_lock, COALESCE(g.relay4_wash, 0) AS relay4_wash,
               COALESCE(g.relays_disabled, 0) AS relays_disabled, COALESCE(g.sim_phone, '') AS sim_phone, COALESCE(g.owner, '') AS owner,
               g.hw_version, COALESCE(g.bat_monitor, 1) AS bat_monitor,
               UNIX_TIMESTAMP(g.mqtt_block_at) AS mqtt_block_ts, UNIX_TIMESTAMP(g.mqtt_block_until) AS mqtt_block_until_ts,
               (SELECT COUNT(*) FROM device_comments c WHERE c.device_id = x.id) AS comments_count,
               (SELECT c.text FROM device_comments c WHERE c.device_id = x.id ORDER BY c.id DESC LIMIT 1) AS last_comment, COALESCE(g.lock_unused, 0) AS lock_unused, UNIX_TIMESTAMP(d.break_in_at) AS break_in_ts, d.break_in_source,
               d.bat_adc, UNIX_TIMESTAMP(d.bat_adc_at) AS bat_adc_ts,
               d.lock_state, d.door_state,
               g.name_reley_1, g.name_reley_2, g.name_reley_3, g.name_reley_4,
               d.status, d.version, d.signal_level, d.battery_power AS alarm, d.conn_state, d.msg_count,
               d.last_seen, UNIX_TIMESTAMP(d.last_seen) AS last_seen_ts,
               d.last_data_at, UNIX_TIMESTAMP(d.last_data_at) AS last_data_ts, d.last_data_topic,
               d.created_at, (d.id IS NOT NULL) AS registered
          FROM (SELECT id FROM general_info UNION SELECT id FROM devices) x
          LEFT JOIN general_info g ON g.id = x.id
          LEFT JOIN devices d ON d.id = x.id
          $where
         ORDER BY x.id"));
    $relays = [];
    foreach (relay_rows(mysqli_query($db, 'SELECT device_id, relay_num, state, UNIX_TIMESTAMP(updated_at) AS ts FROM relay_states'
             . ($onlyId ? ' WHERE device_id = ' . (int)$onlyId : ''))) as $r) {
        $relays[(int)$r['device_id']][(int)$r['relay_num']] = ['state' => (int)$r['state'], 'ts' => (int)$r['ts']];
    }
    $now = time();
    $perms = relay_user_perms();   // null — администратор
    $admin = $perms === null;
    $out = [];
    foreach ($rows as $d) {
        $id = (int)$d['id'];
        if (!$admin && empty($perms[$id])) {
            continue; // пользователю не выдано ни одного реле этого устройства
        }
        $d['id'] = $id;
        $d['relay4_lock'] = (int)$d['relay4_lock'] === 1;
        $d['relay4_wash'] = (int)$d['relay4_wash'] === 1;   // реле 4 — омывание: только «Запуск»
        $mask = (int)$d['relays_disabled'];
        $d['relays_disabled'] = array_values(array_filter([1, 2, 3, 4], fn($i) => ($mask >> ($i - 1)) & 1));
        $d['relay_names'] = [];
        $d['relays'] = [];
        for ($i = 1; $i <= 4; $i++) {
            $d['relay_names'][$i] = ($d["name_reley_$i"] ?? '') ?: "Реле $i";
            if (($mask >> ($i - 1)) & 1) {
                unset($d["name_reley_$i"]);
                continue; // отключённое реле не показывается и не управляется
            }
            if ($admin || isset($perms[$id][$i])) {
                $d['relays'][] = ['num' => $i, 'name' => ($d["name_reley_$i"] ?? '') ?: "Реле $i",
                                  'state' => $relays[$id][$i]['state'] ?? null, 'ts' => $relays[$id][$i]['ts'] ?? null,
                                  'lock' => $i === 4 && $d['relay4_lock'],
                                  'wash' => $i === 4 && $d['relay4_wash'],
                                  'control' => $admin || $perms[$id][$i]];
            }
            unset($d["name_reley_$i"]);
        }
        foreach (['status', 'version', 'signal_level', 'alarm', 'msg_count', 'last_seen_ts', 'last_data_ts', 'bat_adc', 'bat_adc_ts',
                  'cal_adc_6', 'cal_adc_15', 'cal_adc_19', 'cal_adc_26', 'lock_state', 'door_state', 'bat_type'] as $k) {
            $d[$k] = $d[$k] === null ? null : (int)$d[$k];
        }
        // АКБ: активная пара калибровки по типу 12 / 24 В
        $bt = RELAY_BAT_TYPES[relay_bat_type($d['bat_type'])];
        $d['bat_type'] = relay_bat_type($d['bat_type']);
        $d['bat_v_low'] = $bt['low']; $d['bat_v_high'] = $bt['high'];
        $d['bat_v_bad'] = $bt['bad']; $d['bat_v_warn'] = $bt['warn'];
        $d['cal_low'] = $d[$bt['col_low']]; $d['cal_high'] = $d[$bt['col_high']];
        // версия устройства: выбранная в настройках или по данным устройства (поле version пакета stat)
        $d['hw_version'] = $d['hw_version'] === null ? null : (int)$d['hw_version'];
        $d['dev_version'] = $d['hw_version'] ?? $d['version'];
        $d['bat_monitor'] = (int)$d['bat_monitor'] === 1;
        // «не принимать сообщения»: учётная запись устройства убрана из брокера
        $d['mqtt_block'] = $d['mqtt_block_ts'] ? ['ts' => (int)$d['mqtt_block_ts'],
            'until' => $d['mqtt_block_until_ts'] ? (int)$d['mqtt_block_until_ts'] : null] : null;
        unset($d['mqtt_block_ts'], $d['mqtt_block_until_ts']);
        $d['comments_count'] = (int)$d['comments_count'];
        $d['last_comment'] = $d['last_comment'] === null ? null : mb_substr($d['last_comment'], 0, 200);
        // контроль АКБ: выключен в настройках или версия 1 (в её пакете нет АКБ) — напряжение не показываем
        // версия 1-1 (11): первоначальная плата, в поле «уровень сигнала» пакета stat — АЦП АКБ
        if ($d['dev_version'] === RELAY_V11) {
            $d['bat_adc'] = $d['signal_level'];
            $d['bat_adc_ts'] = $d['last_seen_ts'];
            $d['signal_level'] = null; // настоящего уровня сигнала такая плата не передаёт
        }
        $d['bat_on'] = $d['bat_monitor'] && ($d['dev_version'] === null || $d['dev_version'] >= 2);
        if (!$d['bat_on']) {
            $d['bat_adc'] = $d['bat_adc_ts'] = null;
        }
        // замок и датчик двери платы ver_5_1 — только у версии 3 и если замок используется
        $d['lock_unused'] = (int)$d['lock_unused'] === 1;
        if (($d['dev_version'] !== null && $d['dev_version'] !== 3) || $d['lock_unused']) {
            $d['door_state'] = $d['lock_state'] = null;
        }
        // у версии 3 свой замок: режим «реле 4 — замок» не действует
        if ($d['dev_version'] === 3) {
            $d['relay4_lock'] = false;
            foreach ($d['relays'] as &$rr) {
                $rr['lock'] = false;
            }
            unset($rr);
        }
        $d['bat_voltage'] = relay_battery_voltage($d['bat_adc'], $d['cal_low'], $d['cal_high'], $d['bat_type']);
        // замок платы ver_5_1 («реле 5»)
        // плата ver_5_1 (версия 3): 4 реле и отдельный замок («реле 5») — показываем всегда, даже до первых данных о замке
        $d['has_lock'] = $d['dev_version'] === 3 && !$d['lock_unused'];
        $d['lock_access'] = !$d['has_lock'] ? null
            : ($admin ? 'control' : (isset($perms[$id][RELAY_LOCK]) ? ($perms[$id][RELAY_LOCK] ? 'control' : 'view') : null));
        // что можно делать с устройством целиком
        $d['can_edit'] = $admin;
        $d['can_reboot'] = $admin || in_array(true, $perms[$id], true);
        $d['can_all'] = $mask === 0 && !$d['relay4_wash']
            && ($admin || (!empty($perms[$id][1]) && !empty($perms[$id][2]) && !empty($perms[$id][3]) && !empty($perms[$id][4])));
        // дверь: замок на реле 4 (вкл — закрыта) или геркон платы ver_5_1
        $r4 = $relays[$id][4]['state'] ?? null;
        $d['door_open'] = $d['relay4_lock'] ? ($r4 === null ? null : $r4 === 0)
            : ($d['door_state'] === null ? null : (int)$d['door_state'] === 1);
        $d['break_in'] = $d['break_in_ts'] ? ['ts' => (int)$d['break_in_ts'], 'source' => $d['break_in_source']] : null;
        $d['can_ack'] = $admin || !empty($perms[$id][4]) || !empty($perms[$id][RELAY_LOCK]);
        unset($d['break_in_ts'], $d['break_in_source']);
        $d['geo'] = parse_coords((string)$d['coordinates']);
        // координаты не заданы — условная точка на карте «55.<id>, 37.<id>» (в БД не сохраняется)
        $d['geo_auto'] = $d['geo'] === null;
        if ($d['geo_auto']) {
            $d['geo'] = [(float)"55.$id", (float)"37.$id"];
        }
        $d['registered'] = (bool)$d['registered'];
        $fresh = $d['last_seen_ts'] !== null && $now - $d['last_seen_ts'] <= RELAY_OFFLINE_AFTER;
        $d['online'] = $fresh && $d['status'] !== 0;
        $d['state'] = $d['last_seen_ts'] === null ? 'never'
            : (!$d['online'] ? 'offline' : ($d['status'] === 2 ? 'error' : 'online')); // признак «питание от АКБ» не показывается
        $out[] = $d;
    }
    return $out;
}

switch ($action) {
    case 'list':
        $devices = device_rows($connect);
        $sum = ['total' => count($devices), 'online' => 0, 'offline' => 0, 'never' => 0];
        foreach ($devices as $d) {
            if ($d['state'] === 'never') $sum['never']++;
            elseif ($d['online']) $sum['online']++;
            else $sum['offline']++;
        }
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
        relay_event($connect, $id, 'info', 'Изменены данные устройства: название, адрес, подписи реле, АКБ ' . $batType . ' В'
            . ($lock4 ? ', реле 4 — замок' : '') . ($wash4 ? ', реле 4 — омывание' : '') . ($off ? ', отключены реле ' . implode(', ', array_filter([1, 2, 3, 4], fn($i) => ($off >> ($i - 1)) & 1)) : ''),
            'info', null, $login);
        relay_json(['ok' => true]);

    case 'command':
        relay_check_command_access((int)($_POST['id'] ?? 0), (int)($_POST['command'] ?? 0), (int)($_POST['relay'] ?? 0));
        // отключённым реле команды не отправляются (и «всем реле», если хоть одно отключено)
        $cmdRelay = (int)($_POST['relay'] ?? 0);
        $cmdCode = (int)($_POST['command'] ?? 0);
        $offRes = relay_q($connect, 'SELECT relays_disabled FROM general_info WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        $offRow = $offRes instanceof mysqli_result ? mysqli_fetch_row($offRes) : null;
        $offMask = (int)($offRow[0] ?? 0);
        if (relay_relay4_wash($connect, (int)($_POST['id'] ?? 0)) && (($cmdRelay === 4 && $cmdCode !== 3) || ($cmdRelay === 0 && $cmdCode <= 3))) {
            relay_json(['error' => $cmdRelay === 4 ? 'Реле 4 — омывание: доступна только команда «Запуск»'
                                                   : 'Реле 4 — омывание: команды «всем реле» недоступны'], 400);
        }
        if ($cmdCode <= 3 && (($cmdRelay >= 1 && $cmdRelay <= 4 && (($offMask >> ($cmdRelay - 1)) & 1)) || ($cmdRelay === 0 && $offMask))) {
            relay_json(['error' => $cmdRelay ? "Реле $cmdRelay отключено в настройках устройства" : 'Есть отключённые реле — команда «всем реле» недоступна'], 400);
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
        if (!$set) {
            relay_json(['error' => 'Укажите, что изменить: для кого установлено, названия реле, версию, контроль АКБ или режим реле 4'], 400);
        }
        $updated = $missing = [];
        foreach ($ids as $id) {
            $res = relay_q($connect, 'SELECT 1 FROM general_info WHERE id = ?', [$id]);
            if (!$res || mysqli_num_rows($res) === 0) {
                $missing[] = $id;
                continue;
            }
            relay_q($connect, 'UPDATE general_info SET ' . implode(', ', $set) . ' WHERE id = ?', [...$params, $id]);
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
