{{-- Leaflet + Tabla de telemetría --}}
@push('styles')
<link
  rel="stylesheet"
  href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
  crossorigin=""
/>
@endpush

<div class="grid grid-cols-1 lg:grid-cols-10 gap-4">
  {{-- MAPA (izquierda, 2/3) --}}
  <div class="lg:col-span-6">
    <div id="map-main"
         class="w-full h-[600px] rounded-lg border border-violet-700 relative overflow-hidden"></div>
    <p id="map-location-status" class="mt-2 text-xs text-amber-200/80" aria-live="polite">
      Estación terrena sin marcar. El GPS del CanSat aparece al recibir un fix.
    </p>
  </div>

  {{-- TABLA (derecha, 1/3) --}}
  <div class="lg:col-span-4">
    <div class="bg-gray-800 rounded-lg border border-violet-700 h-[600px] flex flex-col shadow-[0_0_0_1px_rgba(139,92,246,.35)_inset,0_0_18px_rgba(139,92,246,.25)]">

      {{-- HEADER --}}
      <div class="px-4 py-3 border-b border-violet-700/70 bg-gray-900/60 rounded-t-lg">
        <h3 class="text-purple-300 font-semibold flex items-center gap-2">
          <i class="fas fa-satellite-dish text-violet-200"></i>
          Telemetría en vivo
        </h3>
        <p class="text-xs text-purple-200/60">Últimos paquetes recibidos</p>
      </div>

      {{-- TABLA --}}
      <div class="flex-1 overflow-auto morado-scroll">
        <table class="min-w-full text-sm tabla-morado table-fixed">
          <thead class="sticky top-0 bg-gray-900/90 border-b border-violet-700/70 text-purple-200 z-10">
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

      {{-- FOOTER --}}
      <div class="px-4 py-3 border-t border-violet-700/70 rounded-b-lg flex items-center justify-between gap-3 flex-wrap text-sm md:text-base lg:text-lg text-purple-100/90">
        
        {{-- Status --}}
        <span id="map-telemetry-status" class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full
                     bg-emerald-900/30 border border-emerald-500/40">
          <span class="inline-block w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
          paquetes: 0
        </span>

        {{-- Distancia y Δt --}}
        <div class="flex flex-wrap items-center gap-2 md:gap-3">
          <span id="map-distancia-seleccion"
                class="pill font-semibold px-3 py-1.5 rounded-full bg-violet-800/40
                       border border-violet-500/50 shadow-[inset_0_0_12px_rgba(139,92,246,.35)]">
            distancia: —
          </span>
          <span id="map-delta-tiempo-seleccion"
                class="pill font-semibold px-3 py-1.5 rounded-full bg-fuchsia-800/30
                       border border-fuchsia-500/50 shadow-[inset_0_0_12px_rgba(217,70,239,.30)]">
            Δt: —
          </span>
        </div>

        {{-- Botones --}}
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
  </div>
</div>
