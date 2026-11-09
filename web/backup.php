<?php
require __DIR__ . '/aut/verificat.php';
require __DIR__ . '/lib/layout.php';
header('Cache-Control: no-store');
if (!relay_is_admin()) {
    relay_forbidden();
}
$dbName = relay_config()['db']['name'];
layout_head('Резервные копии', 'backup', $login);
?>
<div class="page-head">
  <div>
    <h1>Резервные копии</h1>
    <p>Копия базы данных и загрузка данных устройств из копии или из базы первоначальной системы</p>
  </div>
</div>

<div class="backup-grid">
  <section class="card">
    <div class="card-head"><h2>Скачать резервную копию БД</h2></div>
    <div class="card-body">
      <div class="radio-list">
        <label class="check"><input type="radio" name="bk-mode" value="compact" checked> Без журнала MQTT-сообщений
          <span class="hint">устройства, настройки, пользователи, события, команды — файл небольшой</span></label>
        <label class="check"><input type="radio" name="bk-mode" value="full"> Полная копия
          <span class="hint">вместе с журналом MQTT-сообщений — файл может быть большим</span></label>
      </div>
      <p><a class="btn primary" id="bk-download" href="/api/backup.php?action=download&amp;mode=compact"><svg viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>Скачать копию (.sql.gz)</a></p>
      <p class="hint" style="margin:0">Файл — SQL-дамп всей базы. Восстановить базу целиком на сервере:</p>
      <pre class="code">zcat off-on-backup-….sql.gz | sudo mysql <?= h($dbName) ?></pre>
      <p class="hint" style="margin:0">Пароли пользователей, созданных при установке (<span class="mono">/etc/relay-server/users.php</span>), в копию не входят.</p>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Загрузить данные устройств</h2></div>
    <div class="card-body">
      <form id="imp-form">
        <div class="radio-list">
          <label class="check"><input type="radio" name="source" value="backup" checked> Из резервной копии этой панели
            <span class="hint">файл, скачанный слева (.sql.gz или .sql)</span></label>
          <label class="check"><input type="radio" name="source" value="legacy"> Из БД первоначальной системы
            <span class="hint">экспорт старой базы (XAMPP): phpMyAdmin → «Экспорт» или <span class="mono">mysqldump</span>, файл .sql или .sql.gz</span></label>
        </div>
        <div class="send-row" style="margin-top:12px">
          <label class="field grow">Файл<input class="input" type="file" name="file" accept=".sql,.gz,.sql.gz,application/sql,application/gzip" required></label>
          <div class="btn-row"><button class="btn primary" type="submit" id="imp-check">Проверить файл</button></div>
        </div>
        <p class="hint" style="margin:6px 0 0">Загружаются только данные устройств: название, адрес, координаты, подписи реле и другие настройки.
          Новые устройства сразу регистрируются в брокере. Максимальный размер файла: <?= h(ini_get('upload_max_filesize')) ?>.</p>
      </form>
      <div class="result" id="imp-result"></div>
      <div id="imp-preview" class="hidden">
        <div class="imp-tools">
          <span id="imp-summary"></span><span class="spacer"></span>
          <button class="btn sm" type="button" data-sel-all="1">Выбрать все</button>
          <button class="btn sm" type="button" data-sel-all="new">Только новые</button>
          <button class="btn sm" type="button" data-sel-all="0">Снять все</button>
        </div>
        <div class="table-wrap imp-list"><table class="tbl"><thead><tr><th></th><th>ID</th><th>Название</th><th>Адрес</th><th>В панели</th></tr></thead>
          <tbody id="imp-rows"></tbody></table></div>
        <div class="send-row" style="margin-top:12px">
          <label class="field">Существующие устройства<select class="input" id="imp-mode">
            <option value="all">обновить данными из файла</option><option value="new">не трогать (только новые)</option></select></label>
          <div class="btn-row"><button class="btn primary" type="button" id="imp-go">Загрузить выбранные</button></div>
        </div>
      </div>
    </div>
  </section>
</div>

<?php layout_foot(['backup.js']); ?>
