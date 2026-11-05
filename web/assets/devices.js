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
        ${d.alarm === 1 && d.online ? `<span class="badge alarm">${ICONS.battery}АКБ</span>` : ''}
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
        <p>Зарегистрируйте плату на сервере командой <code>sudo relay-device add &lt;ID&gt; "Название"</code>.<br>После подключения она появится здесь автоматически.</p></div>`;
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

  load().catch(e => { $('#grid').innerHTML = `<div class="card empty" style="grid-column:1/-1">${esc(e.message)}</div>`; });
  App.every(5000, load);
})();
