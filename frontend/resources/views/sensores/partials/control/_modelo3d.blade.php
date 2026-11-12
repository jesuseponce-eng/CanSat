{{-- resources/views/sensores/partials/modelo3d.blade.php --}}
<div class="grid grid-cols-1 xl:grid-cols-12 2xl:grid-cols-11 gap-4">
  {{-- LADO IZQUIERDO: 3D (más angosto) --}}
  <div class="xl:col-span-6 2xl:col-span-4 bg-gray-800/60 rounded-lg border border-violet-700/60">
    <div class="px-4 py-3 border-b border-violet-700/60 flex items-center justify-between">
      <h3 class="text-purple-200 space-font font-semibold">
        <i class="fas fa-cube mr-2"></i> Modelo 3D
      </h3>
      <span class="text-xs text-purple-400">echarts-gl</span>
    </div>
    

    <div id="model3d-main" class="w-full h-[680px]"></div>
  </div>

  {{-- LADO DERECHO: KPIs + TABLA (más ancho) --}}
  <div class="xl:col-span-6 2xl:col-span-7 bg-gray-800/60 rounded-lg border  border-violet-700/60 flex flex-col">
    {{-- KPIs --}}
    <div class="px-4 py-3 border-b border-violet-700/60">
      <h3 class="text-purple-200 space-font font-semibold flex items-center">
        <i class="fas fa-wave-square mr-2"></i> Vibraciones
      </h3>

      <p class="text-sm text-purple-300 mt-1">
        Estado:
        <span id="badgeVibracion"
          class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-900/50 text-emerald-300 border border-emerald-700/60">
          Estable
        </span>
      </p>

      <p class="text-[11px] text-gray-400 mt-1">
        Métrica: usa <code>vib_rms_g</code> si viene en los datos; de lo contrario, RMS de |a| (g) en ventana móvil (N=50).
      </p>

      {{-- KPIs IMU en g + orientación --}}
      <div class="grid grid-cols-3 gap-2 mt-3">
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">Ax (g)</div>
          <div id="kpiAx" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">Ay (g)</div>
          <div id="kpiAy" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">Az (g)</div>
          <div id="kpiAz" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-2 mt-3">
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">x° (roll)</div>
          <div id="kpiRoll" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">y° (pitch)</div>
          <div id="kpiPitch" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">z° (yaw; rumbo absoluto no disponible)</div>
          <div id="kpiYaw" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
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

      {{-- KPIs nuevos: temperatura y derivados --}}
      <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-3">
        <!--<div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">Temp. Ext (°C)</div>
          <div id="kpiTempExt" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">Temp. Int (°C)</div>
          <div id="kpiTempInt" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">|a| (m/s²)</div>
          <div id="kpiAabs" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>-->
        <!--
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">Vib RMS (g)</div>
          <div id="kpiVibRms" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">Vib Pico (g)</div>
          <div id="kpiVibPeak" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>
        -->
        <!--<div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">Altitud (m)</div>
          <div id="kpiAlt" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>
        <div class="p-2 bg-gray-900/60 rounded border border-violet-800/40">
          <div class="text-[11px] text-gray-400">V. Descenso (m/s)</div>
          <div id="kpiVdesc" class="text-purple-200 font-mono tabular-nums text-right whitespace-nowrap">—</div>
        </div>-->
      </div>
    </div>

    {{-- TABLA --}}
    <div class="px-4 py-3 border-t border-violet-700/60 flex-1 min-h-0">
      <h4 class="text-purple-200 space-font font-semibold mb-2">Datos recibidos</h4>

      <div class="overflow-y-auto overflow-x-hidden max-h-[350px] rounded border border-violet-800/40">
        <table class="w-full table-fixed text-sm text-purple-200">
          <colgroup>
            <col class="w-[34%]" />
            <col class="w-[11%]" />
            <col class="w-[11%]" />
            <col class="w-[11%]" />
            <col class="w-[11%]" />
            <col class="w-[11%]" />
            <col class="w-[11%]" />
          </colgroup>

          <thead class="bg-gray-900/80 sticky top-0 z-10 backdrop-blur">
            <tr>
              <th class="px-3 py-2 text-left font-semibold border-b border-violet-800/40">Fecha/hora</th>
              <th class="px-2 py-2 text-right font-semibold border-b border-violet-800/40">Ax</th>
              <th class="px-2 py-2 text-right font-semibold border-b border-violet-800/40">Ay</th>
              <th class="px-2 py-2 text-right font-semibold border-b border-violet-800/40">Az</th>
              <th class="px-2 py-2 text-right font-semibold border-b border-violet-800/40">x°</th>
              <th class="px-2 py-2 text-right font-semibold border-b border-violet-800/40">y°</th>
              <th class="px-2 py-2 text-right font-semibold border-b border-violet-800/40">z°</th>
            </tr>
          </thead>

          <tbody id="tbodyTelemetria" class="divide-y divide-violet-900/50 bg-gray-950/30">
            {{-- filas dinámicas --}}
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
