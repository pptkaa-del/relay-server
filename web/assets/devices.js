/* Страница «Устройства» */
'use strict';
(() => {
  const { $, $$, esc, api, ago, fmtTime, stateBadge, signalBars, ICONS, toast, sendCommand, cmdLabel } = App;
  let devices = [], filter = 'all', sort = 'id', query = '';
  const plural = (n, one, few, many) => { const m10 = n % 10, m100 = n % 100;
    return m10 === 1 && m100 !== 11 ? one : m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? few : many; };
  const busy = new Set(); // "id:relay" — ждём подтверждения

  try { sort = localStorage.getItem('relay-sort') || 'id'; } catch (e) {}
  $$('#sort button').forEach(b => b.classList.toggle('on', b.dataset.s === sort));

  function visible() {
    const q = query.trim().toLowerCase();
    let list = devices.filter(d => {
      if (filter === 'online' && !d.online) return false;
      if (filter === 'offline' && (d.online || d.state === 'never')) return false;
      if (filter === 'never' && d.state !== 'never') return false;
      return !q || String(d.id) === q || d.name.toLowerCase().includes(q) || (d.address || '').toLowerCase().includes(q);
    });
    if (sort === 'name') list.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    else if (sort === 'seen') list.sort((a, b) => (b.last_data_ts || 0) - (a.last_data_ts || 0));
    else list.sort((a, b) => a.id - b.id);
    return list;
  }

  /* ---------- Выбор платы: /index.php#9 — плата 9, без номера — сводка всех реле ---------- */
  const selFromHash = () => { const m = /^#(\d{1,10})$/.exec(location.hash); return m ? +m[1] : 0; };
  let selected = selFromHash();
  function select(id) {
    selected = id;
    history.replaceState(null, '', id ? '#' + id : location.pathname + location.search);
    render();
    if (id && matchMedia('(max-width: 1100px)').matches) $('#panel').scrollIntoView({ block: 'start' });
  }
  window.addEventListener('hashchange', () => { selected = selFromHash(); render(); });

  // реле 4 — замок: включено — дверь закрыта (зелёный), выключено — открыта (жёлтый)
  const relayBadge = (st, lock = false) => st == null ? '<span class="badge">нет данных</span>'
    : lock ? `<span class="badge ${st === 1 ? 'success' : 'warning'}">${App.LOCK4_STATE[st]}</span>`
    : `<span class="badge ${st === 1 ? 'success' : st === 2 ? 'warning' : ''}">${App.RSTATE[st]}</span>`;
  const relayStateText = r => r.state == null ? 'нет данных' : r.lock ? App.LOCK4_STATE[r.state] : App.RSTATE[r.state];
  const viewOnly = '<span class="muted ro-note" title="Администратор выдал только просмотр">только просмотр</span>';
  // тревога «взлом двери»: дверь открылась без команды «Открыть»
  const breakBadge = d => d.break_in ? `<span class="badge breakin" title="Дверь открылась без команды «Открыть» · ${fmtTime(d.break_in.ts)}">ВЗЛОМ ДВЕРИ</span>` : '';
  const breakBanner = d => d.break_in ? `<div class="breakin-banner"><b>ВЗЛОМ ДВЕРИ</b>
      <span>Дверь открылась без команды «Открыть» ${d.break_in.source === 'door' ? '(датчик двери)' : '(замок, реле 4)'} · ${fmtTime(d.break_in.ts)} · ${ago(d.break_in.ts)}</span>
      <span class="spacer"></span>${d.can_ack ? `<button class="btn sm" data-ack="${d.id}">Сбросить тревогу</button>` : ''}</div>` : '';
  const canSend = d => d.registered && d.state !== 'never';
  const metaHtml = d => {
    const seen = d.last_data_ts;
    return `<span class="item mono">#${d.id}</span>
      ${d.sim_phone ? `<a class="item mono" href="tel:${esc(d.sim_phone)}" title="Номер SIM-карты">${ICONS.phone}${App.fmtPhone(d.sim_phone)}</a>` : ''}
      <span class="item" title="Последнее поступление данных: ${seen ? fmtTime(seen) : 'нет'}">${ICONS.clock}<span data-ago="${seen || ''}">${ago(seen)}</span></span>
      <span class="item">${signalBars(d.signal_level)}</span>
      ${App.batteryHtml(d) ? `<span class="item">${App.batteryHtml(d)}</span>` : ''}
      ${d.door_state === 1 ? '<span class="badge warn">дверь открыта</span>' : ''}`;
  };

  // список плат слева
  function sideHtml(d) {
    const on = d.relays.filter(r => r.state === 1).length;
    const pips = d.relays.map(r => `<i class="${r.state === 1 ? 'on' : r.state === 2 ? 'err' : r.state == null ? 'na' : ''}${r.lock ? ' lock' : ''}" title="${esc(r.name)}: ${relayStateText(r)}"></i>`).join('');
    return `<button class="side-item ${d.id === selected ? 'sel' : ''}" data-sel="${d.id}">
      <div class="side-item-top"><span class="dot ${d.break_in ? 'breakin' : d.state}"></span><span class="side-item-name">${esc(d.name)}</span>${breakBadge(d)}<span class="mono muted">#${d.id}</span></div>
      <div class="side-item-meta side-dev-meta"><span class="pips">${pips}</span><span>вкл ${on}/${d.relays.length}</span>
        <span class="spacer"></span><span data-ago="${d.last_data_ts || ''}">${ago(d.last_data_ts)}</span></div></button>`;
  }

  // без выбора: все реле всех плат
  // выбрана плата: её реле и краткие сведения
  function panelHtml(d) {
    const dis = !canSend(d) ? 'disabled' : '';
    const relays = d.relays.map(r => {
      const b = n => busy.has(d.id + ':' + r.num) || dis ? 'disabled' : '';
      const buttons = !r.control ? `<div class="ro-box">${viewOnly}</div>`
        : r.lock ? `<div class="btn-row lock-btns">
          <button class="btn sm primary" data-c="3" data-r="4" ${b(3)} title="Кратковременно открыть замок">Открыть</button>
          <button class="btn sm ok" data-c="1" data-r="4" ${b(1)}>Закрыть</button>
          <button class="btn sm" data-c="2" data-r="4" ${b(2)} title="Оставить дверь открытой до команды «Закрыть»">Открыть надолго</button></div>`
        : `<div class="btn-row">
          <button class="btn sm ok" data-c="1" data-r="${r.num}" ${b(1)}>Вкл</button>
          <button class="btn sm bad" data-c="2" data-r="${r.num}" ${b(2)}>Выкл</button>
          <button class="btn sm" data-c="3" data-r="${r.num}" ${b(3)} title="Выключить и через 1 с включить">${ICONS.restart}</button></div>`;
      return `<div class="relay-card${r.lock ? ' is-lock' : ''}">
        <div class="relay-card-top"><span class="relay-card-name" title="${esc(r.name)}">${esc(r.name)}</span>${relayBadge(r.state, r.lock)}</div>
        <div class="muted" style="font-size:12px">${r.lock ? 'Замок · реле 4' : 'Реле ' + r.num}${r.ts ? ' · изм. ' + fmtTime(r.ts) : ''}</div>
        ${buttons}</div>`;
    }).join('');
    const lockDis = dis || d.lock_access !== 'control' ? 'disabled' : '';
    const lock = d.door_state != null && d.lock_access ? `<div class="lock-row">
        <span>Дверь: ${d.door_state === 1 ? '<span class="badge warn">открыта</span>' : '<span class="badge success">закрыта</span>'}</span>
        <span>Замок: ${d.lock_state === 1 ? '<span class="badge info">под напряжением</span>' : '<span class="badge">обесточен</span>'}</span>
        <span class="spacer"></span>
        ${d.lock_access === 'control' ? `<button class="btn sm primary" data-c="3" data-r="5" ${lockDis}>Открыть замок</button>
        <button class="btn sm" data-c="1" data-r="5" ${lockDis}>Подать питание</button>
        <button class="btn sm" data-c="2" data-r="5" ${lockDis}>Снять питание</button>` : viewOnly}</div>` : '';
    const note = d.state === 'never' ? 'Устройство ещё не выходило на связь.' : !d.online ? 'Устройство не на связи: команды выполнятся при подключении.' : '';
    return `<section class="card dev-panel state-${d.state}">
      <div class="card-head">
        <button class="btn sm ghost" data-sel="0" title="Вернуться к карте">← Карта</button>
        <div class="dev-panel-title"><h2><a href="/device.php?id=${d.id}">${esc(d.name)}</a></h2><div class="muted">${esc(d.address || 'адрес не указан')}</div></div>
        ${stateBadge(d.state)}
      </div>
      <div class="card-body">
        ${breakBanner(d)}
        <div class="dev-meta dev-panel-meta">${metaHtml(d)}</div>
        <div class="relay-cards">${relays}</div>
        ${lock}
        ${note ? `<p class="muted" style="margin:12px 0 0;font-size:13px">${note}</p>` : ''}
      </div>
      <div class="dev-foot">
        <a class="btn sm" href="/device.php?id=${d.id}">Подробнее: журнал, АКБ, настройки</a>
        <span class="spacer"></span>
        <button class="btn sm ghost" data-cmd="4" data-id="${d.id}" ${d.registered ? '' : 'disabled'}>${ICONS.refresh}<span class="hide-narrow">Запросить статус</span></button>
        ${d.can_reboot ? `<button class="btn sm ghost danger-text" data-cmd="5" data-id="${d.id}" ${d.registered ? '' : 'disabled'}>${ICONS.power}<span class="hide-narrow">Перезагрузить устройство</span></button>` : ''}
      </div></section>`;
  }

  function render() {
    const list = visible();
    // карта (map.js) рисует те же устройства, что и список: с учётом фильтров и поиска
    document.dispatchEvent(new CustomEvent('relay:devices', { detail: { list, selected } }));
    const sel = selected ? devices.find(d => d.id === selected) : null;
    $('#split').classList.toggle('has-sel', !!sel);
    // карта — когда устройство не выбрано и устройства есть
    $('#map-card').classList.toggle('hidden', !!sel || !devices.length || (selected && !sel));
    $('.side-all').classList.toggle('sel', !sel);
    $('#side-count').textContent = devices.length;
    $('#side').innerHTML = list.length ? list.map(sideHtml).join('')
      : `<div class="side-empty muted">${devices.length ? 'Нет устройств по фильтру' : 'Устройств пока нет'}</div>`;
    const panel = $('#panel');
    if (!devices.length) {
      panel.innerHTML = `<div class="card empty">${ICONS.server}<div><b>Устройств пока нет</b></div>
        <p>Нажмите «Добавить устройство» и укажите ID из меню настройки устройства.<br>После подключения оно появится здесь «на связи».</p>
        <button class="btn primary" data-open-add>Добавить устройство</button></div>`;
    } else if (selected && !sel) {
      panel.innerHTML = `<div class="card empty"><div><b>Устройство #${selected} не найдено</b></div><p><button class="btn" data-sel="0">Показать все устройства</button></p></div>`;
    } else if (sel) {
      panel.innerHTML = panelHtml(sel);
    } else {
      panel.innerHTML = ''; // без выбора справа карта (map.js)
    }
  }

  async function load() {
    const j = await api('/api/devices.php', { action: 'list' });
    devices = j.devices;
    for (const [k, v] of Object.entries(j.summary)) { const el = $(`[data-k="${k}"]`); if (el) el.textContent = v; }
    $('#lastmsg').innerHTML = j.last_message_ts
      ? `Последнее сообщение от устройств: <b data-ago="${j.last_message_ts}">${ago(j.last_message_ts)}</b> · ${fmtTime(j.last_message_ts)}`
      : 'Сообщений от устройств ещё не было';
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

  async function command(id, command, relay, btn) {
    const d = devices.find(x => x.id === id); if (!d) return;
    if (command === 5 && !confirm(`Перезагрузить устройство «${d.name}» (#${id})?\nУстройство будет недоступно 1–2 минуты.`)) return;
    const key = id + ':' + relay;
    if (relay) { busy.add(key); render(); } else if (btn) btn.disabled = true;
    const done = () => { busy.delete(key); load().catch(() => {}); };
    try {
      const lock4 = relay === 4 && d.relay4_lock;
      await sendCommand({ url: '/api/devices.php', params: { action: 'command', id, command, relay }, statusUrl: '/api/devices.php',
        statusParams: { action: 'command_status' }, label: `${d.name}: ${cmdLabel(command, relay, lock4)}`, onDone: done, quiet: lock4 });
    } catch (er) { busy.delete(key); render(); toast(er.message, 'err'); }
    finally { if (!relay && btn) btn.disabled = false; }
  }

  $('#split').addEventListener('click', async e => {
    const ack = e.target.closest('[data-ack]');
    if (ack) {
      ack.disabled = true;
      try { await api('/api/devices.php', { action: 'ack_break_in', id: ack.dataset.ack }, true); toast('Тревога сброшена', 'ok'); load().catch(() => {}); }
      catch (er) { ack.disabled = false; toast(er.message, 'err'); }
      return;
    }
    const s = e.target.closest('[data-sel]');
    if (s) { e.preventDefault(); return select(+s.dataset.sel); }
    const sw = e.target.closest('.switch');
    if (sw) {
      if (sw.disabled || sw.classList.contains('busy')) return;
      return command(+sw.dataset.id, sw.dataset.state === '1' ? 2 : 1, +sw.dataset.relay);
    }
    const rb = e.target.closest('[data-c][data-r]');
    if (rb && !rb.disabled) return command(+(rb.dataset.id || selected), +rb.dataset.c, +rb.dataset.r);
    const cb = e.target.closest('[data-cmd]');
    if (cb) return command(+cb.dataset.id, +cb.dataset.cmd, 0, cb);
  });

  /* ---------- Добавление платы ---------- */
  const addDlg = $('#add'), addForm = $('#add-form'), F = k => addForm.elements[k];
  // подписи полей калибровки по типу АКБ: 12 В — АЦП при 6 и 15 В, 24 В — при 19 и 26 В
  App.bindBatType(addForm);
  function openAdd(prefillId) {
    addForm.reset(); addForm.dispatchEvent(new Event('bat-type')); $('#add-result').textContent = ''; $('#add-result').className = 'result';
    if (prefillId) F('id').value = prefillId;
    addDlg.showModal(); (prefillId ? F('name') : F('id')).focus();
  }
  $('#b-add').addEventListener('click', () => openAdd());
  $('#panel').addEventListener('click', e => { if (e.target.closest('[data-open-add]')) openAdd(); });
  // /index.php?add=12 — открыть форму сразу (ссылка из монитора)
  const addParam = new URLSearchParams(location.search).get('add');
  if (addParam && /^[1-9]\d{0,9}$/.test(addParam)) { openAdd(addParam); history.replaceState(null, '', '/index.php'); }

  /* ---------- Массовое изменение устройств: названия реле, реле 4 — замок ---------- */
  const bulkDlg = $('#bulk'), bulkForm = $('#bulk-form');
  /** «1, 3, 5-10» → [1, 3, 5, …, 10] или строка ошибки (как relay_parse_id_list на сервере) */
  function parseIds(s) {
    const out = new Set();
    for (const part of s.replace(/\s*[-–—]\s*/g, '-').split(/[\s,;]+/).filter(Boolean)) {
      const m = /^(\d{1,10})(?:-(\d{1,10}))?$/.exec(part);
      if (!m) return `неверный номер «${part}»`;
      let a = +m[1], b = m[2] ? +m[2] : a;
      if (b < a) [a, b] = [b, a];
      if (b - a >= 500) return 'не больше 500 устройств за раз';
      for (let i = a; i <= b; i++) out.add(i);
    }
    return [...out].sort((x, y) => x - y);
  }
  function bulkPreview() {
    const box = $('#bulk-preview'), v = bulkForm.elements.ids.value.trim();
    if (!v) { box.textContent = ''; return; }
    const ids = parseIds(v);
    if (typeof ids === 'string') { box.innerHTML = `<span class="bad-text">${esc(ids)}</span>`; return; }
    const known = new Map(devices.map(d => [d.id, d]));
    const found = ids.filter(i => known.has(i)), missing = ids.filter(i => !known.has(i));
    box.innerHTML = `Будет изменено устройств: <b>${found.length}</b>`
      + (found.length ? ` <span class="muted">(${found.slice(0, 12).map(i => '#' + i + ' ' + esc(known.get(i).name)).join(', ')}${found.length > 12 ? ' …' : ''})</span>` : '')
      + (missing.length ? `<div class="muted">нет в панели: ${missing.slice(0, 20).join(', ')}${missing.length > 20 ? ' …' : ''}</div>` : '');
  }
  bulkForm.elements.ids.addEventListener('input', bulkPreview);
  $('#b-bulk').addEventListener('click', () => {
    bulkForm.reset(); $('#bulk-preview').textContent = ''; $('#bulk-result').className = 'result'; $('#bulk-result').textContent = '';
    bulkDlg.showModal(); bulkForm.elements.ids.focus();
  });
  bulkForm.addEventListener('submit', async e => {
    if (e.submitter && e.submitter.value === 'cancel') return;
    e.preventDefault();
    const p = { action: 'bulk_relays', ids: bulkForm.elements.ids.value };
    for (const i of [1, 2, 3, 4]) p['relay' + i] = bulkForm.elements['relay' + i].value.trim();
    p.relay4_lock = bulkForm.elements.relay4_lock.value;
    const res = $('#bulk-result'), btn = $('#bulk-submit');
    btn.disabled = true;
    try {
      const j = await api('/api/devices.php', p, true);
      res.className = 'result ok';
      res.textContent = `Изменено устройств: ${j.updated.length}` + (j.missing.length ? `. Нет в панели: ${j.missing.join(', ')}` : '');
      toast(`Изменено устройств: ${j.updated.length}`, 'ok');
      load().catch(() => {});
    } catch (er) { res.className = 'result err'; res.textContent = er.message; }
    finally { btn.disabled = false; }
  });

  /* ---------- Незарегистрированные платы ---------- */
  const isAdmin = document.body.classList.contains('role-admin');
  async function loadPending() {
    if (!isAdmin) return; // незарегистрированные устройства видит только администратор
    const list = (await api('/api/devices.php', { action: 'pending' })).pending;
    $('#pending').classList.toggle('hidden', !list.length);
    $('#pending-list').innerHTML = list.map(p => `<div class="pending-row">
      <div><b>Устройство #${p.id}</b> <span class="mono muted">${esc(p.client_id)}</span>
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
    for (const k of ['id', 'name', 'address', 'sim_phone', 'relay1', 'relay2', 'relay3', 'relay4', 'bat_type', 'cal_low', 'cal_high']) p[k] = F(k).value.trim();
    p.relay4_lock = F('relay4_lock').checked ? '1' : '0';
    for (const i of [1, 2, 3, 4]) p[`relay${i}_off`] = F(`relay${i}_off`).checked ? '1' : '0';
    const btn = $('#add-submit'), res = $('#add-result');
    btn.disabled = true; res.className = 'result wait'; res.textContent = 'Регистрирую устройство в брокере…';
    try {
      const j = await api('/api/devices.php', p, true);
      addDlg.close();
      $('#added-info').innerHTML = `<dt>ID устройства</dt><dd class="mono">${j.id}</dd>
        <dt>MQTT логин</dt><dd class="mono">${esc(j.mqtt.username)}</dd>
        <dt>MQTT пароль</dt><dd class="mono" style="word-break:break-all">${esc(j.mqtt.password)}</dd>
        <dt>Топик команд</dt><dd class="mono">${esc(j.mqtt.topic)}</dd>`;
      $('#added-open').href = '/device.php?id=' + j.id;
      $('#added').showModal();
      toast(`Устройство #${j.id} добавлено`, 'ok');
      load().catch(() => {}); loadPending().catch(() => {});
    } catch (er) { res.className = 'result err'; res.textContent = er.message; }
    finally { btn.disabled = false; }
  });
  $('#added').addEventListener('click', e => { if (e.target.closest('[data-close]')) $('#added').close(); });

  load().catch(e => { $('#panel').innerHTML = `<div class="card empty">${esc(e.message)}</div>`; });
  App.every(5000, load);
})();
