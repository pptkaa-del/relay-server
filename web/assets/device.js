/* Страница платы */
'use strict';
(() => {
  const { $, $$, esc, api, ago, fmtTime, stateBadge, signalBars, ICONS, toast, sendCommand, cmdLabel, describe, RSTATE } = App;
  const id = +$('#head').dataset.id;
  let dev = null, kind = 'events', lastId = 0, paged = false;
  const busy = new Set();

  async function send(command, relay) {
    const label = `${dev ? dev.name : '#' + id}: ${cmdLabel(command, relay)}`;
    const key = command + ':' + relay;
    if (busy.has(key)) return;
    busy.add(key); renderRelays();
    try {
      await sendCommand({ url: '/api/devices.php', params: { action: 'command', id, command, relay }, statusUrl: '/api/devices.php',
        statusParams: { action: 'command_status' }, label,
        onDone: () => { busy.delete(key); load(); if (kind !== 'messages') loadLog(true); } });
      if (kind === 'events' || kind === 'commands') loadLog(true);
    } catch (e) { busy.delete(key); renderRelays(); toast(e.message, 'err'); }
  }

  function renderRelays() {
    if (!dev) return;
    $('#relays').innerHTML = dev.relays.map(r => {
      const st = r.state, cls = st === 1 ? 'success' : st === 2 ? 'warning' : '';
      const b = n => busy.has(n + ':' + r.num) ? 'disabled' : '';
      return `<div class="relay-card">
        <div class="relay-card-top"><span class="relay-card-name" title="${esc(r.name)}">${esc(r.name)}</span>
          <span class="badge ${cls}">${st == null ? 'нет данных' : RSTATE[st]}</span></div>
        <div class="muted" style="font-size:12px">Реле ${r.num}${r.ts ? ' · изм. ' + fmtTime(r.ts) : ''}</div>
        <div class="btn-row">
          <button class="btn sm ok" data-c="1" data-r="${r.num}" ${b(1)}>Вкл</button>
          <button class="btn sm bad" data-c="2" data-r="${r.num}" ${b(2)}>Выкл</button>
          <button class="btn sm" data-c="3" data-r="${r.num}" ${b(3)} title="Выключить и через 1 с включить">${ICONS.restart}</button>
        </div></div>`;
    }).join('');
    $('#relays-note').textContent = dev.online ? '' : 'Плата не на связи: команды выполнятся при подключении';
  }

  function render() {
    const d = dev;
    document.title = `${d.name} · Relay Control`;
    $('#d-name').textContent = d.name;
    $('#d-addr').textContent = d.address || 'Адрес не указан';
    $('#d-badge').innerHTML = stateBadge(d.state);
    renderRelays();

    const m = d.last_message;
    const st = d.stats || {};
    $('#lastdata').innerHTML = m ? `
      <div class="lastdata"><span class="dot ${d.state} ${d.online ? 'pulse' : ''}" style="width:12px;height:12px"></span>
        <div><div class="lastdata-ago" data-ago="${m.t}">${ago(m.t)}</div><div class="muted">${fmtTime(m.t)}</div></div></div>
      <dl class="kv" style="margin-top:14px">
        <dt>Топик</dt><dd class="mono">${esc(m.topic)}${m.retained ? ' <span class="tag">retain</span>' : ''}</dd>
        <dt>Данные</dt><dd>${describe(m)}</dd>
        <dt>Последний stat</dt><dd>${d.last_seen_ts ? fmtTime(d.last_seen_ts) + ' · ' + ago(d.last_seen_ts) : '—'}</dd>
        <dt>Сообщений</dt><dd>за час ${+st.hour || 0} · за сутки ${+st.day || 0} · всего ${d.msg_count ?? 0}</dd>
      </dl>
      <details class="box" style="margin:12px -16px -16px"><summary>Сырые данные (HEX)</summary><div><code class="mono" style="word-break:break-all">${esc((m.payload_hex || '').replace(/(.{8})/g, '$1 '))}</code></div></details>`
      : '<p class="muted">От платы ещё не было данных. Убедитесь, что она зарегистрирована: <code>sudo relay-device add ' + id + '</code></p>';

    const lc = d.last_command;
    $('#info').innerHTML = `
      <dt>ID платы</dt><dd class="mono">${d.id}</dd>
      <dt>MQTT логин</dt><dd class="mono">relay-${d.id}</dd>
      <dt>Топик команд</dt><dd class="mono">com/${d.id}</dd>
      <dt>Связь</dt><dd>${stateBadge(d.state)}</dd>
      <dt>Сигнал GSM</dt><dd>${signalBars(d.signal_level)} <span class="muted">${d.signal_level ?? '—'}/31</span></dd>
      <dt>Питание</dt><dd>${d.alarm === 1 ? '<span class="badge alarm">от АКБ</span>' : d.registered ? 'основное' : '—'}</dd>
      <dt>Протокол</dt><dd>${d.version ? 'v' + d.version : '—'}</dd>
      <dt>Последняя команда</dt><dd>${lc ? esc(cmdLabel(+lc.command, +lc.relay_num)) + ' ' + App.cmdStatusBadge(lc.status) + `<div class="muted">${esc(lc.created_at)}</div>` : '—'}</dd>
      <dt>Координаты</dt><dd>${d.coordinates ? esc(d.coordinates) : '—'}</dd>
      <dt>Первое подключение</dt><dd>${d.created_at ? esc(d.created_at) : '—'}</dd>`;
  }

  async function load() {
    try { dev = (await api('/api/devices.php', { action: 'get', id })).device; render(); }
    catch (e) { $('#lastdata').innerHTML = `<p class="muted">${esc(e.message)}</p>`; }
  }

  /* ---------- Журналы ---------- */
  const EV_ICON = { created: 'plug', online: 'check', offline: 'x', relay: 'bolt', alarm: 'battery', alarm_clear: 'battery', command: 'send', reply: 'chat', reboot: 'power', info: 'info' };
  function logParams() {
    const p = { id, kind };
    if ($('#f-from').value) p.from = $('#f-from').value;
    if ($('#f-to').value) p.to = $('#f-to').value;
    return p;
  }
  function rowsHtml(rows) {
    if (kind === 'events') return rows.map(r => `<li><span class="ico ${r.level}">${ICONS[EV_ICON[r.type]] || ICONS.info}</span>
        <div>${esc(r.message)}${r.user_login ? ` <span class="who">· ${esc(r.user_login)}</span>` : ''}</div><span class="when">${esc(r.ts.slice(0, 19))}</span></li>`).join('');
    if (kind === 'messages') return rows.map(r => `<tr><td data-c="time" class="mono nowrap">${esc(r.ts.slice(5, 23))}</td>
        <td data-c="dir"><span class="dir ${r.direction}">${ICONS[r.direction]}${r.direction === 'in' ? 'вход' : 'выход'}</span></td>
        <td data-c="topic" class="mono">${esc(r.topic)}${r.retained ? ' <span class="tag">retain</span>' : ''}</td>
        <td data-c="data">${describe(r)}${r.user_login ? ` <span class="tag">${esc(r.user_login)}</span>` : ''}<div class="hex">${esc(r.payload_hex.replace(/(.{8})/g, '$1 '))}</div></td></tr>`).join('');
    return rows.map(r => `<tr><td class="mono">${r.id}</td><td class="nowrap">${esc(r.ts)}</td><td>${esc(r.label)}</td>
        <td>${App.cmdStatusBadge(r.status)}</td>
        <td class="nowrap muted">${esc(r.confirmed_at || '—')}</td></tr>`).join('');
  }
  async function loadLog(reset) {
    if (reset) { lastId = 0; paged = false; } else paged = true;
    const p = logParams(); if (lastId) p.before = lastId;
    $('#f-csv').href = '/api/device_log.php?' + new URLSearchParams({ ...logParams(), format: 'csv' });
    $('#hexwrap').classList.toggle('hidden', kind !== 'messages');
    let j;
    try { j = await api('/api/device_log.php', p); } catch (e) { $('#log').innerHTML = `<div class="empty">${esc(e.message)}</div>`; return; }
    const html = rowsHtml(j.rows);
    if (j.rows.length) lastId = j.rows[j.rows.length - 1].id;
    const box = $('#log');
    if (reset) {
      if (!j.rows.length) box.innerHTML = '<div class="empty">Записей нет</div>';
      else if (kind === 'events') box.innerHTML = `<ul class="timeline">${html}</ul>`;
      else if (kind === 'messages') box.innerHTML = `<table class="tbl cards"><thead><tr><th>Время</th><th></th><th>Топик</th><th>Данные</th></tr></thead><tbody>${html}</tbody></table>`;
      else box.innerHTML = `<table class="tbl"><thead><tr><th>cmd_id</th><th>Создана</th><th>Команда</th><th>Статус</th><th>Ответ платы</th></tr></thead><tbody>${html}</tbody></table>`;
    } else {
      (box.querySelector('tbody, ul') || box).insertAdjacentHTML('beforeend', html);
    }
    box.classList.toggle('show-hex', $('#f-hex').checked);
    $('#more').classList.toggle('hidden', !j.more);
  }

  /* ---------- События интерфейса ---------- */
  $('#relays').addEventListener('click', e => {
    const b = e.target.closest('[data-c]'); if (!b) return;
    const c = +b.dataset.c, r = +b.dataset.r;
    if (c === 3 && !confirm(`Перезапустить «${dev.relays[r - 1].name}»? Реле выключится и через 1 с включится.`)) return;
    send(c, r);
  });
  $$('[data-all]').forEach(b => b.addEventListener('click', () => {
    const c = +b.dataset.all;
    if (!confirm(`${cmdLabel(c, 0)} на плате «${dev.name}»?`)) return;
    send(c, 0);
  }));
  $('#b-status').addEventListener('click', () => send(4, 0));
  $('#b-reboot').addEventListener('click', () => {
    if (confirm(`Перезагрузить плату «${dev ? dev.name : id}»?\nПлата перестанет отвечать на 1–2 минуты, затем подключится снова и восстановит состояние реле.`)) send(5, 0);
  });
  $('#tabs').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    kind = b.dataset.k; $$('#tabs button').forEach(x => x.classList.toggle('on', x === b)); loadLog(true);
  });
  $('#f-apply').addEventListener('click', () => loadLog(true));
  $('#more button').addEventListener('click', () => loadLog(false));
  $('#f-hex').addEventListener('change', e => $('#log').classList.toggle('show-hex', e.target.checked));

  const dlg = $('#edit'), form = $('#edit-form');
  $('#b-edit').addEventListener('click', () => {
    if (!dev) return;
    form.elements.name.value = dev.name; form.elements.address.value = dev.address || ''; form.elements.coordinates.value = dev.coordinates || '';
    dev.relays.forEach(r => { form.elements['relay' + r.num].value = r.name; });
    dlg.showModal();
  });
  dlg.addEventListener('close', async () => {
    if (dlg.returnValue !== 'save') return;
    const p = { action: 'update', id };
    for (const k of ['name', 'address', 'coordinates', 'relay1', 'relay2', 'relay3', 'relay4']) p[k] = form.elements[k].value;
    try { await api('/api/devices.php', p, true); toast('Сохранено', 'ok'); load(); loadLog(true); } catch (e) { toast(e.message, 'err'); }
  });
  $('#b-delete').addEventListener('click', async () => {
    if (!confirm(`Удалить плату #${id}?\nЕё доступ к брокеру будет закрыт, состояние и журнал событий — удалены.\nВернуть плату можно кнопкой «Добавить плату».`)) return;
    try { const j = await api('/api/devices.php', { action: 'delete', id }, true); alert('Плата удалена.\n' + j.note); location.href = '/index.php'; }
    catch (e) { toast(e.message, 'err'); }
  });

  setInterval(() => $$('[data-ago]').forEach(el => { const t = +el.dataset.ago; if (t) el.textContent = ago(t); }), 1000);
  load(); loadLog(true);
  App.every(5000, load);
  App.every(10000, () => { if (kind === 'events' && !paged && !$('#f-from').value && !$('#f-to').value) return loadLog(true); });
})();
