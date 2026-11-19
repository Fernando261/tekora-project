document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-b32-form]');
  if (!form) return;
  const card = form.querySelector('[data-b32-card]');
  const update = () => {
    const isCard = form.querySelector('[name="method"]:checked')?.value === 'card';
    card.hidden = !isCard;
    for (const control of card.querySelectorAll('input, select')) control.disabled = !isCard;
  };
  form.querySelectorAll('[data-b32-method]').forEach(input => input.addEventListener('change', update));
  update();
});
