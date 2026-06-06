<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'MAXIMO WMS Dashboard')</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="wms-body">
        <div class="wms-dashboard-shell">
            <header class="wms-dashboard-header">
                <div>
                    <div class="wms-eyebrow">Entorno autenticado</div>
                    <h1>MAXIMO WMS</h1>
                    <p class="wms-dashboard-subtitle">Gestion profesional de almacen multicliente</p>
                </div>

                <div class="wms-dashboard-user">
                    <div>
                        <strong>{{ auth()->user()->name }}</strong>
                        <span>{{ auth()->user()->email }}</span>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="wms-secondary-button">Cerrar sesion</button>
                    </form>
                </div>
            </header>

            <main class="wms-dashboard-content">
                @yield('content')
            </main>
        </div>
    </body>
</html>
