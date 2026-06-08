<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'MAXIMO WMS')</title>
        <link rel="icon" type="image/png" href="{{ asset('brand/maximo-icon.png') }}">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="brand-body">
        <div class="auth-shell">
            <aside class="auth-brand surface-card" aria-label="Identidad de plataforma">
                <div class="auth-brand-panel">
                    <img
                        src="{{ asset('brand/maximo-logo-vertical.jpg') }}"
                        alt="MAXIMO Servicios Logisticos"
                        class="brand-logo-vertical"
                    >

                    <div class="auth-brand-copy">
                        <span class="status-chip">Plataforma interna</span>
                        <h1>Acceso a MAXIMO WMS</h1>
                        <p>Plataforma operativa logistica para gestion segura de almacen, stock y solicitudes.</p>
                    </div>

                    <div class="auth-highlight-list">
                        <article class="auth-highlight">
                            <strong>Acceso controlado</strong>
                            <p>Autenticacion centralizada con recuperacion de contrasena y jerarquia de roles.</p>
                        </article>

                        <article class="auth-highlight">
                            <strong>Operativa trazable</strong>
                            <p>Base preparada para controlar entradas, salidas, solicitudes y seguimiento funcional.</p>
                        </article>

                        <article class="auth-highlight">
                            <strong>Uso directo en movilidad</strong>
                            <p>Experiencia optimizada para movil, tablet y escritorio sin depender de patrones fragiles.</p>
                        </article>
                    </div>
                </div>
            </aside>

            <main class="auth-stage">
                <div class="auth-card surface-card">
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
                </div>
            </main>
        </div>
    </body>
</html>
