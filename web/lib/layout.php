<?php
/** Общий каркас страниц панели: шапка с навигацией, нижнее меню на телефоне. */

function layout_head(string $title, string $active, string $login): void
{
    $nav = [
        'devices' => ['/index.php', 'Устройства', '<path d="M4 5h16v10H4z"/><path d="M8 19h8M12 15v4"/>'],
        'monitor' => ['/monitor.php', 'Монитор', '<path d="M3 12h4l3-7 4 14 3-7h4"/>'],
        'labels'  => ['/labels.php', 'Наклейки', '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>'],
    ];
    $role = relay_current_role();
    if ($role === 'admin') {
        $nav['users'] = ['/users.php', 'Пользователи', '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M16 3.5a4 4 0 0 1 0 9M22 21a7 7 0 0 0-4-6.3"/>'];
    }
    relay_config(); // часовой пояс сервера для date('Z')
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<meta name="server-tz-offset" content="<?= (int)(date('Z') / 60) ?>">
<title><?= h($title) ?> · Relay Control</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='6' fill='%232563eb'/><path d='M7 12h3l2-4 2 8 2-4h1' stroke='white' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link rel="stylesheet" href="<?= h(asset_url('app.css')) ?>">
</head>
<body class="role-<?= h($role) ?>">
<header class="topbar">
  <a class="brand" href="/index.php">
    <span class="brand-mark"><svg viewBox="0 0 24 24"><path d="M5 12h3l2-5 4 10 2-5h3"/></svg></span>
    <span class="brand-name">Relay Control</span>
  </a>
  <nav class="nav">
    <?php foreach ($nav as $key => [$href, $label, $icon]): ?>
      <a href="<?= $href ?>" class="<?= $key === $active ? 'active' : '' ?>"><svg viewBox="0 0 24 24"><?= $icon ?></svg><span><?= $label ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="topbar-right">
    <span class="live" id="live" title="Связь с сервером"><i></i><span>онлайн</span></span>
    <details class="menu">
      <summary aria-label="Меню пользователя"><span class="avatar"><?= h(mb_strtoupper(mb_substr($login, 0, 1))) ?></span></summary>
      <div class="menu-pop">
        <div class="menu-user"><?= h($login) ?><small><?= h(RELAY_ROLES[$role] ?? '') ?></small></div>
        <button type="button" id="pw-open">Сменить пароль</button>
        <a href="/legacy.php">Классическая панель</a>
        <button type="button" id="theme-toggle">Сменить тему</button>
        <a href="/aut/verificat.php?aut=finish">Выйти</a>
      </div>
    </details>
  </div>
</header>
<main class="page">
    <?php
}

function layout_foot(array $scripts = []): void
{
    ?>
</main>
<div id="toasts" class="toasts" aria-live="polite"></div>
<dialog class="modal" id="pw-dlg">
  <form method="dialog" id="pw-form">
    <div class="modal-head"><h2>Смена пароля</h2><button class="btn ghost sm" value="cancel" formnovalidate>✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <label class="field full">Текущий пароль<input class="input" type="password" name="old" autocomplete="current-password" required></label>
        <label class="field full">Новый пароль<input class="input" type="password" name="password" autocomplete="new-password" minlength="<?= RELAY_MIN_PASS ?>" required></label>
        <label class="field full">Новый пароль ещё раз<input class="input" type="password" name="password2" autocomplete="new-password" required></label>
      </div>
      <div class="result" id="pw-result"></div>
    </div>
    <div class="modal-foot"><button class="btn" value="cancel" formnovalidate>Отмена</button><button class="btn primary" type="submit">Сменить</button></div>
  </form>
</dialog>
<script src="<?= h(asset_url('app.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= h(asset_url($s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
    <?php
}
