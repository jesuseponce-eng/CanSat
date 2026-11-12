@extends('layouts.app')

@section('content')
    <div class="bg-purple-950 shadow-lg rounded-lg overflow-hidden border border-violet-500 glow-effect">
        <div class="nasa-gradient px-4 py-5 sm:px-6 border-b border-violet-700">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-[0.28em] text-violet-200/80 mb-2">CanSat · telemetry</p>
                    <h2 class="text-xl font-semibold text-purple-300 space-font flex items-center">
                        <i class="fas fa-satellite text-violet-200 mr-3"></i>
                        Telemetría en Tiempo Real
                    </h2>
                </div>
                <div class="flex items-center gap-3 text-purple-300 space-font text-sm">
                    <span class="inline-flex items-center gap-2 rounded-full border border-emerald-400/40 bg-emerald-500/10 px-3 py-1.5">
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        Panel en vivo
                    </span>
                </div>
            </div>
        </div>

        <div class="p-4 bg-gray-900">
            <div class="mx-auto max-w-6xl">
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="rounded-xl border border-violet-700/70 bg-gray-800/80 p-4">
                        <div class="text-[10px] uppercase tracking-[0.22em] text-purple-300/70">Estado</div>
                        <div class="mt-3 flex items-center gap-3">
                            <span class="inline-flex h-3 w-3 rounded-full bg-emerald-400 shadow-[0_0_18px_rgba(52,211,153,0.8)]"></span>
                            <span class="text-lg font-semibold text-purple-100">Sistema activo</span>
                        </div>
                    </div>
                    <div class="rounded-xl border border-violet-700/70 bg-gray-800/80 p-4">
                        <div class="text-[10px] uppercase tracking-[0.22em] text-purple-300/70">Frecuencia</div>
                        <div class="mt-3 text-2xl font-semibold text-purple-100 font-mono">3s</div>
                    </div>
                    <div class="rounded-xl border border-violet-700/70 bg-gray-800/80 p-4">
                        <div class="text-[10px] uppercase tracking-[0.22em] text-purple-300/70">Último dato</div>
                        <div id="status-last-update" class="mt-3 text-lg font-semibold text-purple-100 font-mono">—</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 2) MÉTRICAS RÁPIDAS: Altitud / Vel. descenso / Aceleración --}}
        <div class="px-4 pb-2 bg-gray-900">
            <div class="mx-auto max-w-6xl">
                <div class="rounded-lg border border-violet-700 bg-gray-800 p-4">
                    <h3 class="text-center text-purple-300 space-font tracking-wide text-sm mb-2">
                        ÚLTIMOS DATOS REGISTRADOS
                    </h3>

                    @php
                        $last = $datos['datos'][0] ?? null;
                    @endphp

                    <div id="tripletMetrics" data-init='@json($last)'
                        class="grid grid-cols-1 sm:grid-cols-3 gap-4">

                        {{-- Altitud --}}
                        <div
                            class="rounded-lg border border-violet-700 bg-gray-900/40 p-4 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <i class="fas fa-mountain text-violet-300 text-xl"></i>
                                <div>
                                    <div class="text-xs uppercase tracking-wider text-purple-400 space-font">Altura</div>
                                    <div class="text-2xl font-semibold text-purple-100 font-mono">
                                        <span id="metric-alt">—</span>
                                        <span class="text-sm opacity-80">m</span>
                                    </div>
                                </div>
                            </div>
                            <span id="ts-alt" class="text-[10px] text-purple-400/70 font-mono"></span>
                        </div>

                        {{-- Velocidad de descenso --}}
                        <div
                            class="rounded-lg border border-violet-700 bg-gray-900/40 p-4 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <i class="fas fa-arrow-down text-violet-300 text-xl"></i>
                                <div>
                                    <div class="text-xs uppercase tracking-wider text-purple-400 space-font">
                                        Velocidad de descenso
                                    </div>
                                    <div class="text-2xl font-semibold text-purple-100 font-mono">
                                        <span id="metric-vdesc">—</span>
                                        <span class="text-sm opacity-80">m/s</span>
                                    </div>
                                </div>
                            </div>
                            <span id="ts-vdesc" class="text-[10px] text-purple-400/70 font-mono"></span>
                        </div>

                        {{-- Aceleración |a| --}}
                        <div
                            class="rounded-lg border border-violet-700 bg-gray-900/40 p-4 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <i class="fas fa-bolt text-violet-300 text-xl"></i>
                                <div>
                                    <div class="text-xs uppercase tracking-wider text-purple-400 space-font">
                                        Aceleración dinámica
                                    </div>
                                    <div class="text-2xl font-semibold text-purple-100 font-mono">
                                        <span id="metric-aabs">—</span>
                                        <span class="text-sm opacity-80">m/s²</span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <button id="btn-calibrar-aceleracion" type="button"
                                            class="inline-flex items-center gap-1.5 rounded border border-amber-400/50 px-2 py-1 text-xs text-amber-200 hover:bg-amber-500/10 disabled:opacity-50">
                                            <i class="fas fa-crosshairs" aria-hidden="true"></i>
                                            calibrar en reposo
                                        </button>
                                        <span id="accel-calibration-status" class="text-[10px] text-purple-300/70" aria-live="polite"></span>
                                    </div>
                                </div>
                            </div>
                            <span id="ts-aabs" class="text-[10px] text-purple-400/70 font-mono"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-gray-900 p-4">
            @include('sensores.partials.live-video')
        </div>

        {{-- 3) BIOMA / RIESGO --}}


        {{-- 4) MAPA + MODELO 3D EN TIEMPO REAL --}}
        <div class="p-4 bg-gray-900">
            <div class="mx-auto max-w-6xl">
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                    {{-- MAPA + TELEMETRÍA --}}
                    <div class="bg-gray-800 rounded-lg border border-violet-700 h-full flex flex-col">
                        <div class="px-4 py-3 border-b border-violet-700/70 bg-gray-900/60 rounded-t-lg">
                            <h3 class="text-purple-300 font-semibold flex items-center gap-2">
                                <i class="fas fa-location-dot text-violet-200"></i>
                                Mapa en tiempo real
                            </h3>
                            <p class="text-xs text-purple-200/60">Trayectoria y últimos paquetes</p>
                            <p id="map-location-status" class="text-xs text-amber-200/80">Estación terrena sin marcar · GPS CanSat sin fix</p>
                        </div>

                        <div id="map-main" class="w-full h-[360px] rounded-b-lg border-b border-violet-700/40"></div>

                        {{-- CONTENEDOR DE TABLA: alto fijo + scroll interno --}}
                        <div class="border-t border-violet-700/70">
                            <div class="h-40 lg:h-72 overflow-auto morado-scroll">
                                <table class="min-w-[1050px] w-full text-xs tabla-morado table-fixed">
                                    <thead
                                        class="sticky top-0 bg-gray-900/90 border-b border-violet-700/70 text-purple-200 z-10">
                                        <tr>
                                            <th class="text-left px-3 py-2 font-medium uppercase tracking-wider">fecha/hora</th>
                                            <th class="text-right px-3 py-2 font-medium uppercase tracking-wider">paquete</th>
                                            <th class="text-right px-3 py-2 font-medium uppercase tracking-wider">lat</th>
                                            <th class="text-right px-3 py-2 font-medium uppercase tracking-wider">lon</th>
                                            <th class="text-right px-3 py-2 font-medium uppercase tracking-wider">temp</th>
                                            <th class="text-right px-3 py-2 font-medium uppercase tracking-wider">hum</th>
                                            <th class="text-right px-3 py-2 font-medium uppercase tracking-wider">RSSI</th>
                                            <th class="text-right px-3 py-2 font-medium uppercase tracking-wider">SNR</th>
                                            <th class="text-right px-3 py-2 font-medium uppercase tracking-wider">recibidos</th>
                                            <th class="text-right px-3 py-2 font-medium uppercase tracking-wider">perdidos</th>
                                            <th class="text-right px-3 py-2 font-medium uppercase tracking-wider">recepción</th>
                                        </tr>
                                    </thead>
                                    <tbody id="map-telemetry-body" class="divide-y divide-violet-800/30 text-gray-200">
                                        {{-- filas generadas por JS --}}
                                    </tbody>
                                </table>
                            </div>
                        </div>


                        <div
                            class="px-4 py-3 border-t border-violet-700/70 flex items-center justify-between gap-3 flex-wrap text-xs md:text-sm text-purple-100/90">
                            <span id="map-telemetry-status"
                                class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full
                                         bg-emerald-900/30 border border-emerald-500/40">
                                <span class="inline-block w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                paquetes: 0
                            </span>

                            <div class="flex flex-wrap items-center gap-2 md:gap-3">
                                <span id="map-distancia-seleccion"
                                    class="font-semibold px-3 py-1.5 rounded-full bg-violet-800/40
                                             border border-violet-500/50">
                                    distancia: —
                                </span>
                                <span id="map-delta-tiempo-seleccion"
                                    class="font-semibold px-3 py-1.5 rounded-full bg-fuchsia-800/30
                                             border border-fuchsia-500/50">
                                    Δt: —
                                </span>
                            </div>

                            <div class="flex items-center gap-2">
                                <button id="map-btn-limpiar-seleccion"
                                    class="px-3 py-1.5 rounded-lg border border-violet-600/60 text-violet-200 hover:text-white
                                               hover:border-violet-400 hover:bg-violet-700/30 transition-colors">
                                    quitar selección
                                </button>
                                <button id="map-btn-limpiar-tabla"
                                    class="px-3 py-1.5 rounded-lg border border-slate-500/60 text-slate-200 hover:text-white
                                               hover:border-slate-300 hover:bg-slate-600/30 transition-colors">
                                    limpiar
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- MODELO 3D + IMU --}}
                    <div class="bg-gray-800/60 rounded-lg border border-violet-700/60 flex flex-col">
                        <div class="px-4 py-3 border-b border-violet-700/60 flex items-center justify-between">
                            <h3 class="text-purple-200 space-font font-semibold">
                                <i class="fas fa-cube mr-2"></i> Modelo 3D
                            </h3>
                            <span class="text-xs text-purple-400">orientación / vibraciones</span>
                        </div>

                        <div id="model3d-main" class="w-full h-[500px]"></div>

                        <div class="px-4 pt-5 pb-1 border-t border-violet-700/60">
                            <h4 class="text-purple-200 space-font font-semibold mb-3">IMU en tiempo real</h4>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3 text-xs">
                                <div class="rounded border border-violet-800/40 bg-gray-900/60 p-2">
                                    <span class="text-gray-400">MPU6050</span>
                                    <span id="sensor-health-mpu" class="ml-1 text-purple-200">—</span>
                                </div>
                                <div class="rounded border border-violet-800/40 bg-gray-900/60 p-2">
                                    <span class="text-gray-400">BME680</span>
                                    <span id="sensor-health-bme" class="ml-1 text-purple-200">—</span>
                                </div>
                                <div class="rounded border border-violet-800/40 bg-gray-900/60 p-2">
                                    <span class="text-gray-400">GPS</span>
                                    <span id="sensor-health-gps" class="ml-1 text-purple-200">—</span>
                                </div>
                                <div class="rounded border border-violet-800/40 bg-gray-900/60 p-2">
                                    <span class="text-gray-400">Satélites</span>
                                    <span id="sensor-gps-satellites" class="ml-1 text-purple-200">—</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-2">
                                <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
                                    <div class="text-[11px] text-gray-400">Ax (g)</div>
                                    <div id="kpiAx"
                                        class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
                                </div>
                                <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
                                    <div class="text-[11px] text-gray-400">Ay (g)</div>
                                    <div id="kpiAy"
                                        class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
                                </div>
                                <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
                                    <div class="text-[11px] text-gray-400">Az (g)</div>
                                    <div id="kpiAz"
                                        class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-2 mt-3">
                                <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
                                    <div class="text-[11px] text-gray-400">x° (roll)</div>
                                    <div id="kpiRoll"
                                        class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
                                </div>
                                <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
                                    <div class="text-[11px] text-gray-400">y° (pitch)</div>
                                    <div id="kpiPitch"
                                        class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
                                </div>
                                <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
                                    <div class="text-[11px] text-gray-400">z° (yaw; rumbo absoluto no disponible)</div>
                                    <div id="kpiYaw"
                                        class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-2 mt-3">
                                <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
                                    <div class="text-[11px] text-gray-400">Giro X (rad/s)</div>
                                    <div id="kpiGx" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
                                </div>
                                <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
                                    <div class="text-[11px] text-gray-400">Giro Y (rad/s)</div>
                                    <div id="kpiGy" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
                                </div>
                                <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
                                    <div class="text-[11px] text-gray-400">Giro Z (rad/s)</div>
                                    <div id="kpiGz" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <button type="button" data-orientation-calibrate
                                    class="rounded border border-amber-400/50 px-3 py-1.5 text-xs text-amber-200 hover:bg-amber-500/10 disabled:opacity-50">
                                    <i class="fas fa-crosshairs mr-1" aria-hidden="true"></i>
                                    Calibrar orientación
                                </button>
                                <button type="button" data-orientation-reset
                                    class="rounded border border-violet-500/50 px-3 py-1.5 text-xs text-violet-200 hover:bg-violet-700/30">
                                    Restablecer
                                </button>
                                <span data-orientation-status class="text-xs text-purple-300/70" aria-live="polite">
                                    Coloca el CanSat en la posición de referencia y calibra.
                                </span>
                            </div>
                        </div>


                    </div>
                </div> {{-- grid --}}
            </div>
        </div>
        <div class="p-4 bg-gray-900">
            <div class="mx-auto max-w-6xl">
                <div class="rounded-lg border border-violet-700 bg-gray-800 p-4">
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <div class="text-purple-400 text-s uppercase space-font mb-1">
                                Bioma identificado
                            </div>
                            <div id="biome-name" class="text-purple-200 font-medium">—</div>
                            <div id="ecoregion-name" class="text-purple-300 text-sm mt-1 opacity-80">—</div>
                        </div>
                        <div>
                            <div class="text-purple-400 text-s uppercase space-font mb-1">
                                Riesgo de incendio <span class="text-xs lowercase">( Última hora )</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span id="risk-pill"
                                    class="px-2 py-1 text-sm rounded-full bg-gray-600 text-white">—</span>
                                <span id="risk-score" class="text-purple-200 text-sm">—</span>
                            </div>
                            <div id="risk-msg" class="text-purple-400 text-xs mt-1 opacity-70"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        {{-- 5) GRID DE CHARTS (uPlot lee datos desde data-rows) --}}
        <div class="p-4 bg-gray-900">
            <div id="chartsGrid" class="grid gap-4 sm:grid-cols-1 xl:grid-cols-2"
                data-rows='@json($datos['datos'] ?? [])'>

                <div class="rounded-lg border border-violet-700 bg-gray-800 p-3 chart-card">
                    <h3 class="text-sm text-purple-300 mb-2">Temperatura BME680 (°C)</h3>
                    <div id="chart-temp-ext" style="height:220px"></div>
                </div>

                <div class="rounded-lg border border-violet-700 bg-gray-800 p-3 chart-card">
                    <h3 class="text-sm text-purple-300 mb-2">Humedad</h3>
                    <div id="chart-hum" style="height:220px"></div>
                </div>

                <div class="rounded-lg border border-violet-700 bg-gray-800 p-3 chart-card">
                    <h3 class="text-sm text-purple-300 mb-2">Presión</h3>
                    <div id="chart-pres" style="height:220px"></div>
                </div>

                <div class="rounded-lg border border-violet-700 bg-gray-800 p-3 chart-card">
                    <h3 class="text-sm text-purple-300 mb-2">Resistencia de gas BME680 (kΩ)</h3>
                    <div id="chart-gas" style="height:220px"></div>
                </div>

                <div class="rounded-lg border border-violet-700 bg-gray-800 p-3 chart-card">
                    <h3 class="text-sm text-purple-300 mb-2">Aceleración dinámica (m/s²)</h3>
                    <div id="chart-aabs" style="height:220px"></div>
                </div>

                <div class="rounded-lg border border-violet-700 bg-gray-800 p-3 chart-card">
                    <h3 class="text-sm text-purple-300 mb-2">Giro X (rad/s)</h3>
                    <div id="chart-gx" style="height:220px"></div>
                </div>

                <div class="rounded-lg border border-violet-700 bg-gray-800 p-3 chart-card">
                    <h3 class="text-sm text-purple-300 mb-2">Giro Y (rad/s)</h3>
                    <div id="chart-gy" style="height:220px"></div>
                </div>

                <div class="rounded-lg border border-violet-700 bg-gray-800 p-3 chart-card">
                    <h3 class="text-sm text-purple-300 mb-2">Giro Z (rad/s)</h3>
                    <div id="chart-gz" style="height:220px"></div>
                </div>
                <div class="rounded-lg border border-violet-700 bg-gray-800 p-3 chart-card">
                    <h3 class="text-sm text-purple-300 mb-2">Altitud (m)</h3>
                    <div id="chart-alt" style="height:220px"></div>
                </div>


            </div>
        </div>

        {{-- OJO: la tabla completa de todos los datos LA VAMOS A MOVER a Estadísticas --}}
    </div>
@endsection

@push('scripts')
    @vite('resources/js/uplot-sensores.js')
    @vite('resources/css/charts.css')

    @vite('resources/js/riesgo-bioma.js')
    @vite('resources/js/ultimos-mini.js')

    {{-- scripts de mapa + modelo 3D (antes estaban en control) --}}
    @vite('resources/js/mapEcharts.js')
    @vite('resources/js/3DmodelEcharts.js')
    @vite('resources/js/modelo3d-feed.js')
@endpush
