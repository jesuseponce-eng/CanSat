# app.py
from flask import Flask, request, jsonify, send_from_directory
from flask_sqlalchemy import SQLAlchemy
from datetime import datetime, timedelta
import math
import statistics
import pytz
from dotenv import load_dotenv
from sqlalchemy import insert, inspect

from sqlalchemy import func, and_
from flask_cors import CORS
from functools import lru_cache
from shapely.geometry import Point
from queue import Empty, Queue
from threading import Event, Lock
import os
import uuid

# === Zona horaria (CDMX) y helper para hora local naive ===
mexico_tz = pytz.timezone("America/Mexico_City")
def local_now():
    # MySQL DATETIME no guarda tz; guardamos hora local sin tzinfo
    return datetime.now(mexico_tz).replace(tzinfo=None)

load_dotenv()

app = Flask(__name__)
CORS(app)

app.config['SQLALCHEMY_DATABASE_URI'] = (
    f"mysql+pymysql://{os.getenv('DB_USER')}:{os.getenv('DB_PASSWORD')}@"
    f"{os.getenv('DB_HOST')}/{os.getenv('DB_NAME')}"
)
app.config['SQLALCHEMY_TRACK_MODIFICATIONS'] = False
app.config['FRAMES_DIR'] = os.path.join(os.getcwd(), 'frames')

# Crear carpeta frames si no existe (evita 404 tontos)
os.makedirs(app.config['FRAMES_DIR'], exist_ok=True)

db = SQLAlchemy(app)

# ===== COMANDOS DE ESTACION A CANSAT =====
COMMAND_QUEUE = Queue()
COMMAND_JOBS = {}
COMMAND_JOBS_LOCK = Lock()
COMMAND_WAIT_SECONDS = 10

# === Servir archivos de /frames ===
@app.route('/frames/<path:filename>')
def frames(filename):
    return send_from_directory(app.config['FRAMES_DIR'], filename)

# ===== MODELOS =====
ACCELERATION_BASELINE = None
ACCELERATION_CALIBRATION = []
ACCELERATION_CALIBRATION_WINDOW = 5
ACCELERATION_STABILITY_THRESHOLD = 0.05
ACCELERATION_BASELINE_SHIFT_THRESHOLD = 0.10


def acceleration_deviation(ax, ay, az):
    if ACCELERATION_BASELINE is None:
        return None
    return math.sqrt(sum((value - baseline) ** 2 for value, baseline in zip(
        (ax, ay, az), ACCELERATION_BASELINE
    )))


def calibrate_acceleration(ax, ay, az):
    global ACCELERATION_BASELINE
    ACCELERATION_CALIBRATION.append((ax, ay, az))
    if len(ACCELERATION_CALIBRATION) > ACCELERATION_CALIBRATION_WINDOW:
        ACCELERATION_CALIBRATION.pop(0)
    if len(ACCELERATION_CALIBRATION) < ACCELERATION_CALIBRATION_WINDOW:
        return acceleration_deviation(ax, ay, az)

    axis_samples = tuple(zip(*ACCELERATION_CALIBRATION))
    if max(statistics.pstdev(axis) for axis in axis_samples) <= ACCELERATION_STABILITY_THRESHOLD:
        candidate = tuple(statistics.fmean(axis) for axis in axis_samples)
        baseline_shift = (
            math.sqrt(sum((value - baseline) ** 2 for value, baseline in zip(candidate, ACCELERATION_BASELINE)))
            if ACCELERATION_BASELINE is not None else math.inf
        )
        if baseline_shift > ACCELERATION_BASELINE_SHIFT_THRESHOLD:
            ACCELERATION_BASELINE = candidate
            persist_acceleration_baseline()

    return acceleration_deviation(ax, ay, az)


class SensorData(db.Model):
    __tablename__ = 'sensor_data'
    id  = db.Column(db.Integer, primary_key=True)

    # --- temperaturas ---
    temp_int  = db.Column(db.Float)   # °C
    temp_ext  = db.Column(db.Float)   # °C

    # --- meteo / gases ---
    humedad   = db.Column(db.Float)
    presion   = db.Column(db.Float)
    gas       = db.Column(db.Float)
    co        = db.Column(db.Float)
    h2        = db.Column(db.Float)
    ch4       = db.Column(db.Float)
    #nh3       = db.Column(db.Float)
    #etoh      = db.Column(db.Float)

    # NUEVOS (del TX S3)
    c2h       = db.Column(db.Float)   # C2H / C2H5OH del MiCS
    no2       = db.Column(db.Float)   # NO2 del MiCS
   

    # --- IMU / movimiento ---
    ax = db.Column(db.Float)
    ay = db.Column(db.Float)
    az = db.Column(db.Float)
    gx = db.Column(db.Float)
    gy = db.Column(db.Float)
    gz = db.Column(db.Float)


    # NUEVOS: velocidades integradas que manda tu TX
    vx = db.Column(db.Float)
    vy = db.Column(db.Float)
    vz = db.Column(db.Float)

    # NUEVOS: orientación
    pitch = db.Column(db.Float)
    roll  = db.Column(db.Float)
    yaw   = db.Column(db.Float)

    # --- geo ---
    lat = db.Column(db.Float, nullable=True)
    lon = db.Column(db.Float, nullable=True)
    satellites = db.Column(db.Integer)
    gps_fix = db.Column(db.Integer)
    mpu_ok = db.Column(db.Integer)
    bme_ok = db.Column(db.Integer)

    # --- enlace LoRa ---
    numero_paquete = db.Column(db.BigInteger)
    rssi = db.Column(db.Float)
    snr = db.Column(db.Float)
    paquetes_recibidos = db.Column(db.BigInteger)
    paquetes_perdidos = db.Column(db.BigInteger)
    recepcion_pct = db.Column(db.Float)

    # --- derivados ---
    a_abs_ms2  = db.Column(db.Float)  # m/s²
    #vib_x_g    = db.Column(db.Float)
    #vib_y_g    = db.Column(db.Float)
    #vib_z_g    = db.Column(db.Float)
    #vib_rms_g  = db.Column(db.Float)
    #vib_peak_g = db.Column(db.Float)
    altitud_m  = db.Column(db.Float)
    #v_desc_ms  = db.Column(db.Float)

    timestamp = db.Column(db.DateTime, default=local_now)

    @property
    def temperatura_actual(self):
        for v in (self.temp_ext, self.temp_int):
            if v is not None:
                return float(v)
        return None

    @property
    def a_abs_calc(self):
        if self.a_abs_ms2 is not None:
            return float(self.a_abs_ms2)
        try:
            if self.ax is None or self.ay is None or self.az is None:
                return None
            return acceleration_deviation(self.ax, self.ay, self.az)
        except Exception:
            return None

    def to_dict(self):
        return {
            'id': self.id,

            # temperaturas
            'temp_int': self.temp_int,
            'temp_ext': self.temp_ext,
            'temperatura_actual': self.temperatura_actual,

            # meteo/gases
            'humedad': self.humedad,
            'presion': self.presion,
            'gas': self.gas,
            'co': self.co,
            'h2': self.h2,
            'ch4': self.ch4,
            #'nh3': self.nh3,
            #'etoh': self.etoh,
            'c2h': self.c2h,
            'no2': self.no2,
           

            # IMU y movimiento
            'ax': self.ax, 'ay': self.ay, 'az': self.az,
            'gx': self.gx, 'gy': self.gy, 'gz': self.gz,
            'vx': self.vx, 'vy': self.vy, 'vz': self.vz,
            'pitch': self.pitch, 'roll': self.roll, 'yaw': self.yaw,

            # derivados
            'a_abs_ms2': self.a_abs_ms2 if self.a_abs_ms2 is not None else self.a_abs_calc,

            # vibración
            #'vib_x_g': self.vib_x_g, 'vib_y_g': self.vib_y_g, 'vib_z_g': self.vib_z_g,
            #'vib_rms_g': self.vib_rms_g, 'vib_peak_g': self.vib_peak_g,

            # geo/altitud/descenso
            'lat': self.lat, 'lon': self.lon,
            'satellites': self.satellites,
            'gps_fix': self.gps_fix,
            'mpu_ok': self.mpu_ok,
            'bme_ok': self.bme_ok,
            'altitud_m': self.altitud_m,
            'numero_paquete': self.numero_paquete,
            'rssi': self.rssi,
            'snr': self.snr,
            'paquetes_recibidos': self.paquetes_recibidos,
            'paquetes_perdidos': self.paquetes_perdidos,
            'recepcion_pct': self.recepcion_pct,
            #'v_desc_ms': self.v_desc_ms,

            'timestamp': self.timestamp.isoformat() if self.timestamp else None,
        }

class ImageFrame(db.Model):
    __tablename__ = 'image_frames'
    id = db.Column(db.Integer, primary_key=True)
    url = db.Column(db.String(255), nullable=False)
    num = db.Column(db.Integer, nullable=True)
    lat = db.Column(db.Float, nullable=True)
    lon = db.Column(db.Float, nullable=True)
    timestamp = db.Column(db.DateTime, default=local_now)

    def to_dict(self):
        return {
            'id': self.id,
            'url': self.url,
            'lat': self.lat,
            'lon': self.lon,
            'num': self.num,
            'timestamp': self.timestamp.isoformat() if self.timestamp else None
        }


class AccelerationCalibration(db.Model):
    __tablename__ = 'acceleration_calibration'
    id = db.Column(db.Integer, primary_key=True)
    ax = db.Column(db.Float, nullable=False)
    ay = db.Column(db.Float, nullable=False)
    az = db.Column(db.Float, nullable=False)
    timestamp = db.Column(db.DateTime, default=local_now, nullable=False)


def persist_acceleration_baseline():
    calibration = db.session.get(AccelerationCalibration, 1)
    if calibration is None:
        calibration = AccelerationCalibration(id=1)
        db.session.add(calibration)
    calibration.ax, calibration.ay, calibration.az = ACCELERATION_BASELINE
    calibration.timestamp = local_now()
    recent_rows = (SensorData.query
                   .filter(SensorData.ax.isnot(None), SensorData.ay.isnot(None), SensorData.az.isnot(None))
                   .order_by(SensorData.id.desc())
                   .limit(ACCELERATION_CALIBRATION_WINDOW - 1)
                   .all())
    for row in recent_rows:
        row.a_abs_ms2 = acceleration_deviation(row.ax, row.ay, row.az)
    db.session.commit()

with app.app_context():
    db.create_all()
    existing_columns = {column['name'] for column in inspect(db.engine).get_columns('sensor_data')}
    radio_columns = {
        'numero_paquete': 'BIGINT',
        'rssi': 'FLOAT',
        'snr': 'FLOAT',
        'paquetes_recibidos': 'BIGINT',
        'paquetes_perdidos': 'BIGINT',
        'recepcion_pct': 'FLOAT',
    }
    cansat_columns = {
        'gx': 'FLOAT',
        'gy': 'FLOAT',
        'gz': 'FLOAT',
        'satellites': 'INTEGER',
        'gps_fix': 'INTEGER',
        'mpu_ok': 'INTEGER',
        'bme_ok': 'INTEGER',
    }
    with db.engine.begin() as connection:
        for column, sql_type in {**radio_columns, **cansat_columns}.items():
            if column not in existing_columns:
                connection.exec_driver_sql(
                    f'ALTER TABLE sensor_data ADD COLUMN {column} {sql_type} NULL'
                )
    recent_axes = (SensorData.query
                   .with_entities(SensorData.ax, SensorData.ay, SensorData.az)
                   .filter(SensorData.ax.isnot(None), SensorData.ay.isnot(None), SensorData.az.isnot(None))
                   .order_by(SensorData.id.desc())
                   .limit(100)
                   .all())
    saved_calibration = db.session.get(AccelerationCalibration, 1)
    if saved_calibration:
        ACCELERATION_BASELINE = (saved_calibration.ax, saved_calibration.ay, saved_calibration.az)
    elif len(recent_axes) >= 20:
        axis_samples = tuple(zip(*recent_axes))
        if max(statistics.pstdev(axis) for axis in axis_samples) <= 0.25:
            ACCELERATION_BASELINE = tuple(statistics.fmean(axis) for axis in axis_samples)
            db.session.add(AccelerationCalibration(id=1, ax=ACCELERATION_BASELINE[0],
                                                   ay=ACCELERATION_BASELINE[1], az=ACCELERATION_BASELINE[2]))
            db.session.commit()

# ===== ENDPOINTS =====
 
# variable global
LAST_SAMPLE = {}


@app.route('/api/comandos/ping', methods=['POST'])
def ping_cansat():
    request_id = str(uuid.uuid4())
    job = {'event': Event(), 'result': None}
    with COMMAND_JOBS_LOCK:
        COMMAND_JOBS[request_id] = job
    COMMAND_QUEUE.put(request_id)

    try:
        if not job['event'].wait(COMMAND_WAIT_SECONDS):
            return jsonify({
                'error': 'No hubo respuesta del CanSat. Verifica el receptor USB y la ESP32.',
                'request_id': request_id,
            }), 504
        if not job['result']['ok']:
            return jsonify({'error': job['result']['error']}), 502
        return jsonify({
            'ok': True,
            'message': 'CanSat respondió PONG por LoRa.',
            'request_id': request_id,
        })
    finally:
        with COMMAND_JOBS_LOCK:
            COMMAND_JOBS.pop(request_id, None)


@app.route('/api/comandos/siguiente', methods=['GET'])
def siguiente_comando():
    try:
        request_id = COMMAND_QUEUE.get_nowait()
    except Empty:
        return '', 204
    return jsonify({'request_id': request_id, 'command': 'PING'})


@app.route('/api/comandos/<request_id>/resultado', methods=['POST'])
def resultado_comando(request_id):
    payload = request.get_json(silent=True) or {}
    ok = payload.get('ok')
    if not isinstance(ok, bool):
        return jsonify({'error': 'El campo ok debe ser booleano.'}), 400

    with COMMAND_JOBS_LOCK:
        job = COMMAND_JOBS.get(request_id)
        if job is None:
            return jsonify({'error': 'Comando inexistente o expirado.'}), 404
        job['result'] = {
            'ok': ok,
            'error': str(payload.get('error') or 'No se confirmó el PING por radio.'),
        }
        job['event'].set()
    return jsonify({'ok': True})


@app.route('/api/guardar', methods=['POST'])
def guardar_datos():
    global LAST_SAMPLE
    try:
        payload = request.get_json(force=True) or {}

        # === MAPEOS QUE SOPORTA ESTA API ===
        mapping = {
            # temperaturas
            "T": "temp_ext",
            "TI": "temp_int",

            # meteo / gases
            "H": "humedad",
            "P": "presion",
            "G": "gas",
            "GAS": "gas",

            # gases extra
            "CO": "co",
            "CH4": "ch4",
            "C2H": "c2h",
            "H2": "h2",
            "NO2": "no2",

            # IMU / movimiento
            "AX": "ax",
            "AY": "ay",
            "AZ": "az",
            "GX": "gx",
            "GY": "gy",
            "GZ": "gz",
            "VX": "vx",
            "VY": "vy",
            "VZ": "vz",

            # orientación nueva
            "PI": "pitch",
            "RL": "roll",
            "YW": "yaw",

            # geo
            "LT": "lat",
            "LN": "lon",
            "LAT": "lat",
            "LON": "lon",
            "SAT": "satellites",
            "FIX": "gps_fix",
            "MPU": "mpu_ok",
            "BME": "bme_ok",

            # altura
            "A": "altitud_m",

            # enlace LoRa
            "N": "numero_paquete",
            "RSSI": "rssi",
            "SNR": "snr",
            "RECIBIDOS": "paquetes_recibidos",
            "PERDIDOS": "paquetes_perdidos",
            "RECEPCION": "recepcion_pct",

            # aceleración absoluta directa
            "AABS": "a_abs_ms2",
        }

        row = {}
        integer_fields = {
            "numero_paquete", "paquetes_recibidos", "paquetes_perdidos",
            "satellites", "gps_fix", "mpu_ok", "bme_ok",
        }
        for key_json, col_db in mapping.items():
            if key_json in payload and payload[key_json] is not None:
                try:
                    if col_db in integer_fields:
                        row[col_db] = int(float(payload[key_json]))
                    else:
                        row[col_db] = float(payload[key_json])
                except (TypeError, ValueError):
                    row[col_db] = None

        if row.get("gps_fix") == 0:
            row.update({"lat": None, "lon": None, "altitud_m": None})
        if row.get("mpu_ok") == 0:
            row.update({
                "ax": None, "ay": None, "az": None,
                "gx": None, "gy": None, "gz": None,
                "pitch": None, "roll": None, "yaw": None, "a_abs_ms2": None,
            })
        if row.get("bme_ok") == 0:
            row.update({
                "temp_ext": None, "humedad": None,
                "presion": None, "gas": None,
            })
        #Parcheando ubicación porque no sirve el GPS
            #Ubicación de cholula 19.05803237539983, -98.29876281180091
            #lat = 19.058032
            #lon = -98.298762
        # === BLOQUE PARA MODIFICAR LAT/LON ANTES DE GUARDAR ===
        lat = row.get("lat")
        lon = row.get("lon")
        if lat is not None and lon is not None:
            try:
                # Aquí metes tu lógica real de corrección
                # === EJEMPLO: offset y redondeo ===
                # lat += 0.0001
                # lon -= 0.0002

                # Si solo quieres redondear:
                lat = round(lat, 6)
                lon = round(lon, 6)
            except (TypeError, ValueError):
                pass
            else:
                row["lat"] = lat
                row["lon"] = lon
        # =====================================================
        #Parcheando ubicación porque no sirve el GPS
            #Ubicación de cholula 19.05803237539983, -98.29876281180091
            #lat = 19.058032
            #lon = -98.298762

        # si no vino a_abs_ms2 pero sí ax/ay/az, la calculamos
        if "a_abs_ms2" not in row:
            ax = row.get("ax")
            ay = row.get("ay")
            az = row.get("az")
            if ax is not None and ay is not None and az is not None:
                row["a_abs_ms2"] = calibrate_acceleration(ax, ay, az)
        elif row.get("ax") is not None and row.get("ay") is not None and row.get("az") is not None:
            row["a_abs_ms2"] = calibrate_acceleration(row["ax"], row["ay"], row["az"])

        # timestamp siempre
        ts = local_now()
        row["timestamp"] = ts

        # ===== guardar en DB (insert rápido) =====
        sensor_table = SensorData.__table__
        stmt = insert(sensor_table).values(**row)
        with db.engine.begin() as conn:
            result = conn.execute(stmt)
            new_id = result.inserted_primary_key[0] if result.inserted_primary_key else None

        # ===== guardar última muestra en RAM =====
        LAST_SAMPLE = {
            "id": new_id,
            "tsISO": ts.isoformat(),
            # movimiento
            "ax": row.get("ax"),
            "ay": row.get("ay"),
            "az": row.get("az"),
            "gx": row.get("gx"),
            "gy": row.get("gy"),
            "gz": row.get("gz"),
            "pitch": row.get("pitch"),
            "roll": row.get("roll"),
            "yaw": row.get("yaw"),
            # temps
            "temp_ext": row.get("temp_ext"),
            "temp_int": row.get("temp_int"),
            # gases
            "co": row.get("co"),
            "ch4": row.get("ch4"),
            "c2h": row.get("c2h"),
            "h2": row.get("h2"),
            "no2": row.get("no2"),
            # derivados / alt / gps
            "a_abs_ms2": row.get("a_abs_ms2"),
            "altitud_m": row.get("altitud_m"),
            "lat": row.get("lat"),
            "lon": row.get("lon"),
            "satellites": row.get("satellites"),
            "gps_fix": row.get("gps_fix"),
            "mpu_ok": row.get("mpu_ok"),
            "bme_ok": row.get("bme_ok"),
            "numero_paquete": row.get("numero_paquete"),
            "rssi": row.get("rssi"),
            "snr": row.get("snr"),
            "paquetes_recibidos": row.get("paquetes_recibidos"),
            "paquetes_perdidos": row.get("paquetes_perdidos"),
            "recepcion_pct": row.get("recepcion_pct"),
        }

        return jsonify({"ok": True, "id": new_id}), 201

    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route('/api/calibrar-aceleracion', methods=['POST'])
def calibrar_aceleracion():
    global ACCELERATION_BASELINE, ACCELERATION_CALIBRATION
    try:
        recent_rows = (SensorData.query
                       .filter(SensorData.ax.isnot(None), SensorData.ay.isnot(None), SensorData.az.isnot(None))
                       .order_by(SensorData.id.desc())
                       .limit(15)
                       .all())
        if len(recent_rows) < 15:
            return jsonify({'error': 'Se necesitan al menos 15 muestras para calibrar.'}), 409

        axis_samples = tuple(zip(*[(row.ax, row.ay, row.az) for row in recent_rows]))
        deviations = tuple(statistics.pstdev(axis) for axis in axis_samples)
        if max(deviations) > 0.25:
            return jsonify({
                'error': 'El CanSat no está estable. Déjalo quieto y vuelve a calibrar.',
                'desviacion_por_eje': deviations,
            }), 409

        ACCELERATION_BASELINE = tuple(statistics.fmean(axis) for axis in axis_samples)
        ACCELERATION_CALIBRATION.clear()

        calibration = db.session.get(AccelerationCalibration, 1)
        if calibration is None:
            calibration = AccelerationCalibration(id=1)
            db.session.add(calibration)
        calibration.ax, calibration.ay, calibration.az = ACCELERATION_BASELINE
        calibration.timestamp = local_now()

        for row in recent_rows:
            row.a_abs_ms2 = acceleration_deviation(row.ax, row.ay, row.az)

        db.session.commit()
        return jsonify({
            'ok': True,
            'muestras': len(recent_rows),
            'referencia': dict(zip(('ax', 'ay', 'az'), ACCELERATION_BASELINE)),
            'ruido_maximo_ms2': max(deviations),
            'a_abs_ms2': recent_rows[0].a_abs_ms2,
        })
    except Exception as e:
        db.session.rollback()
        return jsonify({'error': str(e)}), 500


@app.route('/api/datos', methods=['GET'])
def obtener_datos():
    try:
        page = request.args.get('page', 1, type=int)
        per_page = request.args.get('per_page', 100, type=int)
        datos = SensorData.query.order_by(SensorData.timestamp.desc()).paginate(page=page, per_page=per_page)
        return jsonify({
            'datos': [dato.to_dict() for dato in datos.items],
            'total_paginas': datos.pages,
            'pagina_actual': datos.page,
            'total_registros': datos.total
        })
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/api/live', methods=['GET'])
def api_live():
    # NO toca la base
    return jsonify(LAST_SAMPLE or {})



@app.route('/api/estadisticas', methods=['GET'])
def obtener_estadisticas():
    try:
        inicio = request.args.get('inicio')
        fin = request.args.get('fin')
        query = SensorData.query
        if inicio and fin:
            query = query.filter(and_(
                SensorData.timestamp >= datetime.fromisoformat(inicio),
                SensorData.timestamp <= datetime.fromisoformat(fin)
            ))

        # Campos a estadisticar (sin 'temperatura' legacy)
        campos = [
            'temp_int', 'temp_ext',
            'humedad', 'presion', 'gas', 'co', 'h2', 'ch4', 'c2h','no2',
            'ax', 'ay', 'az', 'a_abs_ms2',
            'altitud_m'
        ]

        out = {}

        def stats_for(field):
            col = getattr(SensorData, field)
            r = query.with_entities(
                func.avg(col).label('prom'),
                func.min(col).label('min'),
                func.max(col).label('max'),
            ).first()
            return {
                'promedio': float(r.prom) if r and r.prom is not None else 0.0,
                'minimo': float(r.min) if r and r.min is not None else 0.0,
                'maximo': float(r.max) if r and r.max is not None else 0.0,
            }

        for campo in campos:
            out[campo] = stats_for(campo)

        # temperatura_actual = COALESCE(temp_ext, temp_int)
        r = query.with_entities(
            func.avg(func.coalesce(SensorData.temp_ext, SensorData.temp_int)).label('prom'),
            func.min(func.coalesce(SensorData.temp_ext, SensorData.temp_int)).label('min'),
            func.max(func.coalesce(SensorData.temp_ext, SensorData.temp_int)).label('max'),
        ).first()
        out['temperatura_actual'] = {
            'promedio': float(r.prom) if r and r.prom is not None else 0.0,
            'minimo': float(r.min) if r and r.min is not None else 0.0,
            'maximo': float(r.max) if r and r.max is not None else 0.0,
        }

        return jsonify(out)
    except Exception as e:
        return jsonify({'error': str(e)}), 500


@app.route('/api/datos/rango', methods=['GET'])
def obtener_datos_rango():
    try:
        horas = request.args.get('horas', 24, type=int)
        tiempo_limite = local_now() - timedelta(hours=horas)
        datos = (SensorData.query
                 .filter(SensorData.timestamp >= tiempo_limite)
                 .order_by(SensorData.timestamp.asc())
                 .all())
        return jsonify([d.to_dict() for d in datos])
    except Exception as e:
        return jsonify({'error': str(e)}), 500

# ===== FRAMES =====
@app.route('/api/frames', methods=['POST'])
def crear_frame():
    """Espera JSON: { "url": "...", "num": 42, "lat": 19.043, "lon": -98.203 }"""
    try:
        p = request.get_json(force=True) or {}
        url = p.get('url')
        if not url:
            return jsonify({'error': 'url es requerido'}), 400

        frame = ImageFrame(
            url=str(url),
            num=int(p['num']) if p.get('num') is not None else None,
            lat=float(p['lat']) if p.get('lat') is not None else None,
            lon=float(p['lon']) if p.get('lon') is not None else None,
            timestamp=local_now(),  # SIEMPRE current time
        )
        db.session.add(frame)
        db.session.commit()
        return jsonify(frame.to_dict()), 201
    except Exception as e:
        db.session.rollback()
        return jsonify({'error': str(e)}), 500

@app.route('/api/datos/latest', methods=['GET'])
def datos_latest():
    """
    Devuelve los últimos N registros pero SOLO con los campos que realmente usa el front.
    Default: 50
    """
    try:
        limit = request.args.get('limit', 50, type=int)
        # seleccionamos columnas concretas
        q = (
            db.session.query(
                SensorData.id,
                SensorData.timestamp,
                # IMU
                SensorData.ax,
                SensorData.ay,
                SensorData.az,
                SensorData.gx,
                SensorData.gy,
                SensorData.gz,
                SensorData.pitch,
                SensorData.roll,
                SensorData.yaw,
                # temperaturas
                SensorData.temp_ext,
                SensorData.temp_int,
                # meteo / gases
                SensorData.humedad,
                SensorData.presion,
                SensorData.gas,
                SensorData.co,
                SensorData.h2,
                SensorData.ch4,
                SensorData.c2h,
                SensorData.no2,
                
                # derivados / geo
                SensorData.a_abs_ms2,
                SensorData.altitud_m,
                SensorData.lat,
                SensorData.lon,
                SensorData.satellites,
                SensorData.gps_fix,
                SensorData.mpu_ok,
                SensorData.bme_ok,
                SensorData.numero_paquete,
                SensorData.rssi,
                SensorData.snr,
                SensorData.paquetes_recibidos,
                SensorData.paquetes_perdidos,
                SensorData.recepcion_pct,
            )
            .order_by(SensorData.id.desc())
            .limit(limit)
        )
        rows = q.all()

        out = []
        for r in rows:
            out.append(
                {
                    "id": r.id,
                    "timestamp": r.timestamp.isoformat() if r.timestamp else None,
                    "ax": r.ax,
                    "ay": r.ay,
                    "az": r.az,
                    "gx": r.gx,
                    "gy": r.gy,
                    "gz": r.gz,
                    "pitch": r.pitch,
                    "roll": r.roll,
                    "yaw": r.yaw,
                    "temp_ext": r.temp_ext,
                    "temp_int": r.temp_int,
                    "humedad": r.humedad,
                    "presion": r.presion,
                    "gas": r.gas,
                    "co": r.co,
                    "h2": r.h2,
                    "ch4": r.ch4,
                    "c2h": r.c2h,
                    "no2": r.no2,
                    "a_abs_ms2": r.a_abs_ms2,
                    "altitud_m": r.altitud_m,
                    "lat": r.lat,
                    "lon": r.lon,
                    "satellites": r.satellites,
                    "gps_fix": r.gps_fix,
                    "mpu_ok": r.mpu_ok,
                    "bme_ok": r.bme_ok,
                    "numero_paquete": r.numero_paquete,
                    "rssi": r.rssi,
                    "snr": r.snr,
                    "paquetes_recibidos": r.paquetes_recibidos,
                    "paquetes_perdidos": r.paquetes_perdidos,
                    "recepcion_pct": r.recepcion_pct,
                }
            )

        # los regresamos en orden más nuevo primero (como ya vienen)
        return jsonify(out)
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route('/api/frames/latest', methods=['GET'])
def ultimo_frame():
    """Devuelve el último frame por id DESC."""
    try:
        frame = ImageFrame.query.order_by(ImageFrame.id.desc()).first()
        if not frame:
            return jsonify({'id': None, 'url': None, 'lat': None, 'lon': None, 'num': None, 'timestamp': None})
        return jsonify(frame.to_dict())
    except Exception as e:
        return jsonify({'error': str(e)}), 500


# ===== BIOMAS (carga + lookup + scoring) =====
from pathlib import Path
import geopandas as gpd

# GeoJSON ya recortado/simplificado a MX (exportado con tools/export_ecoregions_mx.py)
DATA_PRE = Path(r"C:/xampp/htdocs/cansat/api/data/wwf_ecoregions_mx_simplified.geojson")

ECO = None
try:
    ECO = gpd.read_file(DATA_PRE).to_crs(epsg=4326)
    try:
        _ = ECO.sindex  # índice espacial si tienes rtree
    except Exception:
        pass
    print(f"[OK] Biomas (MX) cargados: {len(ECO)} polígonos")
except Exception as e:
    print(f"[WARN] No se pudo cargar {DATA_PRE}: {e}")
    ECO = None  # seguimos; devolverá UNKNOWN

# === Mapea código->nombre y devuelve nombre + code (cacheado) ===
@lru_cache(maxsize=8192)
def _lookup_cached(lat_r: float, lon_r: float):
    if ECO is None:
        return {"biome": "UNKNOWN", "biome_code": None, "ecoregion": None}
    p = Point(lon_r, lat_r)
    idx = ECO.sindex.query(p, predicate="intersects") if hasattr(ECO, "sindex") else ECO.index
    sub = ECO.iloc[idx]
    hit = sub[sub.intersects(p)]
    if not hit.empty:
        r = hit.iloc[0]
        biome_name, biome_code = biome_name_from_value(r.get("BIOME"))
        return {
            "biome": biome_name,
            "biome_code": biome_code,
            "ecoregion": str(r.get("ECO_NAME"))
        }
    return {"biome": "UNKNOWN", "biome_code": None, "ecoregion": None}

def biome_lookup(lat: float, lon: float):
    if lat is None or lon is None:
        return {"biome": "UNKNOWN", "biome_code": None, "ecoregion": None}
    # cache por cuadrito (~100 m)
    return _lookup_cached(round(float(lat), 3), round(float(lon), 3))


# Pesos
BIOME_WEIGHTS = {
    "Tropical & Subtropical Moist Broadleaf Forests": {"T":0.30,"H":0.50,"G":0.20},  # 1
    "Tropical & Subtropical Dry Broadleaf Forests":   {"T":0.45,"H":0.35,"G":0.20},  # 2
    "Tropical & Subtropical Coniferous Forests":      {"T":0.40,"H":0.45,"G":0.15},  # 3
    "Temperate Broadleaf & Mixed Forests":            {"T":0.35,"H":0.45,"G":0.20},  # 4
    "Temperate Conifer Forests":                      {"T":0.35,"H":0.45,"G":0.20},  # 5
    "Boreal Forests/Taiga":                           {"T":0.30,"H":0.50,"G":0.20},  # 6
    "Tropical & Subtropical Grasslands, Savannas & Shrublands": {"T":0.40,"H":0.40,"G":0.20}, # 7
    "Temperate Grasslands, Savannas & Shrublands":    {"T":0.45,"H":0.35,"G":0.20},  # 8
    "Flooded Grasslands & Savannas":                  {"T":0.25,"H":0.55,"G":0.20},  # 9
    "Montane Grasslands & Shrublands":                {"T":0.40,"H":0.40,"G":0.20},  # 10
    "Tundra":                                         {"T":0.30,"H":0.50,"G":0.20},  # 11
    "Mediterranean Forests, Woodlands and Scrub":     {"T":0.55,"H":0.25,"G":0.20},  # 12
    "Deserts & Xeric Shrublands":                     {"T":0.70,"H":0.20,"G":0.10},  # 13
    "Mangroves":                                      {"T":0.20,"H":0.60,"G":0.20},  # 14
    "UNKNOWN":                                        {"T":0.40,"H":0.40,"G":0.20},
}

# === WWF BIOME: código -> nombre (14 clases) ===
BIOME_CODE_TO_NAME = {
    1:  "Tropical & Subtropical Moist Broadleaf Forests",
    2:  "Tropical & Subtropical Dry Broadleaf Forests",
    3:  "Tropical & Subtropical Coniferous Forests",
    4:  "Temperate Broadleaf & Mixed Forests",
    5:  "Temperate Conifer Forests",
    6:  "Boreal Forests/Taiga",
    7:  "Tropical & Subtropical Grasslands, Savannas & Shrublands",
    8:  "Temperate Grasslands, Savannas & Shrublands",
    9:  "Flooded Grasslands & Savannas",
    10: "Montane Grasslands & Shrublands",
    11: "Tundra",
    12: "Mediterranean Forests, Woodlands and Scrub",
    13: "Deserts & Xeric Shrublands",
    14: "Mangroves",
}

def biome_name_from_value(v):
    """
    v puede ser 3, 3.0, '3.0' o ya un nombre.
    Devuelve (nombre_bioma, codigo_o_None).
    """
    try:
        code = int(float(v))
        return BIOME_CODE_TO_NAME.get(code, "UNKNOWN"), code
    except (TypeError, ValueError):
        name = str(v) if v is not None else "UNKNOWN"
        return name, None

def weights_for(biome_name: str):
    return BIOME_WEIGHTS.get(biome_name, BIOME_WEIGHTS["UNKNOWN"])

def check_weights_coverage():
    """Comprueba que hay pesos para todos los biomas presentes en el GeoJSON."""
    if ECO is None:
        print("[WARN] ECO no cargado; no se puede verificar pesos.")
        return
    try:
        present = set()
        for v in ECO["BIOME"].dropna().unique().tolist():
            name, _ = biome_name_from_value(v)
            present.add(name)
        missing = [b for b in present if b not in BIOME_WEIGHTS]
        if missing:
            print(f"[WARN] Faltan pesos para: {missing}. Usaré UNKNOWN por defecto.")
            for b in missing:
                BIOME_WEIGHTS[b] = BIOME_WEIGHTS["UNKNOWN"]
        else:
            print("[OK] Hay pesos para todos los biomas presentes en el dataset.")
    except Exception as e:
        print(f"[WARN] No se pudo verificar cobertura de pesos: {e}")

def score_T_H(t, h):
    # Escalado simple y robusto a None
    t = float(t) if t is not None else 0.0
    h = float(h) if h is not None else 0.0
    st = min(max((t - 20.0) * (100.0/20.0), 0.0), 100.0)  # 20→0, 40→100
    sh = min(max((60.0 - h) * (100.0/40.0), 0.0), 100.0)  # 60→0, 20→100
    return st, sh

def score_gases(r):
    s = 0.0
    if r.co  and r.co  > 9: s += min((r.co/30)*100, 100) * 0.6
    if r.ch4 and r.ch4 > 2: s += min((r.ch4/10)*100, 100) * 0.3
    if r.h2  and r.h2  > 1: s += min((r.h2/5)*100, 100)  * 0.1
    return min(s, 100)

def clase(ix):
    return "alto" if ix >= 70 else ("medio" if ix >= 40 else "bajo")

# Llama a la verificación al iniciar
check_weights_coverage()

@app.route("/api/bioma", methods=["GET"])
def bioma_endpoint():
    lat = request.args.get("lat", type=float)
    lon = request.args.get("lon", type=float)
    return jsonify(biome_lookup(lat, lon))

@app.route("/api/bioma_raw")
def bioma_raw():
    lat = request.args.get("lat", type=float)
    lon = request.args.get("lon", type=float)
    if ECO is None or lat is None or lon is None:
        return jsonify({"BIOME_raw": None})
    p = Point(lon, lat)
    idx = ECO.sindex.query(p, predicate="intersects") if hasattr(ECO, "sindex") else ECO.index
    sub = ECO.iloc[idx]
    hit = sub[sub.intersects(p)]
    if hit.empty:
        return jsonify({"BIOME_raw": None})
    raw = hit.iloc[0].get("BIOME")
    return jsonify({"BIOME_raw": raw})

@app.route("/api/riesgo_bioma", methods=["GET"])
def riesgo_bioma():
    minutes     = request.args.get("window_minutes", 60, type=int)
    limit       = request.args.get("limit", None, type=int)
    min_samples = request.args.get("min_samples", 10, type=int)

    now = local_now()
    start = now - timedelta(minutes=minutes)

    q = (SensorData.query
         .filter(SensorData.timestamp >= start)
         .order_by(SensorData.timestamp.desc()))
    if limit:
        q = q.limit(limit)
    rows = q.all()

    if len(rows) < min_samples:
        return jsonify({
            "window_minutes": minutes, "count": len(rows),
            "clase": "insuficiente", "riesgo": None,
            "msg": f"Solo {len(rows)} muestras en {minutes} min"
        })

    # Coordenadas
    lat, lon = getattr(rows[0], "lat", None), getattr(rows[0], "lon", None)
    if lat is None or lon is None:
        last_loc = (SensorData.query
                    .filter(SensorData.lat.isnot(None),
                            SensorData.lon.isnot(None))
                    .order_by(SensorData.timestamp.desc())
                    .first())
        if last_loc:
            lat, lon = last_loc.lat, last_loc.lon

    info = biome_lookup(lat, lon)
    W = BIOME_WEIGHTS.get(info["biome"], BIOME_WEIGHTS["UNKNOWN"])

    totals, muestras = [], []
    for r in rows:
        st, sh = score_T_H(r.temperatura_actual, r.humedad)  # << usa temp_ext/temp_int
        sg = score_gases(r)
        total = W["T"]*st + W["H"]*sh + W["G"]*sg
        totals.append(total)
        muestras.append({
            "id": r.id, "ts": r.timestamp.isoformat() if r.timestamp else None,
            "t_act": r.temperatura_actual, "h": r.humedad,
            "score_T": round(st,1), "score_H": round(sh,1), "score_G": round(sg,1),
            "score_total": round(total,1)
        })

    avg_total = sum(totals) / len(totals)
    return jsonify({
        "window_minutes": minutes,
        "limit": limit,
        "min_samples": min_samples,
        "count": len(rows),
        "coord_usada": {"lat": lat, "lon": lon},
        "bioma": info,
        "pesos": W,
        "riesgo": round(avg_total, 1),
        "clase": clase(avg_total),
        "muestras": muestras
    })

if __name__ == '__main__':
    app.run(port=5000, debug=True, threaded=True)
