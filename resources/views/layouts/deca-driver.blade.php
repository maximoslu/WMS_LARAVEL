<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'DECA para chóferes')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main style="padding: 16px; max-width: 760px; margin: auto;">
        <nav class="deca-action" aria-label="Acceso de chóferes">
            <a href="{{ route('driver.quick') }}" class="button-secondary">DECA rápido</a>
            <form method="POST" action="{{ route('driver.logout') }}">@csrf<button class="button-secondary" type="submit">Cerrar acceso</button></form>
        </nav>
        @yield('content')
    </main>
</body>
</html>
