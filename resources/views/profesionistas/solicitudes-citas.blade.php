<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Solicitudes - MindBloom</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/solicitudes.css') }}">
  <link rel="stylesheet" href="{{ asset('css/account.css') }}">
</head>

<body>

  <div class="home-layout">

    <!-- ✅ Sidebar (Profesionista) -->
    <!-- SIDEBAR (IGUAL al dashboard-profesionista) -->
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <div class="brand-logo">MB</div>
    <div class="brand-text">
      <span class="brand-title">MindBloom</span>
      <span class="brand-sub">Profesional</span>
    </div>
  </div>

  <nav class="menu">
    <a class="menu-item" href="/dashboard-profesionista">
      <span class="icon">▦</span>
      <span>Dashboard</span>
    </a>

    <button class="menu-item has-sub" type="button" data-sub="createSub">
      <span class="icon">＋</span>
      <span>Gestión</span>
      <span class="chev" aria-hidden="true">▾</span>
    </button>

    <div class="submenu open" id="createSub">
      <a href="/profesionista/disponibilidad" class="submenu-item">Disponibilidad</a>

      <!-- ✅ Activo aquí -->
      <a href="/solicitudes-citas" class="submenu-item"
         style="background: rgba(255,255,255,.85); border: 1px solid rgba(232,49,138,.25); box-shadow: 0 10px 18px -14px rgba(232,49,138,.28);">
        Solicitudes de citas
      </a>
    </div>

    <button class="menu-item has-sub" type="button" data-sub="accountSub">
      <span class="icon">🔒</span>
      <span>Cuenta</span>
      <span class="chev" aria-hidden="true">▾</span>
    </button>

    <div class="submenu" id="accountSub">
      <a href="{{ route('google.connect') }}" class="submenu-item">Google Calendar</a>
    </div>

    <a class="menu-item active" href="/solicitudes-citas">
      <span class="icon">🔔</span>
      <span>Notificaciones</span>
    </a>

    <!-- ⚠️ OJO: usa el logout del guard profesionista si lo tienes -->
    <form method="POST" action="{{ route('profesionista.logout') }}" class="logout-form">
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

    <!-- ✅ Contenido -->
    <main class="main-content">
<x-legacy-account-notice />

      <div class="content-head">
        <button class="toggle" id="toggleSidebar" type="button" title="Menú">☰</button>
      </div>

      <div class="hero hero-mini">
        <h1>Solicitudes pendientes</h1>
        <p>Revisa las solicitudes. Si aceptas, se agenda en Google Calendar. Si rechazas, no aparece en agenda.</p>
      </div>

      <section class="wrap">

        {{-- alerts --}}
        @if (session('success'))
          <div class="mb-alert mb-success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
          <div class="mb-alert mb-error">{{ session('error') }}</div>
        @endif

        @if ($errors->any())
          <div class="mb-alert mb-error">{{ $errors->first() }}</div>
        @endif

        @if($citas->isEmpty())

          <div class="empty">
            <div class="empty-icon">🌿</div>
            <h2>Todo tranquilo</h2>
            <p>No tienes solicitudes pendientes por ahora.</p>
          </div>

        @else

          <div class="grid">
            @foreach($citas as $cita)

              <div class="card">
                <div class="card-top">
                  <div>
                    <div class="date">
                      {{ \Carbon\Carbon::parse($cita->start_datetime)->format('d/m/Y H:i') }}
                    </div>
                    <div class="meta">
                      Fin: {{ \Carbon\Carbon::parse($cita->end_datetime)->format('H:i') }}
                    </div>
                  </div>

                  <span class="badge">
                    {{ $cita->estado ?? 'pendiente' }}
                  </span>
                </div>

                <div class="actions">
                  <form method="POST" action="{{ route('citas.aceptar', $cita->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-accept">✓ Aceptar</button>
                  </form>

                  <form method="POST" action="{{ route('citas.rechazar', $cita->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-reject">✕ Rechazar</button>
                  </form>
                </div>
              </div>

            @endforeach
          </div>

        @endif

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

  // Sidebar toggle (responsive + collapsed)
  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('toggleSidebar');

  toggle.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    document.body.classList.toggle('sidebar-collapsed');
  });
</script>

</body>
</html>