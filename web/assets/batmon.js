/* Мониторинг АКБ: напряжение АКБ всех устройств на одном графике, выбор устройств */
'use strict';
(() => {
  const { $, $$, esc, api, fmtTime } = App;
  const SLOTS = 8;              // цветом — первые 8 выбранных (палитра --cat-1…8), остальные — серым
  const MAX_TIP = 12;           // строк в подсказке
  let hours = 24, data = null, query = '', hoverId = 0;
  let selected = new Set(), slotOf = new Map(); // id → номер цвета 1…8 (цвет закреплён за устройством, пока оно выбрано)
  const store = { get(k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } },
                  set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} } };
  const saved = store.get('relay-batmon') || {};
  if ([24, 168, 720].includes(saved.hours)) hours = saved.hours;
  let firstRun = !Array.isArray(saved.sel);   // впервые — 8 устройств с самым низким напряжением
  if (!firstRun) selected = new Set(saved.sel);
  if (saved.slots) slotOf = new Map(saved.slots);
  const save = () => store.set('relay-batmon', { hours, sel: [...selected], slots: [...slotOf] });

  const byId = id => data && data.devices.find(d => d.id === id);
  const usable = d => d.calibrated && d.points.length > 0;      // есть что рисовать в вольтах
  const color = id => slotOf.has(id) ? `var(--cat-${slotOf.get(id)})` : 'var(--cat-other)';
  const vClass = d => d.voltage == null ? 'raw' : App.batClass(d.voltage, d);

  /* цвет за устройством: свободный слот с наименьшим номером; уже выбранные не перекрашиваются */
  function assignSlots() {
    for (const id of [...slotOf.keys()]) if (!selected.has(id)) slotOf.delete(id);
    const used = new Set(slotOf.values());
    for (const id of selected) {
      if (slotOf.has(id)) continue;
      let s = 1; while (used.has(s) && s <= SLOTS) s++;
      if (s > SLOTS) break;
      slotOf.set(id, s); used.add(s);
    }
  }

  async function load() {
    try { data = await api('/api/devices.php', { action: 'battery_all', hours }); }
    catch (e) { $('#bm-chart').innerHTML = `<div class="chart-empty">${esc(e.message)}</div>`; return; }
    const ok = data.devices.filter(usable);
    if (firstRun) { selected = new Set(lowest(data.devices)); firstRun = false; }
    // устройства, которых больше нет, — из выбора убрать
    for (const id of [...selected]) if (!data.devices.some(d => d.id === id)) selected.delete(id);
    assignSlots(); save();
    $('#bm-sub').textContent = `Напряжение АКБ всех устройств на одном графике · с графиком: ${ok.length} из ${data.devices.length}`
      + ` · точка — среднее за ${data.bucket >= 3600 ? data.bucket / 3600 + ' ч' : Math.round(data.bucket / 60) + ' мин'}`;
    render();
  }

  /** 8 устройств с самым низким текущим напряжением */
  const lowest = list => list.filter(usable).sort((a, b) => (a.voltage ?? 1e9) - (b.voltage ?? 1e9)).slice(0, SLOTS).map(d => d.id);
  const matches = (d, q) => !q || String(d.id) === q.replace('#', '') || (d.name + ' ' + (d.address || '') + ' ' + d.id).toLowerCase().includes(q);

  function render() { renderList(); renderLegend(); renderChart(); }

  /* ---------- список устройств с флажками: по текущему напряжению, низкое сверху ---------- */
  function renderList() {
    const q = query.trim().toLowerCase();
    const list = data.devices.filter(d => matches(d, q))
      .sort((a, b) => (usable(b) - usable(a)) || ((a.voltage ?? 1e9) - (b.voltage ?? 1e9)) || a.id - b.id);
    $('#bm-sel-count').textContent = `выбрано ${selected.size}`;
    $('#bm-list').innerHTML = list.length ? list.map(d => {
      const on = selected.has(d.id), can = usable(d);
      const v = d.voltage != null ? `${d.voltage.toFixed(2)} В` : d.adc != null ? `АЦП ${d.adc}` : 'нет данных';
      return `<label class="bm-item${on ? ' on' : ''}${can ? '' : ' off'}" data-id="${d.id}" title="${can ? '' : d.calibrated ? 'Нет данных АКБ за период' : 'Нет калибровки АКБ — напряжение не рассчитывается'}">
        <input type="checkbox" ${on ? 'checked' : ''} ${can ? '' : 'disabled'} data-pick-id="${d.id}">
        <i class="bm-sw" style="${on ? `background:${color(d.id)}` : ''}"></i>
        <span class="bm-name"><b>${esc(d.name)}</b> <span class="muted">#${d.id}</span>${d.address ? `<span class="muted bm-addr">${esc(d.address)}</span>` : ''}</span>
        <span class="bm-v"><span class="bat ${vClass(d)}">${v}</span><span class="muted">${can ? 'АКБ ' + d.bat_type + ' В' : d.calibrated ? 'нет данных' : 'без калибровки'}</span></span></label>`;
    }).join('') : '<div class="muted" style="padding:12px 0;font-size:13px">Не найдено</div>';
  }

  /* ---------- легенда: выбранные устройства (цвет — название — текущее), × — убрать ---------- */
  function renderLegend() {
    const sel = [...selected].map(byId).filter(Boolean).sort((a, b) => (slotOf.get(a.id) || 99) - (slotOf.get(b.id) || 99) || a.id - b.id);
    $('#bm-count').textContent = sel.length ? `на графике: ${sel.length}` : '';
    $('#bm-legend').innerHTML = sel.map(d => `<span class="bm-chip" data-hl="${d.id}"><i class="bm-sw" style="background:${color(d.id)}"></i>${esc(d.name)}
        <span class="muted">#${d.id}${d.voltage != null ? ' · ' + d.voltage.toFixed(2) + ' В' : ''}</span>
        <button type="button" class="bm-x" data-unpick="${d.id}" title="Убрать с графика" aria-label="Убрать ${esc(d.name)}">×</button></span>`).join('');
    $('#bm-note').textContent = sel.length > SLOTS ? `Цветом показаны ${SLOTS} устройств, остальные — серым: наведите на линию или на устройство в легенде, чтобы выделить его.` : '';
  }

  /* ---------- график ---------- */
  const niceStep = span => { const raw = span / 5, p = 10 ** Math.floor(Math.log10(raw)), m = raw / p; return (m < 1.5 ? 1 : m < 3 ? 2 : m < 7 ? 5 : 10) * p; };
  let geom = null;
  function renderChart() {
    const box = $('#bm-chart');
    const sel = [...selected].map(byId).filter(d => d && usable(d));
    if (!sel.length) { box.innerHTML = '<div class="chart-empty">Выберите устройства в списке справа — например, «8 с самым низким».</div>'; geom = null; return; }
    const t1 = data.now, t0 = t1 - hours * 3600;
    // АКБ 12 В и 24 В — отдельные панели одна над другой, у каждой своя шкала (время общее)
    const types = [...new Set(sel.map(d => d.bat_type))].sort((a, b) => a - b);
    const W = Math.max(300, box.clientWidth), L = 44, R = 12, T = types.length > 1 ? 22 : 12, B = 26, GAP = types.length > 1 ? 30 : 0;
    const ph = (W < 600 ? 240 : 320) / (types.length > 1 ? 1.35 : 1);       // высота области панели
    const H = T + types.length * ph + (types.length - 1) * GAP + B;
    const x = t => L + (W - L - R) * (t - t0) / (t1 - t0);
    let g = '';
    const panels = types.map((type, i) => {
      const devs = sel.filter(d => d.bat_type === type);
      let lo = Infinity, hi = -Infinity;
      for (const d of devs) for (const p of d.points) { if (p[0] < t0) continue; lo = Math.min(lo, p[1]); hi = Math.max(hi, p[1]); }
      if (!isFinite(lo)) { lo = type - 1; hi = type + 1; }
      if (hi - lo < 0.5) { lo -= 0.25; hi += 0.25; }
      const step = niceStep(hi - lo); lo = Math.floor(lo / step) * step; hi = Math.ceil(hi / step) * step;
      const top = T + i * (ph + GAP), y = v => top + ph * (1 - (v - lo) / (hi - lo));
      // сетка и подписи осей — приглушённые
      for (let v = lo; v <= hi + 1e-9; v += step) g += `<line class="grid" x1="${L}" x2="${W - R}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}"/><text class="ax" x="${L - 6}" y="${(y(v) + 4).toFixed(1)}" text-anchor="end">${(+v.toFixed(2)).toString().replace('.', ',')}</text>`;
      if (types.length > 1) g += `<text class="bm-ptitle" x="${L}" y="${top - 8}">АКБ ${type} В</text>`;
      return { type, top, y };
    });
    const yOf = d => panels.find(p => p.type === d.bat_type).y;
    const bottom = H - B;
    const narrow = W < 600; // на телефоне подписи времени реже
    const xStep = hours <= 24 ? (narrow ? 6 : 3) * 3600 : hours <= 168 ? (narrow ? 2 : 1) * 86400 : (narrow ? 10 : 5) * 86400;
    const off = new Date().getTimezoneOffset() * 60;
    for (let t = Math.ceil((t0 - off) / xStep) * xStep + off; t <= t1; t += xStep) {
      const dt = new Date(t * 1000), lbl = hours <= 24 ? dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : dt.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
      if (x(t) < L + 20 || x(t) > W - R - 20) continue;
      g += `<line class="grid tick" x1="${x(t).toFixed(1)}" x2="${x(t).toFixed(1)}" y1="${bottom}" y2="${bottom + 4}"/><text class="ax" x="${x(t).toFixed(1)}" y="${H - 8}" text-anchor="middle">${lbl}</text>`;
    }
    // линии: разрыв там, где данных нет дольше трёх интервалов; серые — снизу, цветные — сверху
    const gap = data.bucket * 3;
    const path = d => { const y = yOf(d); let s = '', prev = null; for (const p of d.points) { if (p[0] < t0) continue; s += (prev === null || p[0] - prev > gap ? 'M' : 'L') + x(p[0]).toFixed(1) + ',' + y(p[1]).toFixed(1); prev = p[0]; } return s; };
    const order = sel.slice().sort((a, b) => (slotOf.has(a.id) - slotOf.has(b.id)));
    const lines = order.map(d => `<path class="bm-ln${slotOf.has(d.id) ? '' : ' other'}${hoverId === d.id ? ' hl' : ''}" data-ln="${d.id}" d="${path(d)}" style="stroke:${color(d.id)}"/>`).join('');
    const axes = panels.map(p => `<line class="axis" x1="${L}" x2="${W - R}" y1="${(p.top + ph).toFixed(1)}" y2="${(p.top + ph).toFixed(1)}"/>`).join('');
    box.innerHTML = `<svg class="bm-svg${hoverId ? ' has-hl' : ''}" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img" aria-label="Напряжение АКБ выбранных устройств">
        ${g}${axes}${lines}
        <line class="bm-xh hidden" y1="${T}" y2="${bottom}"/><g class="bm-dots"></g>
        <rect class="bm-hit" x="${L}" y="${T}" width="${W - L - R}" height="${bottom - T}"/></svg><div class="bm-tip hidden"></div>`;
    geom = { W, H, L, R, x, y: (d, v) => yOf(d)(v), t0, t1, sel };
  }

  /* ---------- подсказка: значения всех выбранных в этот момент, низкое сверху ---------- */
  function nearest(d, t) {
    let best = null;
    for (const p of d.points) if (!best || Math.abs(p[0] - t) < Math.abs(best[0] - t)) best = p;
    return best && Math.abs(best[0] - t) <= data.bucket * 1.5 ? best : null;
  }
  function onMove(e) {
    if (!geom) return;
    const svg = $('#bm-chart svg'), r = svg.getBoundingClientRect(), px = e.clientX - r.left, py = e.clientY - r.top;
    if (px < geom.L || px > geom.W - geom.R) return hideTip();
    const t = geom.t0 + (px - geom.L) / (geom.W - geom.L - geom.R) * (geom.t1 - geom.t0);
    const vals = geom.sel.map(d => ({ d, p: nearest(d, t) })).filter(v => v.p).sort((a, b) => a.p[1] - b.p[1]);
    if (!vals.length) return hideTip();
    // ближайшая по высоте линия — выделить
    let close = null; for (const v of vals) if (!close || Math.abs(geom.y(v.d, v.p[1]) - py) < Math.abs(geom.y(close.d, close.p[1]) - py)) close = v;
    setHover(Math.abs(geom.y(close.d, close.p[1]) - py) < 14 ? close.d.id : 0);
    const xh = svg.querySelector('.bm-xh'), tx = geom.x(vals[0].p[0]);
    xh.setAttribute('x1', tx); xh.setAttribute('x2', tx); xh.classList.remove('hidden');
    svg.querySelector('.bm-dots').innerHTML = vals.filter(v => slotOf.has(v.d.id) || v.d.id === hoverId)
      .map(v => `<circle class="bm-dot" cx="${geom.x(v.p[0]).toFixed(1)}" cy="${geom.y(v.d, v.p[1]).toFixed(1)}" r="4" style="stroke:${color(v.d.id)}"/>`).join('');
    const tip = $('#bm-chart .bm-tip'), rows = vals.slice(0, MAX_TIP);
    tip.innerHTML = `<div class="muted">${fmtTime(vals[0].p[0])}</div>` + rows.map(v => `<div class="bm-tip-row${v.d.id === hoverId ? ' hl' : ''}">
        <i class="bm-sw" style="background:${color(v.d.id)}"></i><span class="bm-tip-name">${esc(v.d.name)} <span class="muted">#${v.d.id}</span></span>
        <b>${v.p[1].toFixed(2)} В</b></div>`).join('') + (vals.length > MAX_TIP ? `<div class="muted">… и ещё ${vals.length - MAX_TIP}</div>` : '');
    tip.classList.remove('hidden');
    const tw = tip.offsetWidth, left = tx + 14 + tw > geom.W ? tx - 14 - tw : tx + 14;
    tip.style.left = Math.max(0, left) + 'px';
  }
  function hideTip() {
    const box = $('#bm-chart');
    box.querySelector('.bm-tip')?.classList.add('hidden');
    box.querySelector('.bm-xh')?.classList.add('hidden');
    const dots = box.querySelector('.bm-dots'); if (dots) dots.innerHTML = '';
    setHover(0);
  }
  /* выделить линию устройства (наведение на линию, на легенду или в списке) */
  function setHover(id) {
    if (id === hoverId) return;
    hoverId = id;
    const svg = $('#bm-chart svg'); if (!svg) return;
    svg.classList.toggle('has-hl', !!id);
    svg.querySelectorAll('.bm-ln').forEach(l => l.classList.toggle('hl', +l.dataset.ln === id));
    const ln = id && svg.querySelector(`[data-ln="${id}"]`); if (ln) ln.parentNode.insertBefore(ln, svg.querySelector('.bm-xh')); // выделенная — поверх
    $$('.bm-chip').forEach(c => c.classList.toggle('hl', +c.dataset.hl === id));
  }

  $('#bm-chart').addEventListener('pointermove', onMove);
  $('#bm-chart').addEventListener('pointerdown', onMove);
  $('#bm-chart').addEventListener('pointerleave', e => { if (e.pointerType !== 'touch') hideTip(); });
  $('#bm-legend').addEventListener('pointerover', e => { const c = e.target.closest('[data-hl]'); setHover(c ? +c.dataset.hl : 0); });
  $('#bm-legend').addEventListener('pointerleave', () => setHover(0));
  $('#bm-legend').addEventListener('click', e => {
    const x = e.target.closest('[data-unpick]'); if (!x) return;
    selected.delete(+x.dataset.unpick); hoverId = 0; assignSlots(); save(); render();
  });
  $('#bm-list').addEventListener('change', e => {
    const cb = e.target.closest('[data-pick-id]'); if (!cb) return;
    const id = +cb.dataset.pickId;
    cb.checked ? selected.add(id) : selected.delete(id);
    assignSlots(); save(); render();
  });
  $('#bm-list').addEventListener('pointerover', e => { const it = e.target.closest('.bm-item.on'); setHover(it ? +it.dataset.id : 0); });
  $('#bm-list').addEventListener('pointerleave', () => setHover(0));
  $('.bm-quick').addEventListener('click', e => {
    const b = e.target.closest('[data-pick]'); if (!b || !data) return;
    const q = query.trim().toLowerCase(), shown = data.devices.filter(d => usable(d) && matches(d, q)); // с учётом поиска
    if (b.dataset.pick === 'none') selected.clear();
    else if (b.dataset.pick === 'all') shown.forEach(d => selected.add(d.id));
    else selected = new Set(lowest(shown));
    hoverId = 0; assignSlots(); save(); render();
  });
  $('#bm-q').addEventListener('input', e => { query = e.target.value; renderList(); });
  $('#bm-range').addEventListener('click', e => {
    const b = e.target.closest('button[data-h]'); if (!b) return;
    hours = +b.dataset.h; $$('#bm-range button').forEach(x => x.classList.toggle('on', x === b)); save(); load();
  });
  $$('#bm-range button').forEach(x => x.classList.toggle('on', +x.dataset.h === hours));
  let rt = 0; window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => data && renderChart(), 150); });

  load();
  App.every(60000, () => load().catch(() => {}));   // раз в минуту — свежие данные
})();
