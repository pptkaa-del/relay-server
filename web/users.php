<?php
require __DIR__ . '/aut/verificat.php';
require __DIR__ . '/lib/layout.php';
header('Cache-Control: no-store');
if (relay_current_role() !== 'admin') {
    http_response_code(403);
    layout_head('Пользователи', 'users', $login);
    echo '<div class="card empty"><b>Недостаточно прав</b><p>Управлять пользователями может только администратор.</p></div>';
    layout_foot();
    exit;
}
layout_head('Пользователи', 'users', $login);
?>
<div class="page-head">
  <div>
    <h1>Пользователи</h1>
    <p>Кто может входить в панель и что ему разрешено</p>
  </div>
  <button class="btn primary" id="b-add"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Добавить пользователя</button>
</div>

<section class="card">
  <div class="table-wrap">
    <table class="tbl users-tbl">
      <thead><tr><th>Логин</th><th>Имя</th><th>Роль</th><th>Доступ</th><th>Последний вход</th><th></th></tr></thead>
      <tbody id="users"><tr><td colspan="6" class="muted">Загрузка…</td></tr></tbody>
    </table>
  </div>
</section>

<section class="card roles-help">
  <div class="card-head"><h2>Роли</h2></div>
  <div class="card-body">
    <dl class="kv">
      <dt><span class="badge info">Администратор</span></dt><dd>всё, включая управление пользователями</dd>
      <dt><span class="badge success">Оператор</span></dt><dd>управление реле и устройствами, добавление устройств, монитор, наклейки</dd>
      <dt><span class="badge">Просмотр</span></dt><dd>только просмотр состояния и журналов, команды отправлять нельзя</dd>
    </dl>
    <p class="muted" style="margin:12px 0 0;font-size:13px">Пользователи с отметкой «создан при установке» хранятся на сервере и всегда администраторы.
      Их пароль меняется командой <span class="mono">sudo relay-device user-add ЛОГИН</span>.</p>
  </div>
</section>

<dialog class="modal" id="user-dlg">
  <form method="dialog" id="user-form">
    <div class="modal-head"><h2 id="user-title">Новый пользователь</h2><button class="btn ghost sm" value="cancel" formnovalidate>✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <label class="field">Логин<input class="input" name="login" maxlength="64" pattern="[A-Za-z0-9_.@\-]{1,64}" required autocomplete="off"
          title="Латиница, цифры, _ . @ -" placeholder="ivanov"></label>
        <label class="field">Имя<input class="input" name="name" maxlength="100" placeholder="Иван Иванов"></label>
        <label class="field">Роль<select class="input" name="role">
          <option value="operator">Оператор</option><option value="viewer">Просмотр</option><option value="admin">Администратор</option></select></label>
        <label class="field" id="f-enabled">Доступ<select class="input" name="enabled"><option value="1">Разрешён</option><option value="0">Отключён</option></select></label>
        <div class="field full">
          <label for="u-pass" id="pass-label">Пароль</label>
          <div class="pass-row"><input class="input mono" id="u-pass" name="password" type="text" autocomplete="new-password" maxlength="200">
            <button class="btn" type="button" id="b-gen">Сгенерировать</button></div>
          <span class="hint" id="pass-hint"></span>
        </div>
      </div>
      <div class="result" id="user-result"></div>
    </div>
    <div class="modal-foot">
      <button class="btn ghost danger-text hidden" type="button" id="b-del" style="margin-right:auto">Удалить</button>
      <button class="btn" value="cancel" formnovalidate>Отмена</button>
      <button class="btn primary" type="submit" id="user-submit">Добавить</button>
    </div>
  </form>
</dialog>

<?php layout_foot(['users.js']); ?>
