
(() => {
  const mqLg = window.matchMedia('(min-width: 1024px)');
  const sidebar  = document.getElementById('sidebar');
  const main     = document.getElementById('mainContent');
  const toggle   = document.getElementById('sidebarToggle'); // botón "CS" del header
  const backdrop = document.getElementById('sidebarBackdrop');
  const brandTop = document.getElementById('brandCloseTop'); // "CS" dentro del sidebar
  if (!sidebar || !main || !toggle) return;

  const KEY = 'ui:sidebar:open';

  function getInitial() {
    const saved = localStorage.getItem(KEY);
    if (saved !== null) return saved === '1';
    return mqLg.matches; // desktop abierto, móvil cerrado
  }

  let open = getInitial();
  apply(open);

  function fireResize() {
    setTimeout(() => window.dispatchEvent(new Event('resize')), 20);
  }

  function apply(isOpen) {
    open = isOpen;
    localStorage.setItem(KEY, isOpen ? '1' : '0');

    sidebar.classList.toggle('-translate-x-full', !isOpen);

    main.dataset.collapsed = isOpen ? '0' : '1';

    // Backdrop solo móvil
    if (!mqLg.matches) {
      backdrop?.classList.toggle('hidden', !isOpen);
    } else {
      backdrop?.classList.add('hidden');
    }

    toggle.setAttribute('aria-expanded', String(isOpen));
    fireResize();
  }

  // Toggle con el botón CS del header
  toggle.addEventListener('click', () => apply(!open));

  // Cerrar con el CS dentro del sidebar
  brandTop?.addEventListener('click', () => apply(false));

  // Cerrar al hacer clic en cualquier enlace/botón dentro del sidebar
  document.querySelectorAll('#sidebar a, #sidebar button')
    .forEach(el => el.addEventListener('click', () => apply(false)));

  // Cerrar si clic en backdrop (móvil)
  backdrop?.addEventListener('click', () => apply(false));

  // Respetar preferencia guardada al cambiar breakpoint
  mqLg.addEventListener('change', () => {
    const saved = localStorage.getItem(KEY);
    apply(saved ? saved === '1' : mqLg.matches);
  });
})();
