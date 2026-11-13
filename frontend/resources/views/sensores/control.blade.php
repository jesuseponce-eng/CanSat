@extends('layouts.app')

@section('content')
    <div class="bg-gray-800 shadow-lg rounded-lg overflow-hidden border border-violet-500 glow-effect">
        {{-- Encabezado --}}
        <div class="nasa-gradient px-4 py-5 sm:px-6 border-b border-violet-700">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-purple-300 space-font flex items-center">
                    <i class="fas fa-chart-bar text-violet-200 mr-3"></i>
                    Control de CanSat
                </h2>
                <div class="text-purple-400 space-font text-sm">
                    <i class="fas fa-satellite-dish mr-2"></i>
                    <span>Control de Misión</span>
                </div>
            </div>
        </div>

        {{-- Pestañas --}}


        <div class="flex space-x-4   bg-gray-900 border-b border-violet-700">
            <button onclick="cargarVista('mapa')" data-vista="mapa"
                class="tab-btn h-full {{ $pagina === 'mapa' ? 'bg-violet-900 font-bold text-white' : ' ' }}  'text-purple-300' hover:text-white px-4 py-6 rounded transition-colors duration-200">
                Ubicación
            </button>
            <button onclick="cargarVista('modelo3d')" data-vista='modelo3d'
                class="tab-btn h-full text-purple-300 hover:text-white px-4 py-6 rounded transition-colors duration-200">
                Modelo 3D
            </button>

        </div>
        {{-- Contenedor dinámico --}}
        <div id="contenedorContenido" class="p-4 bg-gray-900 min-h-[300px]">
            {!! $contenido ?? '<div class="text-center text-gray-400">Cargando estadísticas...</div>' !!}
        </div>





    </div>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
@endsection


@push('scripts')
    <script>
  function cargarVista(vista) {
    const contenedor = document.getElementById('contenedorContenido');

    // 1) Si hay un 3D vivo, destrúyelo ANTES de cambiar el HTML
    try { window.__three3D?.destroy3D?.(); } catch {}

    contenedor.innerHTML = '<div class="text-center text-gray-400">Cargando...</div>';

    fetch(`/sensores/cargar-vista-control/${vista}`)
      .then(response => {
        if (!response.ok) throw new Error('Error en la respuesta del servidor');
        return response.text();
      })
      .then(html => {
        contenedor.innerHTML = html;
        resaltarBotonActivo(vista);

        // 2) Inicializa SOLO lo que corresponde a la vista cargada
        if (vista === 'mapa' && typeof window.inicializarMapa === 'function') {
          window.inicializarMapa();
        }
        if (vista === 'modelo3d' && typeof window.inicializarModelo3D === 'function') {
          window.inicializarModelo3D();   // <- una única llamada por carga de vista
        }
      })
      .catch(error => {
        console.error('Error:', error);
        contenedor.innerHTML = `
          <div class="text-red-400 p-4 bg-gray-800 rounded">
            Error al cargar los datos: ${error.message}
          </div>
        `;
      });
  }
</script>

    <script>
        // Manejo de formularios
        document.addEventListener('submit', function(e) {
            if (e.target.matches('#formFiltros')) {
                e.preventDefault();
                const form = e.target;
                const contenedor = document.getElementById('contenedorContenido');

                contenedor.innerHTML = '<div class="text-center text-gray-400">Aplicando filtros...</div>';

                fetch(form.action + '?' + new URLSearchParams(new FormData(form)))
                    .then(res => res.text())
                    .then(html => {
                        contenedor.innerHTML = html;
                    })
                    .catch(err => {
                        console.error('Error:', err);
                        contenedor.innerHTML = `
                        <div class="text-red-400 p-4 bg-gray-800 rounded">
                            Error al aplicar filtros: ${err.message}
                        </div>
                    `;
                    });
            }
        });
    </script>
    <script>
        function resaltarBotonActivo(vistaActiva) {
            document.querySelectorAll('.tab-btn').forEach(btn => {
                if (btn.dataset.vista === vistaActiva) {
                    btn.classList.add('bg-violet-900', 'font-bold', 'text-white');
                } else {
                    btn.classList.remove('bg-violet-900', 'font-bold', 'text-white');
                    btn.classList.add('text-purple-300');
                }
            });
        }
    </script>
    @vite('resources/js/mapEcharts.js')
    @vite('resources/js/3DmodelEcharts.js')
@endpush
@push('scripts')
  @vite('resources/js/modelo3d-feed.js')
@endpush
