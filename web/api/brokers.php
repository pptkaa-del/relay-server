<?php
/**
 * Брокеры MQTT, к которым подключается сервер.
 *   GET  ?action=list
 *   POST action=save    [id], name, host, port, use_tls, tls_verify, username, password, topics, enabled
 *                       (пустой password при редактировании = оставить прежний)
 *   POST action=test    те же поля — пробное подключение без сохранения
 *   POST action=toggle  id, enabled
 *   POST action=delete  id (собственный брокер удалить нельзя)
 * Подписчик подхватывает изменения в течение 10 секунд.
 */
require __DIR__ . '/../conectbd.php';

$action = $_REQUEST['action'] ?? 'list';
if ($action !== 'list' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}

function broker_public(array $b): array
{
    $local = (int)$b['is_local'] === 1;
    if ($local) {
        $p = relay_broker_params($b);
        $b['host'] = $p['host'];
        $b['port'] = $p['port'];
        $b['username'] = $p['user'];
    }
    $b['has_password'] = $local || (string)$b['password'] !== '';
    unset($b['password']);
    foreach (['id', 'port', 'use_tls', 'tls_verify', 'is_local', 'enabled'] as $k) {
        $b[$k] = (int)$b[$k];
    }
    return $b;
}

/** Проверяет и нормализует список подписок; при ошибке отвечает 400. */
function broker_topics(string $raw): string
{
    [$ok, $err] = relay_parse_topics($raw === '' ? '#' : $raw);
    if ($err) {
        relay_json(['error' => 'Неверная подписка ' . implode('; ', $err)
            . '. Укажите фильтры через запятую, например: «#» (всё) или «stat, rep, com/#».'], 400);
    }
    return implode(', ', $ok ?: ['#']);
}

function broker_input(mysqli $db): array
{
    $s = fn($k, $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $b = [
        'name' => $s('name', 100),
        'host' => $s('host'),
        'port' => (int)($_POST['port'] ?? 1883),
        'use_tls' => empty($_POST['use_tls']) ? 0 : 1,
        'tls_verify' => empty($_POST['tls_verify']) ? 0 : 1,
        'username' => $s('username'),
        'password' => (string)($_POST['password'] ?? ''),
        'topics' => broker_topics($s('topics', 1000)),
        'enabled' => isset($_POST['enabled']) && $_POST['enabled'] === '0' ? 0 : 1,
        'is_local' => 0,
    ];
    if ($b['host'] === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $b['host'])) {
        relay_json(['error' => 'Укажите адрес брокера (домен или IP)'], 400);
    }
    if ($b['port'] < 1 || $b['port'] > 65535) {
        relay_json(['error' => 'Неверный порт'], 400);
    }
    if ($b['name'] === '') {
        $b['name'] = $b['host'] . ':' . $b['port'];
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id && $b['password'] === '') {
        $old = relay_broker($db, $id);
        $b['password'] = (string)($old['password'] ?? '');
    }
    return $b;
}

switch ($action) {
    case 'list':
        $rows = relay_rows(mysqli_query($connect, 'SELECT * FROM mqtt_brokers ORDER BY is_local DESC, id'));
        $counts = [];
        foreach (relay_rows(mysqli_query($connect, 'SELECT broker_id, COUNT(*) AS n, UNIX_TIMESTAMP(MAX(ts)) AS t FROM mqtt_messages GROUP BY broker_id')) as $r) {
            $counts[(int)$r['broker_id']] = ['messages' => (int)$r['n'], 'last_ts' => (int)$r['t']];
        }
        relay_json(['brokers' => array_map(fn($b) => broker_public($b) + ($counts[(int)$b['id']] ?? ['messages' => 0, 'last_ts' => null]), $rows)]);

    case 'save':
        $id = (int)($_POST['id'] ?? 0);
        $existing = $id ? relay_broker($connect, $id) : null;
        if ($existing && (int)$existing['is_local'] === 1) {
            // у собственного брокера меняются только имя и подписки
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100) ?: 'Локальный брокер';
            $topics = broker_topics(mb_substr(trim((string)($_POST['topics'] ?? '')), 0, 1000));
            relay_q($connect, 'UPDATE mqtt_brokers SET name = ?, topics = ? WHERE id = ?', [$name, $topics, $id]);
            relay_json(['ok' => true, 'id' => $id]);
        }
        $b = broker_input($connect);
        if ($existing) {
            relay_q($connect, 'UPDATE mqtt_brokers SET name = ?, host = ?, port = ?, use_tls = ?, tls_verify = ?, username = ?, password = ?, topics = ?, enabled = ?, status = ? WHERE id = ?',
                [$b['name'], $b['host'], $b['port'], $b['use_tls'], $b['tls_verify'], $b['username'], $b['password'], $b['topics'], $b['enabled'],
                 $b['enabled'] ? 'connecting' : 'disabled', $id]);
        } else {
            relay_q($connect, 'INSERT INTO mqtt_brokers (name, host, port, use_tls, tls_verify, username, password, topics, enabled, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$b['name'], $b['host'], $b['port'], $b['use_tls'], $b['tls_verify'], $b['username'], $b['password'], $b['topics'], $b['enabled'],
                 $b['enabled'] ? 'connecting' : 'disabled']);
            $id = mysqli_insert_id($connect);
        }
        relay_json(['ok' => true, 'id' => $id]);

    case 'test':
        $id = (int)($_POST['id'] ?? 0);
        $existing = $id ? relay_broker($connect, $id) : null;
        $b = ($existing && (int)$existing['is_local'] === 1) ? $existing : broker_input($connect);
        $t = microtime(true);
        try {
            // проверяем не только вход, но и подписки: на неверный фильтр брокер разрывает соединение
            $client = relay_mqtt_client($b, 'relay_test_' . bin2hex(random_bytes(4)), 5);
            foreach (relay_parse_topics((string)$b['topics'])[0] as $filter) {
                $client->subscribe($filter, fn() => null, 0);
            }
            // loop(…, exitWhenQueuesEmpty) при активных подписках не завершается — ждём ответы брокера 2 с вручную
            $start = microtime(true);
            while (microtime(true) - $start < 2) {
                $client->loopOnce($start, true, 50000);
            }
            $client->disconnect();
            relay_json(['ok' => true, 'ms' => (int)round((microtime(true) - $t) * 1000)]);
        } catch (Throwable $e) {
            relay_json(['ok' => false, 'error' => $e->getMessage()]);
        }

    case 'toggle':
        $id = (int)($_POST['id'] ?? 0);
        $en = ($_POST['enabled'] ?? '1') === '1' ? 1 : 0;
        $b = relay_broker($connect, $id);
        if (!$b) {
            relay_json(['error' => 'Брокер не найден'], 404);
        }
        if ((int)$b['is_local'] === 1 && !$en) {
            relay_json(['error' => 'Собственный брокер отключить нельзя — через него работают устройства'], 400);
        }
        relay_q($connect, 'UPDATE mqtt_brokers SET enabled = ?, status = ? WHERE id = ?', [$en, $en ? 'connecting' : 'disabled', $id]);
        relay_json(['ok' => true]);

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $b = relay_broker($connect, $id);
        if (!$b || (int)$b['is_local'] === 1) {
            relay_json(['error' => 'Этот брокер удалить нельзя'], 400);
        }
        relay_q($connect, 'DELETE FROM mqtt_brokers WHERE id = ?', [$id]);
        relay_q($connect, 'DELETE FROM mqtt_messages WHERE broker_id = ?', [$id]);
        relay_json(['ok' => true]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
