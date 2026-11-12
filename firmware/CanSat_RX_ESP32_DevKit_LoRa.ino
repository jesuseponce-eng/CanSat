/*
  CanSat RX ESP32 DevKit + SX1278 433 MHz.
  Emits CAN01/IMU01 and JPEG Base64 over USB for api/serial_sender.py.
  Accepts CMD,PING,<id> from serial_sender and confirms PONG,<id> from CanSat.
  Serial: 115200. SX1278: SCK18 MISO19 MOSI23 NSS5 RST14 DIO0 26.
*/
#include <Arduino.h>
#include <SPI.h>
#include <LoRa.h>
#include <string.h>

constexpr int LORA_SCK = 18, LORA_MISO = 19, LORA_MOSI = 23;
constexpr int LORA_CS = 5, LORA_RST = 14, LORA_DIO0 = 26;
constexpr long LORA_FREQ = 433000000L;
constexpr uint8_t RADIO_SYNC = 0x27;
constexpr size_t CHUNK_BYTES = 180;
constexpr uint32_t MAX_JPEG_BYTES = 40000;
static uint8_t imageBuffer[MAX_JPEG_BYTES];
static bool imageActive = false, lastDone = false;
static uint16_t currentId = 0, expectedSeq = 0;
static uint16_t lastDoneId = 0, lastDoneSeq = 0;
static uint32_t imageSize = 0, imageCrc = 0, receivedBytes = 0;
static uint32_t telemetryReceived = 0, telemetryLost = 0;
static bool telemetrySeqKnown = false;
static uint16_t lastTelemetrySeq = 0;
static char commandLine[40];
static size_t commandLineUsed = 0;

uint16_t readU16(const uint8_t *p) { return uint16_t(p[0]) | (uint16_t(p[1]) << 8); }
uint32_t readU32(const uint8_t *p) {
  return uint32_t(p[0]) | (uint32_t(p[1]) << 8) | (uint32_t(p[2]) << 16) | (uint32_t(p[3]) << 24);
}
uint32_t crc32jpeg(const uint8_t *data, size_t len) {
  uint32_t crc = 0xFFFFFFFFUL;
  for (size_t i = 0; i < len; i++) {
    crc ^= data[i];
    for (uint8_t bit = 0; bit < 8; bit++) {
      crc = (crc >> 1) ^ ((crc & 1) ? 0xEDB88320UL : 0UL);
    }
  }
  return ~crc;
}

void sendAck(uint16_t id, uint16_t seq, char kind) {
  const uint8_t out[8] = {'C', 'S', 'A', uint8_t(id), uint8_t(id >> 8),
                          uint8_t(seq), uint8_t(seq >> 8), uint8_t(kind)};
  if (LoRa.beginPacket() == 1) {
    LoRa.write(out, sizeof(out));
    LoRa.endPacket();
  }
  LoRa.receive();
}

bool sendGroundPing(uint16_t id) {
  const uint8_t ping[] = {'P', 'I', 'N', 'G'};
  if (LoRa.beginPacket() != 1) return false;
  const uint8_t header[7] = {'C', 'S', 'C', uint8_t(id), uint8_t(id >> 8), 0, 0};
  const bool packetWritten = LoRa.write(header, sizeof(header)) == sizeof(header) &&
                             LoRa.write(ping, sizeof(ping)) == sizeof(ping);
  const bool sent = LoRa.endPacket() == 1;
  LoRa.receive();
  return packetWritten && sent;
}

void processSerialCommands() {
  while (Serial.available()) {
    const int value = Serial.read();
    if (value < 0 || value == '\r') continue;
    if (value == '\n') {
      commandLine[commandLineUsed] = '\0';
      unsigned id = 0;
      char extra = '\0';
      if (sscanf(commandLine, "CMD,PING,%u%c", &id, &extra) == 1 && id <= 65535) {
        if (sendGroundPing(static_cast<uint16_t>(id))) {
          Serial.printf("[CMD] PING enviado por LoRa (id=%u)\n", id);
        } else {
          Serial.printf("[CMD] ERROR enviando PING (id=%u)\n", id);
        }
      } else if (commandLineUsed > 0) {
        Serial.println("[CMD] Comando serial no reconocido");
      }
      commandLineUsed = 0;
    } else if (commandLineUsed < sizeof(commandLine) - 1) {
      commandLine[commandLineUsed++] = static_cast<char>(value);
    } else {
      commandLineUsed = 0;
    }
  }
}

void printB64(const uint8_t *src, size_t len) {
  static const char b64[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
  char out[68];
  size_t j = 0;
  for (size_t i = 0; i < len; i += 3) {
    uint32_t x = uint32_t(src[i]) << 16;
    if (i + 1 < len) x |= uint32_t(src[i + 1]) << 8;
    if (i + 2 < len) x |= src[i + 2];
    out[j++] = b64[(x >> 18) & 63];
    out[j++] = b64[(x >> 12) & 63];
    out[j++] = (i + 1 < len) ? b64[(x >> 6) & 63] : '=';
    out[j++] = (i + 2 < len) ? b64[x & 63] : '=';
  }
  out[j] = '\0';
  Serial.print("B64 ");
  Serial.println(out);
}

void emitImage(uint16_t id) {
  Serial.printf("SIMG id=%u len=%lu crc32=0x%08lX ok=1\n",
                (unsigned)id, (unsigned long)imageSize, (unsigned long)imageCrc);
  for (size_t i = 0; i < imageSize; i += 48) {
    printB64(imageBuffer + i, min((size_t)48, (size_t)(imageSize - i)));
  }
  Serial.printf("EIMG id=%u\n", (unsigned)id);
}

void handlePacket(const uint8_t *p, int n, int rssi, float snr) {
  if (n < 7 || p[0] != 'C' || p[1] != 'S') return;
  const char kind = char(p[2]);
  const uint16_t id = readU16(p + 3), seq = readU16(p + 5);
  const uint8_t *payload = p + 7;
  const size_t len = n - 7;

  if (kind == 'P') {
    if (seq == 0 && len == 4 && memcmp(payload, "PONG", 4) == 0) {
      Serial.printf("PONG,%u\n", (unsigned)id);
    }
    return;
  }
  if (kind == 'I') {
    if (len > 0 && len <= 160 && !strncmp((const char *)payload, "IMU01,", 6)) {
      Serial.write(payload, len);
      Serial.println();
    }
    return;
  }
  if (kind == 'T') {
    ++telemetryReceived;
    if (telemetrySeqKnown) {
      const uint16_t delta = (uint16_t)(id - lastTelemetrySeq);
      if (delta > 1 && delta < 1000) telemetryLost += delta - 1;
    }
    telemetrySeqKnown = true;
    lastTelemetrySeq = id;
    Serial.write(payload, len);
    Serial.println();
    Serial.printf("Paquete: %u\n", (unsigned)id);
    Serial.printf("RSSI: %d\n", rssi);
    Serial.printf("SNR: %.1f\n", snr);
    Serial.printf("Recibidos: %lu\n", (unsigned long)telemetryReceived);
    Serial.printf("Perdidos: %lu\n", (unsigned long)telemetryLost);
    const float pct = (100.f * telemetryReceived) / (telemetryReceived + telemetryLost);
    Serial.printf("Recepcion: %.1f\n", pct);
    return;
  }
  if (kind == 'B') {
    if (seq != 0xFFFF || len != 8) return;
    const uint32_t size = readU32(payload), crc = readU32(payload + 4);
    if (size == 0 || size > MAX_JPEG_BYTES) return;
    if (imageActive && id == currentId && imageSize == size && imageCrc == crc) {
      sendAck(id, seq, 'B');
      return;
    }
    imageActive = true;
    currentId = id;
    expectedSeq = 0;
    imageSize = size;
    imageCrc = crc;
    receivedBytes = 0;
    sendAck(id, seq, 'B');
    return;
  }
  if (kind != 'D') return;
  if (!imageActive && lastDone && id == lastDoneId && seq == lastDoneSeq) {
    sendAck(id, seq, 'D');
    return;
  }
  if (!imageActive || id != currentId) return;
  if (expectedSeq > 0 && seq == uint16_t(expectedSeq - 1)) {
    sendAck(id, seq, 'D');
    return;
  }
  if (seq != expectedSeq || receivedBytes >= imageSize) return;
  const size_t expected = min(CHUNK_BYTES, (size_t)(imageSize - receivedBytes));
  if (len != expected) return;
  memcpy(imageBuffer + receivedBytes, payload, len);
  receivedBytes += len;
  ++expectedSeq;
  if (receivedBytes == imageSize) {
    const bool valid = crc32jpeg(imageBuffer, imageSize) == imageCrc &&
      imageSize >= 4 && imageBuffer[0] == 0xFF && imageBuffer[1] == 0xD8 &&
      imageBuffer[imageSize - 2] == 0xFF && imageBuffer[imageSize - 1] == 0xD9;
    if (!valid) {
      imageActive = false;
      return;
    }
    imageActive = false;
    lastDone = true;
    lastDoneId = id;
    lastDoneSeq = seq;
    sendAck(id, seq, 'D');
    emitImage(id);
    return;
  }
  sendAck(id, seq, 'D');
}

void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println("[RX] CAN01 + IMU01 + JPEG Base64 + PING/PONG");
  SPI.begin(LORA_SCK, LORA_MISO, LORA_MOSI, LORA_CS);
  LoRa.setSPI(SPI);
  LoRa.setPins(LORA_CS, LORA_RST, LORA_DIO0);
  if (!LoRa.begin(LORA_FREQ)) {
    Serial.println("[RX] ERROR SX1278 (revisar cableado/antena)");
    while (true) delay(1000);
  }
  LoRa.setSpreadingFactor(7);
  LoRa.setSignalBandwidth(125E3);
  LoRa.setCodingRate4(5);
  LoRa.setSyncWord(RADIO_SYNC);
  LoRa.enableCrc();
  LoRa.setTxPower(14);
  LoRa.receive();
  Serial.println("[RX] Escuchando 433 MHz, SF7, BW125, sync 0x27");
}

void loop() {
  processSerialCommands();
  const int size = LoRa.parsePacket();
  if (size > 0) {
    uint8_t packet[255];
    int n = 0;
    while (LoRa.available()) {
      const int value = LoRa.read();
      if (n < (int)sizeof(packet)) packet[n++] = uint8_t(value);
    }
    if (n == size) handlePacket(packet, n, LoRa.packetRssi(), LoRa.packetSnr());
    LoRa.receive();
  }
  delay(1);
}
