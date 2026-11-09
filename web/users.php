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
      <thead><tr><th>Логин</th><th>Имя</th><th>Роль</th><th>Устройства</th><th title="Нажатия «Перезапуск реле»; «Открыть» замку не считается">Перезапуски реле</th><th title="Нажатия «Перезагрузить устройство»">Перезагрузки устройств</th><th>Доступ</th><th>Последний вход</th><th></th></tr></thead>
      <tbody id="users"><tr><td colspan="9" class="muted">Загрузка…</td></tr></tbody>
    </table>
  </div>
</section>

<section class="card roles-help">
  <div class="card-head"><h2>Роли</h2></div>
  <div class="card-body">
    <dl class="kv">
      <dt><span class="badge info">Администратор</span></dt><dd>всё: добавляет устройства и вносит их данные (названия, подписи реле, АКБ, замок),
        монитор, переадресация MQTT, наклейки, пользователи</dd>
      <dt><span class="badge success">Пользователь</span></dt><dd>видит только выданные ему устройства: «просмотр» — видит состояние реле,
        «управление» — может включать, выключать и перезагружать устройство</dd>
    </dl>
    <p class="muted" style="margin:12px 0 0;font-size:13px">Пользователи с отметкой «создан при установке» хранятся на сервере и всегда администраторы.
      Их пароль меняется командой <span class="mono">sudo relay-device user-add ЛОГИН</span>.</p>
  </div>
</section>

<dialog class="modal wide" id="user-dlg">
  <form method="dialog" id="user-form">
    <div class="modal-head"><h2 id="user-title">Новый пользователь</h2><button class="btn ghost sm" value="cancel" formnovalidate>✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <label class="field">Логин<input class="input" name="login" maxlength="64" pattern="[A-Za-z0-9_.@\-]{1,64}" required autocomplete="off"
          title="Латиница, цифры, _ . @ -" placeholder="ivanov"></label>
        <label class="field">Имя<input class="input" name="name" maxlength="100" placeholder="Иван Иванов"></label>
        <label class="field">Роль<select class="input" name="role">
          <option value="user">Пользователь</option><option value="admin">Администратор</option></select></label>
        <label class="field" id="f-enabled">Доступ<select class="input" name="enabled"><option value="1">Разрешён</option><option value="0">Отключён</option></select></label>
        <div class="field full">
          <label for="u-pass" id="pass-label">Пароль</label>
          <div class="pass-row"><input class="input mono" id="u-pass" name="password" type="text" autocomplete="new-password" maxlength="200">
            <button class="btn" type="button" id="b-gen">Сгенерировать</button></div>
          <span class="hint" id="pass-hint"></span>
        </div>
      </div>
      <fieldset class="fieldset" id="perm-box">
        <legend>Доступ к устройствам</legend>
        <div class="perm-ids">
          <label class="field grow">Номера устройств<input class="input mono" id="perm-ids" autocomplete="off" placeholder="например: 1, 3, 5-10"></label>
          <label class="field">Доступ<select class="input" id="perm-ids-mode">
            <option value="control">Управление</option><option value="view">Просмотр</option><option value="">Нет</option></select></label>
          <button class="btn" type="button" id="perm-ids-apply">Применить</button>
        </div>
        <div class="hint" id="perm-ids-note">через запятую; диапазон — через дефис: 1-10 — устройства с 1 по 10</div>
        <div class="perm-tools">
          <span class="perm-summary" id="perm-summary"></span><span style="flex:1"></span>
          <span class="muted" style="font-size:12.5px">Всем устройствам:</span>
          <button class="btn sm" type="button" data-all-perm="">Нет</button>
          <button class="btn sm" type="button" data-all-perm="view">Просмотр</button>
          <button class="btn sm" type="button" data-all-perm="control">Управление</button>
        </div>
        <div class="perm-list" id="perm-list"></div>
        <p class="hint" style="margin:0">«Управление» — видит все реле устройства и может ими управлять, «Просмотр» — только видит.</p>
      </fieldset>
      <div class="result" id="user-result"></div>
    </div>
    <div class="modal-foot">
      <button class="btn ghost danger-text hidden" type="button" id="b-del" style="margin-right:auto">Удалить</button>
      <button class="btn" value="cancel" formnovalidate>Отмена</button>
      <button class="btn primary" type="submit" id="user-submit">Добавить</button>
    </div>
  </form>
</dialog>

<dialog class="modal wide" id="clicks-dlg">
  <div class="modal-head"><h2 id="clicks-title">Нажатия</h2><button class="btn ghost sm" data-close>✕</button></div>
  <div class="modal-body">
    <p class="hint" style="margin:0">«Перезапуск реле» и «Перезагрузить устройство», нажатые пользователем. «Открыть» замку не учитывается.</p>
    <div id="clicks-list"></div>
  </div>
</dialog>

<?php layout_foot(['users.js']); ?>
