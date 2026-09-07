<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Registro - MindBloom</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/registro-unificado.css') }}">
</head>

@php
  $role = request('role', 'user'); // user | prof
  $isProf = $role === 'prof';
@endphp

<body class="{{ $isProf ? 'is-prof' : 'is-user' }}">

  <div class="mb-auth-wrap">

    <div class="mb-auth-card {{ $isProf ? 'right-panel-active' : '' }}" id="authCard">

      <!-- ====== REGISTRO USUARIO ====== -->
      <div class="mb-form-container mb-sign-in-container">
        <form class="mb-form" method="POST" action="{{ route('registro.store') }}">
          @csrf
          <input type="hidden" name="role" value="user">

          <div class="mb-brand">
            <div class="mb-logo">MB</div>
            <div>
              <div class="mb-title">MindBloom</div>
              <div class="mb-sub">Usuario</div>
            </div>
          </div>

          <h1 class="mb-h1">Crear cuenta</h1>
          <p class="mb-p">Tu camino empieza aquí 🌿</p>

          @if ($errors->any() && (old('role') === 'user' || !old('role')))
            <div class="mb-alert mb-error">{{ $errors->first() }}</div>
          @endif

          <label class="mb-label" for="name_user">Nombre</label>
          <input id="name_user" class="mb-input" type="text" name="name" value="{{ old('name') }}" required placeholder="Tu nombre">

          <label class="mb-label" for="name_user">¿Cuantos años tienes?</label>
          <input class="mb-input" type="number" name="age"
           placeholder="¿Qué edad tienes?" required>

           <label class="mb-label" for="name_user">¿Cual es tu genero?</label>
           <select class="mb-input" name="gender" required>
            <option value="">¿Cuál es tu género?</option>
            <option value="Hombre">Hombre</option>
            <option value="Mujer">Mujer</option>
            <option value="Otra identidad de género">Otra identidad de género</option>
            <option value="Prefiero no decirlo">Prefiero no decirlo</option>
          </select>

          <label class="mb-label" for="email_user">Correo</label>
          <input id="email_user" class="mb-input" type="email" name="email" value="{{ old('email') }}" required placeholder="tuemail@correo.com">

          <label class="mb-label" for="pass_user">Contraseña</label>
          <input id="pass_user" class="mb-input" type="password" name="password" required placeholder="••••••••">
<br>
          <button class="mb-btn mb-btn-primary" type="submit">Crear cuenta</button>

          <div class="mb-bottom">
            <span>¿Ya tienes cuenta?</span>
            <a class="mb-link" href="{{ route('login', ['role' => 'user']) }}">Iniciar sesión</a>
          </div>
        </form>
      </div>

      <!-- ====== REGISTRO PROFESIONISTA ====== -->
      <div class="mb-form-container mb-sign-up-container">
        <form class="mb-form" method="POST" action="{{ route('profesionista.store') }}">
          @csrf
          <input type="hidden" name="role" value="prof">

          <div class="mb-brand">
            <div class="mb-logo">MB</div>
            <div>
              <div class="mb-title">MindBloom</div>
              <div class="mb-sub">Profesional</div>
            </div>
          </div>

          <h1 class="mb-h1">Registro profesional</h1>
          <p class="mb-p">Conecta, acompaña y agenda ✨</p>

          @if ($errors->any() && old('role') === 'prof')
            <div class="mb-alert mb-error">{{ $errors->first() }}</div>
          @endif

          <label class="mb-label" for="nombre_prof">Nombre</label>
          <input id="nombre_prof" class="mb-input" type="text" name="nombre" value="{{ old('nombre') }}" required placeholder="Tu nombre">

          <label class="mb-label" for="email_prof">Correo</label>
          <input id="email_prof" class="mb-input" type="email" name="email" value="{{ old('email') }}" required placeholder="prof@correo.com">

          <label class="mb-label" for="pass_prof">Contraseña</label>
          <input id="pass_prof" class="mb-input" type="password" name="password" required placeholder="••••••••">

          <label class="mb-label" for="passc_prof">Confirmar contraseña</label>
          <input id="passc_prof" class="mb-input" type="password" name="password_confirmation" required placeholder="••••••••">

          <label class="mb-label" for="passc_prof">Cedula Profesional</label>
          <input class="mb-input" type="text" name="cedula" placeholder="Cédula profesional" required>
<br>
          <button class="mb-btn mb-btn-primary" type="submit">Crear cuenta</button>

          <div class="mb-bottom">
            <span>¿Ya tienes cuenta?</span>
            <a class="mb-link" href="{{ route('login', ['role' => 'prof']) }}">Iniciar sesión</a>
          </div>
        </form>
      </div>

      <!-- ====== OVERLAY ====== -->
      <div class="mb-overlay-container">
        <div class="mb-overlay">

          <div class="mb-overlay-panel mb-overlay-left">
            <h1 class="mb-oh1">Registro profesional</h1>
            <p class="mb-op">Crea tu perfil y configura disponibilidad.</p>
            <button class="mb-btn mb-btn-ghost" id="toUser">Soy usuario</button>
          </div>

          <div class="mb-overlay-panel mb-overlay-right">
            <h1 class="mb-oh1">Registro de usuario</h1>
            <p class="mb-op">Crea tu cuenta para agendar y recibir apoyo.</p>
            <button class="mb-btn mb-btn-ghost" id="toProf">Soy profesionista</button>
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

    toProf.addEventListener('click', () => {
      card.classList.add('right-panel-active');
      const url = new URL(window.location.href);
      url.searchParams.set('role', 'prof');
      window.history.replaceState({}, '', url);
    });

    toUser.addEventListener('click', () => {
      card.classList.remove('right-panel-active');
      const url = new URL(window.location.href);
      url.searchParams.set('role', 'user');
      window.history.replaceState({}, '', url);
    });

    @if (old('role') === 'prof')
      card.classList.add('right-panel-active');
    @endif
  </script>

</body>
</html>