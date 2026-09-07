<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Acceso - MindBloom</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/auth-portal.css') }}">
</head>
<body>

  <main class="mb-auth">
    <div class="mb-shell">

      <div class="mb-brand">
        <div class="mb-logo">MB</div>
        <div class="mb-brand-text">
          <div class="mb-brand-title">MindBloom</div>
          <div class="mb-brand-sub">Bienestar • Acceso</div>
        </div>
      </div>

      <div class="mb-card">

        <div class="mb-panel">
          <div class="mb-panel-inner">
            <h1>Tu espacio seguro, en un clic.</h1>
            <p>
              Elige cómo quieres entrar a MindBloom:
              <strong>Usuario</strong> para agendar y ver tus citas,
              o <strong>Profesional</strong> para gestionar solicitudes y disponibilidad.
            </p>

            <div class="mb-badges">
              <span class="mb-badge">🌿 Bienestar</span>
              <span class="mb-badge">🔒 Seguro</span>
              <span class="mb-badge">🗓 Citas</span>
            </div>

            <div class="mb-divider"></div>

            <div class="mb-mini">
              <div class="mb-mini-dot"></div>
              <span>Tip: si eres profesionista, conecta Google Calendar para agendar automáticamente.</span>
            </div>
          </div>
        </div>

        <div class="mb-options">

          @if (session('success'))
            <div class="mb-alert mb-success">{{ session('success') }}</div>
          @endif

          @if (session('error'))
            <div class="mb-alert mb-error">{{ session('error') }}</div>
          @endif

          @if ($errors->any())
            <div class="mb-alert mb-error">{{ $errors->first() }}</div>
          @endif

          <div class="mb-title">
            <h2>¿Cómo quieres entrar?</h2>
            <p>Selecciona tu rol para iniciar sesión o crear cuenta.</p>
          </div>

          <div class="mb-grid">

            <div class="mb-box">
              <div class="mb-box-top">
                <div class="mb-icon">👤</div>
                <div>
                  <div class="mb-box-title">Usuario</div>
                  <div class="mb-box-sub">Agenda, revisa y gestiona tus citas.</div>
                </div>
              </div>

              <div class="mb-actions">
                <a class="mb-btn mb-btn-primary" href="{{ route('login') }}">
                  Iniciar sesión (Usuario)
                </a>
                <a class="mb-btn mb-btn-ghost" href="{{ route('registro.create') }}">
                  Crear cuenta (Usuario)
                </a>
              </div>
            </div>

            <div class="mb-box">
              <div class="mb-box-top">
                <div class="mb-icon">🩺</div>
                <div>
                  <div class="mb-box-title">Profesionista</div>
                  <div class="mb-box-sub">Disponibilidad, solicitudes y agenda.</div>
                </div>
              </div>

              <div class="mb-actions">
                <a class="mb-btn mb-btn-primary" href="{{ route('profesionista.login') }}">
                  Iniciar sesión (Profesional)
                </a>
                <a class="mb-btn mb-btn-ghost" href="{{ route('profesionista.create') }}">
                  Crear cuenta (Profesional)
                </a>
              </div>
            </div>

          </div>

          <div class="mb-footer">
            <span>MindBloom</span>
            <span class="mb-dot">•</span>
            <a href="/" class="mb-link">Volver</a>
          </div>

        </div>

      </div>

    </div>
  </main>

</body>
</html>