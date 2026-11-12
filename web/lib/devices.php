<?php
/**
 * Устройства: сводные данные для панели (api/devices.php) и внешнего API (api/v1/*).
 * Права берутся из relay_user_perms(): администратор — всё, пользователь панели и клиент API — выданные устройства.
 */

/** Состояние монтажа (general_info.mount_state) */
const RELAY_MOUNT = [0 => 'mounted', 1 => 'not_mounted', 2 => 'repair'];
const RELAY_MOUNT_LABEL = ['mounted' => 'смонтировано', 'not_mounted' => 'не смонтировано', 'repair' => 'демонтировано (в ремонт)'];

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
               COALESCE(g.mount_state, 0) AS mount_state, UNIX_TIMESTAMP(g.mount_changed_at) AS mount_ts,
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
        // координаты не заданы — на карте такие устройства собраны в кластер в точке 55.00, 37.00
        $d['geo_auto'] = $d['geo'] === null;
        $d['registered'] = (bool)$d['registered'];
        $fresh = $d['last_seen_ts'] !== null && $now - $d['last_seen_ts'] <= RELAY_OFFLINE_AFTER;
        $d['online'] = $fresh && $d['status'] !== 0;
        $d['state'] = $d['last_seen_ts'] === null ? 'never'
            : (!$d['online'] ? 'offline' : ($d['status'] === 2 ? 'error' : 'online')); // признак «питание от АКБ» не показывается
        // монтаж: «не смонтировано» и «демонтировано (в ремонт)» — отсутствие данных не считается неисправностью
        $d['mount'] = RELAY_MOUNT[(int)$d['mount_state']] ?? 'mounted';
        $d['mount_ts'] = $d['mount_ts'] === null ? null : (int)$d['mount_ts'];
        unset($d['mount_state']);
        // АКБ ниже 6 В на связи — тревога; пропала связь при АКБ ниже 20 В (24 В) / 10 В (12 В) — «отключено по питанию»
        $d['bat_low'] = $d['bat_on'] && $d['bat_voltage'] !== null && $d['bat_voltage'] < RELAY_BAT_LOW_V;
        if ($d['state'] === 'offline' && $d['bat_on'] && $d['bat_voltage'] !== null && $d['bat_voltage'] < relay_power_off_v($d['bat_type'])) {
            $d['state'] = 'power_off';
        }
        if (!$d['online'] && $d['mount'] !== 'mounted') {
            $d['state'] = $d['mount'];   // not_mounted | repair — вместо «нет связи», «не выходило», «отключено по питанию»
            $d['bat_low'] = false;
        }
        // на связи, но ни одно реле не включено (замок, омывание и отключённые реле не считаются)
        $plain = array_filter($d['relays'], fn($r) => !$r['lock'] && !$r['wash']);
        $d['all_off'] = $d['online'] && $plain && !array_filter($plain, fn($r) => $r['state'] === 1);
        $out[] = $d;
    }
    return $out;
}

/** Сводка по устройствам для плиток: не смонтированные и демонтированные не попадают в «нет связи» / «ожидают» */
function relay_devices_summary(array $devices): array
{
    $sum = ['total' => count($devices), 'online' => 0, 'offline' => 0, 'never' => 0, 'bat_low' => 0, 'power_off' => 0, 'all_off' => 0,
            'not_mounted' => 0, 'repair' => 0];
    foreach ($devices as $d) {
        if ($d['mount'] !== 'mounted') $sum[$d['mount']]++;
        if ($d['online']) $sum['online']++;
        elseif ($d['mount'] !== 'mounted') continue;
        elseif ($d['state'] === 'never') $sum['never']++;
        elseif ($d['state'] === 'power_off') $sum['power_off']++;
        else $sum['offline']++;
        if ($d['online'] && $d['bat_low']) $sum['bat_low']++;
        if ($d['all_off']) $sum['all_off']++;
    }
    return $sum;
}

/**
 * Проверки команды, общие для панели и внешнего API (права проверяются отдельно — relay_check_command_access):
 * отключённые реле, реле 4 — омывание. Возвращает текст ошибки или null.
 */
function relay_command_error(mysqli $db, int $id, int $command, int $relay): ?string
{
    $res = relay_q($db, 'SELECT relays_disabled FROM general_info WHERE id = ?', [$id]);
    $row = $res instanceof mysqli_result ? mysqli_fetch_row($res) : null;
    $off = (int)($row[0] ?? 0);
    if (relay_relay4_wash($db, $id) && (($relay === 4 && $command !== 3) || ($relay === 0 && $command <= 3))) {
        return $relay === 4 ? 'Реле 4 — омывание: доступна только команда «Запуск» (restart)' : 'Реле 4 — омывание: команды «всем реле» недоступны';
    }
    if ($command <= 3 && (($relay >= 1 && $relay <= 4 && (($off >> ($relay - 1)) & 1)) || ($relay === 0 && $off))) {
        return $relay ? "Реле $relay отключено в настройках устройства" : 'Есть отключённые реле — команда «всем реле» недоступна';
    }
    return null;
}
