<?php
/**
 * Переадресация MQTT-сообщений между брокерами (только администратор).
 *   GET  ?action=list
 *   POST action=save    [id], name, src_broker_id, topic_filter, dst_broker_id, dst_prefix, keep_retain, enabled
 *   POST action=toggle  id, enabled
 *   POST action=delete  id
 * Сообщения брокера-источника, подходящие под фильтр, подписчик публикует в брокер-получатель
 * с тем же содержимым; топик — dst_prefix + исходный топик. Изменения применяются в течение 10 секунд.
 */
require __DIR__ . '/../conectbd.php';

$action = $_REQUEST['action'] ?? 'list';
if ($action !== 'list' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}
if (!mysqli_query($connect, 'SELECT 1 FROM mqtt_forwards LIMIT 0')) {
    relay_json(['error' => 'Нет таблицы переадресации — обновите сервер: sudo relay-update'], 500);
}

switch ($action) {
    case 'list':
        $rows = relay_rows(mysqli_query($connect, 'SELECT f.*, UNIX_TIMESTAMP(f.last_fwd_at) AS last_fwd_ts,
                s.name AS src_name, s.enabled AS src_enabled, s.status AS src_status,
                d.name AS dst_name, d.enabled AS dst_enabled, d.status AS dst_status
              FROM mqtt_forwards f JOIN mqtt_brokers s ON s.id = f.src_broker_id JOIN mqtt_brokers d ON d.id = f.dst_broker_id
             ORDER BY f.id'));
        foreach ($rows as &$r) {
            foreach (['id', 'src_broker_id', 'dst_broker_id', 'keep_retain', 'enabled', 'fwd_count', 'src_enabled', 'dst_enabled'] as $k) {
                $r[$k] = (int)$r[$k];
            }
            $r['last_fwd_ts'] = $r['last_fwd_ts'] === null ? null : (int)$r['last_fwd_ts'];
        }
        unset($r);
        $brokers = relay_rows(mysqli_query($connect, 'SELECT id, name, is_local, enabled FROM mqtt_brokers ORDER BY is_local DESC, id'));
        relay_json(['forwards' => $rows, 'brokers' => $brokers]);

    case 'save':
        $id = (int)($_POST['id'] ?? 0);
        $src = (int)($_POST['src_broker_id'] ?? 0);
        $dst = (int)($_POST['dst_broker_id'] ?? 0);
        $filter = trim((string)($_POST['topic_filter'] ?? '')) ?: '#';
        $prefix = trim((string)($_POST['dst_prefix'] ?? ''));
        foreach ([$src, $dst] as $b) {
            if (!relay_broker($connect, $b)) {
                relay_json(['error' => 'Выберите брокер-источник и брокер-получатель'], 400);
            }
        }
        $err = relay_topic_filter_error($filter);
        if ($err !== null || str_starts_with($filter, '$')) {
            relay_json(['error' => "Фильтр топиков «{$filter}»: " . ($err ?? 'служебные топики $SYS не пересылаются')], 400);
        }
        if (mb_strlen($prefix) > 100 || preg_match('/[+#\x00]/', $prefix) || str_starts_with($prefix, '$')) {
            relay_json(['error' => 'Префикс топика не может содержать + # и начинаться с $'], 400);
        }
        if ($src === $dst && $prefix === '') {
            relay_json(['error' => 'Пересылка в тот же брокер без префикса зациклится — укажите другой брокер или префикс'], 400);
        }
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        $params = [$name, $src, $filter, $dst, $prefix, ($_POST['keep_retain'] ?? '1') === '1' ? 1 : 0, ($_POST['enabled'] ?? '1') === '1' ? 1 : 0];
        if ($id > 0) {
            relay_q($connect, 'UPDATE mqtt_forwards SET name = ?, src_broker_id = ?, topic_filter = ?, dst_broker_id = ?, dst_prefix = ?,
                                      keep_retain = ?, enabled = ?, last_error = NULL WHERE id = ?', [...$params, $id]);
        } else {
            relay_q($connect, 'INSERT INTO mqtt_forwards (name, src_broker_id, topic_filter, dst_broker_id, dst_prefix, keep_retain, enabled)
                               VALUES (?, ?, ?, ?, ?, ?, ?)', $params);
            $id = mysqli_insert_id($connect);
        }
        relay_json(['ok' => true, 'id' => $id]);

    case 'toggle':
        relay_q($connect, 'UPDATE mqtt_forwards SET enabled = ? WHERE id = ?', [($_POST['enabled'] ?? '0') === '1' ? 1 : 0, (int)($_POST['id'] ?? 0)]);
        relay_json(['ok' => true]);

    case 'delete':
        relay_q($connect, 'DELETE FROM mqtt_forwards WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        relay_json(['ok' => true]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
