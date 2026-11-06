/* Страница «Устройства» */
'use strict';
(() => {
  const { $, $$, esc, api, ago, fmtTime, stateBadge, signalBars, ICONS, toast, sendCommand, cmdLabel } = App;
  let devices = [], filter = 'all', sort = 'id', query = '';
  const busy = new Set(); // "id:relay" — ждём подтверждения

  try { sort = localStorage.getItem('relay-sort') || 'id'; } catch (e) {}
  $$('#sort button').forEach(b => b.classList.toggle('on', b.dataset.s === sort));

  function visible() {
    const q = query.trim().toLowerCase();
    let list = devices.filter(d => {
      if (filter === 'online' && !d.online) return false;
      if (filter === 'offline' && (d.online || d.state === 'never')) return false;
      if (filter === 'alarm' && !(d.online && d.alarm === 1)) return false;
      if (filter === 'never' && d.state !== 'never') return false;
      return !q || String(d.id) === q || d.name.toLowerCase().includes(q) || (d.address || '').toLowerCase().includes(q);
    });
    if (sort === 'name') list.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    else if (sort === 'seen') list.sort((a, b) => (b.last_data_ts || 0) - (a.last_data_ts || 0));
    else list.sort((a, b) => a.id - b.id);
    return list;
  }

  function relayHtml(d, r) {
    const key = d.id + ':' + r.num;
    const st = r.state;
    const cls = st === 1 ? 'on' : st === 2 ? 'err' : '';
    const label = st == null ? 'нет данных' : App.RSTATE[st];
    const disabled = !d.registered || d.state === 'never';
    return `<div class="relay">
      <span class="relay-name" title="${esc(r.name)}">${esc(r.name)}</span>
      <button class="switch ${cls} ${busy.has(key) ? 'busy' : ''}" data-id="${d.id}" data-relay="${r.num}" data-state="${st ?? ''}"
        ${disabled ? 'disabled' : ''} aria-label="${esc(r.name)}: ${label}" title="${d.online ? 'Нажмите, чтобы ' + (st === 1 ? 'выключить' : 'включить') : 'Плата не на связи — команда выполнится при подключении'}"></button>
      <span class="relay-state">${label}</span></div>`;
  }

  function cardHtml(d) {
    const seen = d.last_data_ts;
    return `<article class="dev state-${d.state}">
      <div class="dev-head">
        <div class="dev-title"><a href="/device.php?id=${d.id}">${esc(d.name)}</a><div class="dev-addr">${esc(d.address || 'адрес не указан')}</div></div>
        <div>${stateBadge(d.state)}</div>
      </div>
      <div class="dev-meta">
        <span class="item mono">#${d.id}</span>
        <span class="item" title="Последнее поступление данных: ${seen ? fmtTime(seen) + ' (' + esc(d.last_data_topic || '') + ')' : 'нет'}">${ICONS.clock}<span data-ago="${seen || ''}">${ago(seen)}</span></span>
        <span class="item">${signalBars(d.signal_level)}</span>
        ${App.batteryHtml(d) ? `<span class="item">${App.batteryHtml(d)}</span>` : ''}
        ${d.alarm === 1 && d.online ? `<span class="badge alarm">${ICONS.battery}АКБ</span>` : ''}
        ${d.door_state === 1 ? '<span class="badge warn">дверь открыта</span>' : ''}
      </div>
      <div class="relays">${d.relays.map(r => relayHtml(d, r)).join('')}</div>
      <div class="dev-foot">
        <a class="btn sm ghost" href="/device.php?id=${d.id}">Подробнее</a>
        <span class="spacer"></span>
        <button class="btn sm ghost" data-cmd="4" data-id="${d.id}" title="Запросить состояние" ${d.registered ? '' : 'disabled'}>${ICONS.refresh}</button>
        <button class="btn sm ghost danger-text" data-cmd="5" data-id="${d.id}" title="Перезагрузить плату" ${d.registered ? '' : 'disabled'}>${ICONS.power}</button>
      </div>
    </article>`;
  }

  function render() {
    const list = visible();
    const grid = $('#grid');
    if (!devices.length) {
      grid.innerHTML = `<div class="card empty" style="grid-column:1/-1">${ICONS.server}<div><b>Плат пока нет</b></div>
        <p>Нажмите «Добавить плату» и укажите ID из меню настройки платы.<br>После подключения она появится здесь «на связи».</p>
        <button class="btn primary" data-open-add>Добавить плату</button></div>`;
      return;
    }
    grid.innerHTML = list.length ? list.map(cardHtml).join('') : '<div class="card empty" style="grid-column:1/-1">Нет плат по выбранному фильтру</div>';
  }

  async function load() {
    const j = await api('/api/devices.php', { action: 'list' });
    devices = j.devices;
    for (const [k, v] of Object.entries(j.summary)) { const el = $(`[data-k="${k}"]`); if (el) el.textContent = v; }
    $('#lastmsg').innerHTML = j.last_message_ts
      ? `Последнее сообщение от плат: <b data-ago="${j.last_message_ts}">${ago(j.last_message_ts)}</b> · ${fmtTime(j.last_message_ts)}`
      : 'Сообщений от плат ещё не было';
    render();
  }

  // обновляем «N минут назад» без запроса к серверу
  setInterval(() => $$('[data-ago]').forEach(el => { const t = +el.dataset.ago; if (t) el.textContent = ago(t); }), 5000);

  $('#tiles').addEventListener('click', e => {
    const t = e.target.closest('.tile'); if (!t) return;
    filter = t.dataset.f; $$('#tiles .tile').forEach(x => x.classList.toggle('sel', x === t)); render();
  });
  $('#q').addEventListener('input', e => { query = e.target.value; render(); });
  $('#sort').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    sort = b.dataset.s; $$('#sort button').forEach(x => x.classList.toggle('on', x === b));
    try { localStorage.setItem('relay-sort', sort); } catch (er) {}
    render();
  });

  $('#grid').addEventListener('click', async e => {
    const sw = e.target.closest('.switch');
    const cb = e.target.closest('[data-cmd]');
    if (sw && !sw.disabled && !sw.classList.contains('busy')) {
      const id = +sw.dataset.id, relay = +sw.dataset.relay, command = sw.dataset.state === '1' ? 2 : 1;
      const d = devices.find(x => x.id === id);
      const label = `${d.name}: ${cmdLabel(command, relay)}`;
      const key = id + ':' + relay;
      busy.add(key); sw.classList.add('busy');
      try {
        await sendCommand({ url: '/api/devices.php', params: { action: 'command', id, command, relay }, statusUrl: '/api/devices.php',
          statusParams: { action: 'command_status' }, label, onDone: () => { busy.delete(key); load().catch(() => {}); } });
      } catch (er) { busy.delete(key); sw.classList.remove('busy'); toast(er.message, 'err'); }
    } else if (cb) {
      const id = +cb.dataset.id, command = +cb.dataset.cmd;
      const d = devices.find(x => x.id === id);
      if (command === 5 && !confirm(`Перезагрузить плату «${d.name}» (#${id})?\nПлата будет недоступна 1–2 минуты.`)) return;
      cb.disabled = true;
      try {
        await sendCommand({ url: '/api/devices.php', params: { action: 'command', id, command, relay: 0 }, statusUrl: '/api/devices.php',
          statusParams: { action: 'command_status' }, label: `${d.name}: ${cmdLabel(command, 0)}`, onDone: () => load().catch(() => {}) });
      } catch (er) { toast(er.message, 'err'); }
      finally { cb.disabled = false; }
    }
  });

  /* ---------- Добавление платы ---------- */
  const addDlg = $('#add'), addForm = $('#add-form'), F = k => addForm.elements[k];
  function openAdd(prefillId) {
    addForm.reset(); $('#add-result').textContent = ''; $('#add-result').className = 'result';
    if (prefillId) F('id').value = prefillId;
    addDlg.showModal(); (prefillId ? F('name') : F('id')).focus();
  }
  $('#b-add').addEventListener('click', () => openAdd());
  $('#grid').addEventListener('click', e => { if (e.target.closest('[data-open-add]')) openAdd(); });
  // /index.php?add=12 — открыть форму сразу (ссылка из монитора)
  const addParam = new URLSearchParams(location.search).get('add');
  if (addParam && /^[1-9]\d{0,9}$/.test(addParam)) { openAdd(addParam); history.replaceState(null, '', '/index.php'); }

  /* ---------- Незарегистрированные платы ---------- */
  async function loadPending() {
    const list = (await api('/api/devices.php', { action: 'pending' })).pending;
    $('#pending').classList.toggle('hidden', !list.length);
    $('#pending-list').innerHTML = list.map(p => `<div class="pending-row">
      <div><b>Плата #${p.id}</b> <span class="mono muted">${esc(p.client_id)}</span>
        <div class="muted" style="font-size:12.5px">попыток подключения: ${p.attempts} · последняя ${ago(p.last_ts)}${p.ip ? ' · IP ' + esc(p.ip) : ''}</div></div>
      <div class="btn-row"><button class="btn sm primary" data-padd="${p.id}">Добавить</button>
        <button class="btn sm ghost" data-pign="${p.id}" title="Скрыть из списка">Скрыть</button></div></div>`).join('');
  }
  $('#pending').addEventListener('click', async e => {
    const a = e.target.closest('[data-padd]'), ig = e.target.closest('[data-pign]');
    if (a) openAdd(a.dataset.padd);
    if (ig) { try { await api('/api/devices.php', { action: 'pending_ignore', id: ig.dataset.pign }, true); loadPending(); } catch (er) { toast(er.message, 'err'); } }
  });
  loadPending().catch(() => {});
  App.every(15000, loadPending);
  addForm.addEventListener('submit', async e => {
    if (e.submitter && e.submitter.value === 'cancel') return;
    e.preventDefault();
    const p = { action: 'add' };
    for (const k of ['id', 'name', 'address', 'relay1', 'relay2', 'relay3', 'relay4', 'cal_adc_19', 'cal_adc_26']) p[k] = F(k).value.trim();
    const btn = $('#add-submit'), res = $('#add-result');
    btn.disabled = true; res.className = 'result wait'; res.textContent = 'Регистрирую плату в брокере…';
    try {
      const j = await api('/api/devices.php', p, true);
      addDlg.close();
      $('#added-info').innerHTML = `<dt>ID платы</dt><dd class="mono">${j.id}</dd>
        <dt>MQTT логин</dt><dd class="mono">${esc(j.mqtt.username)}</dd>
        <dt>MQTT пароль</dt><dd class="mono" style="word-break:break-all">${esc(j.mqtt.password)}</dd>
        <dt>Топик команд</dt><dd class="mono">${esc(j.mqtt.topic)}</dd>`;
      $('#added-open').href = '/device.php?id=' + j.id;
      $('#added').showModal();
      toast(`Плата #${j.id} добавлена`, 'ok');
      load().catch(() => {}); loadPending().catch(() => {});
    } catch (er) { res.className = 'result err'; res.textContent = er.message; }
    finally { btn.disabled = false; }
  });
  $('#added').addEventListener('click', e => { if (e.target.closest('[data-close]')) $('#added').close(); });

  load().catch(e => { $('#grid').innerHTML = `<div class="card empty" style="grid-column:1/-1">${esc(e.message)}</div>`; });
  App.every(5000, load);
})();
