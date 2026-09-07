<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar Nombre - MindBloom</title>
  <link rel="stylesheet" href="/css/perfil.css">
  <link rel="stylesheet" href="/css/editar.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>

<header>
  <h2>MindBloom</h2>
  <nav>
    <ul>
      <li><a href="/">Inicio</a></li>
      <li><a href="/comunidad">Comunidad</a></li>
      <li><a href="/conferencias">Conferencias</a></li>
      <li><a href="/citas">Citas</a></li>
      <li><a href="/reuniones">Reuniones</a></li>
      <li><a href="/perfil"><span title="Perfil">👤</span></a></li>
      <li>
        <form action="{{ route('logout') }}" method="POST" style="display: inline;">
          @csrf
          <button type="submit" style="background:none;border:none;cursor:pointer;">🚪</button>
        </form>
      </li>
    </ul>
  </nav>
</header>

<main>
  <section class="perfil-container">
    <div class="form-card">
        <h1>Actualizar nombre</h1>

        @if(session('success'))
            <p class="success-message">{{ session('success') }}</p>
        @endif

        <form action="{{ route('perfil.updateNombre') }}" method="POST">
            @csrf
            @method('PUT')

            <input type="text" 
                   name="name"
                   value="{{ Auth::user()->name }}"
                   placeholder="Nuevo nombre"
                   required>

            @error('name')
                <p class="error-message">{{ $message }}</p>
            @enderror

            <button type="submit">Guardar cambios</button>
        </form>

        <a href="{{ route('perfil') }}" class="volver-link">
            ← Volver al perfil
        </a>
    </div>
</section>

</main>


<footer class="footer-links">
  <div>
    <strong>COMPANY</strong>
    <p>How it Works</p>
    <p>Pricing</p>
  </div>
  <div>
    <strong>RESOURCES</strong>
    <p>Blog Post Name List</p>
  </div>
  <div>
    <strong>ABOUT</strong>
    <p>Terms & Conditions</p>
    <p>Privacy Policy</p>
  </div>
</footer>

</body>
</html>
