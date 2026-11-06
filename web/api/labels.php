<?php
/**
 * Журнал серийных номеров наклеек.
 *   GET  ?action=list[&q=...]          — последние номера + предложение следующего
 *   GET  ?action=check&sn=A,B,...      — какие из номеров уже выпущены
 *   POST action=add  sns=A,B,...  [device_id] [note]  — записать выпущенные номера
 *   POST action=delete id              — удалить запись (ошибочный номер)
 */
require __DIR__ . '/../conectbd.php';

$action = $_REQUEST['action'] ?? 'list';
if (in_array($action, ['add', 'delete'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    relay_json(['error' => 'Нужен POST'], 405);
}

const SN_RE = '/^[A-Za-z0-9\-]{1,32}$/';

function sn_list(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) as $sn) {
        if (!preg_match(SN_RE, $sn)) {
            relay_json(['error' => "Недопустимый серийный номер «{$sn}»: латиница, цифры и дефис, до 32 символов"], 400);
        }
        $out[$sn] = true;
    }
    if (count($out) > 1000) relay_json(['error' => 'Не больше 1000 номеров за раз'], 400);
    return array_keys($out);
}

/** Следующий номер: последний выпущенный +1 (с сохранением длины), иначе ГГГГММДД0001 */
function next_sn(mysqli $db): string
{
    $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT sn FROM serial_labels WHERE sn REGEXP '^[0-9]+$' ORDER BY LENGTH(sn) DESC, sn DESC LIMIT 1"));
    if (!$row) return date('Ymd') . '0001';
    $sn = $row['sn'];
    $next = function_exists('bcadd') ? bcadd($sn, '1') : (string)((int)$sn + 1);
    return str_pad($next, strlen($sn), '0', STR_PAD_LEFT);
}

switch ($action) {
    case 'list':
        $q = trim((string)($_GET['q'] ?? ''));
        $rows = $q !== ''
            ? relay_rows(relay_q($connect, 'SELECT id, sn, device_id, note, user_login, created_at FROM serial_labels WHERE sn LIKE ? OR note LIKE ? ORDER BY id DESC LIMIT 200', ["%$q%", "%$q%"]))
            : relay_rows(mysqli_query($connect, 'SELECT id, sn, device_id, note, user_login, created_at FROM serial_labels ORDER BY id DESC LIMIT 100'));
        $total = (int)mysqli_fetch_row(mysqli_query($connect, 'SELECT COUNT(*) FROM serial_labels'))[0];
        relay_json(['rows' => $rows, 'total' => $total, 'next' => next_sn($connect)]);

    case 'check':
        $sns = sn_list((string)($_GET['sn'] ?? ''));
        if (!$sns) relay_json(['exists' => []]);
        $ph = implode(',', array_fill(0, count($sns), '?'));
        $rows = relay_rows(relay_q($connect, "SELECT sn, created_at FROM serial_labels WHERE sn IN ($ph)", $sns));
        relay_json(['exists' => $rows]);

    case 'add':
        $sns = sn_list((string)($_POST['sns'] ?? ''));
        $dev = (int)($_POST['device_id'] ?? 0) ?: null;
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200) ?: null;
        $added = 0;
        foreach ($sns as $sn) {
            relay_q($connect, 'INSERT IGNORE INTO serial_labels (sn, device_id, note, user_login) VALUES (?, ?, ?, ?)', [$sn, $dev, $note, $login]);
            $added += mysqli_affected_rows($connect) > 0 ? 1 : 0;
        }
        relay_json(['ok' => true, 'added' => $added, 'skipped' => count($sns) - $added, 'next' => next_sn($connect)]);

    case 'delete':
        relay_q($connect, 'DELETE FROM serial_labels WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        relay_json(['ok' => true]);

    default:
        relay_json(['error' => 'Неизвестное действие'], 400);
}
