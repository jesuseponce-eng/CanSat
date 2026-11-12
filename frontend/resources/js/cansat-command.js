(() => {
  const button = document.getElementById('btn-ping-cansat');
  const status = document.getElementById('ping-cansat-status');
  if (!button || !status) return;

  button.addEventListener('click', async () => {
    button.disabled = true;
    status.textContent = 'Enviando PING por la estación…';

    try {
      const response = await fetch('/sensores/probar-conexion', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || `HTTP ${response.status}`);
      status.textContent = 'CanSat respondió PONG por LoRa.';
      status.className = 'text-xs text-emerald-300';
    } catch (error) {
      status.textContent = error.message || 'No se pudo confirmar la conexión.';
      status.className = 'text-xs text-rose-300';
    } finally {
      button.disabled = false;
    }
  });
})();
