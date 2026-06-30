@extends('layouts.dashboard')

@section('title', 'Editar usuario | MAXIMO WMS')
@section('topbar_title', 'Editar usuario')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <a href="{{ route('users.index') }}">Usuarios</a>
        <span>/</span>
        <span>{{ $managedUser->name }}</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Editar usuario</h2>
            <span class="ops-page-meta">{{ $managedUser->email }}</span>
        </div>
    </section>

    <section class="surface-card item-form-card compact-card">
        <form method="POST" action="{{ route('users.update', $managedUser) }}" class="item-form">
            @csrf
            @method('PUT')

            <div class="item-form-grid form-grid">
                <label class="auth-field">
                    <span>Nombre</span>
                    <input type="text" name="name" value="{{ old('name', $managedUser->name) }}" class="auth-input" required>
                    @error('name')
                        <small class="helper-text helper-text--error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Email</span>
                    <input type="email" name="email" value="{{ old('email', $managedUser->email) }}" class="auth-input" required>
                    @error('email')
                        <small class="helper-text helper-text--error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Rol</span>
                    <select
                        name="role_id"
                        class="auth-input"
                        @disabled(! $canManageAssignments)
                        @if ($canManageAssignments) data-user-role-select @endif
                    >
                        @foreach ($roles as $role)
                            <option
                                value="{{ $role->id }}"
                                data-role-slug="{{ $role->slug }}"
                                @selected((string) old('role_id', $managedUser->role_id) === (string) $role->id)
                            >
                                {{ $role->name }}
                            </option>
                        @endforeach
                    </select>
                    @if (! $canManageAssignments)
                        <small class="helper-text">Solo superadmin puede cambiar el rol.</small>
                    @endif
                    @error('role_id')
                        <small class="helper-text helper-text--error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Cliente</span>
                    <select
                        name="client_id"
                        class="auth-input"
                        @disabled(! $canManageAssignments)
                        @if ($canManageAssignments) data-user-client-select @endif
                    >
                        <option value="">
                            {{ $managedUser->role?->slug === \App\Models\Role::CLIENTE ? 'Seleccionar cliente' : 'Todos los clientes / Sin asignar' }}
                        </option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) old('client_id', $managedUser->client_id) === (string) $client->id)>
                                {{ $client->name }}
                            </option>
                        @endforeach
                    </select>
                    <small class="helper-text user-scope-hint" @if ($canManageAssignments) data-user-client-help @endif>
                        {{ $managedUser->role?->slug === \App\Models\Role::CLIENTE
                            ? 'El rol Cliente debe quedar vinculado a un cliente concreto.'
                            : 'Los roles internos trabajan con todos los clientes y se guardan sin cliente asignado.' }}
                    </small>
                    @if (! $canManageAssignments)
                        <small class="helper-text">Solo superadmin puede asignar cliente.</small>
                    @endif
                    @error('client_id')
                        <small class="helper-text helper-text--error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Nueva contrasena</span>
                    <input type="password" name="password" class="auth-input" autocomplete="new-password">
                    @error('password')
                        <small class="helper-text helper-text--error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Confirmar contrasena</span>
                    <input type="password" name="password_confirmation" class="auth-input" autocomplete="new-password">
                </label>

                <div class="auth-field form-toggle-field">
                    <span>Estado</span>
                    <label class="toggle-field">
                        <input
                            type="checkbox"
                            name="active"
                            value="1"
                            @checked(old('active', $managedUser->active))
                            @disabled(! $canManageAssignments)
                        >
                        <span>Usuario activo</span>
                    </label>
                    @if (! $canManageAssignments)
                        <small class="helper-text">Solo superadmin puede activar o desactivar usuarios.</small>
                    @endif
                </div>
            </div>

            <div class="item-form-actions action-buttons">
                <button type="submit" class="button-primary compact-button btn-compact">Guardar usuario</button>
                <a href="{{ route('users.index') }}" class="button-secondary compact-button btn-compact">Volver</a>
            </div>
        </form>
    </section>

    @if ($canManageAssignments)
        <script>
            (() => {
                const roleSelect = document.querySelector('[data-user-role-select]');
                const clientSelect = document.querySelector('[data-user-client-select]');
                const clientHelp = document.querySelector('[data-user-client-help]');

                if (!roleSelect || !clientSelect || !clientHelp) {
                    return;
                }

                const emptyOption = clientSelect.querySelector('option[value=""]');

                const syncClientScope = () => {
                    const selectedRole = roleSelect.options[roleSelect.selectedIndex]?.dataset.roleSlug;
                    const isClientRole = selectedRole === '{{ \App\Models\Role::CLIENTE }}';

                    clientSelect.required = isClientRole;
                    clientSelect.disabled = !isClientRole;

                    if (!isClientRole) {
                        clientSelect.value = '';
                    }

                    if (emptyOption) {
                        emptyOption.textContent = isClientRole
                            ? 'Seleccionar cliente'
                            : 'Todos los clientes / Sin asignar';
                    }

                    clientHelp.textContent = isClientRole
                        ? 'El rol Cliente debe quedar vinculado a un cliente concreto.'
                        : 'Los roles internos trabajan con todos los clientes y se guardan sin cliente asignado.';
                };

                syncClientScope();
                roleSelect.addEventListener('change', syncClientScope);
            })();
        </script>
    @endif
@endsection
