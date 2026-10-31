const editor = document.querySelector('[data-availability-editor]');
if (editor) {
  const template = document.getElementById('availability-interval-template');
  let nextIndex = Math.max(-1, ...Array.from(editor.querySelectorAll('input[name^="intervals["]'), input => Number(input.name.match(/intervals\[(\d+)\]/)[1]))) + 1;
  const refresh = () => {
    const count = editor.querySelectorAll('[data-interval-row]').length;
    editor.querySelectorAll('[data-add-interval]').forEach(button => { button.hidden = false; button.disabled = count >= 42; });
    editor.querySelectorAll('[data-remove-interval]').forEach(button => { button.hidden = false; });
    editor.querySelector('[data-row-limit]').textContent = `${count} filas de un máximo de 42; las filas vacías no publican un turno.`;
  };
  editor.addEventListener('click', event => {
    const remove = event.target.closest('[data-remove-interval]');
    if (remove) {
      const day = remove.closest('[data-day]');
      remove.closest('[data-interval-row]').remove(); refresh(); day.querySelector('[data-add-interval]').focus(); return;
    }
    const add = event.target.closest('[data-add-interval]');
    if (!add || editor.querySelectorAll('[data-interval-row]').length >= 42) return;
    const day = add.closest('[data-day]');
    const row = template.content.cloneNode(true);
    row.querySelectorAll('[data-field]').forEach(input => {
      input.name = `intervals[${nextIndex}][${input.dataset.field}]`;
      if (input.dataset.field === 'day_of_week') input.value = day.dataset.day;
    });
    nextIndex++; day.querySelector('[data-interval-rows]').append(row); refresh();
    day.querySelector('[data-interval-row]:last-child input[type="time"]').focus();
  });
  refresh();
}
