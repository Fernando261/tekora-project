<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard Profesionista - MindBloom</title>
  <link rel="stylesheet" href="/css/dashboard-profesionista.css">
</head>
<body>

<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-logo">MB</div>
      <div class="brand-text">
        <span class="brand-title">MindBloom</span>
        <span class="brand-sub">Profesional</span>
      </div>
    </div>

    <nav class="menu">
      <a class="menu-item active" href="/dashboard-profesionista">
        <span class="icon">▦</span>
        <span>Dashboard</span>
      </a>

      <button class="menu-item has-sub" type="button" data-sub="createSub">
        <span class="icon">＋</span>
        <span>Gestión</span>
        <span class="chev" aria-hidden="true">▾</span>
      </button>

      <div class="submenu" id="createSub">
        <a href="/profesionista/disponibilidad" class="submenu-item">Disponibilidad</a>
        <a href="/solicitudes-citas" class="submenu-item">Solicitudes de citas</a>
      </div>

      <button class="menu-item has-sub" type="button" data-sub="accountSub">
        <span class="icon">🔒</span>
        <span>Cuenta</span>
        <span class="chev" aria-hidden="true">▾</span>
      </button>

      <div class="submenu" id="accountSub">
        <a href="/perfil" class="submenu-item">Perfil</a>
        <a href="{{ route('google.connect') }}" class="submenu-item">Google Calendar</a>
        <a href="#" class="submenu-item">Preferencias</a>
      </div>

      <a class="menu-item" href="/solicitudes-citas">
        <span class="icon">🔔</span>
        <span>Notificaciones</span>
      </a>

      <a class="menu-item" href="#">
        <span class="icon">❤</span>
        <span>Favoritos</span>
      </a>

      <a class="menu-item" href="#">
        <span class="icon">⚙</span>
        <span>Configuración</span>
      </a>

      <form method="POST" action="{{ route('logout') }}" class="logout-form">
          @csrf
          <button type="submit" class="menu-item logout-btn" style="width:100%;">
            <span class="icon">⎋</span>
            <span>Cerrar sesión</span>
          </button>
        </form>

    </nav>

    <div class="sidebar-footer">
      @php $p = auth('profesionista')->user(); @endphp
      <div class="user">
        <div class="avatar">{{ strtoupper(substr($p->nombre ?? 'P', 0, 1)) }}</div>
        <div class="user-meta">
          <div class="user-name">{{ $p->nombre ?? 'Profesional' }}</div>
          <div class="user-email">{{ $p->email ?? '' }}</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="content">
    <div class="content-head">
      <button class="toggle" id="toggleSidebar" type="button" title="Menú">☰</button>
      <div>
        <h1>MindBloom Profesionista</h1>
        <p>Resumen rápido de tu actividad en MindBloom.</p>
      </div>
    </div>

    <section class="cards">
      <div class="card">
        <h3>Solicitudes pendientes</h3>
        <p class="big">—</p>
        <p class="muted">Revisa y responde solicitudes.</p>
        <a class="card-link" href="{{ route('profesionista.solicitudes') }}">Ver solicitudes →</a>
      </div>

      <div class="card">
        <h3>Disponibilidad</h3>
        <p class="big">—</p>
        <p class="muted">Configura horarios para agendar.</p>
        <a class="card-link" href="{{ route('prof.dispo') }}">Configurar →</a>
      </div>

      <div class="card">
        <h3>Google Calendar</h3>
        @php $connected = !empty($p->google_access_token); @endphp
        <p class="pill {{ $connected ? 'ok' : 'warn' }}">
          {{ $connected ? 'Conectado' : 'No conectado' }}
        </p>
        <p class="muted">Sincroniza disponibilidad real.</p>
        <a class="card-link" href="{{ route('google.connect') }}">Conectar →</a>
      </div>
    </section>
  </main>

</div>

<footer class="footer-links">
  <div><strong>COMPANY</strong><br>How it Works<br>Pricing</div>
  <div><strong>RESOURCES</strong><br>Blog Post Name<br>Articles</div>
  <div><strong>ABOUT</strong><br>Terms & Conditions<br>Privacy Policy</div>
</footer>

<script>
  // Expand/collapse submenu
  document.querySelectorAll('.menu-item.has-sub').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.sub;
      const box = document.getElementById(id);
      const opened = box.classList.toggle('open');
      btn.classList.toggle('open', opened);
    });
  });

  // Sidebar toggle (responsive)
  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('toggleSidebar');

  toggle.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    document.body.classList.toggle('sidebar-collapsed');
  });
</script>

</body>
</html>