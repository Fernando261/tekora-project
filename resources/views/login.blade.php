<x-access-layout title="Iniciar sesión">
  <section class="account-card access-card">
    <span class="account-eyebrow">Cuenta única · Contrata y ofrece</span>
    <h1>Iniciar sesión</h1>
    <p>Entra con tu cuenta para contratar servicios, ofrecerlos o hacer ambas cosas.</p>
    <form class="account-form" method="POST" action="{{ route('login.post') }}">
      @csrf
      <label for="email">Correo electrónico</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
      <label for="password">Contraseña</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
      <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
      <button class="account-button" type="submit">Entrar a mi cuenta</button>
    </form>
    <p class="access-footer">¿No tienes cuenta? <a href="{{ route('registro.create') }}">Crear una cuenta única</a></p>
  </section>
  <details class="account-card legacy-access" id="legacy-access" {{ request('role') === 'prof' || session('legacy_login') ? 'open' : '' }}>
    <summary>Ya tenía una cuenta de profesionista en MindBloom</summary>
    <p>Acceso heredado, solo para cuentas profesionales ya existentes. Conserva tu agenda y tus citas anteriores. Tu cuenta no se vincula automáticamente con la cuenta única.</p>
    <form class="account-form" method="POST" action="{{ route('profesionista.login.post') }}">
      @csrf
      <label for="legacy-email">Correo de tu cuenta profesional anterior</label>
      <input id="legacy-email" name="email" type="email" value="{{ session('legacy_login') ? old('email') : '' }}" required autocomplete="email">
      <label for="legacy-password">Contraseña de la cuenta anterior</label>
      <input id="legacy-password" name="password" type="password" required autocomplete="current-password">
      <button class="account-button secondary" type="submit">Entrar al acceso heredado</button>
    </form>
    <p>No tienes que usar este acceso para empezar a ofrecer servicios: <a href="{{ route('registro.create', ['intent' => 'provider']) }}">crea tu cuenta única</a>.</p>
  </details>
</x-access-layout>
