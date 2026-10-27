<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Agendar cita - MindBloom</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">

  {{-- ✅ opcional: estilos específicos de slots/agenda --}}
  <link rel="stylesheet" href="{{ asset('css/citas2.css') }}">
  <link rel="stylesheet" href="{{ asset('css/account.css') }}">
</head>

<body>

  <div class="home-layout">

    <!-- ✅ Sidebar (Usuario) -->
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
          <p>Haz una pausa, respira y vuelve a tu centro 🌿</p>
        </div>
      </div>
    </aside>

    <!-- ✅ Contenido -->
    <main class="main-content">

      <div class="content-head">
        <button class="toggle" id="toggleSidebar" type="button" title="Menú">☰</button>
      </div>

      <!-- ✅ Hero MindBloom -->
      <div class="hero hero-mini">
        <h1>Agendar con {{ $profesionista->nombre ?? $profesionista->name }}</h1>
        <p>Selecciona una fecha y un horario disponible para enviar la solicitud.</p>
      </div>

      <!-- ✅ Card MindBloom -->
      <section style="max-width: 900px; margin: 0 auto; padding: 0 10px 20px 10px;">

        {{-- ✅ Alerts --}}
        @if (session('success'))
          <div class="mb-alert mb-success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
          <div class="mb-alert mb-error">{{ session('error') }}</div>
        @endif

        @if ($errors->any())
          <div class="mb-alert mb-error">{{ $errors->first() }}</div>
        @endif

        <div class="card" style="border-left-color: rgba(109,184,201,.0); text-align:left;">
          <h2 style="margin:0 0 10px 0; color: var(--primary);">Selecciona tu horario</h2>
          <p class="description" style="margin:0 0 14px 0;">
            Te mostraremos los espacios libres del profesionista. Al confirmar, la cita queda <strong>pendiente</strong> hasta aprobación.
          </p>

          <label class="date-label" style="font-weight:900; display:block; margin: 6px 0;">Fecha</label>
          <input type="date" id="date" class="date-input" style="width:100%;">

          <div id="slots" class="slots-grid" style="margin-top:14px;">
            <div class="loading">Selecciona una fecha…</div>
          </div>

          <form method="POST" action="{{ route('citas.store') }}" style="margin-top:16px;">
            @csrf
            <input type="hidden" name="profesionista_id" value="{{ $profesionista->id }}">
            <input type="hidden" name="starts_at" id="starts_at">
            <input type="hidden" name="ends_at" id="ends_at">

            <button type="submit" id="confirmBtn" class="preview-btn" style="border:none; cursor:pointer;" disabled>
              Confirmar cita
            </button>
          </form>

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

  <script>
    const dateInput = document.getElementById('date');
    const slotsBox = document.getElementById('slots');
    const confirmBtn = document.getElementById('confirmBtn');

    const urlTpl = @json(route('profesionistas.availability', ['profesionista' => $profesionista->id]));

    dateInput.addEventListener('change', async () => {
      slotsBox.innerHTML = '<div class="loading">Cargando horarios…</div>';
      confirmBtn.disabled = true;

      const res = await fetch(`${urlTpl}?date=${dateInput.value}`);
      const data = await res.json();

      slotsBox.innerHTML = '';

      if (!data.slots || data.slots.length === 0) {
        slotsBox.innerHTML = '<div class="loading">No hay horarios disponibles.</div>';
        return;
      }

      data.slots.forEach(s => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'slot-btn';
        btn.textContent = s.label;

        btn.onclick = () => {
          document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');

          document.getElementById('starts_at').value = s.start;
          document.getElementById('ends_at').value = s.end;
          confirmBtn.disabled = false;
        };

        slotsBox.appendChild(btn);
      });
    });
  </script>

</body>
</html>