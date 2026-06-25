@extends('layouts.dashboard')

@section('title', 'Editar usuario | MAXIMO WMS')
@section('topbar_title', 'Editar usuario')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
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
                    <select name="role_id" class="auth-input" @disabled(! $canManageAssignments)>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected((string) old('role_id', $managedUser->role_id) === (string) $role->id)>
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
                    <select name="client_id" class="auth-input" @disabled(! $canManageAssignments)>
                        <option value="">Sin cliente</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) old('client_id', $managedUser->client_id) === (string) $client->id)>
                                {{ $client->name }}
                            </option>
                        @endforeach
                    </select>
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
@endsection
