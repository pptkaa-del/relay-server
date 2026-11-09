/* Главная: карта устройств по координатам (Leaflet + OpenStreetMap) */
'use strict';
(() => {
  const { $, esc, ago } = App;
  const card = $('#map-card');
  if (!card || !window.L) return;

  /**
   * Цвет точки (по убыванию важности):
   *  фиолетовый — взлом двери (дверь открылась без команды «Открыть»);
   *  красный — нет данных (не на связи или ещё не выходило на связь);
   *  жёлтый — на связи, но все реле выключены (на реле нет питания);
   *  синий — реле 4 управляет замком и дверь открыта;
   *  зелёный — на связи (и дверь закрыта, если есть замок).
   */
  function status(d) {
    if (d.break_in) return ['purple', 'ВЗЛОМ ДВЕРИ'];
    if (!d.online) return ['red', d.state === 'never' ? 'ещё не выходило на связь' : d.state === 'power_off' ? 'отключено по питанию' : 'нет данных'];
    const rel = d.relays.filter(r => !r.lock);
    if (rel.length && rel.every(r => r.state === 0)) return ['yellow', 'на связи, все реле выключены'];
    if (d.door_open) return ['blue', 'на связи, дверь открыта'];   // реле 4 — замок или датчик двери платы ver_5_1
    return ['green', d.door_open === false ? 'на связи, дверь закрыта' : 'на связи'];
  }

  let map = null, layer = null, fitted = false, lastSel = 0, shown = false;
  const markers = new Map(); // id → marker

  function ensureMap() {
    if (map) return;
    map = L.map('map', { zoomControl: true, attributionControl: true, scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    }).addTo(map);
    layer = L.layerGroup().addTo(map);
    map.on('click', () => map.closePopup()); // клик по пустому месту карты (клик по точке до карты не доходит)
    map.on('focus', () => map.scrollWheelZoom.enable());   // колесо мыши — после клика по карте
    map.on('blur', () => map.scrollWheelZoom.disable());
  }

  const icon = (color, sel) => L.divIcon({
    className: 'map-pin-wrap', iconSize: [22, 22], iconAnchor: [11, 11], popupAnchor: [0, -10],
    html: `<span class="pin c-${color}${sel ? ' sel' : ''}"></span>`,
  });

  function popupHtml(d, text) {
    const bi = d.break_in ? `<div class="map-alarm">ВЗЛОМ ДВЕРИ · ${ago(d.break_in.ts)}</div>` : '';
    return `<div class="map-pop"><b>${esc(d.name)}</b> <span class="muted">#${d.id}</span>${bi}
      <div>${esc(text)}</div>${d.address ? `<div class="muted">${esc(d.address)}</div>` : ''}
      <div class="muted">данные: ${ago(d.last_data_ts)}</div>
      <a href="#${d.id}" class="map-open">Открыть устройство →</a></div>`;
  }

  /* Подсказка по клику: первый клик по точке — подсказка, повторный (или ссылка в ней) — открыть устройство */
  const CLUSTER = [55.0, 37.0];          // сюда собираются устройства без координат
  const RANK = { purple: 5, red: 4, yellow: 3, blue: 2, green: 1 };
  let pop = null, popFor = null;         // открытая подсказка и чья она (id устройства или 'cluster')
  function showPopup(key, latlng, html) {
    if (popFor === key && pop && map.hasLayer(pop)) {
      if (key !== 'cluster') location.hash = '#' + key; // повторный клик — открыть устройство
      return;
    }
    // closeOnClick: false — иначе Leaflet закрывает подсказку ещё до обработки повторного клика по точке;
    // клик по пустому месту карты закрывает её (map.on('click') в ensureMap)
    pop = L.popup({ offset: [0, -14], maxWidth: 320, autoPanPadding: [20, 20], closeOnClick: false }).setLatLng(latlng).setContent(html).openOn(map);
    popFor = key;
    pop.on('remove', () => { if (popFor === key) popFor = null; });
  }

  const clusterIcon = (n, color) => L.divIcon({
    className: 'map-pin-wrap', iconSize: [34, 34], iconAnchor: [17, 17],
    html: `<span class="cluster c-${color}">${n}</span>`,
  });
  function clusterHtml(list) {
    return `<div class="map-pop"><b>Без координат: ${list.length}</b>
      <div class="muted" style="margin-bottom:6px">точка 55.00, 37.00 — координаты задаются в «Изменить» → «Координаты»</div>
      <div class="cluster-list">${list.map(d => { const [c, t] = status(d);
        return `<a href="#${d.id}" class="cluster-row" title="${esc(t)}"><i class="pin c-${c}"></i><span>${esc(d.name)}</span><span class="muted">#${d.id}</span></a>`; }).join('')}</div></div>`;
  }
  let cluster = null;

  function update(list, selected) {
    const geo = list.filter(d => d.geo);
    const noGeo = list.filter(d => !d.geo);
    const note = $('#map-note');
    note.textContent = noGeo.length ? `Без координат: ${noGeo.length} — собраны в кластер в точке 55.00, 37.00. Координаты задаются в «Изменить» → «Координаты».` : '';
    const any = geo.length || noGeo.length;
    $('#map').classList.toggle('hidden', !any);
    if (!any || card.classList.contains('hidden')) { shown = false; return; }
    ensureMap();
    if (!shown) { shown = true; setTimeout(() => map.invalidateSize(), 0); } // карта снова видна (вернулись из устройства)

    const seen = new Set();
    for (const d of geo) {
      seen.add(d.id);
      const [color, text] = status(d);
      const key = color + (d.id === selected ? ':sel' : '') + ':' + d.geo.join(',');
      let m = markers.get(d.id);
      if (!m) {
        m = L.marker(d.geo, { icon: icon(color, d.id === selected), keyboard: true, title: d.name, riseOnHover: true });
        m.on('click', () => { const cur = m._d; showPopup(cur.id, cur.geo, popupHtml(cur, status(cur)[1])); });
        m.addTo(layer); markers.set(d.id, m);
      } else if (m._key !== key) {
        m.setLatLng(d.geo); m.setIcon(icon(color, d.id === selected));
      }
      m._key = key; m._d = d;
      m.setZIndexOffset(color === 'purple' ? 1000 : color === 'red' ? 500 : 0);
      m.unbindTooltip().bindTooltip(`${esc(d.name)} — ${esc(text)}`, { direction: 'top', offset: [0, -10] });
      if (popFor === d.id && pop && map.hasLayer(pop)) pop.setContent(popupHtml(d, text)); // открытая подсказка — свежие данные
    }
    for (const [id, m] of markers) if (!seen.has(id)) { layer.removeLayer(m); markers.delete(id); }

    // кластер устройств без координат: число и цвет самого тревожного состояния
    if (noGeo.length) {
      const worst = noGeo.map(d => status(d)[0]).sort((a, b) => RANK[b] - RANK[a])[0];
      const ckey = noGeo.length + ':' + worst;
      if (!cluster) {
        cluster = L.marker(CLUSTER, { icon: clusterIcon(noGeo.length, worst), title: 'Устройства без координат', zIndexOffset: 200 });
        cluster.on('click', () => showPopup('cluster', CLUSTER, clusterHtml(cluster._list)));
        cluster.addTo(layer);
      } else if (cluster._key !== ckey) cluster.setIcon(clusterIcon(noGeo.length, worst));
      cluster._key = ckey; cluster._list = noGeo;
      cluster.unbindTooltip().bindTooltip(`Без координат: ${noGeo.length}`, { direction: 'top', offset: [0, -16] });
      if (popFor === 'cluster' && pop && map.hasLayer(pop)) pop.setContent(clusterHtml(noGeo));
    } else if (cluster) { layer.removeLayer(cluster); cluster = null; }

    if (!fitted) {
      fitted = true;
      const pts = geo.map(d => d.geo).concat(noGeo.length ? [CLUSTER] : []);
      pts.length === 1 ? map.setView(pts[0], geo.length ? 15 : 10) : map.fitBounds(L.latLngBounds(pts), { padding: [30, 30], maxZoom: 16 });
    }
    // выбрано устройство — показываем его на карте
    if (selected && selected !== lastSel) {
      const d = geo.find(x => x.id === selected);
      if (d) map.panTo(d.geo);
    }
    lastSel = selected;
  }

  document.addEventListener('relay:devices', e => update(e.detail.list, e.detail.selected));

})();
