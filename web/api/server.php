<?php
/**
 * Вкладка «Сервер» (только администратор).
 *   GET ?action=now                     — текущие показатели: процессор, память, диск, сеть, службы, БД, брокер, предупреждения
 *   GET ?action=history&range=24h       — история (1h | 6h | 24h | 7d | 30d) из server_stats (пишет подписчик раз в минуту)
 */
require __DIR__ . '/../conectbd.php';
require __DIR__ . '/../lib/serverstats.php';

if (!relay_is_admin()) {
    relay_forbidden('Статистика сервера доступна только администратору');
}
session_write_close(); // замер процессора идёт 0,3 с — не держим сессию

$action = $_GET['action'] ?? 'now';

function state_get(mysqli $db, string $k): array
{
    $res = @relay_q($db, 'SELECT v, UNIX_TIMESTAMP(ts) AS t FROM server_state WHERE k = ?', [$k]);
    $r = $res instanceof mysqli_result ? mysqli_fetch_assoc($res) : null;
    return $r ? [json_decode($r['v'], true) ?: [], (int)$r['t']] : [[], null];
}

switch ($action) {
    case 'now':
        $now = relay_server_now();
        $services = relay_services();

        // база данных: размер таблиц
        $tables = relay_rows(mysqli_query($connect, 'SELECT TABLE_NAME AS name, TABLE_ROWS AS rows_est, DATA_LENGTH + INDEX_LENGTH AS size
                                                       FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY size DESC'));
        $dbSize = 0;
        foreach ($tables as &$t) {
            $t['size'] = (int)$t['size'];
            $t['rows_est'] = (int)$t['rows_est'];
            $dbSize += $t['size'];
        }
        unset($t);
        $dbVer = mysqli_fetch_row(mysqli_query($connect, 'SELECT VERSION()'))[0] ?? '';
        $dbUp = mysqli_fetch_row(mysqli_query($connect, "SHOW GLOBAL STATUS LIKE 'Uptime'"))[1] ?? null;
        $dbConn = mysqli_fetch_row(mysqli_query($connect, "SHOW GLOBAL STATUS LIKE 'Threads_connected'"))[1] ?? null;

        [$broker, $brokerTs] = state_get($connect, 'broker');
        [$sub, $subTs] = state_get($connect, 'subscriber');
        $last = mysqli_fetch_row(mysqli_query($connect, 'SELECT UNIX_TIMESTAMP(MAX(ts)) FROM server_stats'));
        $lastSample = $last && $last[0] ? (int)$last[0] : null;

        // устройства и поток данных
        $dev = mysqli_fetch_assoc(relay_q($connect, 'SELECT COUNT(*) AS total, SUM(last_seen > NOW() - INTERVAL ? SECOND AND status <> 0) AS online FROM devices', [RELAY_OFFLINE_AFTER]));
        $msgs = mysqli_fetch_assoc(mysqli_query($connect, "SELECT SUM(ts > NOW() - INTERVAL 1 MINUTE) AS m1, SUM(ts > NOW() - INTERVAL 1 HOUR) AS h1, COUNT(*) AS day
                                                            FROM mqtt_messages WHERE ts > NOW() - INTERVAL 1 DAY AND direction = 'in'"));
        $os = preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', (string)@file_get_contents('/etc/os-release'), $m) ? $m[1] : PHP_OS;

        // предупреждения
        $warn = [];
        $pct = fn($a, $b) => $b > 0 ? 100 * $a / $b : 0;
        if ($pct($now['disk']['used'], $now['disk']['total']) >= 85) {
            $warn[] = ['level' => $pct($now['disk']['used'], $now['disk']['total']) >= 95 ? 'error' : 'warning',
                       'text' => sprintf('Диск заполнен на %.0f%%: уменьшите срок хранения журнала MQTT (sudo relay-update --retention 7) или удалите старые резервные копии', $pct($now['disk']['used'], $now['disk']['total']))];
        }
        if ($pct($now['mem']['used'], $now['mem']['total']) >= 90) {
            $warn[] = ['level' => 'warning', 'text' => sprintf('Память занята на %.0f%%', $pct($now['mem']['used'], $now['mem']['total']))];
        }
        if ($now['mem']['swap_total'] === 0 && $now['mem']['total'] < 4 * 1024 ** 3) {
            $warn[] = ['level' => 'info', 'text' => 'Нет файла подкачки (swap): при нехватке памяти службы могут остановиться. Рекомендуется swap 2 ГБ'];
        } elseif ($now['mem']['swap_total'] > 0 && $pct($now['mem']['swap_used'], $now['mem']['swap_total']) >= 50) {
            $warn[] = ['level' => 'warning', 'text' => sprintf('Используется %.0f%% файла подкачки: серверу не хватает памяти', $pct($now['mem']['swap_used'], $now['mem']['swap_total']))];
        }
        if ($now['load'][0] > $now['cores'] * 1.5) {
            $warn[] = ['level' => 'warning', 'text' => sprintf('Высокая нагрузка: %.2f при %d ядрах процессора', $now['load'][0], $now['cores'])];
        }
        foreach ($services as $s) {
            if ($s['installed'] && $s['state'] !== 'active') {
                $warn[] = ['level' => 'error', 'text' => "Служба «{$s['title']}» ({$s['name']}) не работает: {$s['state']}"];
            }
        }
        if ($lastSample === null || time() - $lastSample > 180) {
            $warn[] = ['level' => 'warning', 'text' => 'Подписчик MQTT не записывал статистику больше 3 минут — история графиков не пополняется'
                . ' (журнал: journalctl -u relay-mqtt-subscriber -n 50)'];
        }

        relay_json([
            'now' => $now,
            'services' => $services,
            'db' => ['size' => $dbSize, 'tables' => $tables, 'version' => $dbVer, 'uptime' => $dbUp !== null ? (int)$dbUp : null,
                     'connections' => $dbConn !== null ? (int)$dbConn : null, 'name' => relay_config()['db']['name'] ?? ''],
            'broker' => ['sys' => $broker, 'ts' => $brokerTs],
            'subscriber' => ['state' => $sub, 'ts' => $subTs, 'last_sample' => $lastSample],
            'devices' => ['total' => (int)$dev['total'], 'online' => (int)$dev['online']],
            'messages' => ['m1' => (int)$msgs['m1'], 'h1' => (int)$msgs['h1'], 'day' => (int)$msgs['day']],
            'info' => [
                'hostname' => gethostname(), 'os' => $os, 'kernel' => php_uname('r'), 'arch' => php_uname('m'),
                'php' => PHP_VERSION, 'web' => (string)($_SERVER['SERVER_SOFTWARE'] ?? ''), 'panel' => RELAY_VERSION,
                'timezone' => date_default_timezone_get(), 'time' => time(),
            ],
            'warnings' => $warn,
        ]);

    case 'history':
        $ranges = ['1h' => [3600, 60], '6h' => [21600, 300], '24h' => [86400, 600], '7d' => [604800, 3600], '30d' => [2592000, 14400]];
        [$span, $bucket] = $ranges[$_GET['range'] ?? '24h'] ?? $ranges['24h'];
        $rows = relay_rows(@relay_q($connect,
            'SELECT FLOOR(UNIX_TIMESTAMP(ts) / ?) * ? AS t,
                    AVG(cpu) AS cpu, MAX(cpu) AS cpu_max, AVG(iowait) AS iowait, AVG(load1) AS load1,
                    AVG(mem_used) AS mem_used, MAX(mem_total) AS mem_total, AVG(swap_used) AS swap_used,
                    MAX(disk_used) AS disk_used, MAX(disk_total) AS disk_total,
                    AVG(net_rx) AS net_rx, AVG(net_tx) AS net_tx,
                    AVG(mqtt_clients) AS mqtt_clients, AVG(mqtt_in) AS mqtt_in, AVG(mqtt_out) AS mqtt_out, AVG(devices_online) AS devices_online
               FROM server_stats WHERE ts > NOW() - INTERVAL ? SECOND GROUP BY t ORDER BY t', [$bucket, $bucket, $span]) ?: null);
        $points = array_map(fn($r) => array_map(fn($v) => $v === null ? null : round((float)$v, 2), $r), $rows);
        relay_json(['points' => $points, 'bucket' => $bucket, 'span' => $span, 'now' => time()]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
