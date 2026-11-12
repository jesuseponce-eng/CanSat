/* ===== Leaflet + Telemetría (México) ===== */
import L from "leaflet";
import "leaflet/dist/leaflet.css";

const MX_BOUNDS = L.latLngBounds([[14.3, -118.8], [32.9, -86.4]]);
let map, deviceMarker = null, accuracyCircle = null, trailPolyline;
let stationMarker = null, stationAccuracyCircle = null;
let stationLocationStatus = null;

let abLayer;
let markerA = null, markerB = null, lineAB = null;

// IDs NUEVOS DEL BLADE
const MAP_EL_ID          = 'map-main';
const TBODY_ID           = 'map-telemetry-body';
const STATUS_ID          = 'map-telemetry-status';
const DISTANCIA_ID       = 'map-distancia-seleccion';
const DELTA_T_ID         = 'map-delta-tiempo-seleccion';
const BTN_LIMPIAR_TABLA  = 'map-btn-limpiar-tabla';
const BTN_LIMPIAR_SEL    = 'map-btn-limpiar-seleccion';

window.inicializarMapa = function () {
  const el = document.getElementById(MAP_EL_ID);
  if (!el) return;

  map = L.map(el, {
    zoomControl: true,
    maxBounds: MX_BOUNDS,
    maxBoundsViscosity: 0.7,
    minZoom: 4,
    maxZoom: 18
  }).setView([23.5, -102.0], 5);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  trailPolyline  = L.polyline([], { weight: 3, opacity: 0.9 }).addTo(map);
  abLayer = L.layerGroup().addTo(map);
  stationLocationStatus = document.getElementById('map-location-status');

  const StationLocationControl = L.Control.extend({
    onAdd() {
      const container = L.DomUtil.create('div', 'leaflet-bar');
      const button = L.DomUtil.create('a', 'station-locate-control', container);
      button.href = '#';
      button.title = 'Marcar ubicación de la estación terrena';
      button.setAttribute('role', 'button');
      button.setAttribute('aria-label', button.title);
      button.innerHTML = '<i class="fas fa-location-crosshairs" aria-hidden="true"></i>';
      L.DomEvent.disableClickPropagation(container);
      L.DomEvent.on(button, 'click', (event) => {
        L.DomEvent.preventDefault(event);
        locateStation();
      });
      return container;
    }
  });
  new StationLocationControl({ position: 'topleft' }).addTo(map);

  el.addEventListener('click', (event) => {
    if (stationLocationStatus?.dataset.manualPlacement !== 'true') return;
    if (event.target.closest('.leaflet-control')) return;
    const bounds = el.getBoundingClientRect();
    const point = L.point(event.clientX - bounds.left, event.clientY - bounds.top);
    const location = map.containerPointToLatLng(point);
    stationLocationStatus.dataset.manualPlacement = 'false';
    markStationLocation(location.lat, location.lng, 0, 'marcada en el mapa');
  }, true);

  setTimeout(() => map.invalidateSize(), 200);
};

/* ====== Selección A/B en tabla ====== */
let selectedKeys = [];
let paquetesIndex = new Map();

const makeKey = (p) => {
  const id = p.id ?? p._id ?? null;
  return id != null ? String(id) : `${p.tsISO ?? p.timestamp ?? p.created_at}|${p.lat}|${p.lon}`;
};
const toMs = (ts) => { try { return new Date(ts).getTime(); } catch { return null; } };

function calcDistMeters(p1, p2) {
  return L.latLng(p1.lat, p1.lon).distanceTo([p2.lat, p2.lon]);
}

function setBadge(firstTd, label) {
  if (!firstTd) return;
  let chip = firstTd.querySelector('.ab-chip');
  if (!chip) {
    chip = document.createElement('span');
    chip.className = 'ab-chip';
    firstTd.prepend(chip);
  }
  chip.textContent = label;
}
function removeBadge(firstTd) {
  if (!firstTd) return;
  const chip = firstTd.querySelector('.ab-chip');
  if (chip) chip.remove();
}

function refreshABOverlay() {
  if (!map || !abLayer) return;
  abLayer.clearLayers();
  markerA = markerB = lineAB = null;

  if (selectedKeys.length === 0) return;

  const a = paquetesIndex.get(selectedKeys[0]);
  if (!a || a.lat == null || a.lon == null) return;

  markerA = L.marker([a.lat, a.lon], {
    icon: L.divIcon({ className: 'ab-marker ab-a', html: 'A' }),
    title: 'Punto A'
  }).addTo(abLayer);

  if (selectedKeys.length === 2) {
    const b = paquetesIndex.get(selectedKeys[1]);
    if (b && b.lat != null && b.lon != null) {
      markerB = L.marker([b.lat, b.lon], {
        icon: L.divIcon({ className: 'ab-marker ab-b', html: 'B' }),
        title: 'Punto B'
      }).addTo(abLayer);

      lineAB = L.polyline([[a.lat, a.lon], [b.lat, b.lon]], { weight: 3, opacity: 0.9 }).addTo(abLayer);
      const bounds = L.latLngBounds([[a.lat, a.lon], [b.lat, b.lon]]);
      map.fitBounds(bounds.pad(0.2), { animate: true });
    }
  }
}

function refreshSelectionUI() {
  const tbody = document.getElementById(TBODY_ID);
  if (!tbody) return;

  const rows = [...tbody.querySelectorAll('tr[data-key]')];
  rows.forEach(tr => {
    const key = tr.dataset.key;
    const firstTd = tr.querySelector('td:first-child');
    tr.classList.remove('sel-1','sel-2','fila-select');
    removeBadge(firstTd);

    const i = selectedKeys.indexOf(key);
    if (i !== -1) {
      tr.classList.add('fila-select');
      if (i === 0) { tr.classList.add('sel-1'); setBadge(firstTd,'A'); }
      if (i === 1) { tr.classList.add('sel-2'); setBadge(firstTd,'B'); }
    }
  });

  const distEl = document.getElementById(DISTANCIA_ID);
  const dtEl   = document.getElementById(DELTA_T_ID);

  if (selectedKeys.length === 2) {
    const a = paquetesIndex.get(selectedKeys[0]);
    const b = paquetesIndex.get(selectedKeys[1]);
    if (a && b && a.lat && a.lon && b.lat && b.lon) {
      const m  = calcDistMeters(a, b);
      const km = m / 1000;
      if (distEl) distEl.textContent = `distancia: ${m<1000?`${Math.round(m)} m`:`${km.toFixed(3)} km`}`;
      const t1 = toMs(a.tsISO), t2 = toMs(b.tsISO);
      if (t1 && t2 && dtEl) dtEl.textContent = `Δt: ${(Math.abs(t2-t1)/1000).toFixed(1)} s`;
      refreshABOverlay();
      return;
    }
  }
  if (distEl) distEl.textContent = 'distancia: —';
  if (dtEl)   dtEl.textContent   = 'Δt: —';
  refreshABOverlay();
}

function updateDevice({ lat, lon, acc = 0 }) {
  if (!map) return false;
  const latitude = Number(lat);
  const longitude = Number(lon);
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return false;
  const ll = L.latLng(latitude, longitude);
  if (!MX_BOUNDS.contains(ll)) return false;
  if (!deviceMarker) {
    deviceMarker = L.marker(ll, { title: 'CanSat: ubicación GPS' }).addTo(map);
  } else {
    deviceMarker.setLatLng(ll);
  }
  if (!accuracyCircle) {
    accuracyCircle = L.circle(ll, {
      radius: 0,
      stroke: false,
      fillColor: '#3388ff',
      fillOpacity: 0.15
    }).addTo(map);
  } else {
    accuracyCircle.setLatLng(ll);
  }
  accuracyCircle.setRadius(Number(acc) || 0);
  const pts = trailPolyline.getLatLngs();
  pts.push(ll); if (pts.length > 3000) pts.shift();
  trailPolyline.setLatLngs(pts);
  if (!map.getBounds().contains(ll)) map.panTo(ll);
  return true;
}

function clearDeviceMarker() {
  if (!map) return;
  if (deviceMarker) map.removeLayer(deviceMarker);
  if (accuracyCircle) map.removeLayer(accuracyCircle);
  deviceMarker = null;
  accuracyCircle = null;
}

function markStationLocation(lat, lon, accuracy = 0, source = 'ubicación del equipo') {
  const latitude = Number(lat);
  const longitude = Number(lon);
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
    if (stationLocationStatus) stationLocationStatus.textContent = 'No se pudo obtener una coordenada válida.';
    return false;
  }
  const location = L.latLng(latitude, longitude);
  if (!MX_BOUNDS.contains(location)) {
    if (stationLocationStatus) stationLocationStatus.textContent = 'La ubicación está fuera del mapa de México.';
    return false;
  }
  if (stationLocationStatus) stationLocationStatus.dataset.manualPlacement = 'false';

  if (!stationMarker) {
    stationMarker = L.marker(location, {
      title: 'Estación terrena (este equipo)',
      icon: L.divIcon({
        className: 'station-marker',
        html: '<i class="fas fa-location-crosshairs" aria-hidden="true"></i>',
        iconSize: [30, 30],
        iconAnchor: [15, 15]
      })
    }).addTo(map);
  } else {
    stationMarker.setLatLng(location);
  }
  stationMarker.bindPopup(`<strong>Estación terrena</strong><br>${source}`);

  if (!stationAccuracyCircle) {
    stationAccuracyCircle = L.circle(location, {
      radius: 0,
      stroke: false,
      fillColor: '#f97316',
      fillOpacity: 0.14
    }).addTo(map);
  } else {
    stationAccuracyCircle.setLatLng(location);
  }
  stationAccuracyCircle.setRadius(Number(accuracy) || 0);
  map.setView(location, Math.max(map.getZoom(), 14));
  if (stationLocationStatus) stationLocationStatus.textContent = 'Estación terrena marcada; no es el GPS del CanSat.';
  return true;
}

function locateStation() {
  if (!window.isSecureContext || !navigator.geolocation) {
    if (stationLocationStatus) {
      stationLocationStatus.dataset.manualPlacement = 'true';
      stationLocationStatus.textContent = 'Haz clic en el mapa para marcar la estación; ubicación del navegador requiere localhost o HTTPS.';
    }
    return;
  }

  if (stationLocationStatus) stationLocationStatus.textContent = 'Solicitando ubicación de este equipo…';
  navigator.geolocation.getCurrentPosition(
    ({ coords }) => markStationLocation(coords.latitude, coords.longitude, coords.accuracy),
    () => {
      if (stationLocationStatus) {
        stationLocationStatus.dataset.manualPlacement = 'true';
        stationLocationStatus.textContent = 'Ubicación no autorizada; haz clic en el mapa para marcar la estación.';
      }
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
  );
}

function renderTablaTelemetria(paquetes) {
  const tbody = document.getElementById(TBODY_ID);
  if (!tbody) return;

  // === CONFIG: máximo de filas que quieres ver ===
  const MAX_ROWS = 40;    // cámbialo a 20, 50, lo que quieras

  const fmtNum = (v, n = 6) =>
    (v == null || Number.isNaN(v)) ? '—' :
      (typeof v === 'number' ? v.toFixed(n) : v);

  const fmtTemp = (v) =>
    (v == null || Number.isNaN(v)) ? '—' : `${Number(v).toFixed(1)} °C`;

  const fmtHum = (v) =>
    (v == null || Number.isNaN(v)) ? '—' : `${Math.round(Number(v))} %`;

  paquetesIndex.clear();

  // Me quedo solo con las primeras N muestras (la API ya viene ordenada)
  const limited = paquetes.slice(0, MAX_ROWS);

  const rowsHtml = limited.map((p, i) => {
    const key = makeKey(p);

    paquetesIndex.set(key, {
      id:   p.id ?? p._id ?? null,
      lat:  p.lat,
      lon:  p.lon,
      tsISO: p.tsISO ?? p.timestamp ?? p.created_at
    });

    // La fila 0 la marcamos como "más reciente"
    const isNewest = (i === 0);

    return `
      <tr data-key="${key}"
          class="
            group
            border-b border-violet-800/20
            transition-colors
            odd:bg-slate-900/40
            even:bg-slate-900/20
            hover:bg-violet-900/30
            ${isNewest ? 'ring-1 ring-violet-500/70 bg-violet-900/40' : ''}
          ">
        <td class="px-3 py-2 whitespace-nowrap text-[11px] text-purple-200/80 font-mono">
          ${p.tsISO ?? '—'}
        </td>
        <td class="px-3 py-2 text-right font-mono text-xs text-gray-200">
          ${fmtNum(p.numero_paquete, 0)}
        </td>
        <td class="px-3 py-2 text-right font-mono text-xs text-purple-100">
          ${fmtNum(p.lat, 5)}
        </td>
        <td class="px-3 py-2 text-right font-mono text-xs text-purple-100">
          ${fmtNum(p.lon, 5)}
        </td>
        <td class="px-3 py-2 text-right font-mono text-xs text-amber-200">
          ${fmtTemp(p.temp)}
        </td>
        <td class="px-3 py-2 text-right font-mono text-xs text-cyan-200">
          ${fmtHum(p.hum)}
        </td>
        <td class="px-3 py-2 text-right font-mono text-xs text-amber-200">
          ${p.rssi == null ? '—' : `${fmtNum(p.rssi, 0)} dBm`}
        </td>
        <td class="px-3 py-2 text-right font-mono text-xs text-cyan-200">
          ${p.snr == null ? '—' : `${fmtNum(p.snr, 2)} dB`}
        </td>
        <td class="px-3 py-2 text-right font-mono text-xs text-gray-200">
          ${fmtNum(p.paquetes_recibidos, 0)}
        </td>
        <td class="px-3 py-2 text-right font-mono text-xs text-gray-200">
          ${fmtNum(p.paquetes_perdidos, 0)}
        </td>
        <td class="px-3 py-2 text-right font-mono text-xs text-emerald-200">
          ${p.recepcion_pct == null ? '—' : `${fmtNum(p.recepcion_pct, 2)} %`}
        </td>
      </tr>`;
  }).join('');

  tbody.innerHTML = rowsHtml;
  refreshSelectionUI();
}

/* Delegación de clicks en la tabla */
document.addEventListener('click', (e) => {
  const tr = e.target.closest(`tr[data-key]`);
  if (!tr) return;
  const key = tr.dataset.key;
  const idx = selectedKeys.indexOf(key);
  if (idx >= 0) {
    selectedKeys.splice(idx, 1);
    selectedKeys.push(key);
  } else {
    if (selectedKeys.length >= 2) selectedKeys.shift();
    selectedKeys.push(key);
  }
  refreshSelectionUI();
});

/* API global para lo que llama el polling */
window.setTelemetria = function (paquetes) {
  renderTablaTelemetria(paquetes);
  const st = document.getElementById(STATUS_ID);
  const latest = paquetes[0];
  const hasGpsFix = latest && updateDevice({
    lat: latest.lat,
    lon: latest.lon,
    acc: latest.hAcc ?? latest.accuracy ?? 0
  });
  if (!hasGpsFix) clearDeviceMarker();
  if (st) {
    const numero = latest?.numero_paquete;
    st.textContent = `muestras: ${paquetes.length}${numero == null ? '' : ` · paquete: ${numero}`} · ${hasGpsFix ? 'GPS OK' : 'GPS sin fix'}`;
  }
  window.dispatchEvent(new CustomEvent('cansat:telemetry', { detail: paquetes }));
};

/* ===== Polling ===== */
(function setupPolling(){
  const ENDPOINT = '/sensores/ultimos?limit=200';
  const st = document.getElementById(STATUS_ID);
  const limpiar = document.getElementById(BTN_LIMPIAR_TABLA);
  const quitar  = document.getElementById(BTN_LIMPIAR_SEL);
  let polling = false;

  if (limpiar) limpiar.addEventListener('click', () => {
    const tbody = document.getElementById(TBODY_ID);
    if (tbody) tbody.innerHTML='';
    if (st) st.textContent = 'paquetes: 0';
    if (trailPolyline) trailPolyline.setLatLngs([]);
    if (abLayer) abLayer.clearLayers();
    selectedKeys=[]; paquetesIndex.clear();
    refreshSelectionUI();
  });

  if (quitar) quitar.addEventListener('click', () => {
    selectedKeys=[]; refreshSelectionUI();
  });

  async function tick() {
    if (polling) return;
    polling = true;
    try {
      const r = await fetch(ENDPOINT,{cache:'no-store'});
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      const data = await r.json();
      const paquetes = (Array.isArray(data)?data:data.items||[]).map(d=>({
        id:d.id??d._id,
        tsISO:d.tsISO??d.timestamp??d.created_at,
        lat:d.lat??d.LT??d.LAT??d.latitude,
        lon:d.lon??d.LN??d.LON??d.longitude,
        temp:d.temp??d.temp_ext??d.temp_int??d.temperature,
        hum:d.humedad??d.hum??d.humidity,
        temp_ext:d.temp_ext??d.tempExterna??d.temp??d.temperature,
        temp_int:d.temp_int??d.tempInterna,
        humedad:d.humedad??d.hum??d.humidity,
        presion:d.presion,
        gas:d.gas??d.GAS,
        co:d.co,
        h2:d.h2,
        ch4:d.ch4,
        c2h:d.c2h,
        no2:d.no2,
        a_abs_ms2:d.a_abs_ms2,
        altitud_m:d.altitud_m,
        v_desc_ms:d.v_desc_ms,
        gx:d.gx,
        gy:d.gy,
        gz:d.gz,
        satellites:d.satellites,
        gps_fix:d.gps_fix,
        mpu_ok:d.mpu_ok,
        bme_ok:d.bme_ok,
        hAcc:d.hAcc??d.accuracy,
        numero_paquete:d.numero_paquete,
        rssi:d.rssi,
        snr:d.snr,
        paquetes_recibidos:d.paquetes_recibidos,
        paquetes_perdidos:d.paquetes_perdidos,
        recepcion_pct:d.recepcion_pct
      }));
      window.setTelemetria(paquetes);
    } catch(e){
      if (st) st.textContent = 'sin conexión';
    } finally {
      polling = false;
    }
  }

  tick();
  setInterval(tick,500);
})();

/* Estilos AB y z-index del mapa (adaptados a #map-main) */
(function injectABStyles() {
  if (document.getElementById("ab-styles")) return;
  const style = document.createElement("style");
  style.id = "ab-styles";
  style.textContent = `
    #${TBODY_ID} tr {
      cursor: pointer;
      transition: background 0.2s ease;
    }
    #${TBODY_ID} tr:hover td {
      background: rgba(139,92,246,.08);
    }
    .station-locate-control {
      width: 30px !important;
      height: 30px !important;
      line-height: 30px !important;
      text-align: center;
      color: #c2410c !important;
      font-size: 16px;
    }
    .station-marker {
      color: #c2410c;
      font-size: 24px;
      text-align: center;
      text-shadow: 0 1px 3px #fff;
    }
    tr.fila-select > td {
      font-weight: 500;
      position: relative;
    }
    tr.sel-1 > td {
      background: rgba(139,92,246,.25) !important;
      box-shadow: inset 4px 0 0 rgba(139,92,246,.9);
    }
    tr.sel-2 > td {
      background: rgba(217,70,239,.25) !important;
      box-shadow: inset 4px 0 0 rgba(217,70,239,.9);
    }
    .ab-chip {
      display: inline-block;
      margin-right: .4rem;
      font-weight: 700;
      font-size: .7rem;
      padding: .2rem .4rem;
      border-radius: 9999px;
      color: #fff;
    }
    tr.sel-1 .ab-chip { background: #7c3aed; }
    tr.sel-2 .ab-chip { background: #db2777; }
  `;
  document.head.appendChild(style);
})();

(function injectLeafletZFix() {
  if (document.getElementById("leaflet-z-fix")) return;
  const style = document.createElement("style");
  style.id = "leaflet-z-fix";
  style.textContent = `
    #${MAP_EL_ID},
    #${MAP_EL_ID} .leaflet-container {
      position: relative;
      z-index: 0 !important;
    }
    #${MAP_EL_ID} .leaflet-pane,
    #${MAP_EL_ID} .leaflet-top,
    #${MAP_EL_ID} .leaflet-bottom,
    #${MAP_EL_ID} .leaflet-control-container {
      z-index: 1 !important;
    }
  `;
  document.head.appendChild(style);
})();

/* Auto-init cuando el div existe */
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById(MAP_EL_ID)) {
    window.inicializarMapa();
  }
});
