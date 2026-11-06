<?php
require __DIR__ . '/aut/verificat.php';
require __DIR__ . '/lib/layout.php';
header('Cache-Control: no-store');
layout_head('Монитор', 'monitor', $login);
?>
<div class="page-head">
  <div><h1>MQTT монитор</h1><p>Все сообщения выбранного брокера в реальном времени и отправка команд устройствам</p></div>
</div>

<div class="broker-bar">
  <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>
  <select class="input" id="broker" aria-label="Брокер"></select>
  <div class="broker-info" id="broker-info"></div>
  <span style="flex:1"></span>
  <button class="btn" id="b-brokers">Брокеры…</button>
</div>

<div class="monitor">
  <section class="card">
    <div class="card-head"><h2>Устройства</h2><span class="muted" id="devcount"></span></div>
    <div class="side-list" id="devices"><div class="empty">Загрузка…</div></div>
  </section>

  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Отправить команду</h2></div>
      <div class="card-body">
        <div class="send-row">
          <label class="field">Устройство (ID)<input class="input" id="c-device" type="number" min="1" placeholder="ID" style="width:110px"></label>
          <label class="field">Реле<select class="input" id="c-relay" style="width:120px">
            <option value="0">все</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">замок</option></select></label>
          <div class="btn-row">
            <button class="btn ok" data-cmd="1">Включить</button>
            <button class="btn bad" data-cmd="2">Выключить</button>
            <button class="btn" data-cmd="3">Перезапуск реле</button>
            <button class="btn" data-cmd="4">Запрос статуса</button>
            <button class="btn danger-text" data-cmd="5"><svg viewBox="0 0 24 24"><path d="M12 3v9M6.3 6.3a8 8 0 1 0 11.4 0"/></svg>Перезагрузить устройство</button>
          </div>
        </div>
        <div class="result" id="result"></div>
      </div>
      <details class="box">
        <summary>Произвольное MQTT-сообщение</summary>
        <div>
          <div class="send-row">
            <label class="field grow">Топик<input class="input" id="r-topic" placeholder="например com/12 или sensors/temp"></label>
            <label class="field">Формат<select class="input" id="r-format" style="width:110px"><option value="text">текст</option><option value="hex">HEX</option></select></label>
            <label class="check" style="height:36px"><input type="checkbox" id="r-retain"> retain</label>
          </div>
          <textarea class="input" id="r-payload" placeholder="содержимое сообщения"></textarea>
          <div><button class="btn primary" id="r-send">Опубликовать</button></div>
        </div>
      </details>
    </section>

    <section class="card">
      <div class="card-head">
        <h2>Сообщения</h2>
        <input class="input" id="f-topic" placeholder="фильтр топика: stat, com/*" style="width:190px">
        <label class="check"><input type="checkbox" id="f-hex"> HEX</label>
        <label class="check"><input type="checkbox" id="f-pause"> пауза</label>
      </div>
      <div class="log-wrap" id="logwrap">
        <table class="tbl cards"><thead><tr><th>Время</th><th></th><th>Топик</th><th>Данные</th><th></th></tr></thead><tbody id="log"></tbody></table>
        <div class="empty" id="logempty">Сообщений пока нет</div>
      </div>
      <div class="more hidden" id="more"><button class="btn">Показать ещё</button></div>
    </section>
  </div>
</div>

<dialog class="modal" id="brokers">
  <div class="modal-head"><h2>Брокеры MQTT</h2><button class="btn ghost sm" data-close>✕</button></div>
  <div class="modal-body">
    <p class="muted" style="margin-top:0">Сервер постоянно подключён ко всем включённым брокерам и сохраняет их сообщения в журнал.
      Устройства и команды собственного брокера обрабатываются полностью; на сторонних брокерах можно наблюдать трафик и отправлять команды устройствам.</p>
    <div id="broker-list"></div>
    <div style="margin-top:12px"><button class="btn primary" id="b-add">+ Добавить брокер</button></div>
  </div>
</dialog>

<dialog class="modal" id="broker-edit">
  <form method="dialog" id="bf">
    <div class="modal-head"><h2 id="bf-title">Брокер</h2><button class="btn ghost sm" value="cancel" formnovalidate>✕</button></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-grid">
        <label class="field full">Название<input class="input" name="name" maxlength="100" placeholder="например, Старый сервер"></label>
        <label class="field">Адрес (домен или IP)<input class="input" name="host" required placeholder="185.209.29.25"></label>
        <label class="field">Порт<input class="input" name="port" type="number" min="1" max="65535" value="1883" required></label>
        <label class="field">Логин<input class="input" name="username" autocomplete="off"></label>
        <label class="field">Пароль<input class="input" name="password" type="password" autocomplete="new-password" placeholder=""></label>
        <label class="field full">Подписки (через запятую)<input class="input" name="topics" value="#" placeholder="#  или  stat, rep, com/#"><span class="muted">«#» — все сообщения. Знак # ставится только отдельно или в конце: com/#</span></label>
        <label class="check"><input type="checkbox" name="use_tls"> TLS (обычно порт 8883)</label>
        <label class="check"><input type="checkbox" name="tls_verify" checked> проверять сертификат</label>
        <label class="check"><input type="checkbox" name="enabled" checked> подключаться</label>
      </div>
      <div class="result" id="bf-result"></div>
    </div>
    <div class="modal-foot">
      <button class="btn" type="button" id="bf-test" style="margin-right:auto">Проверить подключение</button>
      <button class="btn" value="cancel" formnovalidate>Отмена</button>
      <button class="btn primary" value="save">Сохранить</button>
    </div>
  </form>
</dialog>

<?php layout_foot(['monitor.js']); ?>
