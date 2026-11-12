<?php
require __DIR__ . '/aut/verificat.php';
require __DIR__ . '/lib/layout.php';
header('Cache-Control: no-store');
if (!relay_is_admin()) {
    relay_forbidden('Статистика сервера доступна только администратору');
}
layout_head('Сервер', 'server', $login);
?>
<div class="page-head">
  <div><h1>Сервер</h1><p id="srv-sub">Загрузка…</p></div>
  <div class="seg" id="range">
    <button data-r="1h">1 час</button><button data-r="6h">6 часов</button><button data-r="24h" class="on">24 часа</button><button data-r="7d">7 дней</button><button data-r="30d">30 дней</button>
  </div>
</div>

<div id="srv-warn"></div>

<div class="srv-tiles" id="srv-tiles"></div>

<div class="srv-charts">
  <section class="card"><div class="card-head"><h2>Процессор, %</h2><span class="muted chart-now" id="n-cpu"></span></div><div class="chart" id="c-cpu"></div></section>
  <section class="card"><div class="card-head"><h2>Память, занято программами</h2><span class="muted chart-now" id="n-mem"></span></div><div class="chart" id="c-mem"></div></section>
  <section class="card"><div class="card-head"><h2>Сеть</h2><span class="muted chart-now" id="n-net"></span></div><div class="chart" id="c-net"></div></section>
  <section class="card"><div class="card-head"><h2>Сообщения MQTT в минуту</h2><span class="muted chart-now" id="n-mqtt"></span></div><div class="chart" id="c-mqtt"></div></section>
  <section class="card"><div class="card-head"><h2>Устройства на связи</h2><span class="muted chart-now" id="n-dev"></span></div><div class="chart" id="c-dev"></div></section>
  <section class="card"><div class="card-head"><h2>Подключено к брокеру</h2><span class="muted chart-now" id="n-cli"></span></div><div class="chart" id="c-cli"></div></section>
</div>

<section class="card">
  <div class="card-head"><h2>Службы</h2></div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Служба</th><th>Состояние</th><th>Работает с</th><th class="num">Память</th><th class="num">Время процессора</th><th class="num">Перезапуски</th></tr></thead>
    <tbody id="srv-services"><tr><td colspan="6" class="muted">Загрузка…</td></tr></tbody>
  </table></div>
</section>

<div class="srv-grid">
  <section class="card"><div class="card-head"><h2>MQTT-брокер</h2></div><div class="card-body"><dl class="kv" id="srv-broker"></dl></div></section>
  <section class="card"><div class="card-head"><h2>Подписчик MQTT</h2></div><div class="card-body"><dl class="kv" id="srv-sub-info"></dl></div></section>
  <section class="card"><div class="card-head"><h2>Система</h2></div><div class="card-body"><dl class="kv" id="srv-info"></dl></div></section>
</div>

<section class="card">
  <div class="card-head"><h2>База данных</h2><span class="muted" id="db-head"></span></div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Таблица</th><th class="num">Строк (примерно)</th><th class="num">Размер</th><th style="width:40%"></th></tr></thead>
    <tbody id="srv-db"></tbody>
  </table></div>
</section>

<?php layout_foot(['server.js']); ?>
