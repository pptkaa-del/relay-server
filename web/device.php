<?php
require __DIR__ . '/aut/verificat.php';
require __DIR__ . '/lib/layout.php';
header('Cache-Control: no-store');
$id = (int)($_GET['id'] ?? 0);
layout_head("Плата #$id", 'devices', $login);
?>
<div class="page-head" id="head" data-id="<?= $id ?>">
  <div style="min-width:0">
    <div class="crumbs"><a href="/index.php">Устройства</a> / <span class="mono">#<?= $id ?></span></div>
    <h1 id="d-name">Плата #<?= $id ?></h1>
    <p id="d-addr"></p>
  </div>
  <div class="btn-row">
    <span id="d-badge"></span>
    <button class="btn" id="b-status"><svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>Запросить статус</button>
    <button class="btn" id="b-edit"><svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Изменить</button>
    <button class="btn bad" id="b-reboot"><svg viewBox="0 0 24 24"><path d="M12 3v9M6.3 6.3a8 8 0 1 0 11.4 0"/></svg>Перезагрузить плату</button>
  </div>
</div>

<div class="layout-2">
  <div class="stack">
    <section class="card o-relays">
      <div class="card-head"><h2>Реле</h2><span class="muted" id="relays-note"></span>
        <div class="btn-row"><button class="btn sm" data-all="1">Все вкл</button><button class="btn sm" data-all="2">Все выкл</button><button class="btn sm" data-all="3">Перезапуск всех</button></div>
      </div>
      <div class="card-body"><div class="relay-cards" id="relays"></div></div>
    </section>

    <section class="card o-logs">
      <div class="tabs" id="tabs">
        <button data-k="events" class="on">События</button>
        <button data-k="messages">MQTT-сообщения</button>
        <button data-k="commands">Команды</button>
      </div>
      <div class="filters">
        <label class="field">С даты<input class="input" type="date" id="f-from"></label>
        <label class="field">По дату<input class="input" type="date" id="f-to"></label>
        <button class="btn" id="f-apply">Показать</button>
        <span style="flex:1"></span>
        <label class="check hidden" id="hexwrap"><input type="checkbox" id="f-hex"> HEX</label>
        <a class="btn" id="f-csv" href="#"><svg viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>CSV</a>
      </div>
      <div id="log" class="table-wrap"></div>
      <div class="more hidden" id="more"><button class="btn">Показать ещё</button></div>
    </section>
  </div>

  <div class="stack">
    <section class="card o-last">
      <div class="card-head"><h2>Последнее поступление данных</h2></div>
      <div class="card-body" id="lastdata"><span class="muted">Загрузка…</span></div>
    </section>
    <section class="card o-info">
      <div class="card-head"><h2>Сведения</h2></div>
      <div class="card-body"><dl class="kv" id="info"></dl></div>
    </section>
  </div>
</div>

<dialog class="modal" id="edit">
  <form method="dialog" id="edit-form">
    <div class="modal-head"><h2>Плата #<?= $id ?></h2><button class="btn ghost sm" value="cancel" formnovalidate>✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <label class="field full">Название объекта<input class="input" name="name" maxlength="200" required></label>
        <label class="field full">Адрес<input class="input" name="address" maxlength="300"></label>
        <label class="field full">Координаты<input class="input" name="coordinates" maxlength="100" placeholder="55.7558, 37.6173"></label>
        <label class="field">Реле 1<input class="input" name="relay1" maxlength="60"></label>
        <label class="field">Реле 2<input class="input" name="relay2" maxlength="60"></label>
        <label class="field">Реле 3<input class="input" name="relay3" maxlength="60"></label>
        <label class="field">Реле 4<input class="input" name="relay4" maxlength="60"></label>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn ghost danger-text" type="button" id="b-delete" style="margin-right:auto">Удалить из панели</button>
      <button class="btn" value="cancel" formnovalidate>Отмена</button>
      <button class="btn primary" value="save">Сохранить</button>
    </div>
  </form>
</dialog>

<?php layout_foot(['device.js']); ?>
