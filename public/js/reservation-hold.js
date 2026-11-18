(() => {
  document.querySelectorAll('[data-hold-countdown]').forEach(panel => {
    const remaining = Number(panel.dataset.remainingMs);
    if (!Number.isFinite(remaining) || remaining < 0) return;
    const deadline = performance.now() + remaining;
    const output = panel.querySelector('[data-hold-time]');
    const expiry = panel.querySelector('[data-hold-expiry]');
    const tick = () => {
      const seconds = Math.max(0, Math.ceil((deadline - performance.now()) / 1000));
      output.textContent = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
      if (seconds === 0) {
        expiry.textContent = 'El plazo terminó. Actualiza para consultar el estado del servidor.';
        document.querySelectorAll('[data-hold-confirm]').forEach(button => { button.disabled = true; });
      }
      return seconds;
    };
    if (tick() > 0) {
      const timer = setInterval(() => { if (tick() === 0) clearInterval(timer); }, 250);
    }
  });
})();
