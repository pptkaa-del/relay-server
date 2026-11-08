/* Монитор → «Переадресация…»: правила пересылки MQTT-сообщений между брокерами */
'use strict';
(() => {
  const { $, esc, api, ago, toast, ICONS } = App;
  let rules = [], brokers = [];
  const dlg = $('#forwards'), edit = $('#fwd-edit'), ff = $('#ff');

  const statusOf = r => {
    if (!r.enabled) return '<span class="badge">выключено</span>';
    if (!r.src_enabled || !r.dst_enabled) return '<span class="badge warn">брокер отключён</span>';
    if (r.last_error) return `<span class="badge bad" title="${esc(r.last_error)}">ошибка</span>`;
    return '<span class="badge success">работает</span>';
  };

  function render() {
    $('#fwd-list').innerHTML = rules.length ? `<div class="table-wrap"><table class="tbl fwd-tbl">
      <thead><tr><th>Правило</th><th>Откуда → куда</th><th>Переслано</th><th>Состояние</th><th></th></tr></thead><tbody>
      ${rules.map(r => `<tr>
        <td><b>${esc(r.name) || 'Правило #' + r.id}</b><div class="mono muted" style="font-size:12.5px">${esc(r.topic_filter)}${r.dst_prefix ? ' → ' + esc(r.dst_prefix) + '…' : ''}</div></td>
        <td>${esc(r.src_name)} → ${esc(r.dst_name)}</td>
        <td class="nowrap">${r.fwd_count}<div class="muted" style="font-size:12px">${r.last_fwd_ts ? ago(r.last_fwd_ts) : '—'}</div></td>
        <td>${statusOf(r)}${r.last_error ? `<div class="muted" style="font-size:12px;max-width:220px">${esc(r.last_error)}</div>` : ''}</td>
        <td class="users-act"><button class="btn sm" data-fedit="${r.id}">Изменить</button>
          <button class="btn sm ghost" data-ftoggle="${r.id}" data-en="${r.enabled ? 0 : 1}">${r.enabled ? 'Выкл' : 'Вкл'}</button></td></tr>`).join('')}
      </tbody></table></div>`
      : `<div class="empty" style="padding:24px">${ICONS.send}<div>Правил переадресации пока нет</div></div>`;
  }
  async function load() {
    const j = await api('/api/forwards.php', { action: 'list' });
    rules = j.forwards; brokers = j.brokers; render();
  }

  function open(r) {
    ff.reset(); $('#ff-result').className = 'result'; $('#ff-result').textContent = '';
    const opts = brokers.map(b => `<option value="${b.id}">${esc(b.name)}${+b.is_local ? ' (свой)' : ''}${+b.enabled ? '' : ' — отключён'}</option>`).join('');
    ff.elements.src_broker_id.innerHTML = opts; ff.elements.dst_broker_id.innerHTML = opts;
    $('#ff-title').textContent = r ? 'Правило переадресации' : 'Новое правило';
    $('#ff-del').classList.toggle('hidden', !r);
    ff.elements.id.value = r ? r.id : '';
    if (r) {
      for (const k of ['name', 'topic_filter', 'dst_prefix']) ff.elements[k].value = r[k];
      ff.elements.src_broker_id.value = r.src_broker_id; ff.elements.dst_broker_id.value = r.dst_broker_id;
      ff.elements.keep_retain.checked = !!r.keep_retain; ff.elements.enabled.checked = !!r.enabled;
    } else {
      // по умолчанию: из собственного брокера в первый сторонний
      const ext = brokers.find(b => !+b.is_local);
      if (ext) ff.elements.dst_broker_id.value = ext.id;
    }
    edit.showModal();
  }

  $('#b-forwards').addEventListener('click', async () => {
    dlg.showModal();
    try { await load(); } catch (e) { $('#fwd-list').innerHTML = `<div class="empty">${esc(e.message)}</div>`; }
  });
  $('#fwd-add').addEventListener('click', () => open(null));
  dlg.addEventListener('click', async e => {
    if (e.target.closest('[data-close]')) return dlg.close();
    const ed = e.target.closest('[data-fedit]'), tg = e.target.closest('[data-ftoggle]');
    if (ed) open(rules.find(r => r.id === +ed.dataset.fedit));
    if (tg) { try { await api('/api/forwards.php', { action: 'toggle', id: tg.dataset.ftoggle, enabled: tg.dataset.en }, true); await load(); } catch (er) { toast(er.message, 'err'); } }
  });
  ff.addEventListener('submit', async e => {
    if (e.submitter && e.submitter.value === 'cancel') return;
    e.preventDefault();
    const f = ff.elements;
    const p = { action: 'save', id: f.id.value, name: f.name.value.trim(), src_broker_id: f.src_broker_id.value, dst_broker_id: f.dst_broker_id.value,
                topic_filter: f.topic_filter.value.trim(), dst_prefix: f.dst_prefix.value.trim(),
                keep_retain: f.keep_retain.checked ? '1' : '0', enabled: f.enabled.checked ? '1' : '0' };
    try {
      await api('/api/forwards.php', p, true);
      edit.close(); toast('Правило сохранено. Сервер применит его в течение 10 секунд', 'ok'); load().catch(() => {});
    } catch (er) { $('#ff-result').className = 'result err'; $('#ff-result').textContent = er.message; }
  });
  $('#ff-del').addEventListener('click', async () => {
    const id = ff.elements.id.value;
    if (!id || !confirm('Удалить правило переадресации?')) return;
    try { await api('/api/forwards.php', { action: 'delete', id }, true); edit.close(); load().catch(() => {}); }
    catch (er) { $('#ff-result').className = 'result err'; $('#ff-result').textContent = er.message; }
  });
  App.every(10000, async () => { if (dlg.open) await load(); });
})();
