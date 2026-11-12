/* Вкладка «Сервер»: загрузка процессора, память, диск, сеть, службы, брокер, база данных */
'use strict';
(() => {
  const { $, $$, esc, api, fmtTime, ago } = App;
  let range = '24h', hist = null;
  try { range = localStorage.getItem('relay-srv-range') || '24h'; } catch (e) {}
  $$('#range button').forEach(b => b.classList.toggle('on', b.dataset.r === range));

  /* ---------- форматирование ---------- */
  const bytes = (v, d = 1) => {
    if (v == null || isNaN(v)) return '—';
    const u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ']; let i = 0; v = +v;
    while (Math.abs(v) >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return (i === 0 ? Math.round(v) : v.toFixed(v >= 100 ? 0 : d)).toString().replace('.', ',') + ' ' + u[i];
  };
  const rate = v => v == null ? '—' : bytes(v) + '/с';
  const pct = (a, b) => b > 0 ? 100 * a / b : 0;
  const num = (v, d = 0) => v == null || isNaN(v) ? '—' : (+v).toFixed(d).replace('.', ',');
  const dur = s => {
    if (s == null) return '—';
    const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
    return d ? `${d} дн ${h} ч` : h ? `${h} ч ${m} мин` : `${m} мин`;
  };
  const level = (p, warn = 80, bad = 92) => p >= bad ? 'bad' : p >= warn ? 'warn' : 'ok';
  const bar = (p, cls) => `<div class="meter"><i class="${cls}" style="width:${Math.min(100, Math.max(0, p)).toFixed(1)}%"></i></div>`;

  /* ---------- линейный график (SVG) с подсказкой при наведении ---------- */
  const tfmt = (t, span) => {
    const d = new Date(t * 1000), p = n => String(n).padStart(2, '0');
    return span > 86400 * 1.5 ? `${p(d.getDate())}.${p(d.getMonth() + 1)}` + (span <= 7 * 86400 ? ` ${p(d.getHours())}:00` : '') : `${p(d.getHours())}:${p(d.getMinutes())}`;
  };
  function niceMax(v) {
    if (!(v > 0)) return 1;
    const e = Math.pow(10, Math.floor(Math.log10(v))), f = v / e;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * e;
  }
  /**
   * series: [{key, label, cls, fmt}]; opt: {max, yfmt}
   * Пропуски (нет данных — подписчик не работал) разрывают линию.
   */
  function lineChart(el, points, series, opt = {}) {
    const W = Math.max(280, el.clientWidth), H = 170, L = 52, R = 12, T = 10, B = 24;
    if (!points.length) { el.innerHTML = '<div class="chart-empty">Нет данных за период: история появится через несколько минут работы подписчика MQTT.</div>'; return; }
    const span = hist.span, t1 = hist.now, t0 = t1 - span;
    const x = t => L + (W - L - R) * (t - t0) / span;
    // верх шкалы — 4 «круглых» деления (для целых величин — целые)
    let step = niceMax(Math.max(...series.flatMap(s => points.map(p => p[s.key] ?? 0))) * 1.1 / 4);
    if (opt.int) step = Math.max(1, Math.ceil(step));
    const vmax = opt.max ?? 4 * step;
    const y = v => T + (H - T - B) * (1 - v / vmax);
    const yfmt = opt.yfmt || (v => num(v, Number.isInteger(v) ? 0 : 1));
    let svg = `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img">`;
    for (let i = 0; i <= 4; i++) {           // сетка и подписи оси Y
      const v = vmax * i / 4, yy = y(v);
      svg += `<line class="grid" x1="${L}" x2="${W - R}" y1="${yy}" y2="${yy}"/><text class="ax" x="${L - 6}" y="${yy + 4}" text-anchor="end">${esc(yfmt(v))}</text>`;
    }
    for (let i = 0; i <= 4; i++) {           // подписи оси X
      const t = t0 + span * i / 4;
      svg += `<text class="ax" x="${x(t)}" y="${H - 6}" text-anchor="${i === 0 ? 'start' : i === 4 ? 'end' : 'middle'}">${tfmt(t, span)}</text>`;
    }
    const gap = hist.bucket * 2.5;
    for (const s of series) {
      let d = '', prev = null;
      for (const p of points) {
        const v = p[s.key];
        if (v == null) { prev = null; continue; }
        d += (prev === null || p.t - prev > gap ? 'M' : 'L') + x(p.t).toFixed(1) + ' ' + y(v).toFixed(1);
        prev = p.t;
      }
      svg += `<path class="ln ${s.cls}" d="${d}"/>`;
    }
    svg += `<line class="xh" y1="${T}" y2="${H - B}" x1="-10" x2="-10"/>`;
    svg += series.map(s => `<circle class="dot ${s.cls}" r="4" cx="-10" cy="-10"/>`).join('');
    svg += `<rect class="hit" x="${L}" y="${T}" width="${W - L - R}" height="${H - T - B}"/></svg>`;
    const legend = series.length > 1 ? `<div class="chart-legend">${series.map(s => `<span><i class="sw ${s.cls}"></i>${esc(s.label)}</span>`).join('')}</div>` : '';
    el.innerHTML = legend + svg + '<div class="chart-tip hidden"></div>';
    const sv = el.querySelector('svg'), tip = el.querySelector('.chart-tip'), xh = sv.querySelector('.xh'), dots = [...sv.querySelectorAll('.dot')];
    const hide = () => { el.classList.remove('hover'); tip.classList.add('hidden'); xh.setAttribute('x1', -10); xh.setAttribute('x2', -10); dots.forEach(c => c.setAttribute('cx', -10)); };
    sv.querySelector('.hit').addEventListener('mousemove', e => {
      const r = sv.getBoundingClientRect(), px = (e.clientX - r.left) * W / r.width;
      const t = t0 + (px - L) / (W - L - R) * span;
      let best = null;
      for (const p of points) if (!best || Math.abs(p.t - t) < Math.abs(best.t - t)) best = p;
      if (!best) return hide();
      const xx = x(best.t);
      xh.setAttribute('x1', xx); xh.setAttribute('x2', xx);
      series.forEach((s, i) => { const v = best[s.key]; dots[i].setAttribute('cx', v == null ? -10 : xx); dots[i].setAttribute('cy', v == null ? -10 : y(v)); });
      tip.innerHTML = `<b>${fmtTime(best.t)}</b>` + series.map(s => `<div><i class="sw ${s.cls}"></i>${esc(s.label)}: <b>${best[s.key] == null ? 'нет данных' : esc((s.fmt || yfmt)(best[s.key]))}</b></div>`).join('');
      tip.classList.remove('hidden'); el.classList.add('hover');
      const left = xx / W * r.width;
      tip.style.left = Math.min(r.width - tip.offsetWidth - 4, Math.max(4, left + 12 > r.width - tip.offsetWidth ? left - tip.offsetWidth - 12 : left + 12)) + 'px';
    });
    sv.querySelector('.hit').addEventListener('mouseleave', hide);
  }

  function drawCharts() {
    if (!hist) return;
    const p = hist.points;
    const memTotal = Math.max(0, ...p.map(x => x.mem_total || 0));
    lineChart($('#c-cpu'), p, [{ key: 'cpu', label: 'Процессор', cls: 's1', fmt: v => num(v, 1) + ' %' }], { max: 100, yfmt: v => num(v) + '%' });
    lineChart($('#c-mem'), p, [{ key: 'mem_used', label: 'Занято', cls: 's1', fmt: v => bytes(v) + (memTotal ? ` (${num(pct(v, memTotal))} %)` : '') }],
      { max: memTotal || undefined, yfmt: v => bytes(v, 0) });
    lineChart($('#c-net'), p, [{ key: 'net_rx', label: 'Принято', cls: 's1', fmt: rate }, { key: 'net_tx', label: 'Отправлено', cls: 's2', fmt: rate }],
      { yfmt: v => bytes(v, 0) + '/с' });
    lineChart($('#c-mqtt'), p, [{ key: 'mqtt_in', label: 'Принято брокером', cls: 's1', fmt: v => num(v, 1) }, { key: 'mqtt_out', label: 'Отправлено брокером', cls: 's2', fmt: v => num(v, 1) }]);
    lineChart($('#c-dev'), p, [{ key: 'devices_online', label: 'На связи', cls: 's1', fmt: v => num(v) }], { int: true });
    lineChart($('#c-cli'), p, [{ key: 'mqtt_clients', label: 'Подключено', cls: 's1', fmt: v => num(v) }], { int: true });
  }

  async function loadHistory() {
    const j = await api('/api/server.php', { action: 'history', range });
    j.points = j.points.map(r => ({ ...r, t: +r.t }));
    hist = j;
    drawCharts();
  }

  /* ---------- текущие показатели ---------- */
  async function loadNow() {
    const j = await api('/api/server.php', { action: 'now' });
    const n = j.now, m = n.mem, d = n.disk, info = j.info, sys = j.broker.sys || {};
    $('#srv-sub').textContent = `${info.hostname} · ${info.os} · работает ${dur(n.uptime)} · обновлено ${fmtTime(info.time)}`;
    const W = { error: 'bad', warning: 'warn', info: 'info' };
    $('#srv-warn').innerHTML = j.warnings.length ? `<div class="srv-warns">${j.warnings.map(w => `<div class="srv-warn ${W[w.level]}">${esc(w.text)}</div>`).join('')}</div>` : '';

    const memP = pct(m.used, m.total), diskP = pct(d.used, d.total), swapP = pct(m.swap_used, m.swap_total), cpuP = n.cpu ?? 0;
    const tile = (label, value, sub, meter) => `<div class="tile srv-tile"><div class="tile-label">${label}</div><div class="tile-value">${value}</div>${meter || ''}<div class="tile-sub">${sub}</div></div>`;
    $('#srv-tiles').innerHTML = [
      tile('Процессор', num(cpuP, 0) + ' %', `${n.cores} ${n.cores === 1 ? 'ядро' : n.cores < 5 ? 'ядра' : 'ядер'} · нагрузка ${n.load.map(v => num(v, 2)).join(' / ')}${n.iowait >= 5 ? ` · ожидание диска ${num(n.iowait, 0)} %` : ''}`, bar(cpuP, level(cpuP, 70, 90))),
      tile('Память', num(memP, 0) + ' %', `${bytes(m.used)} из ${bytes(m.total)} · кэш ${bytes(m.cache)}`, bar(memP, level(memP, 80, 92))),
      tile('Диск', num(diskP, 0) + ' %', `${bytes(d.used)} из ${bytes(d.total)} · свободно ${bytes(d.free)}`, bar(diskP, level(diskP, 80, 92))),
      tile('Файл подкачки', m.swap_total ? num(swapP, 0) + ' %' : 'нет', m.swap_total ? `${bytes(m.swap_used)} из ${bytes(m.swap_total)}` : 'swap не настроен', m.swap_total ? bar(swapP, level(swapP, 30, 60)) : ''),
      tile('Сеть', rate(n.net.rx + n.net.tx), `↓ ${rate(n.net.rx)} · ↑ ${rate(n.net.tx)}`),
      tile('Устройства', `${j.devices.online} / ${j.devices.total}`, `на связи · MQTT за минуту: ${j.messages.m1}, за час: ${j.messages.h1}`),
    ].join('');
    $('#n-cpu').textContent = `сейчас ${num(cpuP, 0)} %`;
    $('#n-mem').textContent = `сейчас ${bytes(m.used)} из ${bytes(m.total)}`;
    $('#n-net').textContent = `сейчас ↓ ${rate(n.net.rx)} ↑ ${rate(n.net.tx)}`;
    $('#n-mqtt').textContent = sys['load/messages/received/1min'] != null ? `сейчас ${num(sys['load/messages/received/1min'], 1)} / ${num(sys['load/messages/sent/1min'], 1)}` : '';
    $('#n-dev').textContent = `сейчас ${j.devices.online}`;
    $('#n-cli').textContent = sys['clients/connected'] != null ? `сейчас ${sys['clients/connected']}` : '';

    const ST = { active: ['success', 'работает'], inactive: ['', 'остановлена'], failed: ['bad', 'ошибка'], activating: ['warning', 'запускается'], deactivating: ['warning', 'останавливается'] };
    $('#srv-services').innerHTML = j.services.map(s => {
      const [c, t] = s.installed ? (ST[s.state] || ['', s.state]) : ['', 'не установлена'];
      return `<tr class="${s.installed ? '' : 'disabled-row'}"><td><b>${esc(s.title)}</b><div class="mono muted" style="font-size:12px">${esc(s.name)}</div></td>
        <td><span class="badge ${c}">${t}</span>${s.sub && s.sub !== 'running' && s.state === 'active' ? ` <span class="muted">${esc(s.sub)}</span>` : ''}</td>
        <td class="muted">${s.since && s.state === 'active' ? `${fmtTime(s.since)} <span title="">(${dur(info.time - s.since)})</span>` : '—'}</td>
        <td class="num">${s.memory != null ? bytes(s.memory) : '—'}</td>
        <td class="num">${s.cpu_sec != null ? dur(s.cpu_sec).replace(/^0 мин$/, '< 1 мин') : '—'}</td>
        <td class="num">${s.restarts ?? '—'}</td></tr>`;
    }).join('');

    const kv = rows => rows.filter(r => r[1] != null && r[1] !== '').map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('');
    const sv = k => sys[k] != null ? esc(String(sys[k])) : null;
    $('#srv-broker').innerHTML = j.broker.ts ? kv([
      ['Версия', sv('version')],
      ['Работает', sys.uptime ? dur(parseInt(sys.uptime, 10)) : null],
      ['Клиенты', sys['clients/connected'] != null ? `подключено ${sv('clients/connected')}${sys['clients/total'] != null ? ` из ${sv('clients/total')} известных` : ''}` : null],
      ['Сообщений в минуту', sys['load/messages/received/1min'] != null ? `принято ${num(sys['load/messages/received/1min'], 1)} · отправлено ${num(sys['load/messages/sent/1min'], 1)}` : null],
      ['Трафик в минуту', sys['load/bytes/received/1min'] != null ? `принято ${bytes(sys['load/bytes/received/1min'])} · отправлено ${bytes(sys['load/bytes/sent/1min'])}` : null],
      ['Всего сообщений', sys['messages/received'] != null ? `принято ${sv('messages/received')} · отправлено ${sv('messages/sent')}` : null],
      ['Подписки', sv('subscriptions/count')],
      ['Сохранённые (retained)', sv('retained messages/count')],
      ['Память брокера', sys['heap/current'] != null ? bytes(sys['heap/current']) : null],
      ['Данные', `${ago(j.broker.ts)}`],
    ]) : '<dd class="muted" style="grid-column:1/-1">Статистики брокера ещё нет: она появится через минуту после обновления сервера (подписчику нужен доступ к $SYS).</dd>';

    const st = j.subscriber.state || {};
    $('#srv-sub-info').innerHTML = j.subscriber.ts ? kv([
      ['Работает', st.started ? `с ${fmtTime(st.started)} (${dur(info.time - st.started)})` : null],
      ['Память', st.memory != null ? `${bytes(st.memory)} (пик ${bytes(st.memory_peak)})` : null],
      ['Брокеры', (st.brokers || []).map(b => `${esc(b.name)} — ${b.connected ? '<span class="badge success">подключён</span>' : '<span class="badge bad">нет соединения</span>'}`).join('<br>') || null],
      ['Статистика', j.subscriber.last_sample ? `записана ${ago(j.subscriber.last_sample)}` : 'ещё нет'],
    ]) : '<dd class="muted" style="grid-column:1/-1">Подписчик ещё не передавал данные.</dd>';

    $('#srv-info').innerHTML = kv([
      ['Имя сервера', esc(info.hostname)], ['ОС', esc(info.os)], ['Ядро', `${esc(info.kernel)} · ${esc(info.arch)}`],
      ['Процессор', n.cpu_model ? `${esc(n.cpu_model)} · ${n.cores} ${n.cores === 1 ? 'ядро' : 'ядер'}` : `${n.cores} ядер`],
      ['Работает', dur(n.uptime)], ['Процессов', n.processes], ['Часовой пояс', esc(info.timezone)],
      ['Панель', `off-on service ${esc(info.panel)}`], ['PHP', esc(info.php)], ['Веб-сервер', esc(info.web)],
      ['Сеть всего', `принято ${bytes(n.net.rx_total)} · отправлено ${bytes(n.net.tx_total)}`],
    ]);

    const db = j.db, maxT = Math.max(1, ...db.tables.map(t => t.size));
    $('#db-head').textContent = `${db.name} · ${bytes(db.size)} · MariaDB ${db.version}${db.uptime ? ' · работает ' + dur(db.uptime) : ''}${db.connections != null ? ' · соединений ' + db.connections : ''}`;
    $('#srv-db').innerHTML = db.tables.map(t => `<tr><td class="mono">${esc(t.name)}</td><td class="num">${t.rows_est.toLocaleString('ru-RU')}</td>
      <td class="num">${bytes(t.size)}</td><td>${bar(100 * t.size / maxT, 'ok')}</td></tr>`).join('');
  }

  $('#range').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    range = b.dataset.r; $$('#range button').forEach(x => x.classList.toggle('on', x === b));
    try { localStorage.setItem('relay-srv-range', range); } catch (er) {}
    loadHistory().catch(er => App.toast(er.message, 'err'));
  });
  let rt; window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(drawCharts, 150); });

  loadNow().catch(e => { $('#srv-sub').textContent = e.message; });
  loadHistory().catch(() => {});
  App.every(10000, loadNow);
  App.every(60000, loadHistory);
})();
