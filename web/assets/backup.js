/* Страница «Резервные копии» */
'use strict';
(() => {
  const { $, $$, esc, toast } = App;

  // ссылка на скачивание следует за выбранным вариантом
  $$('[name="bk-mode"]').forEach(r => r.addEventListener('change', () => {
    $('#bk-download').href = '/api/backup.php?action=download&mode=' + $('[name="bk-mode"]:checked').value;
  }));

  let token = null, devices = [];
  const res = $('#imp-result');
  const setRes = (cls, text) => { res.className = 'result ' + cls; res.textContent = text; };

  /** POST с файлом (App.api отправляет только поля формы) */
  async function post(body) {
    const r = await fetch('/api/backup.php', { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
    let j; try { j = await r.json(); } catch (e) { throw new Error('Ошибка сервера (' + r.status + ')' + (r.status === 413 ? ': файл слишком большой' : '')); }
    if (!r.ok || j.error) throw new Error(j.error || r.statusText);
    return j;
  }

  function render() {
    const n = devices.length, ex = devices.filter(d => d.exists).length;
    $('#imp-summary').innerHTML = `Найдено устройств: <b>${n}</b> · новых: <b>${n - ex}</b> · уже есть в панели: <b>${ex}</b>`;
    $('#imp-rows').innerHTML = devices.map(d => `<tr>
      <td><input type="checkbox" data-id="${d.id}" checked></td><td class="mono">${d.id}</td>
      <td>${esc(d.name)}</td><td class="muted">${esc(d.address || '—')}</td>
      <td>${d.exists ? '<span class="badge info">есть</span>' : '<span class="badge success">новое</span>'}</td></tr>`).join('');
    $('#imp-preview').classList.remove('hidden');
  }

  $('#imp-form').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target); fd.append('action', 'preview');
    const btn = $('#imp-check'); btn.disabled = true;
    $('#imp-preview').classList.add('hidden'); setRes('wait', 'Читаю файл…');
    try {
      const j = await post(fd);
      token = j.token; devices = j.devices;
      setRes('ok', `Файл прочитан: строк general_info — ${j.found.general_info}, devices — ${j.found.devices}. Выберите устройства и нажмите «Загрузить выбранные».`);
      render();
    } catch (er) { setRes('err', er.message); }
    finally { btn.disabled = false; }
  });

  $('#imp-preview').addEventListener('click', e => {
    const b = e.target.closest('[data-sel-all]'); if (!b) return;
    $$('#imp-rows [data-id]').forEach(cb => {
      const d = devices.find(x => x.id === +cb.dataset.id);
      cb.checked = b.dataset.selAll === '1' || (b.dataset.selAll === 'new' && d && !d.exists);
    });
  });

  $('#imp-go').addEventListener('click', async () => {
    const ids = $$('#imp-rows [data-id]').filter(cb => cb.checked).map(cb => cb.dataset.id);
    if (!ids.length) return setRes('err', 'Не выбрано ни одного устройства');
    const mode = $('#imp-mode').value;
    if (!confirm(`Загрузить устройств: ${ids.length}?` + (mode === 'all' ? '\nДанные уже существующих устройств будут заменены данными из файла.' : ''))) return;
    const fd = new FormData();
    fd.append('action', 'import'); fd.append('token', token); fd.append('mode', mode);
    fd.append('ids', ids.length === devices.length ? 'all' : ids.join(','));
    const btn = $('#imp-go'); btn.disabled = true; setRes('wait', 'Загружаю… новые устройства регистрируются в брокере, это может занять время');
    try {
      const j = await post(fd);
      setRes(j.no_mqtt.length ? 'err' : 'ok', `Готово: добавлено ${j.created.length}, обновлено ${j.updated.length}`
        + (j.skipped.length ? `, пропущено существующих ${j.skipped.length}` : '')
        + (j.no_mqtt.length ? `. Не удалось зарегистрировать в брокере: ${j.no_mqtt.join(', ')} — выполните на сервере sudo relay-device add <ID>` : ''));
      toast('Данные устройств загружены', 'ok');
      $('#imp-preview').classList.add('hidden'); token = null;
    } catch (er) { setRes('err', er.message); }
    finally { btn.disabled = false; }
  });
})();
