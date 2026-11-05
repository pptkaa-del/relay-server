<?php
// Подключение к БД. Для веб-запросов дополнительно проверяется авторизация,
// поэтому все AJAX-обработчики панели (script/php/*, api/*) закрыты от анонимов.
require_once __DIR__ . '/lib/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    $login = relay_require_auth();
}
$connect = relay_db_connect();
if (!$connect && PHP_SAPI !== 'cli') {
    http_response_code(500);
    die('Нет соединения с базой данных');
}
