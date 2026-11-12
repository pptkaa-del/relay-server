/* «Пользователи» → «Пользователи API»: ключи для сторонних систем */
'use strict';
(() => {
  const { $, esc, api, fmtTime, ago, toast } = App;
  let clients = [], baseUrl = '', editing = null;
  const dlg = $('#api-dlg'), form = $('#api-form'), F = k => form.elements[k];

  function render() {
    $('#api-base').textContent = baseUrl;
    $('#api-clients').innerHTML = clients.map(c => `<tr class="${c.enabled ? '' : 'disabled-row'}">
      <td><b class="mono">${esc(c.name)}</b>${c.note ? `<div class="muted" style="font-size:12.5px">${esc(c.note)}</div>` : ''}</td>
      <td class="mono muted">…${esc(c.token_hint)}</td>
      <td>${c.can_control ? '<span class="badge info">чтение и управление</span>' : '<span class="badge">только чтение</span>'}${c.enabled ? '' : ' <span class="badge bad">отключён</span>'}</td>
      <td class="mono">${c.devices === '*' ? 'все' : esc(c.devices)}</td>
      <td class="mono muted">${c.ip_allow ? esc(c.ip_allow) : 'любые'}</td>
      <td class="muted">${c.requests} <span title="ограничение в минуту">· ${c.rate_limit}/мин</span></td>
      <td class="muted">${c.last_used_ts ? `<span title="${fmtTime(c.last_used_ts)}${c.last_ip ? ' · ' + esc(c.last_ip) : ''}">${ago(c.last_used_ts)}</span>` : 'не было'}</td>
      <td class="users-act"><button class="btn sm" data-api-edit="${c.id}">Изменить</button></td></tr>`).join('')
      || '<tr><td colspan="8" class="muted">Пользователей API нет. Нажмите «Добавить пользователя API», чтобы выдать ключ сторонней системе.</td></tr>';
  }

  async function load() {
    const j = await api('/api/api_clients.php', { action: 'list' });
    clients = j.clients; baseUrl = j.base_url;
    render();
  }

  function showKey(name, token) {
    $('#api-key-name').textContent = name;
    $('#api-key').value = token;
    $('#api-key-example').textContent = `curl -H "Authorization: Bearer ${token}" ${baseUrl}/devices.php`;
    $('#api-key-dlg').showModal();
    $('#api-key').select();
  }
  $('#b-api-copy').addEventListener('click', async () => {
    const v = $('#api-key').value;
    try { await navigator.clipboard.writeText(v); toast('Ключ скопирован', 'ok'); }
    catch (e) { $('#api-key').select(); document.execCommand('copy'); toast('Ключ скопирован', 'ok'); }
  });
  $('#api-key-dlg').addEventListener('click', e => { if (e.target.closest('[data-close]')) $('#api-key-dlg').close(); });

  function open(c) {
    editing = c;
    form.reset();
    $('#api-result').className = 'result'; $('#api-result').textContent = '';
    $('#api-title').textContent = c ? `Пользователь API ${c.name}` : 'Новый пользователь API';
    $('#api-submit').textContent = c ? 'Сохранить' : 'Создать';
    $('#b-api-del').classList.toggle('hidden', !c);
    $('#b-api-regen').classList.toggle('hidden', !c);
    $('#api-f-enabled').classList.toggle('hidden', !c);
    if (c) {
      F('name').value = c.name; F('can_control').value = c.can_control ? '1' : '0'; F('devices').value = c.devices;
      F('ip_allow').value = c.ip_allow; F('rate_limit').value = c.rate_limit; F('enabled').value = c.enabled ? '1' : '0'; F('note').value = c.note;
    }
    dlg.showModal(); F('name').focus();
  }
  $('#b-api-add').addEventListener('click', () => open(null));
  $('#api-clients').addEventListener('click', e => {
    const b = e.target.closest('[data-api-edit]'); if (!b) return;
    open(clients.find(c => c.id === +b.dataset.apiEdit));
  });

  form.addEventListener('submit', async e => {
    if (e.submitter && e.submitter.value === 'cancel') return;
    e.preventDefault();
    const p = { action: editing ? 'update' : 'add' };
    for (const k of ['name', 'can_control', 'devices', 'ip_allow', 'rate_limit', 'enabled', 'note']) p[k] = F(k).value.trim();
    if (editing) p.id = editing.id;
    const btn = $('#api-submit'); btn.disabled = true;
    try {
      const j = await api('/api/api_clients.php', p, true);
      dlg.close();
      await load();
      if (j.token) showKey(p.name, j.token); else toast('Сохранено', 'ok');
    } catch (er) { $('#api-result').className = 'result err'; $('#api-result').textContent = er.message; }
    finally { btn.disabled = false; }
  });
  $('#b-api-regen').addEventListener('click', async () => {
    if (!editing || !confirm(`Выдать новый ключ пользователю API «${editing.name}»? Старый ключ сразу перестанет работать.`)) return;
    try {
      const j = await api('/api/api_clients.php', { action: 'regen', id: editing.id }, true);
      dlg.close(); await load(); showKey(editing.name, j.token);
    } catch (er) { toast(er.message, 'err'); }
  });
  $('#b-api-del').addEventListener('click', async () => {
    if (!editing || !confirm(`Удалить пользователя API «${editing.name}»? Сторонняя система с его ключом потеряет доступ.`)) return;
    try { await api('/api/api_clients.php', { action: 'delete', id: editing.id }, true); dlg.close(); toast('Удалено', 'ok'); load(); }
    catch (er) { toast(er.message, 'err'); }
  });

  load().catch(e => { $('#api-clients').innerHTML = `<tr><td colspan="8" class="muted">${esc(e.message)}</td></tr>`; });
})();
