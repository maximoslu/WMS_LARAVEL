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
        @php($navigationSections = $navigationSections ?? [])

        <div class="ops-shell">
            <aside class="ops-sidebar surface-card">
                <div class="ops-brand">
                    <img
                        src="{{ asset('brand/maximo-logo-horizontal.png') }}"
                        alt="MAXIMO Servicios Logisticos"
                        class="brand-logo-horizontal"
                    >

                    <div class="ops-brand-copy">
                        <span class="status-chip">MAXIMO WMS</span>
                    </div>
                </div>

                <div class="ops-user-card">
                    <div class="ops-user-copy">
                        <strong>{{ auth()->user()->name }}</strong>
                        <span>{{ auth()->user()->email }}</span>
                        <span class="role-badge">{{ $roleName }}</span>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="button-secondary">Cerrar sesion</button>
                    </form>
                </div>

                <nav class="ops-nav" aria-label="Navegacion principal">
                    @foreach ($navigationSections as $section)
                        @php($sectionActive = collect($section['children'])->contains(fn (array $child) => request()->routeIs(...($child['active_patterns'] ?? [$child['route']]))))

                        <details class="ops-nav-section" @if($sectionActive) open @endif>
                            <summary class="ops-nav-summary">
                                <strong>{{ $section['title'] }}</strong>
                                <span class="ops-status">{{ count($section['children']) }}</span>
                            </summary>

                            <div class="ops-nav-list">
                                @foreach ($section['children'] as $child)
                                    @php($isActive = request()->routeIs(...($child['active_patterns'] ?? [$child['route']])))

                                    <a href="{{ route($child['route']) }}" class="ops-nav-link{{ $isActive ? ' is-active' : '' }}">
                                        <strong>{{ $child['title'] }}</strong>
                                        <span class="ops-link-meta">
                                            <span class="ops-status {{ $child['status'] === 'ready' ? 'ops-status--ready' : 'ops-status--placeholder' }}">
                                                {{ $child['status_label'] }}
                                            </span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </nav>
            </aside>

            <div class="ops-main">
                <header class="ops-topbar surface-card">
                    <div class="ops-topbar-copy">
                        <span class="status-chip">Panel operativo</span>
                    </div>

                    <div class="ops-topbar-meta">
                        <span class="role-badge">{{ $roleName }}</span>
                        <span class="text-muted">{{ auth()->user()->email }}</span>
                    </div>
                </header>

                <main class="ops-content">
                    @yield('content')
                </main>
            </div>
        </div>
    </body>
</html>
