# Firmware para prueba PING/PONG

La estación muestra el botón **Probar conexión**. Este botón solo verifica el
enlace; no activa monitores, cargas ni actuadores.

Para que la confirmación llegue por radio, actualiza ambos ESP32:

1. Carga `CanSat_RX_ESP32_DevKit_LoRa.ino` en el receptor de la estación terrena.
   Conecta ese receptor por USB al equipo donde se ejecuta `api/serial_sender.py`.
2. Carga `CanSat_ESP32_Principal_UART_LoRaActualizado.ino` en la ESP32 principal
   del CanSat.
3. Mantén en ambos lados la misma configuración LoRa: 433 MHz, SF7, BW125 kHz,
   sync word `0x27` y CRC habilitado.
4. Inicia Flask y `serial_sender.py`, además de Laravel. Pulsa **Probar conexión**.

El recorrido es: Laravel solicita un PING a Flask, `serial_sender.py` lo entrega
por USB al receptor, el receptor transmite `CS/C/PING` por LoRa y el CanSat
contesta `CS/P/PONG`. La web solo muestra éxito al recibir ese PONG. Si falta
algún proceso o equipo, el botón informa que no hubo confirmación.

El protocolo solo reconoce PING; no ofrece un canal para activar cargas o
actuadores.
