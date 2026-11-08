/* Relay Control — общие функции интерфейса */
'use strict';

const App = (() => {
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- Тема ---------- */
  const THEME_KEY = 'relay-theme';
  function applyTheme(t) { if (t) document.documentElement.dataset.theme = t; else delete document.documentElement.dataset.theme; }
  try { applyTheme(localStorage.getItem(THEME_KEY)); } catch (e) {}
  document.addEventListener('click', e => {
    if (!e.target.closest('#theme-toggle')) return;
    const dark = document.documentElement.dataset.theme
      ? document.documentElement.dataset.theme === 'dark'
      : matchMedia('(prefers-color-scheme: dark)').matches;
    const next = dark ? 'light' : 'dark';
    applyTheme(next);
    try { localStorage.setItem(THEME_KEY, next); } catch (e) {}
  });
  document.addEventListener('click', e => { // закрытие меню по клику вне
    $$('details.menu[open]').forEach(d => { if (!d.contains(e.target)) d.open = false; });
  });

  /* ---------- Запросы ---------- */
  let failures = 0;
  function setLive(ok) {
    const el = $('#live'); if (!el) return;
    el.classList.toggle('err', !ok);
    el.title = ok ? 'Связь с сервером есть' : 'Нет связи с сервером';
    const s = el.querySelector('span'); if (s) s.textContent = ok ? 'онлайн' : 'нет связи';
  }
  async function api(url, params = {}, post = false) {
    const opt = { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } };
    if (post) { opt.method = 'POST'; opt.body = new URLSearchParams(params); }
    else { const q = new URLSearchParams(params).toString(); if (q) url += (url.includes('?') ? '&' : '?') + q; }
    let r;
    try { r = await fetch(url, opt); }
    catch (e) { failures++; if (failures > 1) setLive(false); throw new Error('Нет связи с сервером'); }
    if (r.status === 401) { location.href = '/login.php?next=' + encodeURIComponent(location.pathname + location.search); throw new Error('Требуется вход'); }
    let j;
    try { j = await r.json(); } catch (e) { throw new Error('Ошибка сервера (' + r.status + ')'); }
    failures = 0; setLive(true);
    if (!r.ok || j.error) throw new Error(j.error || r.statusText);
    return j;
  }

  /* ---------- Смена своего пароля (меню пользователя) ---------- */
  const pwDlg = $('#pw-dlg'), pwForm = $('#pw-form');
  if (pwDlg) {
    $('#pw-open').addEventListener('click', () => {
      pwForm.reset(); $('#pw-result').className = 'result'; $('#pw-result').textContent = '';
      $$('details.menu[open]').forEach(d => { d.open = false; });
      pwDlg.showModal();
    });
    pwForm.addEventListener('submit', async e => {
      if (e.submitter && e.submitter.value === 'cancel') return;
      e.preventDefault();
      const f = pwForm.elements, res = $('#pw-result');
      if (f.password.value !== f.password2.value) { res.className = 'result err'; res.textContent = 'Новые пароли не совпадают'; return; }
      try {
        await api('/api/users.php', { action: 'passwd', old: f.old.value, password: f.password.value }, true);
        pwDlg.close(); toast('Пароль изменён', 'ok');
      } catch (er) { res.className = 'result err'; res.textContent = er.message; }
    });
  }

  /* ---------- Форматирование ---------- */
  const pad = n => String(n).padStart(2, '0');
  // Время показываем в часовом поясе сервера (как в журналах), а не браузера
  const tzMeta = document.querySelector('meta[name="server-tz-offset"]');
  const TZ_MIN = tzMeta ? +tzMeta.content : -new Date().getTimezoneOffset();
  const shift = ms => new Date(ms + TZ_MIN * 60000); // дальше используем getUTC*
  const dayKey = d => d.getUTCFullYear() + '-' + d.getUTCMonth() + '-' + d.getUTCDate();
  function fmtTime(ts, withDate = true) {
    if (!ts) return '—';
    const d = shift(ts * 1000), now = shift(Date.now());
    const t = `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())}`;
    if (!withDate) return t;
    if (dayKey(d) === dayKey(now)) return 'сегодня ' + t;
    if (dayKey(d) === dayKey(shift(Date.now() - 86400000))) return 'вчера ' + t;
    return `${pad(d.getUTCDate())}.${pad(d.getUTCMonth() + 1)}.${d.getUTCFullYear()} ${t}`;
  }
  const CMD_STATUS = { pending: ['info', 'ожидает'], sent: ['info', 'отправлена'], success: ['success', 'выполнена'], error: ['error', 'ошибка'] };
  const cmdStatusBadge = s => { const [c, t] = CMD_STATUS[s] || ['', s]; return `<span class="badge ${c}">${t}</span>`; };
  function ago(ts) {
    if (!ts) return 'никогда';
    const s = Math.max(0, Math.round(Date.now() / 1000 - ts));
    if (s < 5) return 'только что';
    if (s < 60) return s + ' с назад';
    if (s < 3600) return Math.floor(s / 60) + ' мин назад';
    if (s < 86400) { const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60); return h + ' ч ' + (m ? m + ' мин ' : '') + 'назад'; }
    return Math.floor(s / 86400) + ' дн назад';
  }
  /** Время из строки MySQL «YYYY-MM-DD HH:MM:SS(.mmm)» (часовой пояс сервера) → секунды */
  function parseTs(s) { if (!s) return null; const d = new Date(s.replace(' ', 'T')); return isNaN(d) ? null : d.getTime() / 1000; }

  const STATE = {
    online: ['online', 'На связи'], offline: ['offline', 'Нет связи'], error: ['error', 'Ошибка устройства'],
    alarm: ['alarm', 'Питание от АКБ'], never: ['never', 'Не выходила на связь'],
  };
  const stateBadge = st => { const [c, t] = STATE[st] || STATE.never; return `<span class="badge ${c}"><span class="dot ${c}"></span>${t}</span>`; };
  const RSTATE = { 0: 'выкл', 1: 'вкл', 2: 'ошибка' };

  /** CSQ 0..31 (99 — нет данных) → 0..4 полоски */
  function signalBars(sl) {
    if (sl == null || sl === 99) return '<span class="muted">—</span>';
    const lvl = sl >= 20 ? 4 : sl >= 15 ? 3 : sl >= 10 ? 2 : sl > 0 ? 1 : 0;
    const word = ['нет', 'слабый', 'средний', 'хороший', 'отличный'][lvl];
    return `<span class="signal s${lvl}" title="Сигнал ${sl}/31 — ${word}"><i></i><i></i><i></i><i></i></span>`;
  }

  /** Калибровка АКБ по типу (как RELAY_BAT_TYPES на сервере): 12 В — точки 6 и 15 В, 24 В — 19 и 26 В */
  const BAT_TYPES = { 12: { low: 6, high: 15, bad: 11.5, warn: 12.2 }, 24: { low: 19, high: 26, bad: 23, warn: 24.4 } };
  const batType = d => BAT_TYPES[d && d.bat_type === 24 ? 24 : 12];
  const BAT_V_LOW = 6, BAT_V_HIGH = 15;
  /** Напряжение АКБ: цвет по уровню (12 В: < 11,5 — разряжена, < 12,2 — внимание; 24 В: < 23 и < 24,4) */
  const batClass = (v, d) => { const t = batType(d); return v == null ? 'raw' : v < t.bad ? 'bad' : v < t.warn ? 'warn' : 'ok'; };
  function batteryHtml(d) {
    const t = batType(d);
    if (d.bat_voltage != null) return `<span class="bat ${batClass(d.bat_voltage, d)}" title="АКБ ${d.bat_type || 12} В (АЦП ${d.bat_adc})">${ICONS.battery}${d.bat_voltage.toFixed(1)} В</span>`;
    if (d.bat_adc != null) return `<span class="bat raw" title="АКБ не откалибрована — укажите значения АЦП при ${t.low} В и ${t.high} В">${ICONS.battery}АЦП ${d.bat_adc}</span>`;
    return '';
  }

  const ICONS = {
    in: '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12l7 7 7-7"/></svg>',
    out: '<svg viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
    clock: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    bolt: '<svg viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>',
    power: '<svg viewBox="0 0 24 24"><path d="M12 3v9M6.3 6.3a8 8 0 1 0 11.4 0"/></svg>',
    restart: '<svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
    refresh: '<svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>',
    plug: '<svg viewBox="0 0 24 24"><path d="M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0zM12 18v4"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path d="M5 12l5 5L20 7"/></svg>',
    x: '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    alert: '<svg viewBox="0 0 24 24"><path d="M12 3 2 21h20zM12 10v4M12 17.5v.5"/></svg>',
    info: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/></svg>',
    send: '<svg viewBox="0 0 24 24"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg>',
    battery: '<svg viewBox="0 0 24 24"><rect x="2" y="7" width="18" height="10" rx="2"/><path d="M22 11v2M6 11v2"/></svg>',
    chat: '<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    edit: '<svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
    download: '<svg viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>',
    search: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
    server: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>',
    trash: '<svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>',
  };

  /* ---------- Уведомления ---------- */
  function toast(msg, type = '') {
    const box = $('#toasts'); if (!box) return alert(msg);
    const el = document.createElement('div');
    el.className = 'toast ' + type; el.textContent = msg;
    box.appendChild(el);
    setTimeout(() => el.remove(), type === 'err' ? 7000 : 4500);
  }

  /* ---------- Расшифровка MQTT-сообщения ---------- */
  function describe(m) {
    const d = m.decoded;
    if (!d) return '<span class="tag">двоичные данные</span>';
    if (d.type === 'stat') {
      const rel = [d.r1, d.r2, d.r3, d.r4].map((v, i) => `${i + 1}:${RSTATE[v] ?? v}`).join(' ');
      return `<b>stat</b> · устройство #${d.id} · реле ${rel} · ${['оффлайн', 'онлайн', 'ошибка'][d.stat] ?? d.stat} · сигнал ${d.sl === 99 ? 'н/д' : d.sl}${d.bat_adc != null ? ' · АЦП АКБ ' + d.bat_adc : ''}${d.door != null ? ' · дверь ' + (d.door ? '<span class="badge warn">открыта</span>' : 'закрыта') + ' · замок ' + (d.lock ? 'вкл' : 'выкл') : ''}`;
    }
    if (d.type === 'rep') return `<b>rep</b> · устройство #${d.id} · команда ${d.cmd_id} · ${d.success === 1 ? '<span class="badge success">выполнена</span>' : '<span class="badge error">не выполнена</span>'}`;
    if (d.type === 'com') return `<b>${esc(d.command_name)}</b>${[1, 2, 3].includes(d.command) ? ' · ' + (d.rel_number === 5 ? 'замок' : d.rel_number ? 'реле ' + d.rel_number : 'все реле') : ''} · cmd_id ${d.cmd_id}`;
    if (d.type === 'json') return `<span class="mono">${esc(JSON.stringify(d.data))}</span>`;
    return esc(d.text);
  }

  /* ---------- Команды платам ---------- */
  const CMD = { 1: 'Включить', 2: 'Выключить', 3: 'Перезапуск реле', 4: 'Запрос статуса', 5: 'Перезагрузка устройства' };
  /** Реле 4 — замок двери: включено — закрыто, выключено — открыто */
  const LOCK4_CMD = { 1: 'Закрыть', 2: 'Открыть надолго', 3: 'Открыть' };
  const LOCK4_STATE = { 0: 'дверь открыта', 1: 'дверь закрыта', 2: 'ошибка' };
  function cmdLabel(command, relay, lock4 = false) {
    if (relay === 4 && lock4 && LOCK4_CMD[command]) return 'Замок: ' + LOCK4_CMD[command].toLowerCase();
    if (relay === 5) return { 1: 'Замок: подать питание', 2: 'Замок: снять питание', 3: 'Замок: открыть' }[command] || CMD[command];
    return CMD[command] + ([1, 2, 3].includes(command) ? ' · ' + (relay ? 'реле ' + relay : 'все реле') : '');
  }
  /**
   * Отправляет команду и ждёт ответ платы (rep) до 60 с.
   * opts: {url, params, statusUrl, statusParams, onDone(status)}
   */
  async function sendCommand({ url, params, statusUrl, statusParams = {}, label, onDone, quiet = false }) {
    const j = await api(url, params, true);
    if (quiet) { // замок на реле 4: результат выполнения не показываем, только обновляем состояние
      toast(`${label}: команда отправлена`, 'ok');
      setTimeout(() => onDone && onDone('sent'), 3000);
      return j.cmd_id;
    }
    toast(`${label}: отправлено, ждём ответ устройства…`);
    (async () => {
      for (let i = 0; i < 30; i++) {
        await new Promise(r => setTimeout(r, 2000));
        let st;
        try { st = (await api(statusUrl, { ...statusParams, cmd_id: j.cmd_id })).status; } catch (e) { continue; }
        if (st === 'success') { toast(`${label}: выполнено`, 'ok'); onDone && onDone('success'); return; }
        if (st === 'error') { toast(`${label}: устройство сообщило об ошибке`, 'err'); onDone && onDone('error'); return; }
      }
      toast(`${label}: ответа от устройства нет 60 с. Команда сохранена в брокере и будет выполнена при подключении.`, 'err');
      onDone && onDone('timeout');
    })();
    return j.cmd_id;
  }

  /** Повтор функции с паузой на время, пока вкладка скрыта */
  function every(ms, fn) {
    let t;
    const run = async () => { if (!document.hidden) { try { await fn(); } catch (e) {} } t = setTimeout(run, ms); };
    t = setTimeout(run, ms);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { clearTimeout(t); run(); } });
  }

  /** Форма с полем bat_type: подписи «АЦП при … В» следуют за типом АКБ (12 В — 6/15 В, 24 В — 19/26 В) */
  function bindBatType(form) {
    const upd = () => {
      const t = BAT_TYPES[form.elements.bat_type.value === '24' ? 24 : 12];
      form.querySelectorAll('[data-bat-low]').forEach(el => { el.textContent = t.low; });
      form.querySelectorAll('[data-bat-high]').forEach(el => { el.textContent = t.high; });
    };
    form.elements.bat_type.addEventListener('change', upd);
    form.addEventListener('bat-type', upd);
    upd();
  }

  return { bindBatType, BAT_TYPES, batType, LOCK4_CMD, LOCK4_STATE, BAT_V_LOW, BAT_V_HIGH, batteryHtml, batClass, cmdStatusBadge, $, $$, esc, api, fmtTime, ago, parseTs, stateBadge, signalBars, ICONS, toast, describe, RSTATE, CMD, cmdLabel, sendCommand, every };
})();
