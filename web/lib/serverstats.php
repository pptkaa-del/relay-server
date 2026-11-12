<?php
/**
 * Показатели сервера (Linux /proc): процессор, память, диск, сеть, службы.
 * Используются страницей «Сервер» (api/server.php) и подписчиком (запись истории раз в минуту в server_stats).
 */

/** Счётчики процессора из /proc/stat: [простой, всего, ожидание ввода-вывода] */
function relay_cpu_times(): ?array
{
    $line = @file('/proc/stat', FILE_IGNORE_NEW_LINES)[0] ?? '';
    if (!str_starts_with($line, 'cpu ')) {
        return null;
    }
    $v = array_map('intval', preg_split('/\s+/', trim(substr($line, 4))));
    $idle = ($v[3] ?? 0) + ($v[4] ?? 0);              // idle + iowait
    $total = array_sum(array_slice($v, 0, 8));         // user nice system idle iowait irq softirq steal
    return [$idle, $total, $v[4] ?? 0];
}

/** Загрузка процессора в % между двумя отсчётами relay_cpu_times(): [всего, ожидание диска] */
function relay_cpu_percent(?array $a, ?array $b): ?array
{
    if (!$a || !$b || $b[1] <= $a[1]) {
        return null;
    }
    $dt = $b[1] - $a[1];
    return [round(100 * (1 - ($b[0] - $a[0]) / $dt), 1), round(100 * ($b[2] - $a[2]) / $dt, 1)];
}

/** /proc/meminfo → байты */
function relay_meminfo(): array
{
    $m = [];
    foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $l, $x)) {
            $m[$x[1]] = (int)$x[2] * 1024;
        }
    }
    $total = $m['MemTotal'] ?? 0;
    $avail = $m['MemAvailable'] ?? (($m['MemFree'] ?? 0) + ($m['Cached'] ?? 0) + ($m['Buffers'] ?? 0));
    return [
        'total' => $total,
        'used' => max(0, $total - $avail),          // занято программами (без кэша, который система отдаст по требованию)
        'cache' => ($m['Cached'] ?? 0) + ($m['Buffers'] ?? 0) + ($m['SReclaimable'] ?? 0),
        'available' => $avail,
        'swap_total' => $m['SwapTotal'] ?? 0,
        'swap_used' => max(0, ($m['SwapTotal'] ?? 0) - ($m['SwapFree'] ?? 0)),
    ];
}

/** Байты, принятые и отправленные всеми сетевыми интерфейсами, кроме lo: [rx, tx] */
function relay_net_bytes(): array
{
    $rx = $tx = 0;
    foreach (array_slice(@file('/proc/net/dev', FILE_IGNORE_NEW_LINES) ?: [], 2) as $l) {
        [$if, $rest] = array_pad(explode(':', $l, 2), 2, '');
        $if = trim($if);
        if ($if === 'lo' || $if === '' || str_starts_with($if, 'docker') || str_starts_with($if, 'veth')) {
            continue;
        }
        $v = preg_split('/\s+/', trim($rest));
        $rx += (int)($v[0] ?? 0);
        $tx += (int)($v[8] ?? 0);
    }
    return [$rx, $tx];
}

function relay_cpu_cores(): int
{
    $n = preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo'));
    return max(1, (int)$n);
}

/** Мгновенные показатели (процессор — за $sampleMs миллисекунд) */
function relay_server_now(int $sampleMs = 300): array
{
    $c1 = relay_cpu_times();
    [$rx1, $tx1] = relay_net_bytes();
    $t1 = microtime(true);
    usleep($sampleMs * 1000);
    $c2 = relay_cpu_times();
    [$rx2, $tx2] = relay_net_bytes();
    $dt = max(0.001, microtime(true) - $t1);
    [$cpu, $iowait] = relay_cpu_percent($c1, $c2) ?? [null, null];
    $load = array_map('floatval', array_slice(explode(' ', (string)@file_get_contents('/proc/loadavg')), 0, 3));
    $mem = relay_meminfo();
    $root = '/';
    $dTotal = @disk_total_space($root) ?: 0;
    $dFree = @disk_free_space($root) ?: 0;
    $model = preg_match('/^model name\s*:\s*(.+)$/m', (string)@file_get_contents('/proc/cpuinfo'), $m) ? trim($m[1]) : '';
    return [
        'cpu' => $cpu, 'iowait' => $iowait, 'cores' => relay_cpu_cores(), 'cpu_model' => $model,
        'load' => $load + [0, 0, 0],
        'mem' => $mem,
        'disk' => ['total' => (int)$dTotal, 'used' => (int)($dTotal - $dFree), 'free' => (int)$dFree],
        'net' => ['rx' => (int)round(($rx2 - $rx1) / $dt), 'tx' => (int)round(($tx2 - $tx1) / $dt), 'rx_total' => $rx2, 'tx_total' => $tx2],
        'uptime' => (int)(float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0],
        'processes' => count(glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: []),
    ];
}

/** Службы сервера: состояние, с какого времени работает, память, перезапуски (systemctl show, без прав root) */
const RELAY_SERVICES = [
    'relay-mqtt-subscriber' => 'Подписчик MQTT (обработка данных устройств)',
    'mosquitto' => 'MQTT-брокер Mosquitto',
    'mariadb' => 'База данных MariaDB',
    'apache2' => 'Веб-сервер Apache (панель, API)',
    'relay-device-queue.path' => 'Очередь регистрации устройств',
    'relay-update.timer' => 'Автообновление из git',
    'certbot.timer' => 'Продление сертификата HTTPS',
];

function relay_services(): array
{
    $names = array_keys(RELAY_SERVICES);
    [$code, $out] = relay_run_process(array_merge(['systemctl', 'show', '--no-pager',
        '-p', 'Id,ActiveState,SubState,LoadState,ActiveEnterTimestamp,MemoryCurrent,NRestarts,CPUUsageNSec'], $names));
    $blocks = $code === 0 ? preg_split('/\n\s*\n/', trim($out)) : [];
    $res = [];
    foreach ($names as $i => $n) {
        $p = [];
        foreach (explode("\n", $blocks[$i] ?? '') as $l) {
            [$k, $v] = array_pad(explode('=', $l, 2), 2, '');
            $p[$k] = $v;
        }
        $since = !empty($p['ActiveEnterTimestamp']) ? strtotime(preg_replace('/^\w{3} /', '', $p['ActiveEnterTimestamp'])) : false;
        $mem = $p['MemoryCurrent'] ?? '';
        $cpu = $p['CPUUsageNSec'] ?? '';
        $res[] = [
            'name' => $n, 'title' => RELAY_SERVICES[$n],
            'installed' => ($p['LoadState'] ?? '') === 'loaded',
            'state' => $p['ActiveState'] ?? 'unknown', 'sub' => $p['SubState'] ?? '',
            'since' => $since ?: null,
            'memory' => ctype_digit($mem) && (int)$mem < PHP_INT_MAX / 2 ? (int)$mem : null,
            'cpu_sec' => ctype_digit($cpu) && (int)$cpu < PHP_INT_MAX / 2 ? round((int)$cpu / 1e9) : null,
            'restarts' => isset($p['NRestarts']) && ctype_digit($p['NRestarts']) ? (int)$p['NRestarts'] : null,
        ];
    }
    return $res;
}
