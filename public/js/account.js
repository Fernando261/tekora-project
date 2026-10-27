const toggle = document.getElementById('toggleSidebar');
if (toggle) {
  const closeMenu = () => {
    document.body.classList.remove('sidebar-open');
    toggle.setAttribute('aria-expanded', 'false');
  };
  toggle.addEventListener('click', () => {
    const opened = document.body.classList.toggle('sidebar-open');
    toggle.setAttribute('aria-expanded', String(opened));
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
      closeMenu();
      toggle.focus();
    }
  });
  document.getElementById('sidebar')?.addEventListener('click', event => {
    if (event.target.closest('a')) closeMenu();
  });
}
