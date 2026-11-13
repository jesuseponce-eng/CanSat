<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class SensorDataController extends Controller
{
    private $apiUrl;

    public function __construct()
    {
        $this->apiUrl = rtrim(config('services.sensores.api_url'), '/');
    }

    public function index(Request $request)
    {
        try {
            $pagina = (int)$request->input('page', 1);

            $response = Http::get($this->apiUrl . '/datos', [
                'page' => $pagina
            ]);

            if (!$response || !$response->successful()) {
                throw new \Exception("Error al conectar con la API");
            }

            $apiData = $response->json();
            if (is_null($apiData)) {
                throw new \Exception("La API devolvió un formato inválido");
            }

            return view('sensores.index', [
                'datos' => [
                    'datos'          => $apiData['datos'] ?? [],
                    'total_paginas'  => $apiData['total_paginas'] ?? 1,
                    'pagina_actual'  => $apiData['pagina_actual'] ?? $pagina
                ]
            ]);
        } catch (\Exception $e) {
            return view('sensores.index', [
                'datos' => [
                    'datos'         => [],
                    'total_paginas' => 1,
                    'pagina_actual' => 1
                ],
                'error' => $e->getMessage()
            ]);
        }
    }


    public function obtenerEstadisticas(Request $request)
    {
        try {
            $query = [];
            if ($request->inicio && $request->fin) {
                $query = ['inicio' => $request->inicio, 'fin' => $request->fin];
            }

            $response = Http::get($this->apiUrl . '/estadisticas', $query);

            if (!$response->successful()) {
                throw new \Exception(
                    "API /estadisticas status {$response->status()} body: " . $response->body()
                );
            }

            return $response->json();
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }


    public function obtenerDatosRango(Request $request)
    {
        try {
            $horas = $request->horas ?? 24;
            $response = Http::get($this->apiUrl . '/datos/rango', ['horas' => $horas]);

            return [
                'datos' => $response->json(),
                'horas' => $horas
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function estadisticas(Request $request)
    {
        $estadisticas = $this->obtenerEstadisticas($request);

        if (isset($estadisticas['error'])) {
            // En vez de back(), pintamos la vista con el error
            $contenido = view('sensores.partials.estadisticas._promedio', [
                'estadisticas' => []   // vacío para que el foreach no truene
            ])->render();

            $pagina = 'promedio';

            return view('sensores.estadisticas', compact('contenido', 'pagina'))
                ->with('error', $estadisticas['error']);
        }

        // Caso normal (sin error)
        $contenido = view('sensores.partials.estadisticas._promedio', compact('estadisticas'))->render();
        $pagina = 'promedio';

        return view('sensores.estadisticas', compact('contenido', 'pagina'));
    }



    public function vistaEstadisticas(Request $request, $vista)
    {
        try {
            switch ($vista) {
                case 'promedio':
                    $estadisticas = $this->obtenerEstadisticas($request);
                    return view('sensores.partials.estadisticas._promedio', compact('estadisticas'));

                case 'rangoHoras':
                    $resultados = $this->obtenerDatosRango($request);
                    return view('sensores.partials.estadisticas._rangoHoras', [
                        'datos' => $resultados['datos'],
                        'horas' => $resultados['horas']
                    ]);

                case 'todos':
                    try {
                        $pagina = (int)$request->input('page', 1);

                        $response = Http::get($this->apiUrl . '/datos', [
                            'page' => $pagina
                        ]);

                        if (!$response || !$response->successful()) {
                            throw new \Exception("Error al conectar con la API");
                        }

                        $apiData = $response->json();
                        if (is_null($apiData)) {
                            throw new \Exception("La API devolvió un formato inválido");
                        }

                        return view('sensores.partials.estadisticas._todos', [
                            'datos' => [
                                'datos'          => $apiData['datos'] ?? [],
                                'total_paginas'  => $apiData['total_paginas'] ?? 1,
                                'pagina_actual'  => $apiData['pagina_actual'] ?? $pagina
                            ]
                        ]);
                    } catch (\Exception $e) {
                        return view('sensores.partials.estadisticas._todos', [
                            'datos' => [
                                'datos'         => [],
                                'total_paginas' => 1,
                                'pagina_actual' => 1
                            ],
                            'error' => $e->getMessage()
                        ]);
                    }


                default:
                    abort(404, 'Vista no encontrada');
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function misionControl(Request $request)
    {
        $contenido = view('sensores.partials.control._mapa')->render();
        $pagina = 'mapa';
        return view('sensores.control', compact('contenido', 'pagina'));
    }

    public function vistaControl(Request $request, $vista)
    {
        try {
            switch ($vista) {
                case 'mapa':
                    return view('sensores.partials.control._mapa');

                case 'modelo3d':
                    return view('sensores.partials.control._modelo3d');

                default:
                    abort(404, 'Vista no encontrada');
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    /**
     * Devuelve los últimos N registros normalizados para el front “live”.
     * Importante: YA NO usamos 'temperatura' legacy.
     * temp => temperatura_actual (o temp_ext, temp_int como fallback).
     */
    public function ultimos(Request $request)
    {
        $limit = (int)($request->query('limit', 200));

        $resp = Http::timeout(5)->get($this->apiUrl . '/datos/latest', [
            'limit' => $limit,
        ]);

        if (!$resp->successful()) {
            return response()->json([
                'error'  => 'No se pudo contactar API Flask',
                'status' => $resp->status(),
                'body'   => $resp->body(),
            ], 502);
        }

        $items = $resp->json(); // ya viene como array simple

        // lo normalizamos igual que antes, pero sin el bug de vx
        $out = array_map(function ($d) {
            $tsRaw = $d['timestamp'] ?? $d['tsISO'] ?? $d['created_at'] ?? null;
            try {
                if ($tsRaw) $tsRaw = \Carbon\Carbon::parse($tsRaw)->toIso8601String();
            } catch (\Throwable $e) {
            }

            return [
                'id'       => $d['id'] ?? null,
                'tsISO'    => $tsRaw,
                'lat'      => $d['lat'] ?? null,
                'lon'      => $d['lon'] ?? null,
                'numero_paquete' => $d['numero_paquete'] ?? null,
                'rssi'     => $d['rssi'] ?? null,
                'snr'      => $d['snr'] ?? null,
                'paquetes_recibidos' => $d['paquetes_recibidos'] ?? null,
                'paquetes_perdidos' => $d['paquetes_perdidos'] ?? null,
                'recepcion_pct' => $d['recepcion_pct'] ?? null,

                'humedad'  => $d['humedad'] ?? null,
                'presion'  => $d['presion'] ?? null,
                'temp_ext' => $d['temp_ext'] ?? null,
                'temp_int' => $d['temp_int'] ?? null,
                'gas'      => $d['gas'] ?? null,     // ← AÑADE ESTO
                'co'       => $d['co'] ?? null,
                'h2'       => $d['h2'] ?? null,
                'ch4'      => $d['ch4'] ?? null,
                'c2h'      => $d['c2h'] ?? null,
                'no2'      => $d['no2'] ?? null,

                'ax'       => $d['ax'] ?? null,
                'ay'       => $d['ay'] ?? null,
                'az'       => $d['az'] ?? null,
                'gx'       => $d['gx'] ?? null,
                'gy'       => $d['gy'] ?? null,
                'gz'       => $d['gz'] ?? null,

                'pitch'    => $d['pitch'] ?? null,
                'roll'     => $d['roll'] ?? null,
                'yaw'      => $d['yaw'] ?? null,
                'satellites' => $d['satellites'] ?? null,
                'gps_fix'    => $d['gps_fix'] ?? null,
                'mpu_ok'     => $d['mpu_ok'] ?? null,
                'bme_ok'     => $d['bme_ok'] ?? null,

                'a_abs_ms2' => $d['a_abs_ms2'] ?? null,
                'altitud_m' => $d['altitud_m'] ?? null,
            ];
        }, $items);

        // opcional: ordenarlos por ts descendente (ya vienen así, pero por si acaso)
        usort($out, fn($a, $b) => strcmp($b['tsISO'] ?? '', $a['tsISO'] ?? ''));

        return response()->json($out);
    }

    public function calibrarAceleracion()
    {
        try {
            $response = Http::timeout(8)->post($this->apiUrl . '/calibrar-aceleracion');
            return response()->json($response->json() ?? [], $response->status());
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo calibrar con la API de telemetría.'], 502);
        }
    }

    public function live()
    {
        try {
            $response = Http::timeout(3)->get($this->apiUrl . '/live');
            if (!$response->successful()) {
                return response()->json(['error' => 'API de telemetría no disponible'], 502);
            }

            return response()->json($response->json() ?? [])
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->header('Pragma', 'no-cache');
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo contactar la API de telemetría.'], 502);
        }
    }

    public function probarConexion()
    {
        try {
            $response = Http::timeout(12)->post($this->apiUrl . '/comandos/ping');
            if (!$response->successful()) {
                return response()->json([
                    'error' => $response->json('error') ?? 'No se pudo confirmar la conexión con el CanSat.',
                ], $response->status() === 504 ? 504 : 502);
            }

            $result = $response->json();
            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                return response()->json([
                    'error' => 'El puente serial no confirmó el PONG del CanSat.',
                ], 502);
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'No se pudo contactar el puente serial de la estación terrena.',
            ], 502);
        }
    }

    /**
     * Igual que ultimos(), pero devolvemos solo las filas con id > since_id
     * para “streaming” incremental.
     */
    public function ultimosStream(Request $request)
    {
        $sinceId = (int) $request->query('since_id', 0);
        $limit   = (int) $request->query('limit', 200);

        $resp = Http::timeout(8)->get($this->apiUrl . '/datos', [
            'page'     => 1,
            'per_page' => $limit,
        ]);

        if (!$resp->successful()) {
            return response()->json([
                'error'  => 'No se pudo contactar API Flask',
                'status' => $resp->status(),
                'body'   => $resp->body(),
            ], 502)->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        $payload = $resp->json();
        $items   = Arr::get($payload, 'datos', []);

        $mapped = array_map(function ($d) {
            $tsRaw = $d['timestamp'] ?? $d['tsISO'] ?? $d['created_at'] ?? null;
            try {
                if ($tsRaw) $tsRaw = Carbon::parse($tsRaw)->toIso8601String();
            } catch (\Throwable $e) {
            }

            $temp = $d['temperatura_actual'] ?? ($d['temp_ext'] ?? ($d['temp_int'] ?? null));

            return [
                'id'      => $d['id'] ?? null,
                'tsISO'   => $tsRaw,
                'lat'     => $d['lat'] ?? null,
                'lon'     => $d['lon'] ?? null,

                #'temp'       => $temp,
                'temp_ext'   => $d['temp_ext'] ?? null,
                'temp_int'   => $d['temp_int'] ?? null,

                'hum'        => $d['humedad'] ?? null,
                'presion'    => $d['presion'] ?? null,
                'gas'        => $d['gas'] ?? null,
                'co'         => $d['co'] ?? null,
                'h2'         => $d['h2'] ?? null,
                'ch4'        => $d['ch4'] ?? null,
                #'nh3'        => $d['nh3'] ?? null,
                #'etoh'       => $d['etoh'] ?? null,

                'ax'         => $d['ax'] ?? null,
                'ay'         => $d['ay'] ?? null,
                'az'         => $d['az'] ?? null,
                #'gx'         => $d['gx'] ?? null,
                #'gy'         => $d['gy'] ?? null,
                #'gz'         => $d['gz'] ?? null,
                'vx'         => $d['vx'] ?? null,
                'vy'         => $d['vy'] ?? null,
                'vz'         => $d['vz'] ?? null,
                'pitch'         => $d['pitch'] ?? null,
                'roll'         => $d['roll'] ?? null,
                'yaw'         => $d['yaw'] ?? null,
                'a_abs_ms2'  => $d['a_abs_ms2'] ?? null,
                #'vib_rms_g'  => $d['vib_rms_g'] ?? null,
                #'vib_peak_g' => $d['vib_peak_g'] ?? null,
                #'vib_x_g'    => $d['vib_x_g'] ?? null,
                #'vib_y_g'    => $d['vib_y_g'] ?? null,
                #'vib_z_g'    => $d['vib_z_g'] ?? null,
                'altitud_m'  => $d['altitud_m'] ?? null,
                #'v_desc_ms'  => $d['v_desc_ms'] ?? null,
            ];
        }, $items);

        $filtered = array_values(array_filter($mapped, function ($row) use ($sinceId) {
            return isset($row['id']) && (int)$row['id'] > $sinceId;
        }));

        usort($filtered, fn($a, $b) => ($a['id'] <=> $b['id']));

        $maxId = $sinceId;
        foreach ($filtered as $r) {
            if (isset($r['id']) && (int)$r['id'] > $maxId) $maxId = (int)$r['id'];
        }

        return response()->json([
            'items'  => $filtered,
            'max_id' => $maxId,
            'count'  => count($filtered),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }


    public function riesgoBioma()
    {
        $params = request()->only(['window_minutes', 'min_samples', 'limit']);
        $params = array_merge(['window_minutes' => 60, 'min_samples' => 10], $params);

        $res = Http::timeout(5)->get($this->apiUrl . '/riesgo_bioma', $params);
        if (!$res->successful()) {
            return response()->json(['error' => 'API Flask down'], 502);
        }
        return response()->json($res->json());
    }
}
