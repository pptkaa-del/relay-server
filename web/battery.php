<?php
require __DIR__ . '/aut/verificat.php';
require __DIR__ . '/lib/layout.php';
header('Cache-Control: no-store');
layout_head('Мониторинг АКБ', 'devices', $login);
?>
<div class="page-head">
  <div>
    <h1>Мониторинг АКБ</h1>
    <p id="bm-sub">Напряжение АКБ всех устройств на одном графике · Загрузка…</p>
  </div>
  <div class="btn-row">
    <a class="btn" href="/index.php"><svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>Устройства</a>
    <div class="seg" id="bm-range"><button data-h="24" class="on">24 ч</button><button data-h="168">7 дн</button><button data-h="720">30 дн</button></div>
  </div>
</div>

<div class="bm-layout">
  <section class="card bm-chart-card">
    <div class="card-head"><h2>Напряжение АКБ, В</h2><span class="muted" id="bm-count"></span></div>
    <div class="card-body">
      <div class="bm-legend" id="bm-legend"></div>
      <div class="bm-chart" id="bm-chart"><div class="chart-empty">Загрузка…</div></div>
      <p class="muted bm-note" id="bm-note"></p>
    </div>
  </section>
  <aside class="card bm-pick">
    <div class="card-head"><h2>Устройства</h2><span class="muted" id="bm-sel-count"></span></div>
    <div class="card-body">
      <label class="search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input class="input" id="bm-q" type="search" placeholder="Поиск: название, адрес, ID"></label>
      <div class="bm-below">
        <label class="field" title="Показать в списке только устройства, у которых текущее напряжение АКБ ниже этого значения">АКБ ниже, В
          <input class="input mono" id="bm-below" type="text" inputmode="decimal" autocomplete="off" maxlength="6" placeholder="все"></label>
        <button class="btn sm ghost" id="bm-below-clear" type="button" title="Показать все устройства">Сбросить</button>
        <button class="btn sm primary hidden" id="bm-below-only" type="button" title="На графике — только найденные устройства">Показать на графике только их</button>
      </div>
      <div class="bm-found muted" id="bm-found"></div>
      <div class="btn-row bm-quick">
        <button class="btn sm" data-pick="low" title="Выбрать 8 устройств с самым низким текущим напряжением">8 с самым низким</button>
        <button class="btn sm" data-pick="all" title="Выбрать все найденные устройства">Все</button>
        <button class="btn sm ghost" data-pick="none">Ничего</button>
      </div>
      <div class="bm-list" id="bm-list"></div>
    </div>
  </aside>
</div>
<?php layout_foot(['batmon.js']); ?>
