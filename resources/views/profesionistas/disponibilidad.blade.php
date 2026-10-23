<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Disponibilidad - MindBloom</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/dashboard-profesionista.css">
  <link rel="stylesheet" href="/css/disponibilidad.css">
</head>
<body>

<div class="layout">

  <!-- SIDEBAR (idéntico al dashboard) -->
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

      <button class="menu-item has-sub open" type="button" data-sub="createSub">
        <span class="icon">＋</span>
        <span>Gestión</span>
        <span class="chev" aria-hidden="true">▾</span>
      </button>

      <div class="submenu open" id="createSub">
        <a href="/profesionista/disponibilidad" class="submenu-item">Disponibilidad</a>
        <a href="/solicitudes-citas" class="submenu-item">Solicitudes de citas</a>
      </div>

      <button class="menu-item has-sub" type="button" data-sub="accountSub">
        <span class="icon">🔒</span>
        <span>Cuenta</span>
        <span class="chev" aria-hidden="true">▾</span>
      </button>

      <div class="submenu" id="accountSub">
        <a href="{{ route('google.connect') }}" class="submenu-item">Google Calendar</a>
      </div>

      <a class="menu-item" href="/solicitudes-citas">
        <span class="icon">🔔</span>
        <span>Notificaciones</span>
      </a>

      <form method="POST" action="{{ route('profesionista.logout') }}" class="logout-form">
        @csrf
        <button type="submit" class="menu-item logout-btn" style="width:100%;">
          <span class="icon">⎋</span>
          <span>Cerrar sesión</span>
        </button>
      </form>
    </nav>

    <div class="sidebar-footer">
      <div class="user">
        <div class="avatar">{{ strtoupper(substr($prof->nombre ?? 'P', 0, 1)) }}</div>
        <div class="user-meta">
          <div class="user-name">{{ $prof->nombre ?? 'Profesional' }}</div>
          <div class="user-email">{{ $prof->email ?? '' }}</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- CONTENT -->
  <main class="content">
    <div class="content-head">
      <button class="toggle" id="toggleSidebar" type="button" title="Menú">☰</button>
      <div>
        <h1>Disponibilidad</h1>
        <p>Define tus rangos reales por día. Esto se usa para generar horarios disponibles.</p>
      </div>
    </div>

    @if (session('success'))
      <div class="mb-alert mb-success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
      @php $msgs = collect($errors->all())->unique()->values(); @endphp
      <div class="mb-alert mb-error">
        <strong>Ups…</strong> Revisa tu disponibilidad antes de guardar.
        <ul class="mb-error-list">
          @foreach ($msgs as $msg)
            <li>{{ $msg }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <div class="card">
      <div class="mb-top">
        <h3>Rangos de atención</h3>
        <button type="button" class="mb-add" id="addRangeBtn">+ Agregar rango</button>
      </div>

      <p class="muted" style="margin-top:6px;">
        Ejemplo: Lunes 09:00–14:00 y Lunes 16:00–19:00 (dos rangos).
      </p>

      <form method="POST" action="{{ route('prof.dispo.store') }}">
        @csrf

        @php
          $days = [
            0=>'Domingo',1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado'
          ];
        @endphp

        <!-- ✅ SOLO UN rangesBox (sin duplicados) -->
        <div id="rangesBox" class="mb-ranges">
          @if(isset($byDay) && $byDay->count())
            @foreach($byDay as $dow => $list)
              @foreach($list as $row)
                <div class="mb-range">
                  <select class="mb-select">
                    @foreach($days as $k=>$label)
                      <option value="{{ $k }}" {{ (int)$dow === (int)$k ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                  </select>

                  <input type="time" value="{{ substr($row->start_time,0,5) }}" class="mb-time">
                  <span class="mb-sep">—</span>
                  <input type="time" value="{{ substr($row->end_time,0,5) }}" class="mb-time">

                  <button type="button" class="mb-del js-del">Eliminar</button>
                </div>
              @endforeach
            @endforeach
          @else
            <!-- default 1 rango -->
            <div class="mb-range">
              <select class="mb-select">
                @foreach($days as $k=>$label)
                  <option value="{{ $k }}" {{ $k===1 ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
              </select>

              <input type="time" value="09:00" class="mb-time">
              <span class="mb-sep">—</span>
              <input type="time" value="18:00" class="mb-time">

              <button type="button" class="mb-del js-del">Eliminar</button>
            </div>
          @endif
        </div>

        <div class="mb-actions">
          <button type="submit" class="mb-save">Guardar disponibilidad</button>
        </div>
      </form>
    </div>
  </main>
</div>

<footer class="footer-links">
  <div><strong>COMPANY</strong><br>How it Works<br>Pricing</div>
  <div><strong>RESOURCES</strong><br>Blog Post Name<br>Articles</div>
  <div><strong>ABOUT</strong><br>Terms & Conditions<br>Privacy Policy</div>
</footer>

<script>
  // submenu (igual al dashboard)
  document.querySelectorAll('.menu-item.has-sub').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.sub;
      const box = document.getElementById(id);
      const opened = box.classList.toggle('open');
      btn.classList.toggle('open', opened);
    });
  });

  // sidebar toggle responsive (igual)
  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('toggleSidebar');
  toggle.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    document.body.classList.toggle('sidebar-collapsed');
  });
</script>

<!-- ✅ SOLO UN script para rangos (sin duplicados) -->
<script>
  const addBtn = document.getElementById('addRangeBtn');
  const box = document.getElementById('rangesBox');
  const form = document.querySelector('form[action="{{ route('prof.dispo.store') }}"]');

  function reindexRanges() {
    const rows = box.querySelectorAll('.mb-range');

    rows.forEach((row, idx) => {
      const sel = row.querySelector('select.mb-select');
      const times = row.querySelectorAll('input.mb-time');

      if (sel) sel.name = `ranges[${idx}][day_of_week]`;
      if (times[0]) times[0].name = `ranges[${idx}][start_time]`;
      if (times[1]) times[1].name = `ranges[${idx}][end_time]`;
    });
  }

  // eliminar (delegación)
  box.addEventListener('click', (e) => {
    if (e.target.classList.contains('js-del')) {
      e.target.closest('.mb-range').remove();
      reindexRanges();
    }
  });

  // agregar
  addBtn.addEventListener('click', () => {
    const html = `
      <div class="mb-range">
        <select class="mb-select">
          <option value="1">Lunes</option>
          <option value="2">Martes</option>
          <option value="3">Miércoles</option>
          <option value="4">Jueves</option>
          <option value="5">Viernes</option>
          <option value="6">Sábado</option>
          <option value="0">Domingo</option>
        </select>

        <input type="time" value="09:00" class="mb-time">
        <span class="mb-sep">—</span>
        <input type="time" value="18:00" class="mb-time">

        <button type="button" class="mb-del js-del">Eliminar</button>
      </div>
    `;
    box.insertAdjacentHTML('beforeend', html);
    reindexRanges();
  });

  // antes de enviar
  form.addEventListener('submit', () => reindexRanges());

  // al cargar
  reindexRanges();
</script>

</body>
</html>