<?php
require_once __DIR__ . '/auth.php';
if (($_GET['aut'] ?? '') === 'finish') {
    relay_session_start();
    $_SESSION = [];
    session_destroy();
    header('Location: /login.php');
    exit;
}
$login = relay_require_auth();
