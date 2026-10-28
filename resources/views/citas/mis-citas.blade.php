<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Mis citas - MindBloom</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/mis-citas.css') }}">
  <link rel="stylesheet" href="{{ asset('css/account.css') }}">
</head>

<body>

<div class="home-layout">

  <!-- SIDEBAR (Usuario) -->
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-logo">
        <img class="brand-logo" src="{{ asset('img/logo.png') }}" alt="">
      </div>
      <div class="brand-text">
        <span class="brand-title">MindBloom</span>
        <span class="brand-sub">Bienestar</span>
      </div>
    </div>

    <x-account-navigation />

    <div class="sidebar-footer">
      <div class="hint">
        <strong>Tip MindBloom</strong>
        <p>Pequeños pasos diarios, grandes cambios 🌿</p>
      </div>
    </div>
  </aside>

  <!-- CONTENIDO -->
  <main class="main-content">

    <div class="content-head">
      <button class="toggle" id="toggleSidebar" type="button" title="Menú">☰</button>
    </div>

    <div class="hero hero-mini">
      <h1>Mis citas</h1>
      <p>Aquí puedes ver el estado de tus solicitudes y citas aceptadas.</p>
    </div>

    <section class="wrap">

      @if($citas->isEmpty())
        <div class="empty">
          <div class="empty-icon">🌿</div>
          <h2>Aún no tienes citas</h2>
          <p>Cuando agendes una, aparecerá aquí.</p>
          <a class="cta" href="/profesionistas">Buscar profesionistas</a>
        </div>
      @else
        <div class="grid">
          @foreach($citas as $cita)
            @php
              $estado = $cita->estado ?? 'pendiente';
              $profName = $cita->profesionista->nombre ?? $cita->profesionista->name ?? 'Profesional';
            @endphp

            <div class="card">
              <div class="card-top">
                <div>
                  <div class="date">
                    {{ \Carbon\Carbon::parse($cita->start_datetime)->format('d/m/Y H:i') }}
                  </div>
                  <div class="meta">
                    Con: {{ $profName }} · Fin: {{ \Carbon\Carbon::parse($cita->end_datetime)->format('H:i') }}
                  </div>
                </div>

                <span class="badge badge-{{ $estado }}">
                  {{ $estado }}
                </span>
              </div>

              <div class="details">
                @if($estado === 'aceptada')
                  <div class="ok">✅ Aceptada. Ya está agendada con el profesionista.</div>
                @elseif($estado === 'rechazada')
                  <div class="bad">❌ Rechazada. Puedes intentar otro horario.</div>
                @else
                  <div class="warn">⏳ Pendiente. Espera la confirmación del profesionista.</div>
                @endif
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
  // submenu
  document.querySelectorAll('.menu-item.has-sub').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.sub;
      const box = document.getElementById(id);
      const opened = box.classList.toggle('open');
      btn.classList.toggle('open', opened);
    });
  });

  // responsive sidebar
  const toggle = document.getElementById('toggleSidebar');
  toggle.addEventListener('click', () => {
    document.body.classList.toggle('sidebar-open');
  });
</script>

</body>
</html>