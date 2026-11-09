<div
    class="mx-auto max-w-6xl overflow-hidden rounded-xl border border-violet-700 bg-gray-800 shadow-lg"
    data-cansat-video
    data-latest-url="{{ route('api.sensores.latest-frame') }}"
    data-image-url="{{ route('api.sensores.latest-frame.image') }}">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-violet-700 bg-gray-900/60 px-4 py-3">
        <div>
            <h3 class="flex items-center gap-2 font-semibold text-purple-200 space-font">
                <i class="fas fa-video text-violet-300" aria-hidden="true"></i>
                Imágenes en vivo por radio
            </h3>
            <p class="mt-1 text-xs text-purple-200/60">Última imagen recibida desde el CanSat mediante LoRa</p>
        </div>

        <div class="flex items-center gap-3">
            <span class="inline-flex items-center gap-2 text-sm text-purple-200" aria-live="polite">
                <span data-video-indicator class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                <span data-video-status>Esperando la primera imagen…</span>
            </span>
            <button
                type="button"
                data-video-retry
                class="rounded border border-violet-500/60 px-3 py-1.5 text-sm text-violet-200 transition hover:bg-violet-700/30 focus:outline-none focus:ring-2 focus:ring-violet-400">
                <i class="fas fa-rotate-right mr-1" aria-hidden="true"></i>
                Actualizar
            </button>
        </div>
    </div>

    <div class="relative flex min-h-56 items-center justify-center overflow-hidden rounded-b-xl bg-black sm:min-h-80">
        <img
            data-video-image
            class="hidden h-full max-h-[70vh] min-h-56 w-full object-contain sm:min-h-80"
            alt="Última imagen recibida por radio desde la cámara del CanSat"
            decoding="async">
        <p
            data-video-placeholder
            class="px-6 py-12 text-center text-sm text-purple-200/80"
            role="status">
            Esperando imágenes LoRa del receptor USB…
        </p>
    </div>
</div>

<script>
    (() => {
        const player = document.querySelector('[data-cansat-video]');
        if (!player) return;

        const image = player.querySelector('[data-video-image]');
        const status = player.querySelector('[data-video-status]');
        const indicator = player.querySelector('[data-video-indicator]');
        const placeholder = player.querySelector('[data-video-placeholder]');
        const retryButton = player.querySelector('[data-video-retry]');
        const latestUrl = player.dataset.latestUrl;
        const imageUrl = player.dataset.imageUrl;
        let timer;
        let currentFrameId = null;
        let polling = false;
        const pollInterval = 200;
        let retryDelay = pollInterval;

        function setStatus(message, connected = false) {
            status.textContent = message;
            indicator.classList.toggle('bg-emerald-400', connected);
            indicator.classList.toggle('bg-amber-400', !connected);
            placeholder.classList.toggle('hidden', connected);
        }

        async function pollLatestFrame() {
            clearTimeout(timer);
            if (polling) return;
            polling = true;

            try {
                const response = await fetch(latestUrl, { cache: 'no-store' });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const frame = await response.json();

                if (!frame.url || frame.id === null) {
                    if (currentFrameId === null) {
                        setStatus('Esperando la primera imagen…');
                        placeholder.textContent = 'El receptor LoRa aún no ha enviado una imagen.';
                    }
                    retryDelay = pollInterval;
                    return;
                }

                if (String(frame.id) !== String(currentFrameId)) {
                    currentFrameId = frame.id;
                    setStatus('Cargando imagen…');
                    image.classList.remove('hidden');
                    const separator = imageUrl.includes('?') ? '&' : '?';
                    image.src = `${imageUrl}${separator}v=${encodeURIComponent(frame.id)}`;
                } else if (image.complete && image.naturalWidth > 0) {
                    setStatus('Recibiendo por radio', true);
                }
                retryDelay = pollInterval;
            } catch (error) {
                console.error('No se pudo consultar la última imagen LoRa:', error);
                if (currentFrameId === null) {
                    setStatus('Receptor de imágenes sin conexión');
                    placeholder.textContent = 'No se pudo consultar el servidor de imágenes. Reintentando…';
                }
                retryDelay = Math.min(retryDelay * 2, 10000);
            } finally {
                polling = false;
                timer = setTimeout(pollLatestFrame, retryDelay);
            }
        }

        image.addEventListener('load', () => {
            image.classList.remove('hidden');
            setStatus('Recibiendo por radio', true);
        });
        image.addEventListener('error', () => {
            currentFrameId = null;
            image.classList.add('hidden');
            setStatus('No se pudo cargar la imagen');
            placeholder.textContent = 'Se intentará cargar de nuevo la última imagen recibida.';
        });
        retryButton.addEventListener('click', pollLatestFrame);
        pollLatestFrame();
    })();
</script>
