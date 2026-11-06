/* Страница платы */
'use strict';
(() => {
  const { $, $$, esc, api, ago, fmtTime, stateBadge, signalBars, ICONS, toast, sendCommand, cmdLabel, describe, RSTATE } = App;
  const id = +$('#head').dataset.id;
  let dev = null, kind = 'events', lastId = 0, paged = false, batHours = 24;
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
    $('#relays-note').textContent = dev.online ? '' : 'Устройство не на связи: команды выполнятся при подключении';
  }

  function render() {
    const d = dev;
    document.title = `${d.name} · Relay Control`;
    $('#d-name').textContent = d.name;
    $('#d-addr').textContent = d.address || 'Адрес не указан';
    $('#d-badge').innerHTML = stateBadge(d.state);
    renderRelays();

    // плата ver_5_1 (протокол v3): замок и геркон двери
    const hasLock = d.door_state != null;
    $('#lockcard').classList.toggle('hidden', !hasLock);
    if (hasLock) {
      $('#lockinfo').innerHTML = `<dt>Дверь</dt><dd>${d.door_state === 1 ? '<span class="badge warn">открыта</span>' : '<span class="badge success">закрыта</span>'}</dd>
        <dt>Замок</dt><dd>${d.lock_state === 1 ? '<span class="badge info">под напряжением</span>' : 'обесточен'}</dd>`;
    }

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
      : '<p class="muted">От устройства ещё не было данных. Убедитесь, что оно зарегистрировано: <code>sudo relay-device add ' + id + '</code></p>';

    const lc = d.last_command;
    $('#info').innerHTML = `
      <dt>ID устройства</dt><dd class="mono">${d.id}</dd>
      <dt>MQTT логин</dt><dd class="mono">relay-${d.id}</dd>
      <dt>Топик команд</dt><dd class="mono">com/${d.id}</dd>
      <dt>Связь</dt><dd>${stateBadge(d.state)}</dd>
      <dt>Сигнал GSM</dt><dd>${signalBars(d.signal_level)} <span class="muted">${d.signal_level ?? '—'}/31</span></dd>
      <dt>Питание</dt><dd>${d.alarm === 1 ? '<span class="badge alarm">от АКБ</span>' : d.registered ? 'основное' : '—'}</dd>
      <dt>АКБ</dt><dd>${App.batteryHtml(d) || '—'}</dd>
      <dt>Протокол</dt><dd>${d.version ? 'v' + d.version : '—'}</dd>
      <dt>Последняя команда</dt><dd>${lc ? esc(cmdLabel(+lc.command, +lc.relay_num)) + ' ' + App.cmdStatusBadge(lc.status) + `<div class="muted">${esc(lc.created_at)}</div>` : '—'}</dd>
      <dt>Координаты</dt><dd>${d.coordinates ? esc(d.coordinates) : '—'}</dd>
      <dt>Первое подключение</dt><dd>${d.created_at ? esc(d.created_at) : '—'}</dd>`;
  }

  /* ---------- АКБ ---------- */
  function chartSvg(points, calibrated) {
    const val = p => calibrated ? p[2] : p[1];
    const pts = points.filter(p => val(p) != null);
    if (pts.length < 2) return '<p class="muted" style="margin:10px 0 0">Для графика нужно хотя бы два пакета stat с данными АКБ.</p>';
    const W = 600, H = 120, P = 2;
    const t0 = pts[0][0], t1 = Math.max(pts[pts.length - 1][0], t0 + 1);
    let lo = Math.min(...pts.map(val)), hi = Math.max(...pts.map(val));
    if (calibrated) { lo = Math.min(lo, App.BAT_V_LOW); hi = Math.max(hi, App.BAT_V_HIGH); }
    if (hi - lo < 1e-6) { hi += 1; lo -= 1; }
    const x = t => P + (W - P - 4) * (t - t0) / (t1 - t0);
    const y = v => 6 + (H - 20) * (1 - (v - lo) / (hi - lo));
    const line = pts.map(p => x(p[0]).toFixed(1) + ',' + y(val(p)).toFixed(1)).join(' ');
    const area = `${x(t0).toFixed(1)},${y(lo).toFixed(1)} ${line} ${x(t1).toFixed(1)},${y(lo).toFixed(1)}`;
    const vs = pts.map(val);
    const f = v => calibrated ? v.toFixed(2) + ' В' : String(Math.round(v));
    const minV = Math.min(...vs), maxV = Math.max(...vs);
    // подписи — обычным текстом: SVG растягивается по ширине (preserveAspectRatio="none"), текст в нём исказился бы
    return `<div class="muted" style="font-size:12px;margin-top:12px">${calibrated ? 'Напряжение' : 'АЦП'}: мин ${f(minV)} · макс ${f(maxV)} · замеров ${pts.length}
        ${calibrated ? ` · шкала ${App.BAT_V_LOW}–${App.BAT_V_HIGH} В` : ''}</div>
      <svg class="chart" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="График напряжения АКБ">
      <line class="grid" x1="0" x2="${W}" y1="${y(hi)}" y2="${y(hi)}"/><line class="grid" x1="0" x2="${W}" y1="${y(lo)}" y2="${y(lo)}"/>
      <polygon class="area" points="${area}"/><polyline class="line" points="${line}"/></svg>
      <div class="bat-scale-labels"><span>${fmtTime(t0)}</span><span>${fmtTime(t1)}</span></div>`;
  }
  async function loadBattery() {
    if (!dev) return;
    const box = $('#battery');
    if (dev.bat_adc == null) {
      box.innerHTML = '<p class="muted" style="margin:0">Устройство не передаёт напряжение АКБ. Нужна прошивка v2.2 (протокол v2): в ней stat содержит значение АЦП АКБ.</p>';
      return;
    }
    let j = { points: [], calibrated: false };
    try { j = await api('/api/devices.php', { action: 'battery', id, hours: batHours }); } catch (e) {}
    const v = dev.bat_voltage;
    const pos = v == null ? 0 : Math.max(0, Math.min(100, (v - App.BAT_V_LOW) / (App.BAT_V_HIGH - App.BAT_V_LOW) * 100));
    const head = v != null
      ? `<div class="bat-big bat ${App.batClass(v)}">${v.toFixed(2)} В</div>
         <div class="bat-scale"><i style="left:${pos}%"></i></div>
         <div class="bat-scale-labels"><span>${App.BAT_V_LOW} В</span><span>${String((App.BAT_V_LOW + App.BAT_V_HIGH) / 2).replace('.', ',')} В</span><span>${App.BAT_V_HIGH} В</span></div>`
      : `<div class="bat-big bat raw">АЦП ${dev.bat_adc}</div>
         <p class="muted" style="margin:6px 0 0">Напряжение не рассчитано: задайте калибровку — «Изменить» → значения АЦП при 6 В и при 15 В.</p>`;
    const cal = dev.cal_adc_6 != null && dev.cal_adc_15 != null ? ` · калибровка: ${dev.cal_adc_6} → 6 В, ${dev.cal_adc_15} → 15 В` : '';
    box.innerHTML = head + `<div class="muted" style="font-size:12.5px;margin-top:8px">АЦП ${dev.bat_adc} · ${fmtTime(dev.bat_adc_ts)}${cal}</div>` + chartSvg(j.points, j.calibrated);
  }
  $('#bat-range').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    batHours = +b.dataset.h; $$('#bat-range button').forEach(x => x.classList.toggle('on', x === b)); loadBattery();
  });

  async function load() {
    try { dev = (await api('/api/devices.php', { action: 'get', id })).device; render(); loadBattery(); }
    catch (e) { $('#lastdata').innerHTML = `<p class="muted">${esc(e.message)}</p>`; }
  }

  /* ---------- Журналы ---------- */
  const EV_ICON = { created: 'plug', online: 'check', offline: 'x', relay: 'bolt', alarm: 'battery', alarm_clear: 'battery', command: 'send', reply: 'chat', reboot: 'power', info: 'info', door: 'alert', lock: 'plug' };
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
      else box.innerHTML = `<table class="tbl"><thead><tr><th>cmd_id</th><th>Создана</th><th>Команда</th><th>Статус</th><th>Ответ устройства</th></tr></thead><tbody>${html}</tbody></table>`;
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
    send(c, r);
  });
  $$('[data-all]').forEach(b => b.addEventListener('click', () => {
    const c = +b.dataset.all;
    send(c, 0);
  }));
  $('#b-status').addEventListener('click', () => send(4, 0));
  $$('[data-lock]').forEach(b => b.addEventListener('click', () => {
    const c = +b.dataset.lock;
    send(c, 5);
  }));
  $('#b-reboot').addEventListener('click', () => {
    if (confirm(`Перезагрузить устройство «${dev ? dev.name : id}»?\nУстройство перестанет отвечать на 1–2 минуты, затем подключится снова и восстановит состояние реле.`)) send(5, 0);
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
    form.elements.cal_adc_6.value = dev.cal_adc_6 ?? ''; form.elements.cal_adc_15.value = dev.cal_adc_15 ?? '';
    $('#cal-current').innerHTML = dev.bat_adc != null ? `Текущее значение АЦП: <b class="mono">${dev.bat_adc}</b> (${fmtTime(dev.bat_adc_ts)})` : 'Устройство ещё не передавало значение АЦП АКБ.';
    dev.relays.forEach(r => { form.elements['relay' + r.num].value = r.name; });
    dlg.showModal();
  });
  dlg.addEventListener('close', async () => {
    if (dlg.returnValue !== 'save') return;
    const p = { action: 'update', id };
    for (const k of ['name', 'address', 'coordinates', 'relay1', 'relay2', 'relay3', 'relay4', 'cal_adc_6', 'cal_adc_15']) p[k] = form.elements[k].value;
    try { await api('/api/devices.php', p, true); toast('Сохранено', 'ok'); load(); loadLog(true); } catch (e) { toast(e.message, 'err'); }
  });
  $('#edit').addEventListener('click', e => {
    const b = e.target.closest('[data-cal]'); if (!b) return;
    if (dev && dev.bat_adc != null) form.elements[b.dataset.cal].value = dev.bat_adc;
    else toast('Устройство ещё не передавало значение АЦП', 'err');
  });
  $('#b-delete').addEventListener('click', async () => {
    if (!confirm(`Удалить устройство #${id}?\nЕго доступ к брокеру будет закрыт, состояние и журнал событий — удалены.\nВернуть устройство можно кнопкой «Добавить устройство».`)) return;
    try { const j = await api('/api/devices.php', { action: 'delete', id }, true); alert('Устройство удалено.\n' + j.note); location.href = '/index.php'; }
    catch (e) { toast(e.message, 'err'); }
  });

  setInterval(() => $$('[data-ago]').forEach(el => { const t = +el.dataset.ago; if (t) el.textContent = ago(t); }), 1000);
  load(); loadLog(true);
  App.every(5000, load);
  App.every(10000, () => { if (kind === 'events' && !paged && !$('#f-from').value && !$('#f-to').value) return loadLog(true); });
})();
