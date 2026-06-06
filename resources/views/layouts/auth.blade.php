<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'MAXIMO WMS')</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="wms-body">
        <div class="wms-auth-shell">
            <section class="wms-auth-hero">
                <div class="wms-eyebrow">Plataforma operativa</div>
                <h1>MAXIMO WMS</h1>
                <p class="wms-auth-subtitle">Gestion profesional de almacen multicliente</p>

                <div class="wms-auth-story">
                    <p>Centraliza accesos, operativa y trazabilidad en un entorno preparado para despliegue profesional.</p>
                </div>

                <div class="wms-feature-list">
                    <article>
                        <span>01</span>
                        <div>
                            <strong>Acceso seguro</strong>
                            <p>Autenticacion base con recuperacion de contraseña y sesiones protegidas.</p>
                        </div>
                    </article>
                    <article>
                        <span>02</span>
                        <div>
                            <strong>Preparado para Forge</strong>
                            <p>Arquitectura limpia en Laravel 12, lista para evolucionar sin acoplamientos innecesarios.</p>
                        </div>
                    </article>
                    <article>
                        <span>03</span>
                        <div>
                            <strong>Visibilidad multicliente</strong>
                            <p>Base visual corporativa para Friesland, Edelvives y futuras operaciones.</p>
                        </div>
                    </article>
                </div>
            </section>

            <main class="wms-auth-panel">
                <div class="wms-panel-card">
                    @if (session('status'))
                        <div class="wms-alert wms-alert-success">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="wms-alert wms-alert-error">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    @yield('content')
                </div>
            </main>
        </div>
    </body>
</html>
