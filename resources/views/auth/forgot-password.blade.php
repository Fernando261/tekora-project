<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar contraseña - MindBloom</title>
    <link rel="stylesheet" href="/css/registro.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>

<header>
    <h2>MindBloom</h2>
    <nav>
        <ul>
            <li><a href="/login">Iniciar sesión</a></li>
        </ul>
    </nav>
</header>

<main>
    <div class="container">
        <div class="heading">Recuperar contraseña</div>

        @if (session('status'))
            <p class="success">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div class="errores">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="form">
            @csrf

            <input class="input" type="email" name="email"
                   placeholder="Ingresa tu correo electrónico" required>

            <input class="register-button" type="submit"
                   value="Enviar enlace de recuperación">
        </form>

        <div class="agreement">
            <a href="/login">Volver al login</a>
        </div>
    </div>
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
