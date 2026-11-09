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
    if (!d.online) return ['red', d.state === 'never' ? 'ещё не выходило на связь' : 'нет данных'];
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

  function update(list, selected) {
    const geo = list.filter(d => d.geo);
    const without = list.length - geo.length;
    const note = $('#map-note');
    note.textContent = !list.length ? '' : !geo.length
      ? 'Ни у одного устройства не указаны координаты. Администратор задаёт их в «Изменить» → «Координаты», например 55.7558, 37.6173.'
      : without ? `Без координат: ${without} — они не показаны на карте.` : '';
    $('#map').classList.toggle('hidden', !geo.length);
    if (!geo.length || card.classList.contains('hidden')) { shown = false; return; }
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
        m.on('click', () => { location.hash = '#' + d.id; });
        m.addTo(layer); markers.set(d.id, m);
      } else if (m._key !== key) {
        m.setLatLng(d.geo); m.setIcon(icon(color, d.id === selected));
      }
      m._key = key;
      m.setZIndexOffset(color === 'purple' ? 1000 : color === 'red' ? 500 : 0);
      m.unbindTooltip().bindTooltip(`${esc(d.name)} — ${esc(text)}`, { direction: 'top', offset: [0, -10] });
      m.unbindPopup().bindPopup(popupHtml(d, text));
    }
    for (const [id, m] of markers) if (!seen.has(id)) { layer.removeLayer(m); markers.delete(id); }

    if (!fitted) {
      fitted = true;
      const b = L.latLngBounds(geo.map(d => d.geo));
      geo.length === 1 ? map.setView(geo[0].geo, 15) : map.fitBounds(b, { padding: [30, 30], maxZoom: 16 });
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
