<?php
require __DIR__ . '/aut/verificat.php';
require __DIR__ . '/lib/layout.php';
header('Cache-Control: no-store');
$id = (int)($_GET['id'] ?? 0);
layout_head("Устройство #$id", 'devices', $login);
?>
<div class="page-head" id="head" data-id="<?= $id ?>">
  <div style="min-width:0">
    <div class="crumbs"><a href="/index.php">Устройства</a> / <span class="mono">#<?= $id ?></span></div>
    <h1 id="d-name">Устройство #<?= $id ?></h1>
    <p id="d-addr"></p>
  </div>
  <div class="btn-row">
    <span id="d-badge"></span>
    <button class="btn" id="b-status"><svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>Запросить статус</button>
    <button class="btn" id="b-edit"><svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Изменить</button>
    <button class="btn bad" id="b-reboot"><svg viewBox="0 0 24 24"><path d="M12 3v9M6.3 6.3a8 8 0 1 0 11.4 0"/></svg>Перезагрузить устройство</button>
  </div>
</div>

<div id="breakin"></div>
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
        <button data-k="relays">Изменения реле</button>
        <button data-k="messages">MQTT-сообщения</button>
        <button data-k="commands">Команды</button>
      </div>
      <div class="filters">
        <label class="field">С даты<input class="input" type="date" id="f-from"></label>
        <label class="field">По дату<input class="input" type="date" id="f-to"></label>
        <button class="btn" id="f-apply">Показать</button>
        <span style="flex:1"></span>
        <label class="check log-limit">Показывать<select class="input" data-log-limit><option>20</option><option>50</option><option>100</option><option>200</option><option>500</option><option value="5000">все</option></select></label>
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
    <section class="card o-lock hidden" id="lockcard">
      <div class="card-head"><h2>Замок и дверь</h2></div>
      <div class="card-body">
        <dl class="kv" id="lockinfo"></dl>
        <div class="btn-row" style="margin-top:12px">
          <button class="btn primary" data-lock="3">Открыть замок</button>
          <button class="btn" data-lock="1">Подать питание</button>
          <button class="btn" data-lock="2">Снять питание</button>
        </div>
      </div>
    </section>
    <section class="card o-bat">
      <div class="card-head"><h2>Напряжение АКБ</h2>
        <div class="seg" id="bat-range"><button data-h="24" class="on">24 ч</button><button data-h="168">7 дн</button></div></div>
      <div class="card-body" id="battery"><span class="muted">Загрузка…</span></div>
    </section>
    <section class="card o-comments" id="comments-card">
      <div class="card-head"><h2>Комментарии</h2><span class="muted" id="comments-count"></span></div>
      <div class="card-body">
        <form id="comment-form" class="comment-form">
          <textarea class="input" name="text" rows="2" maxlength="2000" placeholder="Новый комментарий" required></textarea>
          <div class="btn-row"><button class="btn primary sm" type="submit">Добавить</button></div>
        </form>
        <ul class="comments" id="comments"></ul>
      </div>
    </section>
    <section class="card o-info">
      <div class="card-head"><h2>Сведения</h2></div>
      <div class="card-body"><dl class="kv" id="info"></dl></div>
    </section>
  </div>
</div>

<dialog class="modal" id="edit">
  <form method="dialog" id="edit-form">
    <div class="modal-head"><h2>Устройство #<?= $id ?></h2><button class="btn ghost sm" value="cancel" formnovalidate>✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <label class="field full">Название объекта<input class="input" name="name" maxlength="200" required></label>
        <label class="field full">Адрес<input class="input" name="address" maxlength="300"></label>
        <label class="field">Координаты<input class="input" name="coordinates" maxlength="100"></label>
        <label class="field">Номер SIM-карты<input class="input mono" name="sim_phone" type="tel" inputmode="tel" maxlength="20" pattern="[0-9+()\s\-]{10,20}" title="+7 и 10 цифр, например +79997777777"></label>
        <label class="field full">Для кого установлено<input class="input" name="owner" maxlength="200"></label>
        <div class="field relay-field"><span>Реле 1</span><input class="input" name="relay1" maxlength="60"><label class="check relay-off"><input type="checkbox" name="relay1_off" value="1"> отключено</label></div>
        <div class="field relay-field"><span>Реле 2</span><input class="input" name="relay2" maxlength="60"><label class="check relay-off"><input type="checkbox" name="relay2_off" value="1"> отключено</label></div>
        <div class="field relay-field"><span>Реле 3</span><input class="input" name="relay3" maxlength="60"><label class="check relay-off"><input type="checkbox" name="relay3_off" value="1"> отключено</label></div>
        <div class="field relay-field"><span>Реле 4</span><input class="input" name="relay4" maxlength="60"><label class="check relay-off"><input type="checkbox" name="relay4_off" value="1"> отключено</label></div>
        <label class="check full relay4-lock-field"><input type="checkbox" name="relay4_lock" value="1"> Реле 4 управляет замком двери</label>
        <label class="check full lock-unused-field"><input type="checkbox" name="lock_unused" value="1"> Замок не используется
          <span class="hint">плата ver_5_1 (версия 3): блок «Замок и дверь» не показывается, датчик двери не учитывается</span></label>
        <label class="field">Версия устройства<select class="input" name="hw_version">
          <option value="">Авто — по данным устройства</option>
          <option value="1">1 — первоначальная плата и прошивка (без АКБ)</option>
          <option value="2">2 — старая плата, новая прошивка</option>
          <option value="3">3 — новая плата ver_5_1 и прошивка (замок, дверь)</option></select></label>
        <label class="check bat-monitor"><input type="checkbox" name="bat_monitor" value="1" checked> Контроль АКБ
          <span class="hint">выключите, если АКБ не подключён; у версии 1 контроля АКБ нет</span></label>
        <fieldset class="fieldset" data-bat-box>
          <legend>АКБ и калибровка напряжения</legend>
          <label class="field">Тип АКБ<select class="input" name="bat_type"><option value="12">12 В</option><option value="24">24 В</option></select></label>
          <p class="hint">Подайте на вход АКБ устройства <span data-bat-low>6</span> В, дождитесь нового stat (кнопка «Запросить статус») и нажмите «Текущее»;
            затем то же для <span data-bat-high>15</span> В. Напряжение считается по прямой через эти две точки.</p>
          <p class="hint" id="cal-current"></p>
          <div class="form-grid">
            <div class="cal-row"><label class="field"><span>АЦП при <span data-bat-low>6</span> В</span><input class="input" name="cal_low" type="number" min="0" max="65535" step="1"></label>
              <button class="btn" type="button" data-cal="cal_low">Текущее</button></div>
            <div class="cal-row"><label class="field"><span>АЦП при <span data-bat-high>15</span> В</span><input class="input" name="cal_high" type="number" min="0" max="65535" step="1"></label>
              <button class="btn" type="button" data-cal="cal_high">Текущее</button></div>
          </div>
        </fieldset>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn ghost danger-text" type="button" id="b-delete" style="margin-right:auto">Удалить устройство</button>
      <button class="btn" value="cancel" formnovalidate>Отмена</button>
      <button class="btn primary" value="save">Сохранить</button>
    </div>
  </form>
</dialog>

<?php layout_foot(['device.js']); ?>
