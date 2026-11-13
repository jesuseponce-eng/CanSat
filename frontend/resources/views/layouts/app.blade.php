<!DOCTYPE html>
<html lang="es" class="h-full bg-gray-900">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>CanSat - Monitoreo de Sensores</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Vite (CSS + JS de tu app) -->
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>
            /* (tu CSS inline generado por Tailwind build-less, lo dejo igual) */
            /*! tailwindcss v4 ... (lo que ya tenías) */
        </style>
    @endif

    <!-- uPlot (CDN) -->
    <link rel="stylesheet" href="https://unpkg.com/uplot/dist/uPlot.min.css">
    <script src="https://unpkg.com/uplot/dist/uPlot.iife.min.js" defer></script>


    <style>
        .nasa-gradient {
            background: linear-gradient(135deg, #240046 0%, #3C096C 100%);
        }

        .glow-effect {
            box-shadow: 0 0 15px rgba(157, 78, 221, 0.5);
        }

        .space-font {
            font-family: 'Orbitron', sans-serif;
        }
    </style>
    <style>
        /* Reserva el ancho de la barra expandida para que no cubra las pestañas. */
        @media (min-width: 1024px) {
            #mainContent[data-collapsed="0"] {
                padding-left: 5rem;
            }

            #mainContent[data-collapsed="1"] {
                padding-left: 0;
            }
        }
    </style>

</head>

<body class="h-full bg-black text-blue-200">
    <div class="min-h-screen">

        <!-- Header -->
        <header class="sticky top-0 z-50 bg-gray-900/95 backdrop-blur border-b border-violet-700">
            <div class="flex items-center gap-2 px-3 py-2">
                <!-- Botón toggle sidebar -->
                <button id="sidebarToggle"
                    class="inline-flex items-center justify-center w-12 h-12 rounded border border-violet-700 text-purple-300 hover:bg-gray-800 focus:outline-none focus:ring focus:ring-violet-700/40"
                    aria-controls="sidebar" aria-expanded="true" aria-label="Alternar menú">
                    <div class="space-font text-violet-400  text-xl font-bold">CS</div>
                </button>

                <div class="flex items-center gap-3">

                    <h1 class="text-sm px-3 md:text-base font-semibold tracking-wide space-font"> Estacion Terrena (CanSat Control de Mision)</h1>
                    </h1>
                </div>

                <div class="ml-auto flex items-center gap-4">
                    <div class="text-violet-400 space-font text-sm">
                        <i class="fas fa-clock mr-2"></i>
                        <span id="currentTime">{{ now()->format('H:i:s') }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-purple-400">
                        <i class="transition-all animate-ping duration-1000 scale-90 fas fa-circle text-xs"></i>
                        <span class="text-sm space-font">En línea</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Shell: Sidebar + Main -->
        <div class="relative">

            <!-- Sidebar (off-canvas en móvil, fijo en desktop) -->
            <aside id="sidebar"
                class="fixed inset-y-0 left-0 z-40 w-44 lg:w-20 nasa-gradient glow-effect border-r border-violet-700
         transition-transform duration-300 ease-out transform -translate-x-full"
                aria-label="Sidebar">

                <div class="h-full flex flex-col items-center py-6">
                    <!-- Marca / botón para cerrar -->
                    <button id="brandCloseTop" type="button"
                        class="space-font text-violet-300 text-3xl font-bold hover:opacity-80 focus:outline-none"
                        title="Ocultar barra lateral">
                        CS
                    </button>

                    <!-- Nav centrado verticalmente -->
                    <nav class="flex-1 w-full flex flex-col items-center justify-center gap-6 mt-6">
                        <a href="{{ route('sensores.index') }}"
                            class="group w-12 h-12 flex items-center justify-center text-purple-300 hover:text-white focus:outline-none {{ request()->routeIs('sensores.index') ? 'text-purple-100' : '' }}">
                            <i class="fas fa-satellite-dish text-xl"></i>
                            <span
                                class="absolute left-full ml-4 px-2 py-1 bg-gray-900 text-purple-300 text-s font-semibold rounded-md
             hidden group-hover:inline-block whitespace-nowrap border border-violet-700">
                                Datos en Tiempo Real
                            </span>
                        </a>

                        <a href="{{ route('sensores.estadisticas') }}"
                            class="group w-12 h-12 flex items-center justify-center text-purple-300 hover:text-white focus:outline-none {{ request()->routeIs('sensores.estadisticas') ? 'text-purple-100' : '' }}">
                            <i class="fas fa-chart-bar text-xl"></i>
                            <span
                                class="absolute left-full ml-4 px-2 py-1 bg-gray-900 text-purple-300 text-s font-semibold rounded-md
             opacity-0 group-hover:opacity-100 transition-opacity duration-300 whitespace-nowrap border border-violet-700
             pointer-events-none">
                                Estadísticas
                            </span>
                        </a>

                        <!--
                        <a href="#"
                            class="group w-12 h-12 flex items-center justify-center text-purple-300 hover:text-white focus:outline-none">
                            <i class="fas fa-cog text-xl"></i>
                            <span
                                class="absolute left-full ml-4 px-2 py-1 bg-gray-900 text-purple-300 text-s font-semibold rounded-md
             hidden group-hover:inline-block whitespace-nowrap border border-violet-700">
                                Configuración
                            </span>
                        </a>
                    -->

                       <!--
                     <a href={ route('sensores.control') }
                            class="group w-12 h-12 flex items-center justify-center text-purple-300 hover:text-white focus:outline-none">
                            <i class="fas fa-rocket text-xl"></i>
                            <span
                                class="absolute left-full ml-4 px-2 py-1 bg-gray-900 text-purple-300 text-s font-semibold rounded-md
             hidden group-hover:inline-block whitespace-nowrap border border-violet-700">
                                Control de Misión
                            </span>
                        </a>

                    -->
                    </nav>

                </div>
            </aside>


            <!-- Backdrop (móvil) -->
            <div id="sidebarBackdrop" class="fixed inset-0 z-30 bg-black/50 hidden lg:hidden"></div>

            <!-- Contenido principal (deja espacio cuando sidebar está abierto en desktop) -->
            <!-- empieza cerrado para evitar FOUC en móvil; JS lo abrirá en desktop -->
            <main id="mainContent" data-collapsed="1" class="transition-all duration-300 ease-out">

                <!-- Page Content -->
                <div class="p-6 overflow-auto bg-indigo-950 min-h-[calc(100vh-4rem)]">
                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    <!-- Bootstrap opcional -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>

    <!-- Hora en vivo -->
    <script>
        function actualizarHora() {
            const ahora = new Date();
            const pad = (n) => String(n).padStart(2, '0');
            const horaActual = `${pad(ahora.getHours())}:${pad(ahora.getMinutes())}:${pad(ahora.getSeconds())}`;
            const el1 = document.getElementById('currentTime');
            const el2 = document.getElementById('currentTime2');
            if (el1) el1.textContent = horaActual;
            if (el2) el2.textContent = horaActual;
        }
        actualizarHora();
        setInterval(actualizarHora, 1000);
    </script>

    @stack('scripts')
    <div class="hidden translate-x-0"></div>




</body>

</html>
