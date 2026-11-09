<?php
/**
 * Комментарии к устройствам.
 *   GET  ?action=list&id=N        — комментарии устройства (новые сверху)
 *   POST action=add id, text      — добавить (любой, кому доступно устройство)
 *   POST action=delete comment_id — удалить (автор или администратор)
 */
require __DIR__ . '/../conectbd.php';

const COMMENT_MAX = 2000;

$action = $_REQUEST['action'] ?? 'list';
if ($action !== 'list' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}
if (!mysqli_query($connect, 'SELECT 1 FROM device_comments LIMIT 0')) {
    relay_json(['error' => 'Нет таблицы комментариев — обновите сервер: sudo relay-update'], 500);
}

switch ($action) {
    case 'list':
        $id = (int)($_GET['id'] ?? 0);
        if (!relay_can_view($id)) {
            relay_forbidden('Нет доступа к этому устройству');
        }
        $rows = relay_rows(relay_q($connect, 'SELECT id, login, text, UNIX_TIMESTAMP(created_at) AS ts FROM device_comments
                                               WHERE device_id = ? ORDER BY id DESC LIMIT 500', [$id]));
        $admin = relay_is_admin();
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['ts'] = (int)$r['ts'];
            $r['can_delete'] = $admin || ($r['login'] !== null && $r['login'] === $login);
        }
        unset($r);
        relay_json(['comments' => $rows]);

    case 'add':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0 || !relay_can_view($id)) {
            relay_forbidden('Нет доступа к этому устройству');
        }
        $text = trim(str_replace("\0", '', (string)($_POST['text'] ?? '')));
        if ($text === '') {
            relay_json(['error' => 'Введите текст комментария'], 400);
        }
        if (mb_strlen($text) > COMMENT_MAX) {
            relay_json(['error' => 'Комментарий длиннее ' . COMMENT_MAX . ' символов'], 400);
        }
        relay_q($connect, 'INSERT INTO device_comments (device_id, login, text) VALUES (?, ?, ?)', [$id, $login, $text]);
        relay_json(['ok' => true, 'id' => mysqli_insert_id($connect)]);

    case 'delete':
        $cid = (int)($_POST['comment_id'] ?? 0);
        $c = mysqli_fetch_assoc(relay_q($connect, 'SELECT device_id, login FROM device_comments WHERE id = ?', [$cid]));
        if (!$c) {
            relay_json(['error' => 'Комментарий не найден'], 404);
        }
        if (!relay_is_admin() && $c['login'] !== $login) {
            relay_forbidden('Удалить комментарий может его автор или администратор');
        }
        relay_q($connect, 'DELETE FROM device_comments WHERE id = ?', [$cid]);
        relay_json(['ok' => true]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
