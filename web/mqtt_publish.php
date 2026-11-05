<?php
// Отправка команды плате из основной панели (формат параметров совместим со script.js):
//   param  = "<команда>_<id платы>", param2 = номер реле (0 = все)
require __DIR__ . '/conectbd.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Метод не разрешен');
}
if (!isset($_POST['param'], $_POST['param2'])) {
    http_response_code(400);
    die('Отсутствуют параметры');
}
$parts = explode('_', (string)$_POST['param']);
if (count($parts) !== 2) {
    http_response_code(400);
    die('Неверный формат параметра');
}

try {
    relay_send_command($connect, (int)$parts[1], (int)$parts[0], (int)$_POST['param2'], $login ?? null);
    echo 'OK';
} catch (RuntimeException $e) {
    http_response_code(500);
    echo $e->getMessage();
}
