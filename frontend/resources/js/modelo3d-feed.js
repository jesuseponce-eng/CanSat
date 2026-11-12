// resources/js/modelo3d-feed.js
(() => {
  const ENDPOINT = '/sensores/live';
  const POLL_MS = 100;
  const DATA_IN_MS2 = true;
  const CALIBRATION_KEY = 'cansat.orientation-calibration.v1';

  let started = false;
  let polling = false;
  let pollHandle = null;
  let latestSampleId = null;
  let latestRawAngles = null;
  let calibrationActive = false;
  let orientationCalibration = { roll: 0, pitch: 0, yaw: 0 };

  // ===== Utils =====
  const safeNum = value => value === null || value === undefined || value === ''
    ? NaN
    : Number.isFinite(Number(value)) ? Number(value) : NaN;
  const toG = value => {
    const number = safeNum(value);
    return DATA_IN_MS2 && Number.isFinite(number) ? number / 9.80665 : number;
  };
  const fmtNum = (v, decimals) => Number.isFinite(v) ? v.toFixed(decimals) : '—';
  const wrapAngle = angle => ((angle + 180) % 360 + 360) % 360 - 180;

  function readCalibration() {
    try {
      const saved = JSON.parse(localStorage.getItem(CALIBRATION_KEY) || 'null');
      if (saved && ['roll', 'pitch', 'yaw'].every(axis => Number.isFinite(saved[axis]))) {
        orientationCalibration = saved;
        calibrationActive = true;
      }
    } catch (error) {
      console.warn('[modelo3d] No se pudo leer la calibración guardada:', error);
    }
  }

  function setCalibrationStatus(message) {
    document.querySelectorAll('[data-orientation-status]').forEach(element => {
      if (element.textContent !== message) element.textContent = message;
    });
  }

  function updateCalibrationButtons() {
    const hasAngles = latestRawAngles &&
      ['roll', 'pitch'].every(axis => Number.isFinite(latestRawAngles[axis]));
    document.querySelectorAll('[data-orientation-calibrate]').forEach(button => {
      button.disabled = !hasAngles;
    });
    if (hasAngles && document.querySelector('[data-orientation-status]')) {
      setCalibrationStatus(
        calibrationActive
          ? 'Calibración activa. Puedes volver a calibrar en cualquier momento.'
          : 'Coloca el CanSat en la posición de referencia y calibra roll/pitch.'
      );
    }
  }

  function applyCalibration(sample) {
    latestRawAngles = { roll: sample.roll, pitch: sample.pitch, yaw: sample.yaw };
    updateCalibrationButtons();
    return {
      ...sample,
      roll: Number.isFinite(sample.roll) ? wrapAngle(sample.roll - orientationCalibration.roll) : NaN,
      pitch: Number.isFinite(sample.pitch) ? wrapAngle(sample.pitch - orientationCalibration.pitch) : NaN,
      yaw: Number.isFinite(sample.yaw) ? wrapAngle(sample.yaw - orientationCalibration.yaw) : NaN,
    };
  }

  document.addEventListener('click', event => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    if (target.closest('[data-orientation-calibrate]')) {
      if (!latestRawAngles || !['roll', 'pitch'].every(axis => Number.isFinite(latestRawAngles[axis]))) {
        setCalibrationStatus('Aún no hay ángulos válidos para calibrar.');
        return;
      }
      orientationCalibration = {
        roll: latestRawAngles.roll,
        pitch: latestRawAngles.pitch,
        yaw: Number.isFinite(latestRawAngles.yaw) ? latestRawAngles.yaw : 0,
      };
      calibrationActive = true;
      try {
        localStorage.setItem(CALIBRATION_KEY, JSON.stringify(orientationCalibration));
      } catch (error) {
        console.error('[modelo3d] No se pudo guardar la calibración en este navegador:', error);
      }
      setCalibrationStatus('Orientación calibrada con la posición actual.');
      return;
    }

    if (target.closest('[data-orientation-reset]')) {
      orientationCalibration = { roll: 0, pitch: 0, yaw: 0 };
      calibrationActive = false;
      try {
        localStorage.removeItem(CALIBRATION_KEY);
      } catch (error) {
        console.error('[modelo3d] No se pudo borrar la calibración guardada:', error);
      }
      setCalibrationStatus('Calibración restablecida.');
    }
  });

  readCalibration();

  function makeUIRefs() {
    const el = id => document.getElementById(id);
    return {
      tbody: null, // ya no hay tabla de IMU
      badge: el('badgeVibracion'),   // si un día lo pones
      kAx: el('kpiAx'),
      kAy: el('kpiAy'),
      kAz: el('kpiAz'),
      kRoll: el('kpiRoll'),
      kPitch: el('kpiPitch'),
      kYaw: el('kpiYaw'),
      kGx: el('kpiGx'),
      kGy: el('kpiGy'),
      kGz: el('kpiGz'),
      sensorMpu: el('sensor-health-mpu'),
      sensorBme: el('sensor-health-bme'),
      sensorGps: el('sensor-health-gps'),
      gpsSatellites: el('sensor-gps-satellites'),
      kTempExt: el('kpiTempExt'),
      kTempInt: el('kpiTempInt'),
      kAabs: el('kpiAabs'),
      kVibRms: el('kpiVibRms'),
      kVibPeak: el('kpiVibPeak'),
      kAlt: el('kpiAlt'),
      kVdesc: el('kpiVdesc'),
    };
  }

  function addRow(tbody, s) {
    if (!tbody) return; // ahora es opcional
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="px-3 py-1.5 font-mono text-[12px] text-purple-300 whitespace-nowrap">${s.ts}</td>
      <td class="px-3 py-1.5 text-right font-mono tabular-nums">${fmtNum(s.ax, 3)}</td>
      <td class="px-3 py-1.5 text-right font-mono tabular-nums">${fmtNum(s.ay, 3)}</td>
      <td class="px-3 py-1.5 text-right font-mono tabular-nums">${fmtNum(s.az, 3)}</td>
      <td class="px-3 py-1.5 text-right font-mono tabular-nums">${fmtNum(s.roll, 1)}</td>
      <td class="px-3 py-1.5 text-right font-mono tabular-nums">${fmtNum(s.pitch, 1)}</td>
      <td class="px-3 py-1.5 text-right font-mono tabular-nums">${fmtNum(s.yaw, 1)}</td>`;
    tbody.prepend(tr);
    while (tbody.children.length > 150) tbody.removeChild(tbody.lastChild);
  }

  function updateKPIs(refs, s) {
    const set = (el, val, fmt = (x)=>x) => {
      if (el) el.textContent = Number.isFinite(val) ? fmt(val) : '—';
    };
    set(refs.kAx, s.ax, v=>v.toFixed(3));
    set(refs.kAy, s.ay, v=>v.toFixed(3));
    set(refs.kAz, s.az, v=>v.toFixed(3));
    set(refs.kRoll, s.roll, v=>v.toFixed(1));
    set(refs.kPitch, s.pitch, v=>v.toFixed(1));
    set(refs.kYaw, s.yaw, v=>v.toFixed(1));
    set(refs.kGx, s.gx, v=>v.toFixed(3));
    set(refs.kGy, s.gy, v=>v.toFixed(3));
    set(refs.kGz, s.gz, v=>v.toFixed(3));
    set(refs.kTempExt, s.tempExt, v=>v.toFixed(2));
    set(refs.kTempInt, s.tempInt, v=>v.toFixed(2));
    set(refs.kAabs, s.a_abs_ms2, v=>v.toFixed(2));
    set(refs.kVibRms, s.vibRms, v=>v.toFixed(3));
    set(refs.kVibPeak, s.vibPeak, v=>v.toFixed(3));
    set(refs.kAlt, s.alt, v=>v.toFixed(1));
    set(refs.kVdesc, s.vdesc, v=>v.toFixed(2));
    const setState = (element, value) => {
      if (element) element.textContent = value == null ? 'No reportado' : Number(value) === 1 ? 'OK' : 'Sin respuesta';
    };
    setState(refs.sensorMpu, s.mpu_ok);
    setState(refs.sensorBme, s.bme_ok);
    if (refs.sensorGps) {
      refs.sensorGps.textContent = s.gps_fix == null
        ? 'No reportado'
        : Number(s.gps_fix) === 1 ? 'Fix' : 'Sin fix';
    }
    if (refs.gpsSatellites) {
      refs.gpsSatellites.textContent = Number.isFinite(s.satellites) ? String(s.satellites) : '—';
    }
  }

  const vib = { buf: [], N: 50, TH_LOW: 0.05, TH_HIGH: 0.15 };
  function setBadge(badge, level) {
    if (!badge) return;
    const styles = {
      estable: ['bg-emerald-900/50','text-emerald-300','border','border-emerald-700/60'],
      media:   ['bg-amber-900/40','text-amber-200','border','border-amber-700/60'],
      alta:    ['bg-rose-900/40','text-rose-200','border','border-rose-700/60'],
    };
    badge.className = 'inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold ' + styles[level].join(' ');
    badge.textContent = level === 'estable' ? 'Estable' : (level === 'media' ? 'Media' : 'Alta');
  }
  function pushAndEvalVibration(badge, s) {
    if (!badge) return;
    if (Number.isFinite(s.vibRms)) {
      if (s.vibRms < vib.TH_LOW) setBadge(badge, 'estable');
      else if (s.vibRms < vib.TH_HIGH) setBadge(badge, 'media');
      else setBadge(badge, 'alta');
      return;
    }
    if (![s.ax, s.ay, s.az].every(Number.isFinite)) return;
    const mag = Math.sqrt(s.ax*s.ax + s.ay*s.ay + s.az*s.az);
    vib.buf.push(mag);
    if (vib.buf.length > vib.N) vib.buf.shift();
    if (vib.buf.length < 5) return setBadge(badge, 'estable');
    const mean = vib.buf.reduce((a,b)=>a+b,0)/vib.buf.length;
    const rms  = Math.sqrt(vib.buf.reduce((acc,v)=> acc + Math.pow(v-mean,2), 0) / vib.buf.length);
    if (rms < vib.TH_LOW) setBadge(badge, 'estable');
    else if (rms < vib.TH_HIGH) setBadge(badge, 'media');
    else setBadge(badge, 'alta');
  }

  function normalizeRow(d) {
    const ax_g = toG(d.ax);
    const ay_g = toG(d.ay);
    const az_g = toG(d.az);

    const roll  = safeNum(d.roll);
    const pitch = safeNum(d.pitch);
    const yaw   = safeNum(d.yaw);

    let idNum = safeNum(d.id);
    if (!Number.isFinite(idNum)) {
      const t = d.tsISO ? Date.parse(d.tsISO) : Date.now();
      idNum = Number.isFinite(t) ? t : Date.now();
    }
    const ts = d.tsISO ? new Date(d.tsISO).toLocaleTimeString() : '—';

    const tempExt = safeNum(d.temp_ext);
    const tempInt = safeNum(d.temp_int);
    const aabs    = safeNum(d.a_abs_ms2);
    const alt     = safeNum(d.altitud_m);

    return {
      id: idNum,
      ts,
      tsISO: d.tsISO || null,
      ax: ax_g, ay: ay_g, az: az_g,
      roll, pitch, yaw,
      gx: safeNum(d.gx), gy: safeNum(d.gy), gz: safeNum(d.gz),
      gps_fix: safeNum(d.gps_fix), satellites: safeNum(d.satellites),
      mpu_ok: safeNum(d.mpu_ok), bme_ok: safeNum(d.bme_ok),
      tempExt, tempInt,
      a_abs_ms2: aabs,
      alt,
      vibRms: safeNum(d.vib_rms_g),
      vibPeak: safeNum(d.vib_peak_g),
      vdesc: safeNum(d.v_desc_ms)
    };
  }

  async function tick(refs) {
    const res = await fetch(ENDPOINT, { cache: 'no-store' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data || !Object.keys(data).length) return;

    const sampleId = data.id ?? data.tsISO;
    if (sampleId == null) return;
    if (String(sampleId) === latestSampleId) return;
    latestSampleId = String(sampleId);

    const s = applyCalibration(normalizeRow(data));
    updateKPIs(refs, s);
    addRow(refs.tbody, s);
    pushAndEvalVibration(refs.badge, s);

    if (window.set3DOrientation &&
        Number.isFinite(s.roll) && Number.isFinite(s.pitch)) {
      window.set3DOrientation({ ...s, yaw: Number.isFinite(s.yaw) ? s.yaw : 0 });
    }
  }

  function startFeed() {
    if (started) return;

    const refs = makeUIRefs();
    if (!refs.kAx && !refs.kRoll && !refs.kAlt) return;

    started = true;

    if (document.getElementById('model3d-main') &&
        typeof window.inicializarModelo3D === 'function') {
      window.inicializarModelo3D();
    }

    const poll = async () => {
      if (!started || polling) return;
      polling = true;
      try {
        await tick(makeUIRefs());
      } catch (error) {
        console.error('[modelo3d] tick error:', error);
      } finally {
        polling = false;
        if (started) pollHandle = setTimeout(poll, POLL_MS);
      }
    };
    poll();
  }

  function initializeVisiblePanel() {
    if (document.getElementById('kpiAx') || document.getElementById('kpiRoll')) {
      startFeed();
      updateCalibrationButtons();
      if (document.getElementById('model3d-main') &&
          typeof window.inicializarModelo3D === 'function' &&
          !window.__three3D?.renderer) {
        window.inicializarModelo3D();
      }
    }
  }

  document.addEventListener('DOMContentLoaded', initializeVisiblePanel);
  if (document.readyState !== 'loading') initializeVisiblePanel();

  const panelObserver = new MutationObserver(initializeVisiblePanel);
  panelObserver.observe(document.body, { childList: true, subtree: true });

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && !started) initializeVisiblePanel();
  });
})();
