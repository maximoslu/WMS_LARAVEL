<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'MAXIMO WMS Dashboard')</title>
        <link rel="icon" type="image/png" href="{{ asset('brand/maximo-icon.png') }}">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="brand-body">
        @php($roleName = auth()->user()->role?->name ?? 'Sin rol asignado')

        <div class="app-shell">
            <header class="app-header surface-card">
                <div class="app-header-content">
                    <div class="app-brand">
                        <img
                            src="{{ asset('brand/maximo-logo-horizontal.png') }}"
                            alt="MAXIMO Servicios Logisticos"
                            class="brand-logo-horizontal"
                        >

                        <div class="app-header-copy">
                            <span class="status-chip">Plataforma operativa logistica</span>
                            <p>Entorno interno para operativa de almacen, stock, accesos y trazabilidad multicliente.</p>
                        </div>
                    </div>

                    <div class="user-menu">
                        <div class="user-menu__identity">
                            <strong>{{ auth()->user()->name }}</strong>
                            <span>{{ auth()->user()->email }}</span>
                            <span class="role-badge">{{ $roleName }}</span>
                        </div>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="button-secondary">Cerrar sesion</button>
                        </form>
                    </div>
                </div>
            </header>

            <nav class="app-nav" aria-label="Navegacion principal">
                @foreach (($navigationItems ?? []) as $item)
                    <a
                        href="{{ route($item['route']) }}"
                        class="{{ request()->routeIs($item['route']) ? 'is-active' : '' }}"
                    >
                        <span class="nav-tag">{{ $item['tag'] }}</span>
                        <strong>{{ $item['title'] }}</strong>
                    </a>
                @endforeach
            </nav>

            <main class="app-main">
                @yield('content')
            </main>
        </div>
    </body>
</html>
