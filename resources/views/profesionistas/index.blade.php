<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Profesionistas - MindBloom</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/profesionistas.css') }}">
</head>

<body>

  <div class="home-layout">

    <!-- Sidebar (MISMO del Home) -->
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

      <nav class="menu">
        <a class="menu-item" href="/">
          <span class="icon">▦</span>
          <span>Inicio</span>
        </a>

        <button class="menu-item has-sub" type="button" data-sub="createSub">
          <span class="icon">✎</span>
          <span>Explorar</span>
          <span class="chev">▾</span>
        </button>

        <div class="submenu" id="createSub">
          <a href="/comunidad" class="submenu-item">Comunidad</a>
          <a href="/conferencias" class="submenu-item">Conferencias</a>
        </div>

        <button class="menu-item has-sub" type="button" data-sub="accountSub">
          <span class="icon">👤</span>
          <span>Cuenta</span>
          <span class="chev">▾</span>
        </button>

        <div class="submenu" id="accountSub">
          <a href="/perfil" class="submenu-item">Perfil</a>
          <a href="/citas" class="submenu-item">Mis citas</a>
        </div>

        <!-- ✅ Activo -->
        <a class="menu-item active" href="/profesionistas">
          <span class="icon">🩺</span>
          <span>Profesionistas</span>
        </a>

        <a class="menu-item" href="/citas">
          <span class="icon">🗓</span>
          <span>Agendar cita</span>
        </a>

        <!-- ✅ Logout -->
        <form method="POST" action="{{ route('logout') }}" class="logout-form">
          @csrf
          <button type="submit" class="menu-item logout-btn" style="width:100%;">
            <span class="icon">⎋</span>
            <span>Cerrar sesión</span>
          </button>
        </form>
      </nav>

      <div class="sidebar-footer">
        <div class="hint">
          <strong>Tip MindBloom</strong>
          <p>Haz una pausa, respira y vuelve a tu centro 🌿</p>
        </div>
      </div>
    </aside>

    <!-- Contenido -->
    <main class="main-content">

      <div class="content-head">
        <button class="toggle" id="toggleSidebar" type="button" title="Menú">☰</button>
      </div>

      <div class="hero hero-mini">
        <h1>Profesionistas Aliados</h1>
        <p>Elige un profesionista y agenda una cita en los horarios disponibles.</p>
      </div>

      <section class="prof-wrap">

        <div class="topbar">
          <h2 class="page-title">Listado</h2>
          <a class="btn-top" href="{{ route('user.citas') }}">Ver mis citas</a>
        </div>

        <div class="cards-grid">
          @foreach($profesionistas as $profesionista)
            <div class="prof-card">
              <div class="card-glow"></div>

              <div class="card-header">
                <h3>{{ $profesionista->nombre }}</h3>
                <span class="badge">Aliado</span>
              </div>

              <p><strong>Email:</strong> {{ $profesionista->email }}</p>
              <p><strong>Cédula:</strong> {{ $profesionista->cedula }}</p>

              <div class="card-actions">
                <a class="btn-agendar" href="{{ route('citas.create', $profesionista->id) }}">
                  Agendar cita
                </a>
              </div>
            </div>
          @endforeach
        </div>

      </section>

    </main>
  </div>

  <footer class="footer-links">
    <div><strong>COMPANY</strong><br>How it Works<br>Pricing</div>
    <div><strong>RESOURCES</strong><br>Blog Post Name<br>Articles</div>
    <div><strong>ABOUT</strong><br>Terms & Conditions<br>Privacy Policy</div>
  </footer>

  <button class="chat-btn" onclick="openChat()">💬</button>

  <script>
    function openChat() { alert("Aquí se abriría el chat de asistencia o chatbot."); }

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

    // ✅ opcional: abrir submenu "Explorar" por defecto (para que se vea igual que tu home)
    // document.getElementById('createSub').classList.add('open');
    // document.querySelector('[data-sub="createSub"]').classList.add('open');
  </script>

</body>
</html>