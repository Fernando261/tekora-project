/* B3.2: browser-only interactions for the existing Blue Obsidian checkout.
 * All payment attempts remain ordinary server-validated form POSTs.
 */
document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-b32-checkout]');
  if (!root) return;

  const form = root.querySelector('[data-b32-form]');
  const position = root.querySelector('[data-b32-card-position]');
  const tilt = root.querySelector('[data-b32-card-tilt]');
  const flip = root.querySelector('[data-b32-card-flip]');
  const cashFace = root.querySelector('[data-b32-cash-face]');
  const cardNumber = root.querySelector('[data-b32-card-number]');
  const cvv = root.querySelector('[data-b32-cvv]');
  const hold = root.querySelector('[data-b32-hold]');
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const endTime = performance.now() + Number(hold?.dataset.remaining || 0) * 1000;
  let inFlight = false;
  let cashSelected = root.dataset.checkoutState === 'awaiting';
  let tiltReset = null;

  function resetTilt() {
    if (tilt) tilt.style.transform = '';
    if (tiltReset !== null) {
      cancelAnimationFrame(tiltReset);
      tiltReset = null;
    }
  }

  function showBack(value) {
    if (!flip || cashSelected) return;
    flip.style.transform = value && !reduced.matches ? 'rotateY(180deg)' : '';
  }

  function chooseMethod() {
    if (!form) return;
    cashSelected = form.querySelector('[name="method"]:checked')?.value === 'cash';
    const card = form.querySelector('[data-b32-card]');
    const cashInfo = form.querySelector('[data-b32-cash-info]');
    if (card) {
      card.hidden = cashSelected;
      for (const control of card.querySelectorAll('input, select')) {
        control.disabled = cashSelected;
      }
    }
    if (cashInfo) cashInfo.hidden = !cashSelected;
    if (cashFace) cashFace.hidden = !cashSelected;
    if (flip) flip.style.transform = '';
    resetTilt();
  }

  if (form) {
    form.querySelectorAll('[data-b32-method]').forEach(input => {
      input.addEventListener('change', chooseMethod);
    });
    const select = form.querySelector('[data-b32-number]');
    const updateCard = () => {
      if (!select || !cardNumber) return;
      cardNumber.textContent = '••••   ••••   ••••   ' + select.value.slice(-4);
    };
    select?.addEventListener('change', updateCard);
    updateCard();
    chooseMethod();

    if (cvv) {
      cvv.addEventListener('focus', () => showBack(true));
      cvv.addEventListener('blur', () => showBack(false));
    }

    form.addEventListener('submit', () => {
      if (inFlight) return;
      inFlight = true;
      const button = form.querySelector('[data-b32-submit]');
      if (button) {
        button.disabled = true;
        button.textContent = 'Validando en Tekora…';
      }
      resetTilt();
      showBack(false);
    });
    window.addEventListener('pageshow', (event) => {
      if (event.persisted) {
        inFlight = false;
        const button = form.querySelector('[data-b32-submit]');
        if (button) {
          button.disabled = false;
          button.innerHTML = 'Confirmar pago <span aria-hidden="true">→</span>';
        }
      }
    });
  }

  // Same subtle interaction as the original 3D card; entirely decorative.
  if (position && tilt) {
    position.addEventListener('pointermove', event => {
      if (reduced.matches || cashSelected || event.pointerType === 'touch') return;
      const box = position.getBoundingClientRect();
      const x = ((event.clientX - box.left) / box.width - .5) * 2;
      const y = ((event.clientY - box.top) / box.height - .5) * 2;
      if (tiltReset !== null) cancelAnimationFrame(tiltReset);
      tiltReset = requestAnimationFrame(() => {
        tilt.style.transform = 'rotateX(' + (-y * 4).toFixed(2) + 'deg) rotateY(' + (x * 5).toFixed(2) + 'deg)';
      });
    });
    position.addEventListener('pointerleave', resetTilt);
  }

  // Advisory timer only: all eligibility and idempotency remain server-side.
  if (hold) {
    function tick() {
      const seconds = Math.max(0, Math.ceil((endTime - performance.now()) / 1000));
      hold.textContent = String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
      if (seconds === 0 && form) {
        const submit = form.querySelector('[data-b32-submit]');
        if (submit && !inFlight) {
          submit.disabled = true;
          submit.textContent = 'Retención finalizada · vuelve a tu reserva';
        }
      }
    }
    tick();
    const timer = window.setInterval(tick, 1000);
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
  }
});
