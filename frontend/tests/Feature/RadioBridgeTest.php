<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadioBridgeTest extends TestCase
{
    public function test_live_telemetry_is_proxied_from_the_server_api(): void
    {
        Http::fake([
            '*api/live' => Http::response([
                'roll' => 12.5,
                'pitch' => -3.0,
                'yaw' => 90.0,
                'gx' => 0.1,
                'gy' => -0.2,
                'gz' => 0.3,
                'gps_fix' => 1,
                'satellites' => 7,
                'mpu_ok' => 1,
                'bme_ok' => 1,
            ]),
        ]);

        $response = $this->getJson('/sensores/live');
        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertEquals([
            'roll' => 12.5,
            'pitch' => -3.0,
            'yaw' => 90.0,
            'gx' => 0.1,
            'gy' => -0.2,
            'gz' => 0.3,
            'gps_fix' => 1,
            'satellites' => 7,
            'mpu_ok' => 1,
            'bme_ok' => 1,
        ], $response->json());
    }

    public function test_latest_feed_exposes_updated_sensor_and_gps_fields(): void
    {
        Http::fake([
            '*api/datos/latest*' => Http::response([[
                'id' => 5,
                'timestamp' => '2026-11-11T12:00:00',
                'gx' => 0.1,
                'gy' => -0.2,
                'gz' => 0.3,
                'gps_fix' => 1,
                'satellites' => 7,
                'mpu_ok' => 1,
                'bme_ok' => 1,
            ]]),
        ]);

        $this->getJson('/sensores/ultimos')
            ->assertOk()
            ->assertJsonPath('0.gx', 0.1)
            ->assertJsonPath('0.gy', -0.2)
            ->assertJsonPath('0.gz', 0.3)
            ->assertJsonPath('0.gps_fix', 1)
            ->assertJsonPath('0.satellites', 7)
            ->assertJsonPath('0.mpu_ok', 1)
            ->assertJsonPath('0.bme_ok', 1);
    }

    public function test_ping_route_reports_a_radio_confirmation(): void
    {
        Http::fake([
            '*api/comandos/ping' => Http::response([
                'ok' => true,
                'message' => 'CanSat respondió PONG por LoRa.',
            ]),
        ]);

        $this->postJson('/sensores/probar-conexion')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'CanSat respondió PONG por LoRa.');
    }

    public function test_ping_route_reports_when_the_cansat_does_not_reply(): void
    {
        Http::fake([
            '*api/comandos/ping' => Http::response([
                'error' => 'No hubo respuesta del CanSat.',
            ], 504),
        ]);

        $this->postJson('/sensores/probar-conexion')
            ->assertStatus(504)
            ->assertJsonPath('error', 'No hubo respuesta del CanSat.');
    }

    public function test_ping_route_rejects_an_unconfirmed_success_response(): void
    {
        Http::fake([
            '*api/comandos/ping' => Http::response(['queued' => true]),
        ]);

        $this->postJson('/sensores/probar-conexion')
            ->assertStatus(502)
            ->assertJsonPath('error', 'El puente serial no confirmó el PONG del CanSat.');
    }

    public function test_latest_radio_image_is_proxied_from_the_server_api(): void
    {
        $jpeg = "\xff\xd8radio-frame\xff\xd9";
        Http::fake([
            'http://127.0.0.1:5000/api/frames/latest' => Http::response([
                'id' => 42,
                'url' => 'frames/frame_000042.jpg',
            ]),
            'http://127.0.0.1:5000/frames/frame_000042.jpg' => Http::response($jpeg),
        ]);

        $this->get('/api/sensores/latest-frame/image')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertContent($jpeg);
    }

    public function test_latest_radio_image_rejects_unexpected_paths(): void
    {
        Http::fake([
            'http://127.0.0.1:5000/api/frames/latest' => Http::response([
                'id' => 42,
                'url' => 'https://example.invalid/frame.jpg',
            ]),
        ]);

        $this->getJson('/api/sensores/latest-frame/image')
            ->assertNotFound()
            ->assertJsonPath('error', 'No hay una imagen de radio disponible');
    }
}
