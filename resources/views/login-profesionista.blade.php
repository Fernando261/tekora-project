<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Iniciar sesión - MindBloom</title>
  <link rel="stylesheet" href="/css/registro.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>

<header>
  <h2>MindBloom Profesionistas</h2>
  <nav>
    <a href="/registro-profesionista">Registro</a>
  </nav>
</header>

<main>
  <section class="container">

    <h1 class="heading">Iniciar Sesión</h1>

    @if ($errors->any())
      <div class="errores">
        <ul>
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form method="POST" action="{{ url('login-profesionista') }}" class="form">
      @csrf

      <input 
        class="input" 
        type="email" 
        name="email" 
        placeholder="Correo electrónico" 
        required
      >

      <input 
        class="input" 
        type="password" 
        name="password" 
        placeholder="Contraseña" 
        required
      >
<br><br><br>
      <button type="submit" class="register-button">
        Iniciar sesión
      </button>

    </form>

    <div class="agreement">
      <a href="/registro-profesionista">
        ¿No tienes cuenta? Regístrate
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
