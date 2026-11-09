/* Страница платы */
'use strict';
(() => {
  const { $, $$, esc, api, ago, fmtTime, stateBadge, signalBars, ICONS, toast, sendCommand, cmdLabel, describe, RSTATE } = App;
  const id = +$('#head').dataset.id;
  let dev = null, kind = 'events', lastId = 0, paged = false, batHours = 24;
  const busy = new Set();

  async function send(command, relay) {
    const lock4 = relay === 4 && !!(dev && dev.relay4_lock); // замок: результат команды не показываем
    const mode4 = relay === 4 && dev && dev.relay4_wash ? 'wash' : lock4;
    const label = `${dev ? dev.name : '#' + id}: ${cmdLabel(command, relay, mode4)}`;
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
        : r.wash ? `<div class="btn-row lock-btns">
          <button class="btn sm primary" data-c="3" data-r="4" ${b(3)} title="Запустить омывание (перезапуск реле 4)">Запуск</button></div>`
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
        <div class="muted" style="font-size:12px">${r.lock ? 'Замок · реле 4' : r.wash ? 'Омывание · реле 4' : 'Реле ' + r.num}${r.ts ? ' · изм. ' + fmtTime(r.ts) : ''}</div>
        ${buttons}</div>`;
    }).join('');
    $('#relays-note').textContent = dev.online ? '' : 'Устройство не на связи: команды выполнятся при подключении';
  }

  function render() {
    const d = dev;
    document.title = `${d.name} · off-on service`;
    $('#d-name').textContent = d.name;
    // переход к соседним устройствам
    for (const [el, nid] of [['#dev-prev', d.prev_id], ['#dev-next', d.next_id]]) {
      const a = $(el);
      a.classList.toggle('hidden', !nid);
      if (nid) { a.href = '/device.php?id=' + nid; a.textContent = el === '#dev-prev' ? `← Предыдущее (#${nid})` : `Следующее (#${nid}) →`; }
    }
    $('#dev-pos').textContent = d.position ? `${d.position[0]} из ${d.position[1]}` : '';
    $('#d-addr').textContent = (d.address || 'Адрес не указан') + (d.owner ? ' · ' + d.owner : '');
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
    // версия 1 не умеет команду перезагрузки — вместо неё «Не принимать сообщения»
    const v1 = d.dev_version === 1 || d.dev_version === 11; // 1 и 1-1: нет команды перезагрузки
    $('#b-reboot').classList.toggle('hidden', !d.can_reboot || v1);
    $('#b-mqtt-block').classList.toggle('hidden', !d.can_reboot || !v1 || !!d.mqtt_block);
    $('#mqtt-block').innerHTML = App.mqttBlockBanner(d);
    $$('[data-all]').forEach(b => b.classList.toggle('hidden', !d.can_all));
    $('#tabs [data-k="messages"]').classList.toggle('hidden', !d.can_edit);
    $('.o-bat').classList.toggle('hidden', !d.bat_on); // контроль АКБ выключен или версия 1
    renderRelays();

    // плата ver_5_1 (протокол v3): замок и геркон двери
    const hasLock = !!d.has_lock && !!d.lock_access; // плата ver_5_1: 4 реле и отдельный замок
    $('#lockcard').classList.toggle('hidden', !hasLock);
    $$('[data-lock]').forEach(b => b.classList.toggle('hidden', d.lock_access !== 'control'));
    if (hasLock) {
      $('#lockinfo').innerHTML = `<dt>Дверь</dt><dd>${d.door_state == null ? '<span class="muted">нет данных</span>' : d.door_state === 1 ? '<span class="badge warn">открыта</span>' : '<span class="badge success">закрыта</span>'}</dd>
        <dt>Замок</dt><dd>${d.lock_state == null ? '<span class="muted">нет данных</span>' : d.lock_state === 1 ? '<span class="badge info">под напряжением</span>' : 'обесточен'}</dd>`;
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
      <dt>Для кого установлено</dt><dd>${d.owner ? esc(d.owner) : '<span class="muted">не указано</span>'}</dd>
      <dt>SIM-карта</dt><dd>${d.sim_phone ? `<a class="mono" href="tel:${esc(d.sim_phone)}">${App.fmtPhone(d.sim_phone)}</a>` : '<span class="muted">не указан</span>'}</dd>
      <dt>MQTT логин</dt><dd class="mono">relay-${d.id}</dd>
      <dt>Топик команд</dt><dd class="mono">com/${d.id}</dd>
      <dt>Связь</dt><dd>${stateBadge(d.state)}</dd>
      <dt>Сигнал GSM</dt><dd>${signalBars(d.signal_level)} <span class="muted">${d.signal_level ?? '—'}/31</span></dd>
      <dt>АКБ</dt><dd>${d.bat_on ? (App.batteryHtml(d) || '—') : '<span class="muted">контроль выключен</span>'}</dd>
      <dt>Версия</dt><dd>${d.dev_version ? App.verLabel(d.dev_version) + (d.hw_version ? ' (задана в настройках)' : ' (по данным устройства)') : '—'}${d.version && d.hw_version && d.version !== d.hw_version ? ` <span class="muted">· устройство сообщает v${d.version}</span>` : ''}</dd>
      <dt>Последняя команда</dt><dd>${lc ? esc(cmdLabel(+lc.command, +lc.relay_num, d.relay4_wash ? 'wash' : d.relay4_lock)) + ' ' + (+lc.relay_num === 4 && d.relay4_lock ? '' : App.cmdStatusBadge(lc.status)) + `<div class="muted">${esc(lc.created_at)}</div>` : '—'}</dd>
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
    if (kind === 'relays') return rows.map(r => `<tr><td class="mono nowrap">${esc(r.ts.slice(0, 19))}</td>
        <td>${esc(r.name)} <span class="muted">(${r.lock ? 'замок, ' : ''}реле ${r.relay})</span></td>
        <td class="muted">${esc(r.from)}</td>
        <td><span class="badge ${r.lock ? (r.to_state === 1 ? 'success' : 'warning') : r.to_state === 1 ? 'success' : r.to_state === 2 ? 'warning' : ''}">${esc(r.to)}</span></td></tr>`).join('');
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
    p.limit = App.logLimit(); // последние 20 (или выбранное «Показывать»), «Показать ещё» — следующие столько же
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
      else if (kind === 'relays') box.innerHTML = `<table class="tbl relays-tbl"><thead><tr><th>Время</th><th>Реле</th><th>Было</th><th>Стало</th></tr></thead><tbody>${html}</tbody></table>`;
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
  $('#b-mqtt-block').addEventListener('click', () => { if (dev) App.mqttBlockDialog(dev, () => { load(); loadLog(true); }); });
  $('#mqtt-block').addEventListener('click', e => {
    const b = e.target.closest('[data-mqtt-unblock]'); if (!b || !dev) return;
    b.disabled = true; App.mqttUnblock(dev, () => { load(); loadLog(true); });
  });
  $('#b-reboot').addEventListener('click', () => {
    if (confirm(`Перезагрузить устройство «${dev ? dev.name : id}»?\nУстройство перестанет отвечать на 1–2 минуты, затем подключится снова и восстановит состояние реле.`)) send(5, 0);
  });
  $('#tabs').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    kind = b.dataset.k; $$('#tabs button').forEach(x => x.classList.toggle('on', x === b)); loadLog(true);
  });
  $('#f-apply').addEventListener('click', () => loadLog(true));
  App.bindLogLimit(() => loadLog(true));
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
  App.bindHwVersion(form);
  form.elements.bat_type.addEventListener('change', () => { if (dev) fillCal(); });
  $('#b-edit').addEventListener('click', () => {
    if (!dev) return;
    form.elements.name.value = dev.name; form.elements.address.value = dev.address || ''; form.elements.coordinates.value = dev.coordinates || '';
    form.elements.sim_phone.value = dev.sim_phone || '';
    form.elements.owner.value = dev.owner || '';
    form.elements.hw_version.value = dev.hw_version ? String(dev.hw_version) : '';
    form.dataset.reportedVersion = dev.version || '';
    form.elements.lock_unused.checked = !!dev.lock_unused;
    form.elements.bat_monitor.checked = dev.bat_monitor !== false;
    form.dispatchEvent(new Event('hw-version'));
    form.elements.bat_type.value = String(dev.bat_type || 12);
    form.elements.relay4_mode.value = dev.relay4_wash ? 'wash' : dev.relay4_lock ? 'lock' : 'normal';
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
    for (const k of ['name', 'address', 'coordinates', 'sim_phone', 'owner', 'relay1', 'relay2', 'relay3', 'relay4', 'bat_type', 'cal_low', 'cal_high', 'hw_version']) p[k] = form.elements[k].value;
    p.bat_monitor = form.elements.bat_monitor.checked && form.elements.hw_version.value !== '1' ? '1' : '0';
    p.lock_unused = form.elements.lock_unused.checked ? '1' : '0';
    p.relay4_mode = form.elements.relay4_mode.value;
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

  /* ---------- Комментарии ---------- */
  async function loadComments() {
    let j;
    try { j = await api('/api/comments.php', { action: 'list', id }); } catch (e) { $('#comments').innerHTML = `<li class="muted">${esc(e.message)}</li>`; return; }
    const list = j.comments;
    $('#comments-count').textContent = list.length ? String(list.length) : '';
    $('#comments').innerHTML = list.length ? list.map(c => `<li>
        <div class="comment-head"><b>${esc(c.login || '—')}</b><span>${fmtTime(c.ts)}</span><span class="spacer"></span>
          ${c.can_delete ? `<button class="comment-del" data-del-comment="${c.id}" title="Удалить комментарий">удалить</button>` : ''}</div>
        <div class="comment-text">${esc(c.text)}</div></li>`).join('')
      : '<li class="muted" style="background:none;border:0;padding:0">Комментариев пока нет</li>';
  }
  $('#comment-form').addEventListener('submit', async e => {
    e.preventDefault();
    const ta = e.target.elements.text, btn = e.target.querySelector('[type=submit]');
    if (!ta.value.trim()) return;
    btn.disabled = true;
    try { await api('/api/comments.php', { action: 'add', id, text: ta.value }, true); ta.value = ''; loadComments(); }
    catch (er) { toast(er.message, 'err'); }
    finally { btn.disabled = false; }
  });
  $('#comment-form').elements.text.addEventListener('keydown', e => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) $('#comment-form').requestSubmit();
  });
  $('#comments').addEventListener('click', async e => {
    const b = e.target.closest('[data-del-comment]'); if (!b) return;
    if (!confirm('Удалить комментарий?')) return;
    try { await api('/api/comments.php', { action: 'delete', comment_id: b.dataset.delComment }, true); loadComments(); }
    catch (er) { toast(er.message, 'err'); }
  });
  loadComments();

  setInterval(() => $$('[data-ago]').forEach(el => { const t = +el.dataset.ago; if (t) el.textContent = ago(t); }), 1000);
  // Alt+← / Alt+→ — предыдущее / следующее устройство (не в полях ввода)
  document.addEventListener('keydown', e => {
    if (!e.altKey || !dev || e.target.closest('input, textarea, select')) return;
    const nid = e.key === 'ArrowLeft' ? dev.prev_id : e.key === 'ArrowRight' ? dev.next_id : null;
    if (nid) { e.preventDefault(); location.href = '/device.php?id=' + nid; }
  });
  load(); loadLog(true);
  App.every(5000, load);
  App.every(10000, () => { if ((kind === 'events' || kind === 'relays') && !paged && !$('#f-from').value && !$('#f-to').value) return loadLog(true); });
})();
