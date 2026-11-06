/* Страница «Пользователи» */
'use strict';
(() => {
  const { $, esc, api, fmtTime, ago, toast } = App;
  const ROLE_BADGE = { admin: 'info', operator: 'success', viewer: '' };
  let users = [], roles = {}, me = '', minPass = 6, editing = null;

  function render() {
    $('#users').innerHTML = users.map(u => `<tr class="${u.enabled ? '' : 'disabled-row'}">
      <td><b class="mono">${esc(u.login)}</b>${u.login === me ? ' <span class="muted">(вы)</span>' : ''}</td>
      <td>${esc(u.name) || '<span class="muted">—</span>'}</td>
      <td><span class="badge ${ROLE_BADGE[u.role] ?? ''}">${esc(roles[u.role] || u.role)}</span></td>
      <td>${u.system ? '<span class="muted">создан при установке</span>' : u.enabled ? 'разрешён' : '<span class="badge bad">отключён</span>'}</td>
      <td class="muted">${u.last_login_ts ? `<span title="${fmtTime(u.last_login_ts)}">${ago(u.last_login_ts)}</span>` : '—'}</td>
      <td class="users-act">${u.system ? '' : `<button class="btn sm" data-edit="${esc(u.login)}">Изменить</button>`}</td></tr>`).join('')
      || '<tr><td colspan="6" class="muted">Пользователей нет</td></tr>';
  }

  async function load() {
    const j = await api('/api/users.php', { action: 'list' });
    users = j.users; roles = j.roles; me = j.me; minPass = j.min_pass;
    render();
  }

  const dlg = $('#user-dlg'), form = $('#user-form'), F = k => form.elements[k];
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
    else F('password').value = genPass();
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
    const p = { action: editing ? 'update' : 'add', login: F('login').value.trim(), name: F('name').value.trim(),
                role: editing && editing.login === me ? editing.role : F('role').value, password: F('password').value };
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

  load().catch(e => { $('#users').innerHTML = `<tr><td colspan="6">${esc(e.message)}</td></tr>`; });
})();
