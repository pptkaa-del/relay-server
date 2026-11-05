<?php
require_once __DIR__ . '/auth.php';
if (($_GET['aut'] ?? '') === 'finish') {
    relay_session_start();
    $_SESSION = [];
    session_destroy();
    header('Location: /login.php');
    exit;
}
$login = getUserLogin();
if ($login === null) {
    header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}
