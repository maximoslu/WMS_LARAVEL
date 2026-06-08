<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'Login | MAXIMO WMS')</title>
        <link rel="icon" type="image/png" href="{{ asset('brand/maximo-icon.png') }}">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="auth-page">
        <div class="auth-background" aria-hidden="true">
            <span class="auth-orb auth-orb--north"></span>
            <span class="auth-orb auth-orb--south"></span>
            <span class="auth-grid auth-grid--left"></span>
            <span class="auth-grid auth-grid--right"></span>
            <span class="auth-line auth-line--left"></span>
            <span class="auth-line auth-line--right"></span>
        </div>

        <main class="auth-frame">
            <section class="auth-panel surface-card" aria-label="Acceso a MAXIMO WMS">
                <div class="auth-logo-mark">
                    <img
                        src="{{ asset('brand/maximo-icon.png') }}"
                        alt="MAXIMO WMS"
                        class="auth-logo-icon"
                    >

                    <div class="auth-logo-copy">
                        <span class="auth-kicker">Acceso operativo</span>
                        <strong>MAXIMO WMS</strong>
                    </div>
                </div>

                @if (session('status'))
                    <div class="alert alert-success">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-error">
                        {{ $errors->first() }}
                    </div>
                @endif

                @yield('content')
            </section>
        </main>
    </body>
</html>
