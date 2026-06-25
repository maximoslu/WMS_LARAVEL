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
    <body class="brand-body app-shell-body">
        @php($roleName = auth()->user()->role?->name ?? 'Sin rol asignado')
        @php($navigationSections = $navigationSections ?? [])
        @php($topbarTitle = trim(explode('|', $__env->yieldContent('title', 'MAXIMO WMS'))[0]))

        <div class="app-drawer-backdrop" data-drawer-backdrop hidden></div>

        <aside class="app-drawer" id="app-drawer" data-app-drawer aria-hidden="true">
            <div class="app-drawer-panel surface-card">
                <div class="app-drawer-header">
                    <a href="{{ route('dashboard') }}" class="app-drawer-brand" aria-label="Ir al dashboard">
                        <img
                            src="{{ asset('brand/maximo-logo-horizontal.png') }}"
                            alt="MAXIMO Servicios Logisticos"
                            class="brand-logo-horizontal app-topbar-logo"
                        >
                    </a>

                    <button
                        type="button"
                        class="app-menu-toggle"
                        data-drawer-close
                        aria-controls="app-drawer"
                        aria-label="Cerrar menu"
                    >
                        <span></span>
                        <span></span>
                    </button>
                </div>

                <div class="app-drawer-user">
                    <div class="app-drawer-user-copy">
                        <strong>{{ auth()->user()->name }}</strong>
                        <span>{{ auth()->user()->email }}</span>
                    </div>
                    <div class="app-drawer-user-meta">
                        <span class="role-badge badge-compact">{{ $roleName }}</span>
                    </div>
                </div>

                <nav class="app-drawer-nav ops-nav" aria-label="Navegacion principal">
                    @foreach ($navigationSections as $section)
                        @php($sectionActive = collect($section['children'])->contains(fn (array $child) => request()->routeIs(...($child['active_patterns'] ?? [$child['route']]))))

                        <details class="ops-nav-section" @if($sectionActive) open @endif>
                            <summary class="ops-nav-summary">
                                <strong>{{ $section['title'] }}</strong>
                                <span class="ops-status badge-compact">{{ count($section['children']) }}</span>
                            </summary>

                            <div class="ops-nav-list">
                                @foreach ($section['children'] as $child)
                                    @php($isActive = request()->routeIs(...($child['active_patterns'] ?? [$child['route']])))

                                    <a href="{{ route($child['route']) }}" class="ops-nav-link{{ $isActive ? ' is-active' : '' }}">
                                        <strong>{{ $child['title'] }}</strong>
                                        <span class="ops-link-meta">
                                            <span class="ops-status badge-compact {{ $child['status'] === 'ready' ? 'ops-status--ready' : 'ops-status--placeholder' }}">
                                                {{ $child['status_label'] }}
                                            </span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </nav>

                <form method="POST" action="{{ route('logout') }}" class="app-drawer-logout">
                    @csrf
                    <button type="submit" class="button-secondary compact-button btn-compact">Cerrar sesion</button>
                </form>
            </div>
        </aside>

        <div class="app-shell">
            <header class="app-topbar surface-card">
                <div class="app-topbar-start">
                    <button
                        type="button"
                        class="app-menu-toggle"
                        data-drawer-toggle
                        aria-controls="app-drawer"
                        aria-expanded="false"
                        aria-label="Abrir menu"
                    >
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>

                    <a href="{{ route('dashboard') }}" class="app-topbar-brand" aria-label="Ir al dashboard">
                        <img
                            src="{{ asset('brand/maximo-logo-horizontal.png') }}"
                            alt="MAXIMO Servicios Logisticos"
                            class="brand-logo-horizontal app-topbar-logo"
                        >
                    </a>

                    <div class="app-topbar-copy">
                        <span class="app-topbar-kicker">Panel operativo</span>
                        <strong>{{ $topbarTitle }}</strong>
                    </div>
                </div>

                <div class="app-topbar-end">
                    <div class="app-topbar-user">
                        <strong>{{ auth()->user()->name }}</strong>
                        <span>{{ $roleName }}</span>
                    </div>

                    <form method="POST" action="{{ route('logout') }}" class="app-topbar-logout">
                        @csrf
                        <button type="submit" class="button-secondary compact-button btn-compact">Salir</button>
                    </form>
                </div>
            </header>

            <main class="app-main">
                <div class="ops-content">
                    @yield('content')
                </div>
            </main>
        </div>
    </body>
</html>
