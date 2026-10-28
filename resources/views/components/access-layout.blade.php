@props(['title'])
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }} - MindBloom</title>
  <link rel="stylesheet" href="{{ asset('css/account.css') }}">
</head>
<body class="account-access">
  <main class="access-shell">
    <a class="access-brand" href="{{ route('login') }}"><img src="{{ asset('img/logo.png') }}" alt="">MindBloom</a>
    <x-flash-messages />
    {{ $slot }}
  </main>
</body>
</html>
