<?php
require __DIR__ . '/aut/verificat.php';
require __DIR__ . '/lib/layout.php';
header('Cache-Control: no-store');
layout_head('Устройства', 'devices', $login);
?>
<div class="page-head">
  <div>
    <h1>Устройства</h1>
    <p id="lastmsg">Загрузка…</p>
  </div>
  <button class="btn primary" id="b-add"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Добавить плату</button>
</div>

<div class="tiles" id="tiles">
  <button class="tile sel" data-f="all"><div class="tile-label">Всего плат</div><div class="tile-value" data-k="total">—</div><div class="tile-sub">зарегистрировано</div></button>
  <button class="tile" data-f="online"><div class="tile-label"><span class="dot online"></span>На связи</div><div class="tile-value" data-k="online">—</div><div class="tile-sub">stat за последние 6 мин</div></button>
  <button class="tile" data-f="offline"><div class="tile-label"><span class="dot offline"></span>Нет связи</div><div class="tile-value" data-k="offline">—</div><div class="tile-sub">данных нет &gt; 350 с</div></button>
  <button class="tile" data-f="alarm"><div class="tile-label"><span class="dot alarm"></span>Питание от АКБ</div><div class="tile-value" data-k="alarm">—</div><div class="tile-sub">авария основного питания</div></button>
  <button class="tile" data-f="never"><div class="tile-label"><span class="dot never"></span>Ожидают</div><div class="tile-value" data-k="never">—</div><div class="tile-sub">ещё не выходили на связь</div></button>
</div>

<div class="toolbar">
  <label class="search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <input class="input" id="q" type="search" placeholder="Поиск по названию, адресу или ID"></label>
  <span class="spacer"></span>
  <div class="seg" id="sort">
    <button data-s="id" class="on">По ID</button><button data-s="name">По имени</button><button data-s="seen">По данным</button>
  </div>
</div>

<section class="card attention hidden" id="pending">
  <div class="card-head"><h2>Обнаружены незарегистрированные платы</h2>
    <span class="muted">пытаются подключиться к брокеру, но не добавлены в систему</span></div>
  <div id="pending-list"></div>
</section>

<div class="devgrid" id="grid"><div class="card empty" style="grid-column:1/-1">Загрузка…</div></div>

<dialog class="modal" id="add">
  <form method="dialog" id="add-form">
    <div class="modal-head"><h2>Новая плата</h2><button class="btn ghost sm" value="cancel" formnovalidate>✕</button></div>
    <div class="modal-body">
      <p class="muted" style="margin-top:0">Укажите ID, заданный в меню настройки платы (пункт 1). Сервер создаст для неё
        учётную запись в брокере — логин и пароль плата вычисляет из ID сама, вводить их в плату не нужно.</p>
      <div class="form-grid">
        <label class="field">ID платы<input class="input" name="id" type="number" min="1" step="1" required placeholder="например, 12"></label>
        <label class="field">Название объекта<input class="input" name="name" maxlength="200" placeholder="Шкаф ТП-12"></label>
        <label class="field full">Адрес<input class="input" name="address" maxlength="300" placeholder="ул. Ленина, 14"></label>
        <label class="field">Реле 1<input class="input" name="relay1" maxlength="60" placeholder="Реле 1"></label>
        <label class="field">Реле 2<input class="input" name="relay2" maxlength="60" placeholder="Реле 2"></label>
        <label class="field">Реле 3<input class="input" name="relay3" maxlength="60" placeholder="Реле 3"></label>
        <label class="field">Реле 4<input class="input" name="relay4" maxlength="60" placeholder="Реле 4"></label>
        <fieldset class="fieldset">
          <legend>Калибровка напряжения АКБ (необязательно)</legend>
          <p class="hint">Подайте на вход АКБ платы 6 В и 15 В от лабораторного источника и запишите показания АЦП —
            их видно на странице платы и в мониторе (поле «АЦП АКБ» пакета stat). Калибровку можно задать и позже.</p>
          <div class="form-grid">
            <label class="field">АЦП при 6 В<input class="input" name="cal_adc_6" type="number" min="0" max="65535" step="1" placeholder="например, 150"></label>
            <label class="field">АЦП при 15 В<input class="input" name="cal_adc_15" type="number" min="0" max="65535" step="1" placeholder="например, 375"></label>
          </div>
        </fieldset>
      </div>
      <div class="result" id="add-result"></div>
    </div>
    <div class="modal-foot">
      <button class="btn" value="cancel" formnovalidate>Отмена</button>
      <button class="btn primary" id="add-submit" type="submit">Добавить</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="added">
  <div class="modal-head"><h2>Плата добавлена</h2><button class="btn ghost sm" data-close>✕</button></div>
  <div class="modal-body">
    <p style="margin-top:0">Плата появится «на связи», как только подключится к серверу.</p>
    <dl class="kv" id="added-info"></dl>
    <p class="muted">Если плата сейчас подключена к другому серверу, в прошивке нужно указать адрес этого сервера
      (<span class="mono">mqtt.setServer(...)</span>) и перепрошить её.</p>
  </div>
  <div class="modal-foot"><a class="btn" id="added-open" href="#">Открыть плату</a><button class="btn primary" data-close>Готово</button></div>
</dialog>

<?php layout_foot(['devices.js']); ?>
