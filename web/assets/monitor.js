/* Страница «Монитор» */
'use strict';
(() => {
  const { $, $$, esc, api, ago, fmtTime, ICONS, toast, describe, cmdLabel, sendCommand } = App;
  let brokers = [], broker = 1, devices = [], lastId = 0, oldestId = 0, selected = 0;

  try { broker = +localStorage.getItem('relay-broker') || 1; } catch (e) {}
  const cur = () => brokers.find(b => b.id === broker) || brokers[0];
  const BST = { connected: ['success', 'подключён'], connecting: ['info', 'подключение…'], error: ['error', 'ошибка'], disabled: ['', 'отключён'] };

  /* ---------- Брокеры ---------- */
  async function loadBrokers() {
    brokers = (await api('/api/brokers.php', { action: 'list' })).brokers;
    if (!brokers.some(b => b.id === broker)) broker = brokers[0]?.id || 1;
    $('#broker').innerHTML = brokers.map(b => `<option value="${b.id}">${esc(b.name)}${b.is_local ? ' (свой)' : ''}${b.enabled ? '' : ' — отключён'}</option>`).join('');
    $('#broker').value = broker;
    renderBrokerInfo();
    if ($('#brokers').open) renderBrokerList();
  }
  function renderBrokerInfo() {
    const b = cur(); if (!b) return;
    const [c, t] = BST[b.status] || BST.connecting;
    $('#broker-info').innerHTML = `<span class="badge ${c}">${t}</span><span class="mono">${esc(b.host)}:${b.port}${b.use_tls ? ' TLS' : ''}</span>
      <span>подписки: <span class="mono">${esc(b.topics)}</span></span>
      ${b.last_ts ? `<span>последнее: ${ago(b.last_ts)}</span>` : ''}
      ${b.status === 'error' && b.last_error ? `<span class="broker-err">${esc(b.last_error)}</span>` : ''}`;
  }
  function renderBrokerList() {
    $('#broker-list').innerHTML = brokers.map(b => {
      const [c, t] = BST[b.status] || BST.connecting;
      return `<div class="broker-row">
        <span class="dot ${b.status === 'connected' ? 'online' : b.status === 'error' ? 'offline' : 'never'}"></span>
        <div style="min-width:0"><b>${esc(b.name)}</b> ${b.is_local ? '<span class="tag">собственный</span>' : ''}
          <div class="muted mono" style="overflow-wrap:anywhere">${esc(b.host)}:${b.port}${b.use_tls ? ' · TLS' : ''}${b.username ? ' · ' + esc(b.username) : ''} · ${esc(b.topics)}</div>
          <div style="font-size:12px"><span class="badge ${c}">${t}</span> <span class="muted">сообщений: ${b.messages}</span>
          ${b.status === 'error' && b.last_error ? `<div class="broker-err">${esc(b.last_error)}</div>` : ''}</div></div>
        <div class="btn-row">
          <button class="btn sm" data-edit="${b.id}">Изменить</button>
          ${b.is_local ? '' : `<button class="btn sm" data-toggle="${b.id}" data-en="${b.enabled ? 0 : 1}">${b.enabled ? 'Отключить' : 'Включить'}</button>
          <button class="btn sm ghost danger-text" data-del="${b.id}" title="Удалить">${ICONS.trash}</button>`}
        </div></div>`;
    }).join('');
  }
  function selectBroker(id) {
    broker = id; try { localStorage.setItem('relay-broker', id); } catch (e) {}
    selected = 0; $('#broker').value = id;
    renderBrokerInfo(); loadDevices(); loadMessages(true);
  }
  $('#broker').addEventListener('change', e => selectBroker(+e.target.value));
  $('#b-brokers').addEventListener('click', () => { renderBrokerList(); $('#brokers').showModal(); });
  $('#brokers').addEventListener('click', async e => {
    if (e.target.closest('[data-close]')) return $('#brokers').close();
    const ed = e.target.closest('[data-edit]'), tg = e.target.closest('[data-toggle]'), dl = e.target.closest('[data-del]');
    if (ed) openEditor(brokers.find(b => b.id === +ed.dataset.edit));
    if (tg) { try { await api('/api/brokers.php', { action: 'toggle', id: tg.dataset.toggle, enabled: tg.dataset.en }, true); await loadBrokers(); } catch (er) { toast(er.message, 'err'); } }
    if (dl) {
      const b = brokers.find(x => x.id === +dl.dataset.del);
      if (!confirm(`Удалить брокер «${b.name}» и его журнал сообщений?`)) return;
      try { await api('/api/brokers.php', { action: 'delete', id: b.id }, true); if (broker === b.id) broker = 1; await loadBrokers(); selectBroker(broker); } catch (er) { toast(er.message, 'err'); }
    }
  });
  $('#b-add').addEventListener('click', () => openEditor(null));

  const bf = $('#bf'), bdlg = $('#broker-edit');
  function openEditor(b) {
    bf.reset();
    bf.elements.id.value = b ? b.id : '';
    $('#bf-title').textContent = b ? (b.is_local ? 'Собственный брокер' : 'Изменить брокер') : 'Новый брокер';
    $('#bf-result').textContent = ''; $('#bf-result').className = 'result';
    const local = b && b.is_local;
    for (const k of ['host', 'port', 'username', 'password', 'use_tls', 'tls_verify', 'enabled']) bf.elements[k].disabled = !!local;
    if (b) {
      bf.elements.name.value = b.name; bf.elements.host.value = b.host; bf.elements.port.value = b.port; bf.elements.username.value = b.username || '';
      bf.elements.topics.value = b.topics; bf.elements.use_tls.checked = !!b.use_tls; bf.elements.tls_verify.checked = !!b.tls_verify; bf.elements.enabled.checked = !!b.enabled;
      bf.elements.password.placeholder = local ? 'из config.php' : b.has_password ? 'не менять' : '';
    } else bf.elements.password.placeholder = '';
    bdlg.showModal();
  }
  function formParams() {
    const p = { id: bf.elements.id.value, name: bf.elements.name.value, host: bf.elements.host.value, port: bf.elements.port.value, username: bf.elements.username.value,
      password: bf.elements.password.value, topics: bf.elements.topics.value, enabled: bf.elements.enabled.checked ? '1' : '0' };
    if (bf.elements.use_tls.checked) p.use_tls = 1;
    if (bf.elements.tls_verify.checked) p.tls_verify = 1;
    return p;
  }
  bf.elements.use_tls.addEventListener('change', () => { if (bf.elements.use_tls.checked && bf.elements.port.value === '1883') bf.elements.port.value = 8883; if (!bf.elements.use_tls.checked && bf.elements.port.value === '8883') bf.elements.port.value = 1883; });
  $('#bf-test').addEventListener('click', async () => {
    const r = $('#bf-result'); r.className = 'result wait'; r.textContent = 'Подключаюсь…';
    try {
      const j = await api('/api/brokers.php', { action: 'test', ...formParams() }, true);
      r.className = 'result ' + (j.ok ? 'ok' : 'err');
      r.textContent = j.ok ? `Подключение успешно (${j.ms} мс)` : 'Не удалось подключиться: ' + j.error;
    } catch (e) { r.className = 'result err'; r.textContent = e.message; }
  });
  bdlg.addEventListener('close', async () => {
    if (bdlg.returnValue !== 'save') return;
    try {
      const j = await api('/api/brokers.php', { action: 'save', ...formParams() }, true);
      toast('Брокер сохранён. Сервер подключится к нему в течение 10 секунд.', 'ok');
      await loadBrokers();
      if (!bf.elements.id.value) selectBroker(j.id);
    } catch (e) { toast(e.message, 'err'); }
  });

  /* ---------- Платы брокера ---------- */
  async function loadDevices() {
    const b = cur(); if (!b) return;
    if (b.is_local) {
      const j = await api('/api/devices.php', { action: 'list' });
      devices = j.devices.map(d => ({ id: d.id, name: d.name, ts: d.last_data_ts, state: d.state, signal: d.signal_level,
        relays: d.relays.map(r => r.state), alarm: d.alarm }));
    } else {
      devices = (await api('/api/monitor.php', { action: 'devices', broker })).devices;
    }
    const on = devices.filter(d => d.state === 'online' || d.state === 'alarm').length;
    $('#devcount').textContent = devices.length ? `${on} / ${devices.length} на связи` : '';
    $('#devices').innerHTML = devices.length ? devices.map(d => `
      <div class="side-item ${d.id === selected ? 'sel' : ''}" data-id="${d.id}" role="button" tabindex="0">
        <div class="side-item-top"><span class="dot ${d.state}"></span><span class="side-item-name">${esc(d.name)}</span><span class="mono muted">#${d.id}</span></div>
        <div class="side-item-meta">${d.ts ? 'данные ' + ago(d.ts) : 'данных не было'}${d.signal != null ? ' · сигнал ' + d.signal : ''}${d.alarm === 1 ? ' · <span style="color:var(--warn)">АКБ</span>' : ''}</div>
        <div class="mini-relays">${d.relays.map((s, i) => `<span class="${s === 1 ? 'on' : s === 2 ? 'er' : ''}">${i + 1}</span>`).join('')}</div>
        ${!b.is_local && d.registered === false ? `<a class="btn sm" style="margin-top:8px" href="/index.php?add=${d.id}" title="Зарегистрировать плату на этом сервере">+ Добавить в систему</a>` : ''}
      </div>`).join('')
      : `<div class="empty">${b.is_local ? 'Плат пока нет' : 'Плат не видно. Платы появляются здесь, как только пришлют stat в этот брокер.'}</div>`;
  }
  $('#devices').addEventListener('click', e => {
    if (e.target.closest('a')) return; // ссылка «Добавить в систему»
    const it = e.target.closest('.side-item'); if (!it) return;
    selectDevice(+it.dataset.id === selected ? 0 : +it.dataset.id);
  });
  function selectDevice(id) {
    selected = id;
    $$('#devices .side-item').forEach(x => x.classList.toggle('sel', +x.dataset.id === id));
    if (id) { $('#c-device').value = id; $('#r-topic').value = 'com/' + id; }
    loadMessages(true);
  }

  /* ---------- Сообщения ---------- */
  function rowHtml(m, isNew) {
    return `<tr class="${isNew ? 'flash' : ''}">
      <td data-c="time" class="mono nowrap">${esc(m.ts.slice(5, 23))}</td>
      <td data-c="dir"><span class="dir ${m.direction}">${ICONS[m.direction]}${m.direction === 'in' ? 'вход' : 'выход'}</span></td>
      <td data-c="topic" class="mono">${esc(m.topic)}${m.retained ? ' <span class="tag">retain</span>' : ''}</td>
      <td data-c="data">${describe(m)}${m.user_login ? ` <span class="tag">${esc(m.user_login)}</span>` : ''}<div class="hex">${esc(m.payload_hex.replace(/(.{8})/g, '$1 '))}</div></td>
      <td data-c="act">${m.direction === 'in' && m.device_id ? `<button class="btn sm" data-reply="${m.device_id}">Ответить</button>` : ''}</td></tr>`;
  }
  function filterParams() {
    const p = { action: 'messages', broker };
    if (selected) p.device = selected;
    const t = $('#f-topic').value.trim(); if (t) p.topic = t;
    return p;
  }
  async function loadMessages(reset) {
    if (reset) { lastId = 0; oldestId = 0; $('#log').innerHTML = ''; }
    const p = filterParams(); if (lastId) p.after = lastId;
    const j = await api('/api/monitor.php', p);
    if (j.messages.length) {
      lastId = Math.max(lastId, j.messages[0].id);
      if (!oldestId) oldestId = j.messages[j.messages.length - 1].id;
      $('#log').insertAdjacentHTML('afterbegin', j.messages.map(m => rowHtml(m, !reset)).join(''));
      const rows = $('#log').rows; while (rows.length > 1000) rows[rows.length - 1].remove();
    }
    if (reset) $('#more').classList.toggle('hidden', j.messages.length < 200);
    $('#logempty').style.display = $('#log').rows.length ? 'none' : '';
    // статус брокера обновляется вместе с журналом
    const b = cur(); if (b && j.broker) { b.status = j.broker.status; b.last_error = j.broker.last_error; renderBrokerInfo(); }
  }
  $('#more button').addEventListener('click', async () => {
    const p = filterParams(); p.before = oldestId;
    const j = await api('/api/monitor.php', p);
    if (j.messages.length) { oldestId = j.messages[j.messages.length - 1].id; $('#log').insertAdjacentHTML('beforeend', j.messages.map(m => rowHtml(m, false)).join('')); }
    $('#more').classList.toggle('hidden', j.messages.length < 200);
  });
  $('#log').addEventListener('click', e => {
    const b = e.target.closest('[data-reply]'); if (!b) return;
    const id = +b.dataset.reply;
    $('#c-device').value = id; $('#r-topic').value = 'com/' + id;
    $('#c-device').scrollIntoView({ behavior: 'smooth', block: 'center' }); $('#c-device').focus();
  });
  let topicTimer;
  $('#f-topic').addEventListener('input', () => { clearTimeout(topicTimer); topicTimer = setTimeout(() => loadMessages(true), 400); });
  $('#f-hex').addEventListener('change', e => $('#logwrap').classList.toggle('show-hex', e.target.checked));

  /* ---------- Отправка ---------- */
  const result = (cls, text) => { const r = $('#result'); r.className = 'result ' + cls; r.textContent = text; };
  $$('[data-cmd]').forEach(btn => btn.addEventListener('click', async () => {
    const device = +$('#c-device').value, relay = +$('#c-relay').value, command = +btn.dataset.cmd;
    if (!device) return result('err', 'Укажите ID платы');
    const b = cur();
    if (command === 3 && !confirm(`Перезапустить ${relay ? 'реле ' + relay : 'все реле'} на плате #${device}?`)) return;
    if (command === 5 && !confirm(`Перезагрузить плату #${device}${b.is_local ? '' : ' через брокер «' + b.name + '»'}?\nПлата будет недоступна 1–2 минуты.`)) return;
    const label = `#${device} ${cmdLabel(command, relay)}`;
    btn.disabled = true; result('wait', `${label}: отправка…`);
    try {
      await sendCommand({ url: '/api/monitor.php', params: { action: 'command', broker, device, command, relay }, statusUrl: '/api/monitor.php',
        statusParams: { action: 'command_status', broker }, label,
        onDone: st => result(st === 'success' ? 'ok' : 'err', `${label}: ${st === 'success' ? 'плата подтвердила выполнение' : st === 'error' ? 'плата сообщила об ошибке' : 'подтверждение не получено за 60 с'}`) });
      result('wait', `${label}: отправлено, ждём ответ платы…`);
      loadMessages();
    } catch (e) { result('err', `${label}: ${e.message}`); }
    finally { btn.disabled = false; }
  }));
  $('#r-send').addEventListener('click', async () => {
    try {
      await api('/api/monitor.php', { action: 'publish', broker, topic: $('#r-topic').value, payload: $('#r-payload').value,
        format: $('#r-format').value, retain: $('#r-retain').checked ? 1 : '' }, true);
      toast('Опубликовано в ' + $('#r-topic').value, 'ok'); loadMessages();
    } catch (e) { toast(e.message, 'err'); }
  });

  /* ---------- Старт ---------- */
  loadBrokers().then(() => Promise.all([loadDevices(), loadMessages(true)])).catch(e => toast(e.message, 'err'));
  App.every(2000, () => $('#f-pause').checked ? null : loadMessages());
  App.every(5000, () => loadDevices());
  App.every(10000, () => loadBrokers());
})();
