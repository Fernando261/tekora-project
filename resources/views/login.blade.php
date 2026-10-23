<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Iniciar sesión - MindBloom</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/login-unificado.css') }}">
</head>

@php
  // role: user | prof
  $role = request('role', 'user');
  $roleOld = old('role'); 
  $isProf = ($roleOld === 'prof') ? true : ($role === 'prof');
@endphp

<body class="{{ $isProf ? 'is-prof' : 'is-user' }}">

  <div class="mb-auth-wrap">

    <div class="mb-auth-card {{ $isProf ? 'right-panel-active' : '' }}" id="authCard">

      @php
        // error general por sesión
        $sessionError = session('error');

        // errores de validación
        $hasErrors = $errors->any();
        $firstError = $hasErrors ? $errors->first() : null;

        // si hay errores y no viene old(role), asumimos user
        $errorRole = $roleOld ?: ($role ?: 'user');
      @endphp

      @if($sessionError)
        <div class="mb-alert mb-error mb-global-alert">
          {{ $sessionError }}
        </div>
      @endif

      @if(session('status') || session('success'))
        <div class="mb-alert mb-success mb-global-alert">
          {{ session('status') ?: session('success') }}
        </div>
      @endif

      @if($hasErrors && $firstError)
        <div class="mb-alert mb-error mb-global-alert">
          {{ $firstError }}
        </div>
      @endif

      <!-- ====== PANEL: LOGIN USUARIO ====== -->
      <div class="mb-form-container mb-sign-in-container">
        <form class="mb-form" method="POST" action="{{ url('/login') }}">
          @csrf
          <input type="hidden" name="role" value="user">

          <div class="mb-brand">
            <div class="mb-logo">MB</div>
            <div>
              <div class="mb-title">MindBloom</div>
              <div class="mb-sub">Usuario</div>
            </div>
          </div>

          <h1 class="mb-h1">Iniciar sesión</h1>
          <p class="mb-p">Accede a tu espacio de bienestar 🌿</p>

          <label class="mb-label" for="email_user">Correo</label>
          <input
            id="email_user"
            class="mb-input"
            type="email"
            name="email"
            value="{{ old('role') === 'user' ? old('email') : '' }}"
            required
            autocomplete="email"
            placeholder="tuemail@correo.com"
          >

          <label class="mb-label" for="pass_user">Contraseña</label>
          <input
            id="pass_user"
            class="mb-input"
            type="password"
            name="password"
            required
            autocomplete="current-password"
            placeholder="••••••••"
          >

          <div class="mb-row">
            <a class="mb-link" href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
          </div>
<br>
          <button class="mb-btn mb-btn-primary" type="submit">Entrar</button>

          <div class="mb-bottom">
            <span>¿No tienes cuenta?</span>
            <a class="mb-link" href="{{ route('registro.create', ['role' => 'user']) }}">Crear cuenta</a>
          </div>
        </form>
      </div>

      <!-- ====== PANEL: LOGIN PROFESIONISTA ====== -->
      <div class="mb-form-container mb-sign-up-container">
        <form class="mb-form" method="POST" action="{{ url('/login-profesionista') }}">
          @csrf
          <input type="hidden" name="role" value="prof">

          <div class="mb-brand">
            <div class="mb-logo">MB</div>
            <div>
              <div class="mb-title">MindBloom</div>
              <div class="mb-sub">Profesional</div>
            </div>
          </div>

          <h1 class="mb-h1">Iniciar sesión</h1>
          <p class="mb-p">Gestiona tu agenda y solicitudes ✨</p>

          <label class="mb-label" for="email_prof">Correo</label>
          <input
            id="email_prof"
            class="mb-input"
            type="email"
            name="email"
            value="{{ old('role') === 'prof' ? old('email') : '' }}"
            required
            autocomplete="email"
            placeholder="prof@correo.com"
          >

          <label class="mb-label" for="pass_prof">Contraseña</label>
          <input
            id="pass_prof"
            class="mb-input"
            type="password"
            name="password"
            required
            autocomplete="current-password"
            placeholder="••••••••"
          >

          <br>

          <button class="mb-btn mb-btn-primary" type="submit">Entrar</button>

          <div class="mb-bottom">
            <span>¿Aún no eres profesionista?</span>
            <a class="mb-link" href="{{ route('profesionista.create') }}">Registrarme</a>
          </div>
        </form>
      </div>

      <!-- ====== OVERLAY (ANIMACIÓN TIPO VIDEO) ====== -->
      <div class="mb-overlay-container">
        <div class="mb-overlay">

          <div class="mb-overlay-panel mb-overlay-left">
            <h1 class="mb-oh1">¡Hola, profesionista!</h1>
            <p class="mb-op">Entra para ver solicitudes y sincronizar tu agenda.</p>
            <button class="mb-btn mb-btn-ghost" id="toUser" type="button">Soy usuario</button>
          </div>

          <div class="mb-overlay-panel mb-overlay-right">
            <h1 class="mb-oh1">¡Hola!</h1>
            <p class="mb-op">¿Eres profesionista? Inicia sesión aquí para gestionar citas.</p>
            <button class="mb-btn mb-btn-ghost" id="toProf" type="button">Soy profesionista</button>
          </div>

        </div>
      </div>

    </div>

    <div class="mb-mini-footer">
      <span>MindBloom • Bienestar</span>
    </div>

  </div>

  <script>
    const card = document.getElementById('authCard');
    const toProf = document.getElementById('toProf');
    const toUser = document.getElementById('toUser');

    function setRole(role){
      if(role === 'prof'){
        card.classList.add('right-panel-active');
      } else {
        card.classList.remove('right-panel-active');
      }

      const url = new URL(window.location.href);
      url.searchParams.set('role', role);
      window.history.replaceState({}, '', url);
    }

    toProf.addEventListener('click', () => setRole('prof'));
    toUser.addEventListener('click', () => setRole('user'));

    
    @if($isProf)
      card.classList.add('right-panel-active');
    @endif
  </script>

</body>
</html>
