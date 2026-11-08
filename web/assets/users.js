/* Страница «Пользователи» */
'use strict';
(() => {
  const { $, $$, esc, api, fmtTime, ago, toast } = App;
  const ROLE_BADGE = { admin: 'info', user: 'success' };
  let users = [], roles = {}, me = '', minPass = 6, devices = [], editing = null;

  /** «3 реле на 2 устройствах (управление 2)» */
  function permSummary(perms) {
    let total = 0, ctl = 0, devs = 0;
    for (const relays of Object.values(perms || {})) {
      const v = Object.values(relays); if (!v.length) continue;
      devs++; total += v.length; ctl += v.filter(m => m === 'control').length;
    }
    return total ? `${total} реле на ${devs} устр. · управление ${ctl}` : 'нет доступа';
  }

  function render() {
    $('#users').innerHTML = users.map(u => `<tr class="${u.enabled ? '' : 'disabled-row'}">
      <td><b class="mono">${esc(u.login)}</b>${u.login === me ? ' <span class="muted">(вы)</span>' : ''}</td>
      <td>${esc(u.name) || '<span class="muted">—</span>'}</td>
      <td><span class="badge ${ROLE_BADGE[u.role] ?? ''}">${esc(roles[u.role] || u.role)}</span></td>
      <td class="muted">${u.role === 'admin' ? 'все' : permSummary(u.perms)}</td>
      <td>${u.system ? '<span class="muted">создан при установке</span>' : u.enabled ? 'разрешён' : '<span class="badge bad">отключён</span>'}</td>
      <td class="muted">${u.last_login_ts ? `<span title="${fmtTime(u.last_login_ts)}">${ago(u.last_login_ts)}</span>` : '—'}</td>
      <td class="users-act">${u.system ? '' : `<button class="btn sm" data-edit="${esc(u.login)}">Изменить</button>`}</td></tr>`).join('')
      || '<tr><td colspan="7" class="muted">Пользователей нет</td></tr>';
  }

  async function load() {
    const j = await api('/api/users.php', { action: 'list' });
    users = j.users; roles = j.roles; me = j.me; minPass = j.min_pass; devices = j.devices;
    render();
  }

  /* ---------- Права на реле ---------- */
  const MODES = [['', 'Нет'], ['view', 'Просмотр'], ['control', 'Управление']];
  function renderPerms(perms) {
    $('#perm-list').innerHTML = devices.length ? devices.map(d => `<div class="perm-dev" data-dev="${d.id}">
        <div class="perm-dev-head"><b title="${esc(d.name)}">${esc(d.name)}</b><span class="mono muted">#${d.id}</span>
          <select class="input perm-dev-all" data-dev-all="${d.id}" title="Все реле устройства" style="width:auto;height:30px;font-size:12.5px">
            <option value="">— все реле —</option>${MODES.map(([v, t]) => `<option value="${v}:">${t}</option>`).join('')}</select></div>
        <div class="perm-relays">${d.relays.map(r => {
          const cur = perms?.[d.id]?.[r.num] || '';
          return `<label class="perm-relay"><span title="${esc(r.name)}">${r.lock ? '🔒 ' : ''}${esc(r.name)}</span>
            <select class="input" data-perm="${d.id}:${r.num}">${MODES.map(([v, t]) => `<option value="${v}" ${v === cur ? 'selected' : ''}>${t}</option>`).join('')}</select></label>`;
        }).join('')}</div></div>`).join('')
      : '<div class="empty" style="padding:20px">Устройств пока нет — сначала добавьте устройство на главной странице.</div>';
    updSummary();
  }
  function collectPerms() {
    const out = {};
    $$('[data-perm]').forEach(sel => {
      if (!sel.value) return;
      const [dev, num] = sel.dataset.perm.split(':');
      (out[dev] = out[dev] || {})[num] = sel.value;
    });
    return out;
  }
  const updSummary = () => { $('#perm-summary').textContent = permSummary(collectPerms()); };
  $('#perm-list').addEventListener('change', e => {
    const all = e.target.closest('[data-dev-all]');
    if (all && all.value) {
      const mode = all.value.replace(':', '');
      $$(`[data-perm^="${all.dataset.devAll}:"]`).forEach(s => { s.value = mode; });
      all.value = '';
    }
    updSummary();
  });
  $$('[data-all-perm]').forEach(b => b.addEventListener('click', () => {
    $$('[data-perm]').forEach(s => { s.value = b.dataset.allPerm; }); updSummary();
  }));

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

  load().catch(e => { $('#users').innerHTML = `<tr><td colspan="7">${esc(e.message)}</td></tr>`; });
})();
