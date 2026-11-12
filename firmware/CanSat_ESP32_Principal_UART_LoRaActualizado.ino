/*
  CanSat - ESP32 Dev Module (PRINCIPAL)
  S3 con camara -> UART -> esta ESP32 -> LoRa SX1278 -> receptor tierra.
  Telemetria BME680 + MPU6050 + GPS NEO cada 5 segundos.
  Camara S3: compatible con CanSat_Camara_UART_STREAM_S3_CORREGIDO.
  Receptor LoRa: compatible con protocolo CS B/D/T + ACK del repositorio CansatV2.

  CONEXIONES ESP32 PRINCIPAL:
    Camera S3 TX21 -> RX16   Camera S3 RX14 <- TX17   GND <-> GND
    I2C SDA21 SCL22 (BME680 y MPU6050 en paralelo)
    GPS TX -> RX32, GPS RX <- TX33 (este ultimo es opcional)
    SX1278: SCK18 MISO19 MOSI23 NSS5 RST14 DIO0 26, 3V3 + GND + antena 433 MHz

  NOTAS:
    - Nunca alimentar la camara ni el SX1278 desde pines GPIO.
    - LoRa no es video en tiempo real: las imagenes se enviaran una a una.
    - Los mandos desde la pagina y el paracaidas NO se incluyen en este firmware.
    - El receptor y Python actuales necesitan adaptacion para mostrar los datos
      en la pagina; este firmware es la parte TX del CanSat.
*/

#include <Arduino.h>
#include <Wire.h>
#include <SPI.h>
#include <LoRa.h>
#include <TinyGPSPlus.h>
#include <Adafruit_MPU6050.h>
#include <Adafruit_BME680.h>
#include <Adafruit_Sensor.h>
#include <math.h>
#include <string.h>

// ===== PINES =====
constexpr int I2C_SDA = 21, I2C_SCL = 22;
constexpr int LORA_SCK = 18, LORA_MISO = 19, LORA_MOSI = 23;
constexpr int LORA_CS = 5, LORA_RST = 14, LORA_DIO0 = 26;
constexpr int CAM_UART_RX = 16, CAM_UART_TX = 17;
constexpr int GPS_UART_RX = 32, GPS_UART_TX = 33;
constexpr uint32_t CAM_UART_BAUD = 115200;
constexpr uint32_t GPS_UART_BAUD = 9600;
constexpr long LORA_FREQUENCY = 433000000L;
constexpr uint8_t LORA_SYNC = 0x27;

// ===== PROTOCOLO =====
constexpr uint16_t CHUNK_BYTES = 180;
constexpr uint32_t MAX_JPEG_BYTES = 40000;
constexpr uint32_t TELEMETRY_PERIOD_MS = 5000;
constexpr uint32_t FRAME_TIMEOUT_MS = 7000;
constexpr uint32_t LORA_ACK_TIMEOUT_MS = 1500;
constexpr uint8_t LORA_RETRIES = 5;

// La S3 manda texto (encabezados) seguido de bloques binarios de 180 bytes.
HardwareSerial CamUART(2);
HardwareSerial GpsUART(1);
Adafruit_MPU6050 mpu;
Adafruit_BME680 bme;
TinyGPSPlus gps;
bool mpuOK = false, bmeOK = false, radioOK = false;

// Esta memoria RAM es deliberadamente ESTATICA: 40 KB en la ESP32 normal.
static uint8_t jpeg[MAX_JPEG_BYTES];
static uint32_t expectedImageSize = 0, receivedImageSize = 0, expectedImageCRC = 0;
static uint16_t imageId = 0, expectedChunkSeq = 0;
static bool receivingImage = false, framePendingRadio = false, radioBusy = false;
static uint32_t lastCameraByteMs = 0;

// Parser de texto + binario UART. Los bytes JPEG NUNCA se tratan como texto.
static char camLine[112];
static size_t camLineUsed = 0;
static uint16_t binaryRemaining = 0, binarySeq = 0, binaryId = 0;
static bool binaryValid = false, binaryDuplicate = false;
static uint8_t pendingChunk[CHUNK_BYTES];
static size_t pendingUsed = 0;

static uint32_t lastTelemetryMs = 0;
static uint32_t telemetryCount = 0;
static uint16_t lastCamState = 0;

uint16_t readU16(const uint8_t *p) {
  return (uint16_t)p[0] | ((uint16_t)p[1] << 8);
}
void writeU16(uint8_t *p, uint16_t value) {
  p[0] = (uint8_t)value;
  p[1] = (uint8_t)(value >> 8);
}
void writeU32(uint8_t *p, uint32_t value) {
  for (int k = 0; k < 4; ++k) p[k] = (uint8_t)(value >> (8 * k));
}
uint32_t jpegCRC32(const uint8_t *data, size_t len) {
  uint32_t crc = 0xFFFFFFFFu;
  for (size_t i = 0; i < len; ++i) {
    crc ^= data[i];
    for (uint8_t k = 0; k < 8; ++k) {
      crc = (crc >> 1) ^ ((crc & 1) ? 0xEDB88320u : 0u);
    }
  }
  return ~crc;
}

void feedGPS() {
  while (GpsUART.available()) gps.encode((char)GpsUART.read());
}

void clearImage() {
  receivingImage = false;
  expectedImageSize = 0;
  receivedImageSize = 0;
  expectedImageCRC = 0;
  expectedChunkSeq = 0;
}

void handleCamLine(const char *line) {
  unsigned id = 0, seq = 0, len = 0;
  unsigned long crc = 0;

  if (sscanf(line, "IMG_BEGIN,%u,%u,%lx", &id, &len, &crc) == 3) {
    if (receivingImage || framePendingRadio || radioBusy || len == 0 || len > MAX_JPEG_BYTES) {
      CamUART.printf("BUSY,%u\n", id);
      return;
    }
    receivingImage = true;
    imageId = (uint16_t)id;
    expectedImageSize = len;
    expectedImageCRC = (uint32_t)crc;
    receivedImageSize = 0;
    expectedChunkSeq = 0;
    lastCameraByteMs = millis();
    CamUART.printf("READY,%u\n", id);
    Serial.printf("[UART] Inicio JPEG %u, %u bytes\n", id, len);
    return;
  }

  if (sscanf(line, "IMG_CHUNK,%u,%u,%u", &id, &seq, &len) == 3) {
    // Aunque sea un paquete invalido, hay que CONSUMIR sus bytes binarios.
    if (len == 0 || len > CHUNK_BYTES) {
      // El protocolo S3 garantiza n<=180; si llega una cabecera imposible,
      // descartamos el frame para evitar interpretar binario como ASCII.
      Serial.println("[UART] Tamano de chunk fuera de protocolo: descartar frame");
      clearImage();
      camLineUsed = 0;
      return;
    }
    binaryId = (uint16_t)id;
    binarySeq = (uint16_t)seq;
    binaryRemaining = (uint16_t)len;
    pendingUsed = 0;
    binaryDuplicate = receivingImage && binaryId == imageId &&
                      expectedChunkSeq > 0 && binarySeq == (uint16_t)(expectedChunkSeq - 1);
    binaryValid = receivingImage && binaryId == imageId &&
                  (binarySeq == expectedChunkSeq || binaryDuplicate) &&
                  (binaryDuplicate || (receivedImageSize + len <= expectedImageSize));
    return;
  }

  if (sscanf(line, "IMG_END,%u", &id) == 1) {
    if (receivingImage && imageId == id && receivedImageSize == expectedImageSize) {
      const uint32_t crcActual = jpegCRC32(jpeg, receivedImageSize);
      if (crcActual == expectedImageCRC && receivedImageSize >= 4 &&
          jpeg[0] == 0xFF && jpeg[1] == 0xD8 &&
          jpeg[receivedImageSize - 2] == 0xFF && jpeg[receivedImageSize - 1] == 0xD9) {
        framePendingRadio = true;
        lastCamState = imageId;
        Serial.printf("[UART] JPEG %u completo, CRC correcto (%u bytes)\n",
                      imageId, (unsigned)receivedImageSize);
      } else {
        Serial.printf("[UART] JPEG %u descartado: CRC o formato incorrecto\n", id);
      }
    }
    receivingImage = false;
    return;
  }

  if (sscanf(line, "IMG_ABORT,%u", &id) == 1) {
    if (receivingImage && imageId == id) clearImage();
    return;
  }

  if (!strncmp(line, "CAM_BOOT", 8) || !strncmp(line, "CAM_STATE", 9) ||
      !strncmp(line, "CAM_STATUS", 10) || !strncmp(line, "CAM_ERR", 7)) {
    Serial.printf("[CAM] %s\n", line);
    return;
  }
}

void finishCamChunk() {
  if (binaryValid) {
    if (!binaryDuplicate) {
      memcpy(jpeg + receivedImageSize, pendingChunk, pendingUsed);
      receivedImageSize += (uint32_t)pendingUsed;
      ++expectedChunkSeq;
      lastCameraByteMs = millis();
    }
    // ACK tanto para chunk nuevo como retransmisiones de ACK perdidos.
    CamUART.printf("ACK,%u,%u\n", (unsigned)binaryId, (unsigned)binarySeq);
  }
  binaryRemaining = 0;
  pendingUsed = 0;
  binaryValid = false;
  binaryDuplicate = false;
}

void pollCamUART() {
  unsigned budget = 1024; // Evita monopolizar el bucle principal.
  while (CamUART.available() && budget--) {
    int c = CamUART.read();
    if (c < 0) break;
    lastCameraByteMs = millis();

    if (binaryRemaining) {
      if (pendingUsed < sizeof(pendingChunk)) pendingChunk[pendingUsed++] = (uint8_t)c;
      --binaryRemaining;
      if (binaryRemaining == 0) finishCamChunk();
      continue;
    }

    if (c == '\r') continue;
    if (c == '\n') {
      camLine[camLineUsed] = '\0';
      if (camLineUsed) handleCamLine(camLine);
      camLineUsed = 0;
    } else if (camLineUsed < sizeof(camLine) - 1) {
      camLine[camLineUsed++] = (char)c;
    } else {
      camLineUsed = 0; // Linea corrupta, esperar otra.
    }
  }

  if (receivingImage && (uint32_t)(millis() - lastCameraByteMs) > FRAME_TIMEOUT_MS) {
    Serial.println("[UART] Timeout de JPEG, descartado");
    clearImage();
  }
}

void initSensors() {
  Wire.begin(I2C_SDA, I2C_SCL, 100000);
  Wire.setTimeOut(50);
  if (mpu.begin(0x68, &Wire) || mpu.begin(0x69, &Wire)) {
    mpuOK = true;
    mpu.setAccelerometerRange(MPU6050_RANGE_8_G);
    mpu.setGyroRange(MPU6050_RANGE_500_DEG);
    mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);
  }
  if (bme.begin(0x76, true) || bme.begin(0x77, true)) {
    bmeOK = true;
    bme.setTemperatureOversampling(BME680_OS_8X);
    bme.setHumidityOversampling(BME680_OS_2X);
    bme.setPressureOversampling(BME680_OS_4X);
    bme.setIIRFilterSize(BME680_FILTER_SIZE_3);
    bme.setGasHeater(320, 150);
  }
  Serial.printf("[SENSORES] MPU6050=%d BME680=%d\n", mpuOK, bmeOK);
}

void initRadio() {
  SPI.begin(LORA_SCK, LORA_MISO, LORA_MOSI, LORA_CS);
  LoRa.setSPI(SPI);
  LoRa.setPins(LORA_CS, LORA_RST, LORA_DIO0);
  radioOK = LoRa.begin(LORA_FREQUENCY);
  if (radioOK) {
    LoRa.setSpreadingFactor(7);
    LoRa.setSignalBandwidth(125E3);
    LoRa.setCodingRate4(5);
    LoRa.setSyncWord(LORA_SYNC);
    LoRa.enableCrc();
    LoRa.setTxPower(14);
    LoRa.receive();
  }
  Serial.printf("[LORA] %s (433 MHz, SF7, BW125, SYNC 0x27)\n", radioOK ? "OK" : "ERROR");
}

// CS + tipo + id + secuencia + datos, igual al receptor del repositorio.
bool sendLoRa(char kind, uint16_t id, uint16_t seq, const uint8_t *data, size_t len) {
  if (!radioOK || len > 248) return false;
  uint8_t hdr[7] = {'C', 'S', (uint8_t)kind, 0, 0, 0, 0};
  writeU16(hdr + 3, id);
  writeU16(hdr + 5, seq);
  if (LoRa.beginPacket() != 1) return false;
  if (LoRa.write(hdr, 7) != 7) return false;
  if (len > 0 && LoRa.write(data, len) != len) return false;
  bool ok = LoRa.endPacket() == 1;
  LoRa.receive();
  return ok;
}

void pollGroundCommand() {
  const int size = LoRa.parsePacket();
  if (size <= 0) return;

  uint8_t packet[32];
  int received = 0;
  while (LoRa.available()) {
    const int value = LoRa.read();
    if (received < (int)sizeof(packet)) packet[received] = (uint8_t)value;
    ++received;
  }

  if (received == 11 && packet[0] == 'C' && packet[1] == 'S' && packet[2] == 'C' &&
      readU16(packet + 5) == 0 && memcmp(packet + 7, "PING", 4) == 0) {
    const uint16_t id = readU16(packet + 3);
    const uint8_t reply[] = {'P', 'O', 'N', 'G'};
    if (sendLoRa('P', id, 0, reply, sizeof(reply))) {
      Serial.printf("[CMD] PING de estación confirmado (id=%u)\n", (unsigned)id);
    }
  }
  LoRa.receive();
}

void sendTelemetry() {
  if (!radioOK) return;
  feedGPS();
  sensors_event_t a, g, t;
  bool mpuReadOK = mpuOK && mpu.getEvent(&a, &g, &t);
  bool bmeReadOK = bmeOK && bme.performReading();

  float ax = 0, ay = 0, az = 0, gx = 0, gy = 0, gz = 0;
  float pitch = 0, roll = 0;
  if (mpuReadOK) {
    ax = a.acceleration.x; ay = a.acceleration.y; az = a.acceleration.z;
    gx = g.gyro.x; gy = g.gyro.y; gz = g.gyro.z;
    pitch = atan2f(-ax, sqrtf(ay*ay + az*az)) * 180.0f / PI;
    roll = atan2f(ay, az) * 180.0f / PI;
  }
  float temp = bmeReadOK ? bme.temperature : 0;
  float hum = bmeReadOK ? bme.humidity : 0;
  float pressure = bmeReadOK ? bme.pressure / 100.0f : 0;
  float gas = bmeReadOK ? bme.gas_resistance / 1000.0f : 0;
  const bool fix = gps.location.isValid() && gps.location.age() < 10000;
  double lat = fix ? gps.location.lat() : 0.0;
  double lon = fix ? gps.location.lng() : 0.0;
  double alt = (fix && gps.altitude.isValid()) ? gps.altitude.meters() : 0.0;
  unsigned satellites = gps.satellites.isValid() ? (unsigned)gps.satellites.value() : 0;

  // CAN01 coincide con el parser de api/serial_sender.py. El RX LoRa debe
  // imprimir esta linea sin anteponer [TEL] para que el backend la consuma.
  char out[244];
  const unsigned long packetNumber = ++telemetryCount;
  const int written = snprintf(out, sizeof(out),
       "CAN01,N=%lu,T=%.1f,H=%.1f,P=%.1f,GAS=%.1f,"
       "AX=%.2f,AY=%.2f,AZ=%.2f,GX=%.2f,GY=%.2f,GZ=%.2f,"
       "PI=%.1f,RL=%.1f,LT=%.6f,LN=%.6f,A=%.1f,SAT=%u,FIX=%u,MPU=%u,BME=%u",
       packetNumber, temp, hum, pressure, gas,
       ax, ay, az, gx, gy, gz, pitch, roll, lat, lon, alt, satellites,
       fix ? 1u : 0u, mpuReadOK ? 1u : 0u, bmeReadOK ? 1u : 0u);
  if (written > 0 && (size_t)written < sizeof(out) && written <= 248) {
    bool ok = sendLoRa('T', (uint16_t)packetNumber, 0,
                       (const uint8_t *)out, (size_t)written);
    Serial.printf("[TEL] %s %s\n", ok ? "TX" : "ERROR", out);
  } else {
    Serial.println("[TEL] ERROR: linea demasiado larga");
  }
  lastTelemetryMs = millis();
}

void tickTelemetry() {
  if ((uint32_t)(millis() - lastTelemetryMs) >= TELEMETRY_PERIOD_MS) {
    sendTelemetry();
  }
}

bool sendWithAck(char kind, uint16_t id, uint16_t seq,
                 const uint8_t *body, size_t len) {
  for (uint8_t attempt = 0; attempt < LORA_RETRIES; ++attempt) {
    pollCamUART();
    feedGPS();
    if (!sendLoRa(kind, id, seq, body, len)) continue;
    uint32_t start = millis();
    while ((uint32_t)(millis() - start) < LORA_ACK_TIMEOUT_MS) {
      pollCamUART(); // Responde BUSY a nuevos JPEG aunque estemos en radio.
      feedGPS();
      const int size = LoRa.parsePacket();
      if (size > 0) {
        uint8_t p[32];
        int received = 0;
        while (LoRa.available()) {
          int v = LoRa.read();
          if (received < (int)sizeof(p)) p[received] = (uint8_t)v;
          ++received;
        }
        if (received == 8 && p[0] == 'C' && p[1] == 'S' && p[2] == 'A' &&
            readU16(p + 3) == id && readU16(p + 5) == seq && p[7] == (uint8_t)kind) {
          LoRa.receive();
          return true;
        }
        LoRa.receive();
      }
      delay(1);
    }
    Serial.printf("[LORA] Sin ACK %c:%u (intento %u/%u)\n",
                  kind, (unsigned)seq, (unsigned)attempt + 1, LORA_RETRIES);
  }
  return false;
}

void transmitPhoto() {
  if (!framePendingRadio || !radioOK) return;
  framePendingRadio = false;
  radioBusy = true;
  const uint16_t id = imageId;
  const uint32_t len = receivedImageSize;
  uint8_t meta[8];
  writeU32(meta, len);
  writeU32(meta + 4, expectedImageCRC);
  Serial.printf("[LORA] Transmitiendo JPEG %u (%lu bytes)\n", id, (unsigned long)len);

  bool ok = sendWithAck('B', id, 0xFFFF, meta, sizeof(meta));
  for (uint32_t offset = 0, seq = 0; ok && offset < len; offset += CHUNK_BYTES, ++seq) {
    const size_t n = min((uint32_t)CHUNK_BYTES, len - offset);
    ok = sendWithAck('D', id, (uint16_t)seq, jpeg + offset, n);
    // Intentar respetar los 5 s, como en el firmware TX original.
    // LoRa solo puede enviar una trama por vez, puede haber retrasos.
    if ((uint32_t)(millis() - lastTelemetryMs) >= TELEMETRY_PERIOD_MS) {
      sendTelemetry();
    }
  }
  Serial.printf("[LORA] JPEG %u %s\n", id, ok ? "COMPLETO" : "INCOMPLETO");
  radioBusy = false;
  clearImage();
}

void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println("\n=== CanSat ESP32 PRINCIPAL | UART -> SX1278 | Sensores 5 s ===");
  CamUART.setRxBufferSize(4096);
  CamUART.begin(CAM_UART_BAUD, SERIAL_8N1, CAM_UART_RX, CAM_UART_TX);
  GpsUART.setRxBufferSize(1024);
  GpsUART.begin(GPS_UART_BAUD, SERIAL_8N1, GPS_UART_RX, GPS_UART_TX);
  initSensors();
  initRadio();
  CamUART.println("PING");
  lastTelemetryMs = millis() - TELEMETRY_PERIOD_MS;
}

void loop() {
  pollCamUART();
  feedGPS();
  tickTelemetry();
  pollGroundCommand();
  if (framePendingRadio) transmitPhoto();
  delay(1);
}
