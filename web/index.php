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
  <div class="btn-row">
    <button class="btn" id="b-bulk" title="Изменить сразу несколько устройств: названия реле, реле 4 — замок двери"><svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Массовое изменение устройств</button>
    <button class="btn primary" id="b-add"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Добавить устройство</button>
  </div>
</div>

<div class="tiles" id="tiles">
  <button class="tile sel" data-f="all"><div class="tile-label">Всего устройств</div><div class="tile-value" data-k="total">—</div><div class="tile-sub">зарегистрировано</div></button>
  <button class="tile" data-f="online"><div class="tile-label"><span class="dot online"></span>На связи</div><div class="tile-value" data-k="online">—</div><div class="tile-sub">stat за последние 6 мин</div></button>
  <button class="tile" data-f="offline"><div class="tile-label"><span class="dot offline"></span>Нет связи</div><div class="tile-value" data-k="offline">—</div><div class="tile-sub">данных нет &gt; 350 с</div></button>
  <button class="tile" data-f="never"><div class="tile-label"><span class="dot never"></span>Ожидают</div><div class="tile-value" data-k="never">—</div><div class="tile-sub">ещё не выходили на связь</div></button>
</div>

<section class="card attention hidden" id="pending">
  <div class="card-head"><h2>Обнаружены незарегистрированные устройства</h2>
    <span class="muted">пытаются подключиться к брокеру, но не добавлены в систему</span></div>
  <div id="pending-list"></div>
</section>

<link rel="stylesheet" href="<?= h(asset_url('leaflet/leaflet.css')) ?>">

<div class="toolbar">
  <label class="search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <input class="input" id="q" type="search" placeholder="Поиск по названию, адресу или ID"></label>
  <span class="spacer"></span>
  <div class="seg" id="sort">
    <button data-s="id" class="on">По ID</button><button data-s="name">По имени</button><button data-s="seen">По данным</button>
  </div>
</div>

<div class="split" id="split">
  <aside class="card split-side">
    <button class="side-item side-all sel" data-sel="0">
      <div class="side-item-top"><span class="side-item-name">Все устройства</span><span class="badge" id="side-count">—</span></div>
      <div class="side-item-meta">карта</div>
    </button>
    <div class="side-list" id="side"></div>
  </aside>
  <div class="split-main">
    <section class="card map-card" id="map-card">
      <div class="card-head">
        <h2>Карта</h2>
        <div class="map-legend">
          <span><i class="pin c-green"></i>на связи</span>
          <span><i class="pin c-red"></i>нет данных</span>
          <span><i class="pin c-yellow"></i>все реле выключены</span>
          <span><i class="pin c-blue"></i>дверь открыта</span>
          <span><i class="pin c-purple"></i>взлом двери</span>
        </div>
      </div>
      <div class="map-wrap" id="map-wrap">
        <div id="map" class="map"></div>
        <div class="map-note" id="map-note"></div>
      </div>
    </section>
    <div id="panel"></div>
  </div>
</div>

<dialog class="modal" id="add">
  <form method="dialog" id="add-form">
    <div class="modal-head"><h2>Новое устройство</h2><button class="btn ghost sm" value="cancel" formnovalidate>✕</button></div>
    <div class="modal-body">
      <p class="muted" style="margin-top:0">Укажите ID, заданный в меню настройки устройства (пункт 1). Сервер создаст для него
        учётную запись в брокере — логин и пароль устройство вычисляет из ID само, вводить их в устройство не нужно.</p>
      <div class="form-grid">
        <label class="field">ID устройства<input class="input" name="id" type="number" min="1" step="1" required placeholder="например, 12"></label>
        <label class="field">Название объекта<input class="input" name="name" maxlength="200" placeholder="Шкаф ТП-12"></label>
        <label class="field">Адрес<input class="input" name="address" maxlength="300" placeholder="ул. Ленина, 14"></label>
        <label class="field">Номер SIM-карты<input class="input mono" name="sim_phone" type="tel" inputmode="tel" maxlength="20" placeholder="+79997777777" pattern="[0-9+()\s\-]{10,20}" title="+7 и 10 цифр, например +79997777777"></label>
        <div class="field relay-field"><span>Реле 1</span><input class="input" name="relay1" maxlength="60" placeholder="Реле 1"><label class="check relay-off"><input type="checkbox" name="relay1_off" value="1"> отключено — не показывать и не управлять</label></div>
        <div class="field relay-field"><span>Реле 2</span><input class="input" name="relay2" maxlength="60" placeholder="Реле 2"><label class="check relay-off"><input type="checkbox" name="relay2_off" value="1"> отключено — не показывать и не управлять</label></div>
        <div class="field relay-field"><span>Реле 3</span><input class="input" name="relay3" maxlength="60" placeholder="Реле 3"><label class="check relay-off"><input type="checkbox" name="relay3_off" value="1"> отключено — не показывать и не управлять</label></div>
        <div class="field relay-field"><span>Реле 4</span><input class="input" name="relay4" maxlength="60" placeholder="Реле 4"><label class="check relay-off"><input type="checkbox" name="relay4_off" value="1"> отключено — не показывать и не управлять</label></div>
        <label class="check full"><input type="checkbox" name="relay4_lock" value="1"> Реле 4 управляет замком двери
          <span class="hint">включено — дверь закрыта, выключено — открыта</span></label>
        <label class="field">Версия устройства<select class="input" name="hw_version">
          <option value="">Авто — по данным устройства</option>
          <option value="1">1 — первоначальная плата и прошивка (без АКБ)</option>
          <option value="2">2 — старая плата, новая прошивка</option>
          <option value="3">3 — новая плата ver_5_1 и прошивка (замок, дверь)</option></select></label>
        <label class="check bat-monitor"><input type="checkbox" name="bat_monitor" value="1" checked> Контроль АКБ
          <span class="hint">выключите, если АКБ не подключён; у версии 1 контроля АКБ нет</span></label>
        <fieldset class="fieldset" data-bat-box>
          <legend>АКБ и калибровка напряжения (необязательно)</legend>
          <label class="field">Тип АКБ<select class="input" name="bat_type"><option value="12">12 В</option><option value="24">24 В</option></select></label>
          <p class="hint">Подайте на вход АКБ устройства <span data-bat-low>6</span> В и <span data-bat-high>15</span> В от лабораторного источника и запишите показания АЦП —
            их видно на странице устройства и в мониторе (поле «АЦП АКБ» пакета stat). Калибровку можно задать и позже.</p>
          <div class="form-grid">
            <label class="field"><span>АЦП при <span data-bat-low>6</span> В</span><input class="input" name="cal_low" type="number" min="0" max="65535" step="1"></label>
            <label class="field"><span>АЦП при <span data-bat-high>15</span> В</span><input class="input" name="cal_high" type="number" min="0" max="65535" step="1"></label>
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
  <div class="modal-head"><h2>Устройство добавлено</h2><button class="btn ghost sm" data-close>✕</button></div>
  <div class="modal-body">
    <p style="margin-top:0">Устройство появится «на связи», как только подключится к серверу.</p>
    <dl class="kv" id="added-info"></dl>
    <p class="muted">Если устройство сейчас подключено к другому серверу, в прошивке нужно указать адрес этого сервера
      (<span class="mono">mqtt.setServer(...)</span>) и перепрошить её.</p>
  </div>
  <div class="modal-foot"><a class="btn" id="added-open" href="#">Открыть устройство</a><button class="btn primary" data-close>Готово</button></div>
</dialog>

<dialog class="modal" id="bulk">
  <form method="dialog" id="bulk-form">
    <div class="modal-head"><h2>Массовое изменение устройств</h2><button class="btn ghost sm" value="cancel" formnovalidate>✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <label class="field full">Номера устройств<input class="input mono" name="ids" required autocomplete="off" placeholder="например: 1, 3, 5-10">
          <span class="hint">через запятую; диапазон — через дефис: 1-10 — устройства с 1 по 10</span></label>
        <div class="full bulk-preview" id="bulk-preview"></div>
        <label class="field">Реле 1<input class="input" name="relay1" maxlength="60" placeholder="не менять"></label>
        <label class="field">Реле 2<input class="input" name="relay2" maxlength="60" placeholder="не менять"></label>
        <label class="field">Реле 3<input class="input" name="relay3" maxlength="60" placeholder="не менять"></label>
        <label class="field">Реле 4<input class="input" name="relay4" maxlength="60" placeholder="не менять"></label>
        <p class="hint full" style="margin:0">Пустое поле — название этого реле не меняется.</p>
        <label class="field">Версия устройства<select class="input" name="hw_version">
          <option value="keep">не менять</option><option value="auto">Авто — по данным устройства</option>
          <option value="1">1 — первоначальная плата (без АКБ)</option><option value="2">2 — старая плата, новая прошивка</option>
          <option value="3">3 — новая плата ver_5_1</option></select></label>
        <label class="field">Контроль АКБ<select class="input" name="bat_monitor">
          <option value="">не менять</option><option value="1">включить</option><option value="0">выключить</option></select></label>
        <label class="field full">Реле 4 — замок двери<select class="input" name="relay4_lock">
          <option value="">не менять</option>
          <option value="1">включить: реле 4 управляет замком (вкл — дверь закрыта, выкл — открыта)</option>
          <option value="0">выключить: реле 4 — обычное реле</option></select></label>
      </div>
      <div class="result" id="bulk-result"></div>
    </div>
    <div class="modal-foot">
      <button class="btn" value="cancel" formnovalidate>Закрыть</button>
      <button class="btn primary" type="submit" id="bulk-submit">Применить</button>
    </div>
  </form>
</dialog>

<?php layout_foot(['leaflet/leaflet.js', 'map.js', 'devices.js']); ?>
