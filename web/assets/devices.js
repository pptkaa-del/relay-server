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
      if (filter === 'alarm' && !(d.online && d.alarm === 1)) return false;
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
  const canSend = d => d.registered && d.state !== 'never';
  const metaHtml = d => {
    const seen = d.last_data_ts;
    return `<span class="item mono">#${d.id}</span>
      <span class="item" title="Последнее поступление данных: ${seen ? fmtTime(seen) : 'нет'}">${ICONS.clock}<span data-ago="${seen || ''}">${ago(seen)}</span></span>
      <span class="item">${signalBars(d.signal_level)}</span>
      ${App.batteryHtml(d) ? `<span class="item">${App.batteryHtml(d)}</span>` : ''}
      ${d.alarm === 1 && d.online ? `<span class="badge alarm">${ICONS.battery}питание от АКБ</span>` : ''}
      ${d.door_state === 1 ? '<span class="badge warn">дверь открыта</span>' : ''}`;
  };

  // список плат слева
  function sideHtml(d) {
    const on = d.relays.filter(r => r.state === 1).length;
    const pips = d.relays.map(r => `<i class="${r.state === 1 ? 'on' : r.state === 2 ? 'err' : r.state == null ? 'na' : ''}${r.lock ? ' lock' : ''}" title="${esc(r.name)}: ${relayStateText(r)}"></i>`).join('');
    return `<button class="side-item ${d.id === selected ? 'sel' : ''}" data-sel="${d.id}">
      <div class="side-item-top"><span class="dot ${d.state}"></span><span class="side-item-name">${esc(d.name)}</span><span class="mono muted">#${d.id}</span></div>
      <div class="side-item-meta side-dev-meta"><span class="pips">${pips}</span><span>вкл ${on}/${d.relays.length}</span>
        <span class="spacer"></span><span data-ago="${d.last_data_ts || ''}">${ago(d.last_data_ts)}</span></div></button>`;
  }

  // без выбора: все реле всех плат
  function overviewHtml(list) {
    const relays = list.reduce((n, d) => n + d.relays.length, 0);
    const on = list.reduce((n, d) => n + d.relays.filter(r => r.state === 1).length, 0);
    const rows = list.map(d => {
      const dis = !canSend(d);
      return `<tr class="ov-grp"><td colspan="4"><div class="ov-head">
          <button class="ov-dev" data-sel="${d.id}" title="Открыть устройство"><span class="dot ${d.state}"></span><b>${esc(d.name)}</b></button>
          ${stateBadge(d.state)}<span class="dev-meta ov-meta">${metaHtml(d)}</span></div></td></tr>`
        + d.relays.map(r => {
          const key = d.id + ':' + r.num, cls = r.state === 1 ? 'on' : r.state === 2 ? 'err' : '';
          const ctl = !r.control ? viewOnly
            : r.lock ? `<span class="btn-row nowrap"><button class="btn sm primary" data-id="${d.id}" data-c="3" data-r="4" ${dis || busy.has(key) ? 'disabled' : ''}>Открыть</button>
                <button class="btn sm" data-id="${d.id}" data-c="1" data-r="4" ${dis || busy.has(key) ? 'disabled' : ''}>Закрыть</button></span>`
            : `<button class="switch ${cls} ${busy.has(key) ? 'busy' : ''}" data-id="${d.id}" data-relay="${r.num}" data-state="${r.state ?? ''}" ${dis ? 'disabled' : ''}
              aria-label="${esc(r.name)}" title="${dis ? 'Устройство ещё не выходило на связь' : d.online ? (r.state === 1 ? 'Выключить' : 'Включить') : 'Устройство не на связи — команда выполнится при подключении'}"></button>`;
          return `<tr class="ov-relay"><td><span class="ov-rname" title="${esc(r.name)}">${esc(r.name)}</span> <span class="muted ov-rnum">${r.lock ? 'замок · ' : ''}реле ${r.num}</span></td>
            <td>${relayBadge(r.state, r.lock)}</td>
            <td class="ov-ts muted">${r.ts ? fmtTime(r.ts) : '—'}</td>
            <td class="ov-sw">${ctl}</td></tr>`;
        }).join('');
    }).join('');
    return `<section class="card">
      <div class="card-head"><h2>Все реле</h2><span class="muted">${list.length} ${plural(list.length, 'устройство', 'устройства', 'устройств')} · ${relays} реле · включено ${on}</span></div>
      <div class="table-wrap"><table class="tbl ov">
        <thead><tr><th>Реле</th><th>Состояние</th><th class="ov-ts">Изменено</th><th class="ov-sw">Вкл / выкл</th></tr></thead>
        <tbody>${rows}</tbody></table></div></section>`;
  }

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
        <button class="btn sm ghost only-narrow" data-sel="0">← Все устройства</button>
        <div class="dev-panel-title"><h2><a href="/device.php?id=${d.id}">${esc(d.name)}</a></h2><div class="muted">${esc(d.address || 'адрес не указан')}</div></div>
        ${stateBadge(d.state)}
      </div>
      <div class="card-body">
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
    const sel = selected ? devices.find(d => d.id === selected) : null;
    $('#split').classList.toggle('has-sel', !!sel);
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
      panel.innerHTML = list.length ? overviewHtml(list) : '<div class="card empty">Нет устройств по выбранному фильтру</div>';
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

  $('#split').addEventListener('click', e => {
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
    for (const k of ['id', 'name', 'address', 'relay1', 'relay2', 'relay3', 'relay4', 'bat_type', 'cal_low', 'cal_high']) p[k] = F(k).value.trim();
    p.relay4_lock = F('relay4_lock').checked ? '1' : '0';
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
