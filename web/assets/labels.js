/* Страница «Наклейки»: картинка с серийным номером по шаблону */
'use strict';
(() => {
  const { $, esc, api, toast } = App;
  const W = 1942, H = 833;                 // размер шаблона, px
  // Положение строки «SN» в шаблоне (подобрано по исходной наклейке)
  const SN_X = 874, SN_BASELINE = 711, SN_MAX_RIGHT = 1840, SN_FONT_PX = 113;
  const SN_RE = /^[A-Za-z0-9-]{1,32}$/;

  const form = $('#lf'), F = k => form.elements[k];
  const res = (cls, t) => { const r = $('#lres'); r.className = 'result ' + cls; r.innerHTML = t; };
  const printArea = $('#print-area');
  document.body.appendChild(printArea);    // при печати видна только эта область

  let template = null;
  const ready = (async () => {
    const img = new Image(); img.src = '/assets/label/label_template.png?v=' + Date.now().toString(36).slice(0, 5);
    await img.decode(); template = img;
    try {
      const font = new FontFace('CarlitoLabel', 'url(/assets/label/Carlito-Regular.ttf)');
      await font.load(); document.fonts.add(font);
    } catch (e) { console.warn('шрифт Carlito не загрузился', e); }
  })();

  function drawLabel(canvas, sn) {
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
    ctx.drawImage(template, 0, 0, W, H);
    const text = 'SN:  ' + sn;
    let size = SN_FONT_PX;
    ctx.fillStyle = '#000'; ctx.textBaseline = 'alphabetic';
    for (; size > 40; size -= 2) {        // длинный номер — уменьшаем шрифт, чтобы влез в рамку
      ctx.font = `${size}px CarlitoLabel, Calibri, Carlito, Arial, sans-serif`;
      const m = ctx.measureText(text);
      if (SN_X + m.width <= SN_MAX_RIGHT) break;
    }
    const m = ctx.measureText(text);
    ctx.fillText(text, SN_X + (m.actualBoundingBoxLeft || 0), SN_BASELINE);
  }
  function labelPng(sn) {
    const c = document.createElement('canvas'); c.width = W; c.height = H;
    drawLabel(c, sn); return c.toDataURL('image/png');
  }

  // серия: увеличиваем числовой хвост номера с сохранением длины
  function series(sn, count) {
    const m = sn.match(/^(.*?)(\d+)$/);
    if (!m) return count > 1 ? null : [sn];
    const [, prefix, digits] = m; const out = [];
    let n = BigInt(digits);
    for (let i = 0; i < count; i++, n++) {
      const s = n.toString().padStart(digits.length, '0');
      if (s.length > digits.length) break;  // переполнение разрядов
      out.push(prefix + s);
    }
    return out;
  }
  function current() {
    const sn = F('sn').value.trim();
    const count = Math.max(1, Math.min(500, +F('count').value || 1));
    if (!SN_RE.test(sn)) return { error: 'Серийный номер: латиница, цифры и дефис, до 32 символов' };
    const list = series(sn, count);
    if (!list) return { error: 'Для серии номер должен заканчиваться цифрами' };
    return { sn, list };
  }

  let checkTimer, dups = new Set();
  async function refresh() {
    await ready;
    const c = current();
    const w = +F('width').value || 70;
    $('#pv-size').textContent = `${w} × ${(w * H / W).toFixed(1)} мм при печати`;
    if (c.error) { res('err', c.error); return; }
    res('', '');
    drawLabel($('#preview'), c.list[0]);
    clearTimeout(checkTimer);
    checkTimer = setTimeout(async () => {
      try {
        const j = await api('/api/labels.php', { action: 'check', sn: c.list.join(',') });
        dups = new Set(j.exists.map(r => r.sn));
        if (dups.size) res('err', `Уже выпущены: ${[...dups].slice(0, 5).map(esc).join(', ')}${dups.size > 5 ? ' и ещё ' + (dups.size - 5) : ''}`);
        renderSeries(c.list);
      } catch (e) { renderSeries(c.list); }
    }, 300);
    renderSeries(c.list);
  }
  function renderSeries(list) {
    const card = $('#series-card');
    card.classList.toggle('hidden', list.length < 2);
    if (list.length < 2) return;
    $('#series-info').textContent = `${list.length} шт.: ${list[0]} … ${list[list.length - 1]}`;
    const shown = list.slice(0, 60);
    $('#series').innerHTML = shown.map(sn => `<figure><img src="${labelPng(sn)}" alt="SN ${esc(sn)}">
      <figcaption><span class="mono ${dups.has(sn) ? 'dup' : ''}">${esc(sn)}${dups.has(sn) ? ' — уже выпущен' : ''}</span>
      <a href="#" data-dl="${esc(sn)}">PNG</a></figcaption></figure>`).join('') +
      (list.length > shown.length ? `<p class="muted">…и ещё ${list.length - shown.length} (в предпросмотре показаны первые ${shown.length}, печатаются все)</p>` : '');
  }

  function download(sn) {
    const a = document.createElement('a');
    a.href = labelPng(sn); a.download = `label_SN_${sn}.png`;
    document.body.appendChild(a); a.click(); a.remove();
  }
  async function logIssued(list) {
    if (!F('log').checked) return;
    try {
      const j = await api('/api/labels.php', { action: 'add', sns: list.join(','), note: F('note').value }, true);
      toast(`В журнал записано: ${j.added}${j.skipped ? `, уже были: ${j.skipped}` : ''}`, 'ok');
      loadJournal();
    } catch (e) { toast('Журнал: ' + e.message, 'err'); }
  }
  function confirmDups(list) {
    const d = list.filter(sn => dups.has(sn));
    return !d.length || confirm(`Номера уже выпускались: ${d.slice(0, 10).join(', ')}${d.length > 10 ? '…' : ''}\nВсё равно продолжить?`);
  }

  $('#b-png').addEventListener('click', async () => {
    await ready; const c = current(); if (c.error) return res('err', c.error);
    if (!confirmDups(c.list)) return;
    if (c.list.length > 20 && !confirm(`Скачать ${c.list.length} файлов по одному? Для серии удобнее «Печать».`)) return;
    for (const sn of c.list) { download(sn); await new Promise(r => setTimeout(r, 250)); }
    logIssued(c.list);
  });
  $('#b-print').addEventListener('click', async () => {
    await ready; const c = current(); if (c.error) return res('err', c.error);
    if (!confirmDups(c.list)) return;
    const w = Math.max(20, Math.min(200, +F('width').value || 70));
    printArea.innerHTML = c.list.map(sn => `<img src="${labelPng(sn)}" style="width:${w}mm;height:${(w * H / W).toFixed(2)}mm" alt="">`).join('');
    await Promise.all([...printArea.querySelectorAll('img')].map(i => i.decode().catch(() => {})));
    window.print();
    logIssued(c.list);
  });
  $('#series').addEventListener('click', e => {
    const a = e.target.closest('[data-dl]'); if (!a) return;
    e.preventDefault(); download(a.dataset.dl);
  });
  $('#b-next').addEventListener('click', async () => {
    try { F('sn').value = (await api('/api/labels.php', { action: 'list' })).next; refresh(); } catch (e) { toast(e.message, 'err'); }
  });
  form.addEventListener('input', refresh);

  // ---------- Журнал ----------
  async function loadJournal() {
    const j = await api('/api/labels.php', { action: 'list', q: $('#lj-q').value.trim() });
    $('#lj-total').textContent = `всего ${j.total}`;
    $('#lj').innerHTML = j.rows.length ? j.rows.map(r => `<tr><td class="mono">${esc(r.sn)}${r.note ? `<div class="muted" style="font-size:12px">${esc(r.note)}</div>` : ''}</td>
      <td class="nowrap">${esc(r.created_at)}<div class="muted" style="font-size:12px">${esc(r.user_login || '')}</div></td>
      <td class="nowrap"><a href="#" data-use="${esc(r.sn)}">открыть</a> · <a href="#" class="danger-text" data-del="${r.id}" data-sn="${esc(r.sn)}">удалить</a></td></tr>`).join('')
      : '<tr><td colspan="3" class="muted">Записей нет</td></tr>';
    return j;
  }
  $('#lj').addEventListener('click', async e => {
    const u = e.target.closest('[data-use]'), d = e.target.closest('[data-del]');
    if (u) { e.preventDefault(); F('sn').value = u.dataset.use; F('count').value = 1; refresh(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    if (d) {
      e.preventDefault();
      if (!confirm(`Удалить номер ${d.dataset.sn} из журнала?`)) return;
      try { await api('/api/labels.php', { action: 'delete', id: d.dataset.del }, true); loadJournal(); refresh(); } catch (er) { toast(er.message, 'err'); }
    }
  });
  let qTimer; $('#lj-q').addEventListener('input', () => { clearTimeout(qTimer); qTimer = setTimeout(loadJournal, 300); });

  loadJournal().then(j => { if (!F('sn').value) F('sn').value = j.next; refresh(); }).catch(() => { F('sn').value = '202608185060'; refresh(); });
})();
