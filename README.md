# CanSat - Estacion terrena

Aplicacion web para recibir y visualizar telemetria e imagenes de un CanSat. El
repositorio contiene dos aplicaciones que trabajan juntas:

- **Laravel** sirve las paginas web, presenta los datos y comunica el navegador
  con la API.
- **Flask** recibe telemetria e imagenes, las guarda en MySQL y entrega los datos
  a Laravel.
- **`serial_sender.py`** conecta el receptor LoRa por USB con la API Flask. No
  requiere que el CanSat este conectado para desarrollar la interfaz, aunque sin
  telemetria recibida no hay muestras nuevas que mostrar.

## Que incluye la web

- **Datos en tiempo real (`/sensores`)**: resumen de telemetria, imagenes recibidas
  por radio, mapa y modelo 3D con orientacion del CanSat, graficas y riesgo por
  bioma.
- **Estadisticas (`/sensores/estadisticas`)**: promedios, consultas por rango de
  tiempo e historial de muestras.
- **Control de mision (`/sensores/control`)**: vistas de control que incluyen
  el mapa y el modelo 3D.
- **Modelo 3D**: usa los angulos `roll`, `pitch` y `yaw` de la muestra mas reciente.
  La calibracion de orientacion pone a cero la posicion actual y se conserva en
  el navegador.
- **Camara por radio**: el panel muestra la ultima imagen recibida, no una
  conexion de video directa al navegador.
- **Mapa y biomas**: muestra ubicacion y calcula informacion de riesgo con los
  datos ambientales y la capa geografica local.

## Estructura del repositorio

```text
.
|-- README.md                 Guia del proyecto
|-- emisor.py                 Utilidad de emision existente
|-- index.html, index.php     Entradas web antiguas/de compatibilidad
|-- api/                      Servicio de datos en Flask y herramientas Python
|   |-- app.py                API, modelos, persistencia, biomas y riesgo
|   |-- serial_sender.py      Puente USB/serial: telemetria e imagenes hacia Flask
|   |-- esp32_hub.py          Hub opcional para distribuir un stream MJPEG
|   |-- emision.py            Emision opcional de camara a YouTube con FFmpeg
|   |-- relay_youtube.py      Relay auxiliar de video existente
|   |-- config_privado.py     Configuracion local privada; no publicar credenciales
|   |-- requirements.txt      Dependencias Python
|   |-- data/                 GeoJSON y datos de biomas
|   |-- frames/               Imagenes recibidas y almacenadas
|   |-- auxiliares/           Herramientas de analisis y preparacion de datos
|   `-- archivosPasados/      Versiones anteriores del emisor
|-- frames/                   Frames de muestra del repositorio
`-- frontend/                 Aplicacion Laravel y frontend
    |-- app/                  Controladores, modelos y proveedores
    |-- bootstrap/            Arranque de Laravel
    |-- config/               Configuracion de Laravel y servicios
    |-- database/              Migraciones, seeders y factories
    |-- public/                Punto publico, assets y archivos servidos
    |-- resources/
    |   |-- css/              Estilos
    |   |-- js/               Mapa, graficas, video y modelo 3D
    |   `-- views/             Plantillas Blade y paginas
    |-- routes/               Rutas web y API de Laravel
    |-- storage/              Logs, cache y archivos temporales de Laravel
    |-- tests/                Pruebas PHP
    |-- composer.json         Dependencias y comandos PHP
    |-- package.json          Dependencias y comandos JavaScript
    `-- vite.config.js         Compilacion y servidor de desarrollo Vite
```

### Archivos principales

| Archivo | Responsabilidad |
|---|---|
| `api/app.py` | API Flask, esquema de sensores e imagenes, calculos y endpoints. |
| `api/serial_sender.py` | Lee el puerto serial, envia telemetria y procesa imagenes en segundo plano. |
| `frontend/routes/web.php` | Rutas de paginas, datos en vivo, calibracion y ultima imagen. |
| `frontend/app/Http/Controllers/SensorDataController.php` | Conecta las vistas Laravel con la API Flask y adapta respuestas. |
| `frontend/app/Http/Controllers/FrameController.php` | Consulta la ultima imagen y la entrega al navegador. |
| `frontend/resources/views/sensores/index.blade.php` | Pagina principal de telemetria. |
| `frontend/resources/views/sensores/estadisticas.blade.php` | Pagina de estadisticas e historial. |
| `frontend/resources/views/sensores/control.blade.php` | Pagina de control de mision. |
| `frontend/resources/views/sensores/partials/live-video.blade.php` | Panel de ultima imagen recibida por LoRa. |
| `frontend/resources/js/modelo3d-feed.js` | Actualiza el modelo 3D y los indicadores de telemetria. |
| `frontend/resources/js/3DmodelEcharts.js` | Escena Three.js y modelo 3D. |
| `frontend/resources/js/mapEcharts.js` | Mapa de Leaflet y presentacion de ubicacion. |
| `frontend/resources/js/uplot-sensores.js` | Graficas de sensores. |
| `frontend/resources/js/riesgo-bioma.js` | Consulta y muestra el riesgo ambiental por bioma. |
| `frontend/config/services.php` | Direcciones configurables de la API de sensores y archivos. |

## Flujo de datos

1. El CanSat transmite telemetria e imagenes por radio al receptor.
2. El receptor conectado por USB entrega los mensajes seriales a
   `api/serial_sender.py`.
3. El emisor envia telemetria a `POST /api/guardar`. Las imagenes completas se
   guardan en `api/frames/` y se registran con `POST /api/frames`.
4. Flask (`api/app.py`) persiste las muestras y metadatos de imagen en MySQL;
   las imagenes se sirven desde `/frames/...`.
5. Laravel consulta Flask mediante `SENSORES_API_URL` y `SENSORES_BASE_FILES`.
   El navegador consulta las rutas Laravel para actualizar la interfaz.
6. La pagina consulta el estado de la imagen cada 200 ms y la telemetria del
   modelo cada 100 ms. La muestra repetida no se agrega nuevamente a la tabla.
   Estos intervalos solo determinan la frecuencia de consulta de la interfaz:
   la frecuencia efectiva depende de la radio, el emisor y la disponibilidad de
   datos nuevos.

El emisor serial espera telemetria JSON, `CAN01` o campos `CLAVE=valor`. La
version CanSat con BME680/MPU6050 transmite temperatura, humedad, presion y
resistencia de gas (`T`, `H`, `P`, `GAS`), aceleracion (`AX`/`AY`/`AZ`),
velocidad angular (`GX`/`GY`/`GZ`), inclinacion (`PI`/`RL`) y estado del GPS y
sensores (`FIX`, `SAT`, `MPU`, `BME`). El modelo 3D usa roll/pitch y deja yaw
sin dato porque el MPU6050 no mide rumbo magnetico. Las lecturas de sensores
sin respuesta y las coordenadas sin fix se guardan como nulas, no como ceros.
Las imagenes se pueden enviar como Base64 (`SIMG`/`B64`/`EIMG`) o como JPEG
binario (`JPEG_BEGIN,id,tamano`, seguido por exactamente esa cantidad de bytes
JPEG).

## Requisitos

- PHP 8.2 o posterior y Composer.
- Node.js y npm.
- Python 3 y las dependencias de `api/requirements.txt`.
- MySQL compatible con la configuracion de Flask.
- Receptor USB/serial y CanSat solo para recibir datos reales.

## Configuracion local

### API Flask

Desde la carpeta del proyecto:

```powershell
py -m venv api\venv
api\venv\Scripts\Activate.ps1
pip install -r api\requirements.txt
```

Crea `api/.env` con las variables de conexion a MySQL que usa `api/app.py`:

```dotenv
DB_USER=usuario_mysql
DB_PASSWORD=contrasena_mysql
DB_HOST=127.0.0.1
DB_NAME=cansat
```

No agregues contrasenas, tokens ni URLs privadas al repositorio. La API crea o
usa las tablas `sensor_data`, `image_frames` y `acceleration_calibration` en la
base configurada.

Inicia Flask desde `api/` para que cargue `api/.env` y guarde las imagenes en
`api/frames/`:

```powershell
cd api
python app.py
```

Por defecto escucha en el puerto `5000`.

### Aplicacion Laravel

```powershell
cd frontend
Copy-Item .env.example .env
composer install
npm install
php artisan key:generate
```

Configura `frontend/.env` con los parametros Laravel necesarios y las
direcciones de Flask:

```dotenv
SENSORES_API_URL=http://127.0.0.1:5000/api
SENSORES_BASE_FILES=http://127.0.0.1:5000
```

Si Flask esta en otro equipo, cambia `127.0.0.1` por la direccion IP accesible
desde el servidor Laravel. Inicia el sitio y compila los recursos con:

```powershell
php artisan serve --host=0.0.0.0
```

En otra terminal, desde `frontend/`:

```powershell
npm run dev
```

Para generar assets de produccion, usa `npm run build`.

### Receptor serial

Con Flask en ejecucion, inicia desde la raiz:

```powershell
python api\serial_sender.py
```

El emisor intenta detectar el puerto USB. Si hace falta fijarlo, define
`CANSAT_SERIAL_PORT` (por ejemplo, `COM5`). `serial_sender.py` envia los datos a
`http://localhost:5000/api`; si Flask corre en otra maquina, actualiza las
constantes `API_TELEM` y `API_FRAMES` de ese archivo.

El hub MJPEG de `api/esp32_hub.py` y el emisor de YouTube de `api/emision.py` son
flujos auxiliares distintos de las imagenes LoRa del panel. Requieren su propia
configuracion local y, para YouTube, FFmpeg.

## Endpoints principales

### Flask

| Metodo | Ruta | Uso |
|---|---|---|
| `POST` | `/api/guardar` | Guardar una muestra de telemetria. |
| `GET` | `/api/live` | Obtener la muestra mas reciente en memoria. |
| `GET` | `/api/datos` | Consultar historial paginado. |
| `GET` | `/api/datos/latest` | Consultar las muestras mas recientes. |
| `POST` | `/api/frames` | Registrar metadatos de una imagen recibida. |
| `GET` | `/api/frames/latest` | Obtener metadatos de la ultima imagen. |
| `GET` | `/frames/<archivo>` | Servir una imagen guardada. |
| `POST` | `/api/calibrar-aceleracion` | Calibrar aceleracion con muestras recientes. |
| `GET` | `/api/estadisticas`, `/api/datos/rango` | Consultar estadisticas e historial por tiempo. |
| `GET` | `/api/bioma`, `/api/riesgo_bioma` | Consultas de bioma y riesgo ambiental. |

Laravel expone las paginas `/sensores`, `/sensores/estadisticas` y
`/sensores/control`, asi como `/sensores/live` para el modelo y
`/api/sensores/latest-frame` y `/api/sensores/latest-frame/image` para la camara.

## Pruebas y estado del hardware

Puedes instalar dependencias, compilar el frontend y ejecutar pruebas de
software sin el CanSat. Sin embargo, la recepcion real de telemetria, imagenes y
la latencia de extremo a extremo solo se pueden validar con el transmisor,
receptor y enlace de radio conectados.
