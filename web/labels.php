<?php
require __DIR__ . '/aut/verificat.php';
require __DIR__ . '/lib/layout.php';
header('Cache-Control: no-store');
layout_head('Наклейки', 'labels', $login);
?>
<div class="page-head">
  <div><h1>Наклейки с серийным номером</h1>
    <p>Картинка собирается из шаблона <span class="mono">assets/label/label_template.png</span> — меняется только строка «SN»</p></div>
</div>

<div class="layout-2 labels-layout">
  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Предпросмотр</h2><span class="muted" id="pv-size"></span></div>
      <div class="card-body"><canvas id="preview" class="label-canvas" width="1942" height="833" aria-label="Предпросмотр наклейки"></canvas></div>
    </section>
    <section class="card hidden" id="series-card">
      <div class="card-head"><h2>Серия</h2><span class="muted" id="series-info"></span></div>
      <div class="card-body"><div class="label-grid" id="series"></div></div>
    </section>
  </div>

  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Параметры</h2></div>
      <div class="card-body">
        <form id="lf" class="form-grid" onsubmit="return false">
          <label class="field full">Серийный номер
            <div class="cal-row"><input class="input mono" name="sn" maxlength="32" required autocomplete="off" placeholder="202608185060">
              <button class="btn" type="button" id="b-next" title="Следующий после последнего выпущенного">Следующий</button></div>
          </label>
          <label class="field">Количество (серия)<input class="input" name="count" type="number" min="1" max="500" value="1"></label>
          <label class="field">Ширина при печати, мм<input class="input" name="width" type="number" min="20" max="200" step="1" value="70"></label>
          <label class="field full">Примечание в журнал (необязательно)<input class="input" name="note" maxlength="200" placeholder="например, партия ноябрь 2026"></label>
          <label class="check full"><input type="checkbox" name="log" checked> записать номера в журнал выпущенных</label>
        </form>
        <div class="result" id="lres"></div>
        <div class="btn-row" style="margin-top:12px">
          <button class="btn primary" id="b-png">Скачать PNG</button>
          <button class="btn" id="b-print">Печать</button>
        </div>
        <p class="muted" style="font-size:12.5px;margin:12px 0 0">Серия: номера увеличиваются на 1 с сохранением длины (…059, …060, …061).
          «Скачать PNG» для серии скачивает файлы по одному; для печати серии удобнее «Печать» — лист с наклейками нужного размера.</p>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>Журнал номеров</h2><span class="muted" id="lj-total"></span></div>
      <div class="filters"><label class="field grow">Поиск<input class="input" id="lj-q" placeholder="номер или примечание"></label></div>
      <div class="table-wrap" style="max-height:420px"><table class="tbl"><thead><tr><th>SN</th><th>Выпущен</th><th></th></tr></thead><tbody id="lj"></tbody></table></div>
    </section>
  </div>
</div>

<div id="print-area" class="print-area" aria-hidden="true"></div>
<?php layout_foot(['labels.js']); ?>
