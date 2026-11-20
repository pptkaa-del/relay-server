/* Монитор → «Симуляция устройств» */
'use strict';
(() => {
  const { $, esc, api, ago, toast } = App;
  const form = $('#sim-form');
  if (!form) return;
  let sims = [], brokersLoaded = false;

  function render() {
    $('#sim-list').innerHTML = sims.length ? `<div class="sim-head">
        <b>Работает: ${sims.length}</b><span class="spacer"></span><button class="btn sm ghost danger-text" data-stop-all>Остановить все</button></div>
      <div class="table-wrap"><table class="tbl sim-tbl"><thead><tr><th>Устройство</th><th>Реле (переключить на месте)</th><th>stat</th><th>Команды</th><th></th></tr></thead><tbody>
      ${sims.map(s => `<tr>
        <td><b class="mono">#${s.device_id}</b><div class="muted" style="font-size:12px">${esc(s.broker_name)} · версия ${s.version === 11 ? '1-1' : s.version} · ${s.mirror_id ? 'вслед за источником' : s.interval_sec + ' с'}</div>
          ${s.mirror_id ? `<span class="badge info sim-mirror" title="Каждый stat устройства #${s.mirror_id} публикуется от имени #${s.device_id}">повторяет #${s.mirror_id}</span>` : ''}</td>
        <td><div class="sim-relays">${s.relays.map((v, i) => `<button class="sim-relay ${v ? 'on' : ''}" data-sim="${s.id}" data-what="relay${i + 1}"
              title="Реле ${i + 1}: ${v ? 'питание подано' : 'нет питания'} — нажмите, чтобы переключить без команды">${i + 1}</button>`).join('')}
            ${s.version === 3 ? `<button class="sim-relay door ${s.door_state ? 'open' : ''}" data-sim="${s.id}" data-what="door" title="Датчик двери">дверь ${s.door_state ? 'откр' : 'закр'}</button>
              <button class="sim-relay ${s.lock_state ? 'on' : ''}" data-sim="${s.id}" data-what="lock" title="Замок">замок</button>` : ''}</div></td>
        <td class="nowrap">${s.stat_count}<div class="muted" style="font-size:12px">${s.last_stat_ts ? ago(s.last_stat_ts) : 'ждёт подключения'}</div></td>
        <td>${s.cmd_count}${s.last_cmd ? `<div class="muted" style="font-size:12px">${esc(s.last_cmd)} · ${ago(s.last_cmd_ts)}</div>` : ''}</td>
        <td class="users-act"><button class="btn sm" data-sim-stat="${s.id}" title="Опубликовать stat сейчас">stat</button>
          ${s.mirror_id ? `<button class="btn sm" data-sim-mirror="${s.id}" data-clear="1" title="Перестать повторять данные #${s.mirror_id}: снова свой stat каждые ${s.interval_sec} с">Не повторять</button>`
            : `<button class="btn sm" data-sim-mirror="${s.id}" data-dev="${s.device_id}" title="Повторять данные другого (настоящего) устройства: его stat — от имени #${s.device_id}">Повторять…</button>`}
          <button class="btn sm ghost danger-text" data-sim-stop="${s.id}">Стоп</button></td></tr>`).join('')}
      </tbody></table></div>` : '<div class="muted" style="font-size:13px">Симуляций нет.</div>';
  }

  async function load() {
    const j = await api('/api/simulations.php', { action: 'list' });
    sims = j.sims;
    if (!brokersLoaded) {
      form.elements.broker_id.innerHTML = j.brokers.map(b => `<option value="${b.id}">${esc(b.name)}${+b.is_local ? ' (свой)' : ''}${+b.enabled ? '' : ' — отключён'}</option>`).join('');
      brokersLoaded = true;
    }
    render();
  }

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const res = $('#sim-result'), btn = form.querySelector('[type=submit]');
    const p = { action: 'start' };
    for (const k of ['devices', 'broker_id', 'interval_sec', 'version', 'mirror']) p[k] = form.elements[k].value.trim();
    btn.disabled = true;
    try {
      const j = await api('/api/simulations.php', p, true);
      res.className = 'result ok'; res.textContent = `Запущено: ${j.started.map(x => '#' + x).join(', ')}.`
        + (p.mirror ? ` Повторяют данные #${p.mirror}: первый stat — его последний, в течение 2–3 секунд.` : ' Первый stat — в течение 2–3 секунд.');
      form.elements.devices.value = ''; form.elements.mirror.value = '';
      load().catch(() => {});
    } catch (er) { res.className = 'result err'; res.textContent = er.message; }
    finally { btn.disabled = false; }
  });

  $('#sim-list').addEventListener('click', async e => {
    const t = e.target.closest('[data-what]'), st = e.target.closest('[data-sim-stat]'), sp = e.target.closest('[data-sim-stop]'), all = e.target.closest('[data-stop-all]');
    const mr = e.target.closest('[data-sim-mirror]');
    try {
      if (mr) {
        let source = '';
        if (!mr.dataset.clear) {
          source = (prompt(`Повторять данные какого устройства от имени #${mr.dataset.dev}? Укажите ID настоящего устройства:`) || '').trim();
          if (!source) return;
        }
        await api('/api/simulations.php', { action: 'mirror', id: mr.dataset.simMirror, source }, true);
        toast(source ? `#${mr.dataset.dev || ''} повторяет данные #${source}` : 'Повтор данных выключен', 'ok');
      }
      else if (t) { t.disabled = true; await api('/api/simulations.php', { action: 'set', id: t.dataset.sim, what: t.dataset.what }, true); }
      else if (st) { await api('/api/simulations.php', { action: 'stat', id: st.dataset.simStat }, true); toast('stat будет опубликован в течение 2 секунд', 'ok'); }
      else if (sp) { await api('/api/simulations.php', { action: 'stop', id: sp.dataset.simStop }, true); }
      else if (all) { if (!confirm('Остановить все симуляции?')) return; await api('/api/simulations.php', { action: 'stop', all: '1' }, true); }
      else return;
      load().catch(() => {});
    } catch (er) { toast(er.message, 'err'); }
  });

  load().catch(e => { $('#sim-list').innerHTML = `<div class="muted">${esc(e.message)}</div>`; });
  App.every(3000, load);
})();
