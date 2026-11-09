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
            $data = relay_sql_extract($f['tmp_name'], ['general_info', 'devices']);
        } catch (RuntimeException $e) {
            relay_json(['error' => 'Не удалось прочитать файл: ' . $e->getMessage()], 400);
        }
        // устройства: из general_info, а те, что есть только в devices, — по имени и месту оттуда
        $items = [];
        foreach ($data['general_info'] as $g) {
            $id = (int)($g['id'] ?? 0);
            if ($id > 0) {
                $items[$id] = $g;
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
                       'exists' => isset($existing[$id])];
        }
        relay_json(['token' => $token, 'source' => $source, 'devices' => $list,
                    'found' => ['general_info' => count($data['general_info']), 'devices' => count($data['devices'])]]);

    /* ---------------- загрузить в БД ---------------- */
    case 'import':
        $path = import_tmp((string)($_POST['token'] ?? ''));
        $pack = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!$pack || ($pack['login'] ?? '') !== $login || time() - (int)$pack['time'] > 3600) {
            relay_json(['error' => 'Данные проверки устарели — выберите файл и нажмите «Проверить» ещё раз'], 400);
        }
        @set_time_limit(0);
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
                // учётная запись MQTT + строка general_info (как «Добавить устройство»)
                [$code] = relay_device_cli(['add', (string)$id, mb_substr($name, 0, 200), mb_substr($addr, 0, 300)]);
                if ($code !== 0) {
                    $noMqtt[] = $id;
                    relay_q($connect, "INSERT IGNORE INTO general_info (id, name_obj, address, coordinates, name_reley_1, name_reley_2, name_reley_3, name_reley_4)
                                       VALUES (?, ?, ?, '', 'Реле 1', 'Реле 2', 'Реле 3', 'Реле 4')", [$id, $name, $addr]);
                }
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
            $isNew ? $created[] = $id : $updated[] = $id;
        }
        @unlink($path);
        relay_json(['ok' => true, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'no_mqtt' => $noMqtt]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
