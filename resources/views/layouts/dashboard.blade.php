<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'MAXIMO WMS Panel de control')</title>
        <link rel="icon" type="image/png" href="{{ asset('brand/maximo-icon.png') }}">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="brand-body app-shell-body">
        @php($user = auth()->user())
        @php($userName = $user->name)
        @php($roleName = $user->role?->name ?? 'Sin rol asignado')
        @php($userAvatarUrl = $user->avatar_url)
        @php($navigationSections = $navigationSections ?? [])
        @php($unreadNotificationsCount = $layoutUnreadNotificationsCount ?? 0)
        @php($topbarTitle = $__env->yieldContent('topbar_title', trim(explode('|', $__env->yieldContent('title', 'MAXIMO WMS'))[0])))
        @php($userInitials = collect(preg_split('/\s+/', trim($userName)))->filter()->take(2)->map(fn (string $chunk) => strtoupper(substr($chunk, 0, 1)))->implode(''))

        <div class="app-drawer-backdrop" data-drawer-backdrop hidden></div>

        <aside class="app-drawer" id="app-drawer" data-app-drawer aria-hidden="true">
            <div class="app-drawer-panel surface-card">
                <div class="app-drawer-header">
                    <a href="{{ route('dashboard') }}" class="app-drawer-brand" aria-label="Ir al panel de control">
                        <img
                            src="{{ asset('brand/maximo-icon.png') }}"
                            alt="MAXIMO Servicios Logisticos"
                            class="app-drawer-mark"
                        >
                        <div class="app-drawer-brand-copy">
                            <strong>MAXIMO WMS</strong>
                            <span>Panel de control</span>
                        </div>
                    </a>

                    <button
                        type="button"
                        class="app-menu-toggle"
                        data-drawer-close
                        aria-controls="app-drawer"
                        aria-label="Cerrar menú"
                    >
                        <span></span>
                        <span></span>
                    </button>
                </div>

                <div class="app-drawer-user">
                    @if ($userAvatarUrl !== null)
                        <img src="{{ $userAvatarUrl }}" alt="Avatar de {{ $userName }}" class="app-drawer-avatar-image">
                    @else
                        <span class="app-drawer-avatar" aria-hidden="true">{{ $userInitials }}</span>
                    @endif
                    <div class="app-drawer-user-copy">
                        <strong>{{ $userName }}</strong>
                        <span>{{ $roleName }}</span>
                    </div>
                </div>

                <nav class="app-drawer-nav ops-nav" aria-label="Navegación principal">
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
                                        <strong>{{ $child['display_title'] ?? $child['title'] }}</strong>
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

                <a href="{{ route('profile.edit') }}" class="button-secondary compact-button btn-compact{{ request()->routeIs('profile.*') ? ' is-active' : '' }}">
                    Mi perfil
                </a>

                <a href="{{ route('notifications.index') }}" class="button-secondary compact-button btn-compact{{ request()->routeIs('notifications.*') ? ' is-active' : '' }}">
                    Notificaciones
                    @if ($unreadNotificationsCount > 0)
                        <span class="users-pending-count">{{ $unreadNotificationsCount }}</span>
                    @endif
                </a>

                <form method="POST" action="{{ route('logout') }}" class="app-drawer-logout">
                    @csrf
                    <button type="submit" class="button-secondary compact-button btn-compact">Cerrar sesión</button>
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
                        aria-label="Abrir menú"
                    >
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>

                    <a href="{{ route('dashboard') }}" class="app-topbar-brand" aria-label="Ir al panel de control">
                        <img
                            src="{{ asset('brand/maximo-icon.png') }}"
                            alt="MAXIMO Servicios Logisticos"
                            class="app-topbar-mark"
                        >
                        <span class="app-topbar-label">MAXIMO</span>
                    </a>

                    <div class="app-topbar-copy">
                        <strong>{{ $topbarTitle }}</strong>
                        <span class="app-topbar-meta">Panel de control</span>
                    </div>
                </div>

                <div class="app-topbar-end">
                    <a href="{{ route('notifications.index') }}" class="button-secondary compact-button btn-compact app-notification-link">
                        Notificaciones
                        @if ($unreadNotificationsCount > 0)
                            <span class="users-pending-count">{{ $unreadNotificationsCount }}</span>
                        @endif
                    </a>
                    <span class="sr-only">Rol</span>
                    <span class="app-role-chip">{{ $roleName }}</span>
                    <a href="{{ route('profile.edit') }}" class="button-secondary compact-button btn-compact">Mi perfil</a>
                    <strong class="app-topbar-user">{{ $userName }}</strong>

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
