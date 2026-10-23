<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Perfil - MindBloom</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/perfil.css">
</head>

<body>
  <div class="home-layout">
    <aside class="sidebar" id="sidebar">
      <div class="brand">
        <div class="brand-logo"><img class="brand-logo" src="/img/logo.png" alt=""></div>
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

        <button class="menu-item has-sub open" type="button" data-sub="accountSub">
          <span class="icon">👤</span>
          <span>Cuenta</span>
          <span class="chev">▾</span>
        </button>

        <div class="submenu open" id="accountSub">
          <a href="/perfil" class="submenu-item active">Perfil</a>
          <a href="/citas" class="submenu-item">Mis citas</a>
        </div>

        <a class="menu-item" href="/profesionistas">
          <span class="icon">🩺</span>
          <span>Profesionistas</span>
        </a>

        <a class="menu-item" href="/citas">
          <span class="icon">🗓</span>
          <span>Agendar cita</span>
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
        <div class="hint">
          <strong>Tip MindBloom</strong>
          <p>Haz una pausa, respira y vuelve a tu centro 🌿</p>
        </div>
      </div>
    </aside>

    <main class="main-content">
      <div class="content-head">
        <button class="toggle" id="toggleSidebar" type="button" title="Menú">☰</button>
      </div>
@if(session('success'))
  <div class="perfil-toast">{{ session('success') }}</div>
@endif
      <section class="perfil-hero">
        <div class="perfil-hero-inner">
          <div class="perfil-pill">MindBloom • tu cuenta</div>
          <h1>Tu perfil</h1>
          <p>Tu espacio, tu ritmo. Aquí puedes revisar tu información y mantenerla al día.</p>
        </div>

        <div class="perfil-hero-card">
          <div class="perfil-hero-card-title">Micro pausa</div>
          <div class="perfil-hero-card-text">Relaja hombros • Suelta mandíbula • Respira suave.</div>
        </div>
      </section>

      <section class="perfil-grid">
        <article class="perfil-card">
          <div class="perfil-card-head">
            @php
  use Illuminate\Support\Facades\Storage;
  $hasPhoto = !empty(Auth::user()->profile_photo_path);
  $photoUrl = $hasPhoto ? Storage::url(Auth::user()->profile_photo_path) : '/img/avatar.png';
@endphp

<form class="perfil-photo-form" action="{{ route('perfil.updatePhoto') }}" method="POST" enctype="multipart/form-data">
  @csrf

  <input
    id="perfilPhotoInput"
    class="perfil-photo-input"
    type="file"
    name="photo"
    accept="image/png,image/jpeg,image/webp"
  />

  <button
    type="button"
    class="perfil-avatar perfil-avatar-btn {{ $hasPhoto ? 'has-photo' : '' }}"
    id="perfilPhotoBtn"
    aria-label="{{ $hasPhoto ? 'Cambiar foto de perfil' : 'Agregar foto de perfil' }}"
    title="{{ $hasPhoto ? 'Cambiar foto' : 'Agregar foto' }}"
  >
    <img src="{{ $photoUrl }}" alt="Foto de perfil" class="perfil-avatar-img">
    <span class="perfil-avatar-overlay">
      <span class="perfil-avatar-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 5v14"></path>
          <path d="M5 12h14"></path>
        </svg>
      </span>
      <span class="perfil-avatar-text">{{ $hasPhoto ? 'Cambiar foto' : 'Agregar foto' }}</span>
    </span>
  </button>

  @error('photo')
    <div class="perfil-photo-error">{{ $message }}</div>
  @enderror
</form>

            <div class="perfil-user">
              <div class="perfil-name">{{ Auth::user()->name }}</div>
              <div class="perfil-email">{{ Auth::user()->email }}</div>
            </div>

            <a href="{{ route('perfil.editNombre') }}" class="perfil-edit">
              <svg class="perfil-edit-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
              </svg>
              <span>Editar nombre</span>
            </a>
          </div>

          <div class="perfil-fields">
            <div class="perfil-field">
              <span class="perfil-label">Nombre</span>
              <span class="perfil-value">{{ Auth::user()->name }}</span>
            </div>

            <div class="perfil-field">
              <span class="perfil-label">Edad</span>
              <span class="perfil-value">{{ Auth::user()->age }}</span>
            </div>

            <div class="perfil-field">
              <span class="perfil-label">Género</span>
              <span class="perfil-value">{{ Auth::user()->gender }}</span>
            </div>

            <div class="perfil-field">
              <span class="perfil-label">Email</span>
              <span class="perfil-value">{{ Auth::user()->email }}</span>
            </div>
          </div>
        </article>

        <aside class="perfil-side">
          <div class="perfil-side-card">
            <div class="perfil-side-head">
              <div class="perfil-side-ico">🌿</div>
              <div>
                <div class="perfil-side-title">Tu bienestar importa</div>
                <div class="perfil-side-sub">Pequeños hábitos, grandes cambios.</div>
              </div>
            </div>

            <div class="perfil-actions">
              <a class="perfil-action" href="/citas">
                <span class="a-ico">🗓</span>
                <span class="a-txt">
                  <strong>Agendar cita</strong>
                  <small>Un paso suave hacia el apoyo.</small>
                </span>
              </a>

              <a class="perfil-action" href="/comunidad">
                <span class="a-ico">💬</span>
                <span class="a-txt">
                  <strong>Comunidad</strong>
                  <small>Conexión y acompañamiento.</small>
                </span>
              </a>

              <a class="perfil-action" href="/conferencias">
                <span class="a-ico">🎧</span>
                <span class="a-txt">
                  <strong>Conferencias</strong>
                  <small>Herramientas para tu día a día.</small>
                </span>
              </a>
            </div>
          </div>

          <div class="perfil-side-card">
            <div class="perfil-mini">
              <div class="perfil-mini-title">Chequeo rápido</div>
              <p class="perfil-mini-text">Nombrar lo que sientes también es cuidarte.</p>
              <div class="perfil-badges">
                <span class="perfil-badge">Calma</span>
                <span class="perfil-badge">Ansiedad</span>
                <span class="perfil-badge">Energía</span>
              </div>
            </div>
          </div>
        </aside>
      </section>
    </main>
  </div>

  <footer class="footer-links">
    <div><strong>COMPANY</strong><br>How it Works<br>Pricing</div>
    <div><strong>RESOURCES</strong><br>Blog Post Name<br>Articles</div>
    <div><strong>ABOUT</strong><br>Terms & Conditions<br>Privacy Policy</div>
  </footer>

  <script>
    document.querySelectorAll('.menu-item.has-sub').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.sub;
        const box = document.getElementById(id);
        const opened = box.classList.toggle('open');
        btn.classList.toggle('open', opened);
      });
    });

    const toggle = document.getElementById('toggleSidebar');
    toggle.addEventListener('click', () => {
      document.body.classList.toggle('sidebar-open');
    });
  </script>

<script>
const btn = document.getElementById('perfilPhotoBtn');
const input = document.getElementById('perfilPhotoInput');

if (btn && input) {
  btn.addEventListener('click', () => {
    input.click();
  });

  input.addEventListener('change', () => {
    if (input.files.length > 0) {
      input.form.submit();
    }
  });
}
</script>

</body>
</html>