<?php
/**
 * Резервные копии (только администратор).
 *   GET  ?action=download&mode=full|compact — SQL-дамп всей БД (.sql.gz); compact — без журнала MQTT-сообщений
 *   POST action=preview  file, source=backup|legacy — прочитать данные устройств из файла (.sql / .sql.gz)
 *   POST action=import   token, ids («all» или «1,2,3»), mode=all|new — загрузить выбранные устройства в БД
 * Копия восстанавливается целиком стандартно: zcat копия.sql.gz | sudo mysql <база>.
 */
require __DIR__ . '/../conectbd.php';
require __DIR__ . '/../lib/sqlimport.php';

if (!relay_is_admin()) {
    relay_forbidden();
}
$action = $_REQUEST['action'] ?? '';
if ($action !== 'download' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}

/**
 * Координаты старой системы → «широта, долгота» с точкой: «37,55072; 55,703292» (через «;» — долгота первой,
 * десятичная запятая) и «55.749912, 37.668702» (широта первой). Нераспознанное — как есть.
 */
function legacy_coords(?string $raw): string
{
    $s = trim((string)$raw);
    if ($s === '') {
        return '';
    }
    $lonFirst = str_contains($s, ';');
    if ($lonFirst || preg_match('/^-?\d+,\d+\s+-?\d+,\d+$/', $s)) {
        $s = str_replace(',', '.', $s);
    }
    if (!preg_match_all('/-?\d+(?:\.\d+)?/', $s, $m) || count($m[0]) !== 2) {
        return trim((string)$raw);
    }
    [$a, $b] = $m[0];
    return $lonFirst ? "$b, $a" : "$a, $b";
}

/**
 * Устройство первоначальной системы → колонки general_info этой панели.
 *   general_info: название, адрес, координаты, подписи реле («not_used» — реле отключено);
 *   AKB (min — АЦП при 19 В, max — при 26 В): min > 700 — АКБ 24 В, иначе 12 В (точки 19/26 В пересчитываются в 6/15 В);
 *        min или max = 1 — калибровку не подставлять; нет строки (или таблицы) AKB — контроль АКБ выключен;
 *   SIM_number: номер SIM → +7XXXXXXXXXX.
 */
function legacy_item(array $g, ?array $akb, ?string $sim): array
{
    $id = (int)$g['id'];
    $it = ['id' => (string)$id, 'name_obj' => trim((string)($g['name_obj'] ?? '')) ?: "Устройство $id",
           'address' => trim((string)($g['address'] ?? '')), 'coordinates' => legacy_coords($g['coordinates'] ?? '')];
    $off = 0;
    for ($i = 1; $i <= 4; $i++) {
        $n = trim((string)($g["name_reley_$i"] ?? ''));
        if (strcasecmp($n, 'not_used') === 0) {
            $off |= 1 << ($i - 1);
            $n = '';
        }
        $it["name_reley_$i"] = $n !== '' ? $n : "Реле $i";
    }
    $it['relays_disabled'] = (string)$off;
    $bat = 'контроль АКБ выключен';
    if ($akb === null) {
        $it['bat_monitor'] = '0';
    } else {
        $it['bat_monitor'] = '1';
        $min = (int)$akb['min'];
        $max = (int)$akb['max'];
        if ($min === 1 || $max === 1 || $min === $max) {
            $bat = 'АКБ без калибровки';
        } elseif ($min > 700) {
            $it += ['bat_type' => '24', 'cal_adc_19' => (string)$min, 'cal_adc_26' => (string)$max];
            $bat = "АКБ 24 В ($min / $max)";
        } else {
            // 12 В: та же прямая через точки 19 и 26 В, пересчитанная в точки 6 и 15 В
            $c6 = (int)round($min + (6 - 19) * ($max - $min) / 7);
            $c15 = (int)round($min + (15 - 19) * ($max - $min) / 7);
            $it += ['bat_type' => '12', 'cal_adc_19' => (string)$min, 'cal_adc_26' => (string)$max];
            if ($c6 >= 0 && $c15 >= 0) {
                $it += ['cal_adc_6' => (string)$c6, 'cal_adc_15' => (string)$c15];
            }
            $bat = "АКБ 12 В ($min / $max)";
        }
    }
    $d = preg_replace('/\D+/', '', (string)$sim);
    if (strlen($d) === 11 && ($d[0] === '7' || $d[0] === '8')) {
        $d = substr($d, 1);
    }
    if (strlen($d) === 10) {
        $it['sim_phone'] = '+7' . $d;
    }
    $note = trim((string)($g['note'] ?? ''));
    if ($note !== '') {
        $it['_note'] = $note; // примечание старой системы → комментарий к устройству
    }
    $it['_info'] = ($note !== '' ? "комментарий «{$note}» · " : '') . $bat . (isset($it['sim_phone']) ? ' · SIM ' . $it['sim_phone'] : '') . ($off ? ' · отключены реле ' . implode(', ', array_filter([1, 2, 3, 4], fn($i) => ($off >> ($i - 1)) & 1)) : '');
    return $it;
}

/** Временный файл с прочитанными данными (между «Проверить» и «Загрузить») */
function import_tmp(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        relay_json(['error' => 'Неверный токен — проверьте файл заново'], 400);
    }
    return sys_get_temp_dir() . "/relay-import-$token.json";
}

switch ($action) {
    /* ---------------- скачать резервную копию ---------------- */
    case 'download':
        $compact = ($_GET['mode'] ?? 'full') === 'compact';
        session_write_close();            // не держим сессию, пока идёт выгрузка
        @set_time_limit(0);
        ignore_user_abort(false);
        $db = relay_config()['db']['name'];
        $file = 'off-on-backup-' . date('Ymd-His') . ($compact ? '-compact' : '') . '.sql.gz';
        header('Content-Type: application/gzip');
        header("Content-Disposition: attachment; filename=\"$file\"");
        header('Cache-Control: no-store');
        while (ob_get_level()) {
            ob_end_clean();
        }
        $z = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        $buf = '';
        $put = function (string $s, bool $final = false) use ($z, &$buf) {
            $buf .= $s;
            if ($final || strlen($buf) > 256 * 1024) {
                echo deflate_add($z, $buf, $final ? ZLIB_FINISH : ZLIB_NO_FLUSH);
                $buf = '';
                flush();
            }
        };
        $put("-- off-on service: резервная копия базы данных `$db`\n-- создана " . date('Y-m-d H:i:s') . ', версия панели ' . RELAY_VERSION
            . ($compact ? ', без журнала MQTT-сообщений' : '') . "\n"
            . "-- восстановление: zcat $file | sudo mysql $db\n\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '" . date('P') . "';\n\n");
        $tables = [];
        foreach (relay_rows(mysqli_query($connect, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")) as $r) {
            $tables[] = array_values($r)[0];
        }
        foreach ($tables as $t) {
            $create = mysqli_fetch_row(mysqli_query($connect, "SHOW CREATE TABLE `$t`"))[1];
            $put("--\n-- Таблица `$t`\n--\nDROP TABLE IF EXISTS `$t`;\n$create;\n\n");
            if ($compact && $t === 'mqtt_messages') {
                continue; // журнал MQTT-сообщений — самая большая таблица
            }
            $res = mysqli_query($connect, "SELECT * FROM `$t`", MYSQLI_USE_RESULT); // построчно, без загрузки в память
            if (!$res) {
                continue;
            }
            $fields = array_map(fn($f) => '`' . $f->name . '`', mysqli_fetch_fields($res));
            $head = "INSERT INTO `$t` (" . implode(', ', $fields) . ") VALUES\n";
            $rows = [];
            $flush = function () use (&$rows, $head, $put) {
                if ($rows) {
                    $put($head . implode(",\n", $rows) . ";\n");
                    $rows = [];
                }
            };
            while ($row = mysqli_fetch_row($res)) {
                $rows[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : "'" . mysqli_real_escape_string($connect, $v) . "'", $row)) . ')';
                if (count($rows) >= 200) {
                    $flush();
                }
            }
            $flush();
            mysqli_free_result($res);
            $put("\n");
        }
        $put("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- конец резервной копии\n", true);
        exit;

    /* ---------------- прочитать файл ---------------- */
    case 'preview':
        session_write_close(); // чтение файла может занять время — не блокируем остальные запросы панели
        $source = ($_POST['source'] ?? 'backup') === 'legacy' ? 'legacy' : 'backup';
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
            relay_json(['error' => match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл больше допустимого размера (' . ini_get('upload_max_filesize')
                    . '). Возьмите копию «без журнала MQTT-сообщений» или обновите сервер: sudo relay-update',
                UPLOAD_ERR_NO_FILE => 'Выберите файл',
                default => "Файл не загрузился (код $err)",
            }], 400);
        }
        @set_time_limit(0);
        try {
            $data = relay_sql_extract($f['tmp_name'], ['general_info', 'devices', 'AKB', 'SIM_number']);
        } catch (RuntimeException $e) {
            relay_json(['error' => 'Не удалось прочитать файл: ' . $e->getMessage()], 400);
        }
        // устройства: из general_info, а те, что есть только в devices, — по имени и месту оттуда
        $items = [];
        if ($source === 'legacy') {
            // первоначальная система: АКБ — из таблицы AKB, номер SIM — из SIM_number
            $akb = $sims = [];
            foreach ($data['AKB'] as $a) {
                $akb[(int)($a['id'] ?? 0)] = $a;
            }
            foreach ($data['SIM_number'] as $n) {
                $sims[(int)($n['id'] ?? 0)] = (string)($n['Number'] ?? '');
            }
            foreach ($data['general_info'] as $g) {
                $id = (int)($g['id'] ?? 0);
                if ($id > 0) {
                    $items[$id] = legacy_item($g, $akb[$id] ?? null, $sims[$id] ?? null);
                }
            }
        } else {
            foreach ($data['general_info'] as $g) {
                $id = (int)($g['id'] ?? 0);
                if ($id > 0) {
                    $items[$id] = $g;
                }
            }
        }
        foreach ($data['devices'] as $d) {
            $id = (int)($d['id'] ?? 0);
            if ($id > 0 && !isset($items[$id])) {
                $items[$id] = ['id' => (string)$id, 'name_obj' => $d['name'] ?? "Устройство $id", 'address' => $d['location'] ?? ''];
            }
        }
        if (!$items) {
            relay_json(['error' => 'В файле не найдено данных устройств (таблицы general_info / devices). Нужен SQL-дамп базы: '
                . ($source === 'legacy' ? 'экспорт phpMyAdmin или mysqldump старой БД' : 'резервная копия из этой панели')], 400);
        }
        ksort($items);
        $existing = [];
        foreach (relay_rows(mysqli_query($connect, 'SELECT id FROM general_info')) as $r) {
            $existing[(int)$r['id']] = true;
        }
        foreach (glob(sys_get_temp_dir() . '/relay-import-*.json') ?: [] as $old) {
            if (time() - (int)@filemtime($old) > 3600) {
                @unlink($old); // старые проверки, которые так и не загрузили
            }
        }
        $token = bin2hex(random_bytes(16));
        file_put_contents(import_tmp($token), json_encode(['login' => $login, 'source' => $source, 'time' => time(), 'items' => $items],
            JSON_UNESCAPED_UNICODE));
        @chmod(import_tmp($token), 0600);
        $list = [];
        foreach ($items as $id => $g) {
            $list[] = ['id' => $id, 'name' => (string)($g['name_obj'] ?? '') ?: "Устройство $id", 'address' => (string)($g['address'] ?? ''),
                       'exists' => isset($existing[$id]), 'info' => (string)($g['_info'] ?? '')];
        }
        relay_json(['token' => $token, 'source' => $source, 'devices' => $list,
                    'found' => ['general_info' => count($data['general_info']), 'devices' => count($data['devices']),
                                'AKB' => count($data['AKB']), 'SIM_number' => count($data['SIM_number'])]]);

    /* ---------------- загрузить в БД ---------------- */
    case 'import':
        $path = import_tmp((string)($_POST['token'] ?? ''));
        $pack = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!$pack || ($pack['login'] ?? '') !== $login || time() - (int)$pack['time'] > 3600) {
            relay_json(['error' => 'Данные проверки устарели — выберите файл и нажмите «Проверить» ещё раз'], 400);
        }
        session_write_close(); // загрузка многих устройств не должна блокировать остальную панель
        @set_time_limit(0);
        ignore_user_abort(true); // если вкладку закроют, загрузка всё равно доведётся до конца
        $onlyNew = ($_POST['mode'] ?? 'all') === 'new';
        $sel = (string)($_POST['ids'] ?? 'all');
        $ids = $sel === 'all' ? array_map('intval', array_keys($pack['items']))
            : array_values(array_filter(array_map('intval', explode(',', $sel))));
        // колонки general_info, которые можно загружать (как в текущей схеме)
        $colInfo = [];
        foreach (relay_rows(mysqli_query($connect, 'SHOW COLUMNS FROM general_info')) as $c) {
            $colInfo[$c['Field']] = $c['Null'] === 'YES';
        }
        unset($colInfo['id'], $colInfo['data_creet']);
        $existing = [];
        foreach (relay_rows(mysqli_query($connect, 'SELECT id FROM general_info')) as $r) {
            $existing[(int)$r['id']] = true;
        }
        $label = $pack['source'] === 'legacy' ? 'из БД первоначальной системы' : 'из резервной копии';
        $created = $updated = $skipped = $noMqtt = [];
        foreach ($ids as $id) {
            $g = $pack['items'][$id] ?? $pack['items'][(string)$id] ?? null;
            if (!$g || $id <= 0) {
                continue;
            }
            $isNew = !isset($existing[$id]);
            if (!$isNew && $onlyNew) {
                $skipped[] = $id;
                continue;
            }
            $name = trim((string)($g['name_obj'] ?? '')) ?: "Устройство $id";
            $addr = trim((string)($g['address'] ?? ''));
            if ($isNew) {
                // строка в панели сразу; учётные записи MQTT — после цикла одной командой для всех новых
                relay_q($connect, "INSERT IGNORE INTO general_info (id, name_obj, address, coordinates, name_reley_1, name_reley_2, name_reley_3, name_reley_4)
                                   VALUES (?, ?, ?, '', 'Реле 1', 'Реле 2', 'Реле 3', 'Реле 4')", [$id, $name, $addr]);
                relay_q($connect, 'DELETE FROM pending_devices WHERE id = ?', [$id]);
            }
            // все известные колонки из файла; NULL в NOT NULL-колонку не пишем
            $set = [];
            $params = [];
            foreach ($g as $col => $val) {
                if (!array_key_exists($col, $colInfo) || ($val === null && !$colInfo[$col])) {
                    continue;
                }
                $set[] = "`$col` = ?";
                $params[] = $val;
            }
            if ($set) {
                $params[] = $id;
                relay_q($connect, 'UPDATE general_info SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
            }
            relay_q($connect, 'UPDATE devices SET name = ?, location = ? WHERE id = ?', [$name, mb_substr($addr, 0, 100), $id]);
            relay_event($connect, $id, 'info', ($isNew ? 'Устройство добавлено ' : 'Данные устройства обновлены ') . $label, 'info', null, $login);
            // примечание старой системы — комментарием (без повторов при повторной загрузке)
            if (!empty($g['_note'])) {
                $dup = relay_q($connect, 'SELECT 1 FROM device_comments WHERE device_id = ? AND text = ?', [$id, $g['_note']]);
                if ($dup instanceof mysqli_result && mysqli_num_rows($dup) === 0) {
                    @relay_q($connect, 'INSERT INTO device_comments (device_id, login, text) VALUES (?, ?, ?)', [$id, 'старая система', $g['_note']]);
                }
            }
            $isNew ? $created[] = $id : $updated[] = $id;
        }
        // учётные записи MQTT новых устройств: одна команда на порцию (ACL и перечитывание брокера — один раз)
        foreach (array_chunk($created, 200) as $chunk) {
            [$code, $out] = relay_device_cli(['add-many', implode(',', $chunk)]);
            if ($code !== 0) {
                array_push($noMqtt, ...$chunk);
            }
        }
        @unlink($path);
        relay_json(['ok' => true, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'no_mqtt' => $noMqtt]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
