<?php
require_once __DIR__ . '/aut/auth.php';
relay_session_start();

$next = (string)($_GET['next'] ?? $_POST['next'] ?? '/index.php');
if (!preg_match('~^/(?![/\\\\])[^\s\\\\]*$~', $next) || str_starts_with($next, '/login.php')) {
    $next = '/index.php'; // только относительные адреса этого сайта
}
if (relay_current_user() !== null) {
    header('Location: ' . $next);
    exit;
}

$error = '';
$login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = (string)($_POST['login'] ?? '');
    $pass  = (string)($_POST['pass'] ?? '');
    if (checkAuth($login, $pass)) {
        session_regenerate_id(true);
        $_SESSION['login'] = $login;
        header('Location: ' . $next);
        exit;
    }
    usleep(700000); // замедляем перебор паролей
    $error = 'Неверный логин или пароль';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход · Relay Control</title>
<link rel="stylesheet" href="<?= h(asset_url('app.css')) ?>">
</head>
<body>
<div class="auth">
  <div class="card auth-card">
    <div class="brand"><span class="brand-mark"><svg viewBox="0 0 24 24"><path d="M5 12h3l2-5 4 10 2-5h3"/></svg></span><span>Relay Control</span></div>
    <p class="sub">Сервер управления релейными платами</p>
    <form method="POST" action="/login.php">
      <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <label class="field">Логин<input class="input" name="login" autocomplete="username" value="<?= h($login) ?>" required autofocus></label>
      <label class="field">Пароль<input class="input" type="password" name="pass" autocomplete="current-password" required></label>
      <button class="btn primary" type="submit">Войти</button>
    </form>
  </div>
</div>
<script>try { const t = localStorage.getItem('relay-theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}</script>
</body>
</html>
