<?php
/**
 * Симуляция устройств (только администратор; Монитор → «Симуляция устройств»).
 *   GET  ?action=list
 *   POST action=start   devices («101, 102-105»), broker_id, interval_sec, version (2|3)
 *   POST action=set     id, relay (1–4) | door | lock — переключить «на месте», без команды (сразу публикуется stat)
 *   POST action=stat    id — опубликовать stat сейчас
 *   POST action=stop    id | all=1
 * Подписчик (mqtt_subscriber.php) читает таблицу mqtt_simulations раз в 2 секунды: публикует stat от имени
 * устройства и отвечает rep на команды com/{id}, как настоящая плата.
 */
require __DIR__ . '/../conectbd.php';

const SIM_MAX = 50;

$action = $_REQUEST['action'] ?? 'list';
if ($action !== 'list' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}
if (!mysqli_query($connect, 'SELECT 1 FROM mqtt_simulations LIMIT 0')) {
    relay_json(['error' => 'Нет таблицы симуляций — обновите сервер: sudo relay-update'], 500);
}

/** «101, 102-105 107» → [101, 102, 103, 104, 105, 107] */
function sim_parse_ids(string $s): array
{
    try {
        return relay_parse_id_list($s, SIM_MAX);
    } catch (RuntimeException $e) {
        relay_json(['error' => $e->getMessage()], 400);
    }
}

function sim_row(mysqli $db, int $id): array
{
    $r = mysqli_fetch_assoc(relay_q($db, 'SELECT * FROM mqtt_simulations WHERE id = ?', [$id]));
    if (!$r) {
        relay_json(['error' => 'Симуляция не найдена'], 404);
    }
    return $r;
}

switch ($action) {
    case 'list':
        $rows = relay_rows(mysqli_query($connect, 'SELECT s.*, UNIX_TIMESTAMP(s.last_stat_at) AS last_stat_ts, UNIX_TIMESTAMP(s.last_cmd_at) AS last_cmd_ts,
                b.name AS broker_name, b.status AS broker_status, b.enabled AS broker_enabled
              FROM mqtt_simulations s JOIN mqtt_brokers b ON b.id = s.broker_id ORDER BY s.broker_id, s.device_id'));
        foreach ($rows as &$r) {
            foreach (['id', 'broker_id', 'device_id', 'version', 'lock_state', 'door_state', 'interval_sec', 'stat_count', 'cmd_count', 'broker_enabled', 'dirty'] as $k) {
                $r[$k] = (int)$r[$k];
            }
            $r['relays'] = array_map('intval', array_slice(explode(',', $r['relays'] . ',0,0,0,0'), 0, 4));
            $r['last_stat_ts'] = $r['last_stat_ts'] === null ? null : (int)$r['last_stat_ts'];
            $r['last_cmd_ts'] = $r['last_cmd_ts'] === null ? null : (int)$r['last_cmd_ts'];
        }
        unset($r);
        $brokers = relay_rows(mysqli_query($connect, 'SELECT id, name, is_local, enabled FROM mqtt_brokers ORDER BY is_local DESC, id'));
        relay_json(['sims' => $rows, 'brokers' => $brokers, 'max' => SIM_MAX]);

    case 'start':
        $ids = sim_parse_ids((string)($_POST['devices'] ?? ''));
        if (!$ids) {
            relay_json(['error' => 'Укажите номера устройств, например: 101, 102-105'], 400);
        }
        $broker = relay_broker($connect, (int)($_POST['broker_id'] ?? RELAY_LOCAL_BROKER));
        if (!$broker) {
            relay_json(['error' => 'Брокер не найден'], 400);
        }
        $count = (int)mysqli_fetch_row(mysqli_query($connect, 'SELECT COUNT(*) FROM mqtt_simulations'))[0];
        if ($count + count($ids) > SIM_MAX) {
            relay_json(['error' => 'Одновременно можно симулировать не больше ' . SIM_MAX . ' устройств'], 400);
        }
        $interval = max(5, min(3600, (int)($_POST['interval_sec'] ?? 60)));
        $version = in_array((int)($_POST['version'] ?? 2), [1, 2, 3], true) ? (int)$_POST['version'] : 2;
        $local = (int)$broker['is_local'] === 1;
        $started = [];
        foreach ($ids as $dev) {
            // начальное состояние реле — последнее известное серверу (для собственного брокера), иначе всё выключено
            $relays = [0, 0, 0, 0];
            if ($local) {
                foreach (relay_rows(relay_q($connect, 'SELECT relay_num, state FROM relay_states WHERE device_id = ?', [$dev])) as $r) {
                    if ((int)$r['relay_num'] >= 1 && (int)$r['relay_num'] <= 4) {
                        $relays[(int)$r['relay_num'] - 1] = (int)$r['state'] === 1 ? 1 : 0;
                    }
                }
            }
            relay_q($connect, 'INSERT INTO mqtt_simulations (broker_id, device_id, relays, version, interval_sec, signal_level, bat_adc, created_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE version = VALUES(version), interval_sec = VALUES(interval_sec), dirty = 1',
                [(int)$broker['id'], $dev, implode(',', $relays), $version, $interval, random_int(14, 28), random_int(560, 640), $login]);
            $started[] = $dev;
        }
        relay_json(['ok' => true, 'started' => $started]);

    case 'set':
        $r = sim_row($connect, (int)($_POST['id'] ?? 0));
        $relays = array_map('intval', array_slice(explode(',', $r['relays'] . ',0,0,0,0'), 0, 4));
        $lock = (int)$r['lock_state'];
        $door = (int)$r['door_state'];
        $what = (string)($_POST['what'] ?? '');
        if (preg_match('/^relay([1-4])$/', $what, $m)) {
            $relays[$m[1] - 1] = $relays[$m[1] - 1] ? 0 : 1;
        } elseif ($what === 'door') {
            $door = $door ? 0 : 1;
        } elseif ($what === 'lock') {
            $lock = $lock ? 0 : 1;
        } else {
            relay_json(['error' => 'Что переключить?'], 400);
        }
        relay_q($connect, 'UPDATE mqtt_simulations SET relays = ?, lock_state = ?, door_state = ?, dirty = 1 WHERE id = ?',
            [implode(',', $relays), $lock, $door, (int)$r['id']]);
        relay_json(['ok' => true]);

    case 'stat':
        relay_q($connect, 'UPDATE mqtt_simulations SET dirty = 1 WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        relay_json(['ok' => true]);

    case 'stop':
        if (($_POST['all'] ?? '') === '1') {
            mysqli_query($connect, 'DELETE FROM mqtt_simulations');
        } else {
            relay_q($connect, 'DELETE FROM mqtt_simulations WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        }
        relay_json(['ok' => true]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
