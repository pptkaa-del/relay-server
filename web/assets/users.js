/* Страница «Пользователи» */
'use strict';
(() => {
  const { $, $$, esc, api, fmtTime, ago, toast } = App;
  const ROLE_BADGE = { admin: 'info', user: 'success' };
  let users = [], roles = {}, me = '', minPass = 6, devices = [], editing = null;

  /** Доступ к устройству по правам на его реле: управление — если хоть одним реле управляет, иначе просмотр */
  const devMode = relays => { const v = Object.values(relays || {}); return v.includes('control') ? 'control' : v.length ? 'view' : ''; };
  /** «5 устр. · управление 3» */
  function permSummary(perms) {
    let total = 0, ctl = 0;
    for (const relays of Object.values(perms || {})) {
      const m = devMode(relays); if (!m) continue;
      total++; if (m === 'control') ctl++;
    }
    return total ? `${total} устр. · управление ${ctl}` : 'нет доступа';
  }

  function render() {
    $('#users').innerHTML = users.map(u => `<tr class="${u.enabled ? '' : 'disabled-row'}">
      <td><b class="mono">${esc(u.login)}</b>${u.login === me ? ' <span class="muted">(вы)</span>' : ''}</td>
      <td>${esc(u.name) || '<span class="muted">—</span>'}</td>
      <td><span class="badge ${ROLE_BADGE[u.role] ?? ''}">${esc(roles[u.role] || u.role)}</span></td>
      <td class="muted">${u.role === 'admin' ? 'все' : permSummary(u.perms)}</td>
      <td class="nowrap">${clickCell(u, 'restarts')}</td>
      <td class="nowrap">${clickCell(u, 'reboots')}</td>
      <td>${u.system ? '<span class="muted">создан при установке</span>' : u.enabled ? 'разрешён' : '<span class="badge bad">отключён</span>'}</td>
      <td class="muted">${u.last_login_ts ? `<span title="${fmtTime(u.last_login_ts)}">${ago(u.last_login_ts)}</span>` : '—'}</td>
      <td class="users-act">${u.system ? '' : `<button class="btn sm" data-edit="${esc(u.login)}">Изменить</button>`}</td></tr>`).join('')
      || '<tr><td colspan="9" class="muted">Пользователей нет</td></tr>';
  }

  async function load() {
    const j = await api('/api/users.php', { action: 'list' });
    users = j.users; roles = j.roles; me = j.me; minPass = j.min_pass; devices = j.devices;
    render();
  }

  /* ---------- Доступ к устройствам (права хранятся по реле: выбор применяется ко всем реле устройства) ---------- */
  const MODES = [['', 'Нет'], ['view', 'Просмотр'], ['control', 'Управление']];
  function renderPerms(perms) {
    $('#perm-ids').value = ''; $('#perm-ids-note').className = 'hint';
    $('#perm-ids-note').textContent = 'через запятую; диапазон — через дефис: 1-10 — устройства с 1 по 10';
    $('#perm-list').innerHTML = devices.length ? devices.map(d => {
      const cur = devMode(perms?.[d.id]);
      return `<label class="perm-dev-row"><span class="perm-dev-name" title="${esc(d.name)}">${esc(d.name)}</span><span class="mono muted">#${d.id}</span>
        <select class="input" data-dev="${d.id}">${MODES.map(([v, t]) => `<option value="${v}" ${v === cur ? 'selected' : ''}>${t}</option>`).join('')}</select></label>`;
    }).join('') : '<div class="empty" style="padding:20px">Устройств пока нет — сначала добавьте устройство на главной странице.</div>';
    updSummary();
  }
  /** {устройство: {реле: режим}} — режим устройства на все его реле (1–4 и замок платы ver_5_1) */
  function collectPerms() {
    const out = {};
    $$('[data-dev]').forEach(sel => {
      if (!sel.value) return;
      const d = devices.find(x => x.id === +sel.dataset.dev); if (!d) return;
      out[d.id] = Object.fromEntries(d.relays.map(r => [r.num, sel.value]));
    });
    return out;
  }
  const updSummary = () => { $('#perm-summary').textContent = permSummary(collectPerms()); };
  $('#perm-list').addEventListener('change', updSummary);
  $$('[data-all-perm]').forEach(b => b.addEventListener('click', () => {
    $$('[data-dev]').forEach(s => { s.value = b.dataset.allPerm; }); updSummary();
  }));
  /** «1, 3, 5-10» → номера (как на сервере) или текст ошибки */
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
    return [...out];
  }
  function applyIds() {
    const note = $('#perm-ids-note'), v = $('#perm-ids').value.trim();
    if (!v) return;
    const ids = parseIds(v);
    if (typeof ids === 'string') { note.className = 'hint bad-text'; note.textContent = ids; return; }
    const mode = $('#perm-ids-mode').value, done = [], missing = [];
    for (const id of ids) {
      const sel = $(`[data-dev="${id}"]`);
      if (sel) { sel.value = mode; done.push(id); } else missing.push(id);
    }
    note.className = 'hint';
    note.textContent = `«${MODES.find(m => m[0] === mode)[1]}» — устройств: ${done.length}`
      + (missing.length ? `; нет в панели: ${missing.slice(0, 20).join(', ')}${missing.length > 20 ? ' …' : ''}` : '');
    updSummary();
  }
  $('#perm-ids-apply').addEventListener('click', applyIds);
  $('#perm-ids').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); applyIds(); } });

  /* ---------- Нажатия «Перезапуск реле» и «Перезагрузить устройство» ---------- */
  function clickCell(u, key) {
    const c = u[key];
    if (!c.total) return '<span class="muted">0</span>';
    return `<button class="link-btn" data-clicks="${esc(u.login)}" title="за 30 дней: ${c.d30} · сегодня: ${c.today}${c.last_ts ? ' · последнее: ' + fmtTime(c.last_ts) : ''}">
      <b>${c.total}</b></button> <span class="muted" style="font-size:12px">· 30 дн ${c.d30} · сегодня ${c.today}</span>`;
  }
  const CMD_NAME = { 3: 'Перезапуск реле', 5: 'Перезагрузка устройства' };
  async function openClicks(login) {
    const u = users.find(x => x.login === login); if (!u) return;
    $('#clicks-title').textContent = `Нажатия: ${login}${u.name ? ' (' + u.name + ')' : ''}`;
    $('#clicks-list').innerHTML = '<span class="muted">Загрузка…</span>';
    $('#clicks-dlg').showModal();
    try {
      const rows = (await api('/api/users.php', { action: 'clicks', login })).clicks;
      const sum = (k, t) => `${t}: всего <b>${u[k].total}</b> · за 30 дней ${u[k].d30} · сегодня ${u[k].today}`;
      $('#clicks-list').innerHTML = `<div style="font-size:13px;margin:10px 0">${sum('restarts', 'Перезапуски реле')}<br>${sum('reboots', 'Перезагрузки устройств')}</div>
        <div class="table-wrap"><table class="tbl clicks-tbl"><thead><tr><th>Кнопка</th><th>Устройство</th><th>Реле</th><th>Всего</th><th>30 дней</th><th>Последнее</th></tr></thead><tbody>
        ${rows.map(r => `<tr><td>${CMD_NAME[r.command] || r.command}</td><td>${esc(r.device_name)} <span class="mono muted">#${r.device_id}</span></td>
          <td>${r.command === 5 ? '—' : r.relay_num ? esc(r.relay_name || 'Реле ' + r.relay_num) + ` <span class="muted">(${r.relay_num})</span>` : 'все реле'}</td>
          <td><b>${r.total}</b></td><td>${r.d30}</td><td class="muted nowrap">${fmtTime(r.last_ts)}</td></tr>`).join('')}</tbody></table></div>`;
    } catch (e) { $('#clicks-list').innerHTML = `<span class="muted">${esc(e.message)}</span>`; }
  }
  $('#users').addEventListener('click', e => { const b = e.target.closest('[data-clicks]'); if (b) openClicks(b.dataset.clicks); });
  $('#clicks-dlg').addEventListener('click', e => { if (e.target.closest('[data-close]')) $('#clicks-dlg').close(); });

  /* ---------- Карточка пользователя ---------- */
  const dlg = $('#user-dlg'), form = $('#user-form'), F = k => form.elements[k];
  const syncRole = () => $('#perm-box').classList.toggle('hidden', F('role').value === 'admin');
  F('role').addEventListener('change', syncRole);
  function genPass() {
    const abc = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', a = new Uint32Array(12);
    crypto.getRandomValues(a);
    return Array.from(a, x => abc[x % abc.length]).join('');
  }
  function open(u) {
    editing = u || null;
    form.reset(); $('#user-result').className = 'result'; $('#user-result').textContent = '';
    $('#user-title').textContent = u ? `Пользователь ${u.login}` : 'Новый пользователь';
    $('#user-submit').textContent = u ? 'Сохранить' : 'Добавить';
    F('login').readOnly = !!u;
    $('#f-enabled').classList.toggle('hidden', !u);
    $('#b-del').classList.toggle('hidden', !u || u.login === me);
    F('password').required = !u;
    F('password').minLength = minPass;
    $('#pass-label').textContent = u ? 'Новый пароль' : 'Пароль';
    $('#pass-hint').textContent = (u ? 'Оставьте пустым, чтобы не менять. ' : '') + `Не короче ${minPass} символов. Передайте пароль пользователю — после входа он сможет сменить его в меню.`;
    const self = u && u.login === me;
    F('role').disabled = !!self; F('enabled').disabled = !!self;
    if (u) { F('login').value = u.login; F('name').value = u.name; F('role').value = u.role; F('enabled').value = u.enabled ? '1' : '0'; }
    else { F('password').value = genPass(); F('role').value = 'user'; }
    renderPerms(u ? u.perms : {});
    syncRole();
    dlg.showModal();
    (u ? F('name') : F('login')).focus();
  }

  $('#b-add').addEventListener('click', () => open());
  $('#users').addEventListener('click', e => {
    const b = e.target.closest('[data-edit]'); if (!b) return;
    open(users.find(u => u.login === b.dataset.edit));
  });
  $('#b-gen').addEventListener('click', () => { F('password').value = genPass(); });

  form.addEventListener('submit', async e => {
    if (e.submitter && e.submitter.value === 'cancel') return;
    e.preventDefault();
    const role = editing && editing.login === me ? editing.role : F('role').value;
    const p = { action: editing ? 'update' : 'add', login: F('login').value.trim(), name: F('name').value.trim(),
                role, password: F('password').value, perms: JSON.stringify(role === 'admin' ? {} : collectPerms()) };
    if (editing) p.enabled = editing.login === me ? '1' : F('enabled').value;
    const btn = $('#user-submit'); btn.disabled = true;
    try {
      await api('/api/users.php', p, true);
      dlg.close();
      toast(editing ? `Пользователь ${p.login} сохранён` : `Пользователь ${p.login} добавлен`, 'ok');
      load().catch(() => {});
    } catch (er) { $('#user-result').className = 'result err'; $('#user-result').textContent = er.message; }
    finally { btn.disabled = false; }
  });

  $('#b-del').addEventListener('click', async () => {
    if (!editing || !confirm(`Удалить пользователя ${editing.login}?\nОн больше не сможет войти в панель.`)) return;
    try {
      await api('/api/users.php', { action: 'delete', login: editing.login }, true);
      dlg.close(); toast(`Пользователь ${editing.login} удалён`, 'ok'); load().catch(() => {});
    } catch (er) { $('#user-result').className = 'result err'; $('#user-result').textContent = er.message; }
  });

  load().catch(e => { $('#users').innerHTML = `<tr><td colspan="9">${esc(e.message)}</td></tr>`; });
})();
