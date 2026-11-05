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

<div class="devgrid" id="grid"><div class="card empty" style="grid-column:1/-1">Загрузка…</div></div>

<?php layout_foot(['devices.js']); ?>
