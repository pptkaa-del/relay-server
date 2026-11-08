/* Страница платы */
'use strict';
(() => {
  const { $, $$, esc, api, ago, fmtTime, stateBadge, signalBars, ICONS, toast, sendCommand, cmdLabel, describe, RSTATE } = App;
  const id = +$('#head').dataset.id;
  let dev = null, kind = 'events', lastId = 0, paged = false, batHours = 24;
  const busy = new Set();

  async function send(command, relay) {
    const lock4 = relay === 4 && !!(dev && dev.relay4_lock);
    const label = `${dev ? dev.name : '#' + id}: ${cmdLabel(command, relay, lock4)}`;
    const key = command + ':' + relay;
    if (busy.has(key)) return;
    busy.add(key); renderRelays();
    try {
      await sendCommand({ url: '/api/devices.php', params: { action: 'command', id, command, relay }, statusUrl: '/api/devices.php',
        statusParams: { action: 'command_status' }, label, quiet: lock4,
        onDone: () => { busy.delete(key); load(); if (kind !== 'messages') loadLog(true); } });
      if (kind === 'events' || kind === 'commands') loadLog(true);
    } catch (e) { busy.delete(key); renderRelays(); toast(e.message, 'err'); }
  }

  function renderRelays() {
    if (!dev) return;
    $('#relays').innerHTML = dev.relays.map(r => {
      const st = r.state;
      // реле 4 — замок: включено — дверь закрыта, выключено — открыта
      const badge = st == null ? '<span class="badge">нет данных</span>'
        : r.lock ? `<span class="badge ${st === 1 ? 'success' : 'warning'}">${App.LOCK4_STATE[st]}</span>`
        : `<span class="badge ${st === 1 ? 'success' : st === 2 ? 'warning' : ''}">${RSTATE[st]}</span>`;
      const b = n => busy.has(n + ':' + r.num) ? 'disabled' : '';
      const buttons = !r.control ? '<div class="ro-box"><span class="muted ro-note">только просмотр</span></div>'
        : r.lock ? `<div class="btn-row lock-btns">
          <button class="btn sm primary" data-c="3" data-r="4" ${b(3)} title="Кратковременно открыть замок">Открыть</button>
          <button class="btn sm ok" data-c="1" data-r="4" ${b(1)}>Закрыть</button>
          <button class="btn sm" data-c="2" data-r="4" ${b(2)} title="Оставить дверь открытой до команды «Закрыть»">Открыть надолго</button></div>`
        : `<div class="btn-row">
          <button class="btn sm ok" data-c="1" data-r="${r.num}" ${b(1)}>Вкл</button>
          <button class="btn sm bad" data-c="2" data-r="${r.num}" ${b(2)}>Выкл</button>
          <button class="btn sm" data-c="3" data-r="${r.num}" ${b(3)} title="Выключить и через 1 с включить">${ICONS.restart}</button></div>`;
      return `<div class="relay-card${r.lock ? ' is-lock' : ''}">
        <div class="relay-card-top"><span class="relay-card-name" title="${esc(r.name)}">${esc(r.name)}</span>${badge}</div>
        <div class="muted" style="font-size:12px">${r.lock ? 'Замок · реле 4' : 'Реле ' + r.num}${r.ts ? ' · изм. ' + fmtTime(r.ts) : ''}</div>
        ${buttons}</div>`;
    }).join('');
    $('#relays-note').textContent = dev.online ? '' : 'Устройство не на связи: команды выполнятся при подключении';
  }

  function render() {
    const d = dev;
    document.title = `${d.name} · Relay Control`;
    $('#d-name').textContent = d.name;
    $('#d-addr').textContent = d.address || 'Адрес не указан';
    $('#d-badge').innerHTML = stateBadge(d.state) + (d.break_in ? ' <span class="badge breakin">ВЗЛОМ ДВЕРИ</span>' : '');
    $('#breakin').innerHTML = d.break_in ? `<div class="breakin-banner"><b>ВЗЛОМ ДВЕРИ</b>
      <span>Дверь открылась без команды «Открыть» ${d.break_in.source === 'door' ? '(датчик двери)' : '(замок, реле 4)'} · ${fmtTime(d.break_in.ts)} · ${ago(d.break_in.ts)}</span>
      <span class="spacer"></span>${d.can_ack ? '<button class="btn sm" id="b-ack">Сбросить тревогу</button>' : ''}</div>` : '';
    const ack = $('#b-ack');
    if (ack) ack.addEventListener('click', async () => {
      ack.disabled = true;
      try { await api('/api/devices.php', { action: 'ack_break_in', id }, true); toast('Тревога сброшена', 'ok'); load(); loadLog(true); }
      catch (e) { ack.disabled = false; toast(e.message, 'err'); }
    });
    // кнопки по правам: изменение — только администратор, перезагрузка — при управлении хотя бы одним реле
    $('#b-edit').classList.toggle('hidden', !d.can_edit);
    $('#b-reboot').classList.toggle('hidden', !d.can_reboot);
    $$('[data-all]').forEach(b => b.classList.toggle('hidden', !d.can_all));
    $('#tabs [data-k="messages"]').classList.toggle('hidden', !d.can_edit);
    renderRelays();

    // плата ver_5_1 (протокол v3): замок и геркон двери
    const hasLock = d.door_state != null && !!d.lock_access;
    $('#lockcard').classList.toggle('hidden', !hasLock);
    $$('[data-lock]').forEach(b => b.classList.toggle('hidden', d.lock_access !== 'control'));
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
      <dt>АКБ</dt><dd>${App.batteryHtml(d) || '—'}</dd>
      <dt>Протокол</dt><dd>${d.version ? 'v' + d.version : '—'}</dd>
      <dt>Последняя команда</dt><dd>${lc ? esc(cmdLabel(+lc.command, +lc.relay_num, d.relay4_lock)) + ' ' + (+lc.relay_num === 4 && d.relay4_lock ? '' : App.cmdStatusBadge(lc.status)) + `<div class="muted">${esc(lc.created_at)}</div>` : '—'}</dd>
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
    const bt = App.batType(dev);
    if (calibrated) { lo = Math.min(lo, bt.low); hi = Math.max(hi, bt.high); }
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
        ${calibrated ? ` · шкала ${bt.low}–${bt.high} В` : ''}</div>
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
    const v = dev.bat_voltage, bt = App.batType(dev);
    const pos = v == null ? 0 : Math.max(0, Math.min(100, (v - bt.low) / (bt.high - bt.low) * 100));
    const head = v != null
      ? `<div class="bat-big bat ${App.batClass(v, dev)}">${v.toFixed(2)} В <span class="muted bat-type">АКБ ${dev.bat_type} В</span></div>
         <div class="bat-scale"><i style="left:${pos}%"></i></div>
         <div class="bat-scale-labels"><span>${bt.low} В</span><span>${String((bt.low + bt.high) / 2).replace('.', ',')} В</span><span>${bt.high} В</span></div>`
      : `<div class="bat-big bat raw">АЦП ${dev.bat_adc} <span class="muted bat-type">АКБ ${dev.bat_type} В</span></div>
         <p class="muted" style="margin:6px 0 0">Напряжение не рассчитано: ${dev.can_edit ? `задайте калибровку — «Изменить» → значения АЦП при ${bt.low} В и при ${bt.high} В` : 'калибровку задаёт администратор'}.</p>`;
    const cal = dev.cal_low != null && dev.cal_high != null ? ` · калибровка: ${dev.cal_low} → ${bt.low} В, ${dev.cal_high} → ${bt.high} В` : '';
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
  // у каждого типа АКБ своя калибровка: 12 В — АЦП при 6/15 В, 24 В — при 19/26 В
  function fillCal() {
    const t24 = form.elements.bat_type.value === '24';
    form.elements.cal_low.value = (t24 ? dev.cal_adc_19 : dev.cal_adc_6) ?? '';
    form.elements.cal_high.value = (t24 ? dev.cal_adc_26 : dev.cal_adc_15) ?? '';
  }
  App.bindBatType(form);
  form.elements.bat_type.addEventListener('change', () => { if (dev) fillCal(); });
  $('#b-edit').addEventListener('click', () => {
    if (!dev) return;
    form.elements.name.value = dev.name; form.elements.address.value = dev.address || ''; form.elements.coordinates.value = dev.coordinates || '';
    form.elements.bat_type.value = String(dev.bat_type || 12);
    form.elements.relay4_lock.checked = !!dev.relay4_lock;
    fillCal(); form.dispatchEvent(new Event('bat-type'));
    $('#cal-current').innerHTML = dev.bat_adc != null ? `Текущее значение АЦП: <b class="mono">${dev.bat_adc}</b> (${fmtTime(dev.bat_adc_ts)})` : 'Устройство ещё не передавало значение АЦП АКБ.';
    // подписи всех четырёх реле, включая отключённые (их нет в dev.relays)
    for (const i of [1, 2, 3, 4]) {
      form.elements['relay' + i].value = (dev.relay_names || {})[i] || 'Реле ' + i;
      form.elements[`relay${i}_off`].checked = (dev.relays_disabled || []).includes(i);
    }
    dlg.showModal();
  });
  dlg.addEventListener('close', async () => {
    if (dlg.returnValue !== 'save') return;
    const p = { action: 'update', id };
    for (const k of ['name', 'address', 'coordinates', 'relay1', 'relay2', 'relay3', 'relay4', 'bat_type', 'cal_low', 'cal_high']) p[k] = form.elements[k].value;
    p.relay4_lock = form.elements.relay4_lock.checked ? '1' : '0';
    for (const i of [1, 2, 3, 4]) p[`relay${i}_off`] = form.elements[`relay${i}_off`].checked ? '1' : '0';
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
