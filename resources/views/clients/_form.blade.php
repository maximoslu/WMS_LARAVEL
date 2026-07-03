@php
    $isEditing = $client->exists;
    $breadcrumbs = [


    ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
    ['label' => 'Gestion'],
    ['label' => 'Clientes', 'href' => route('clients.index')],
    ['label' => $isEditing ? 'Editar' : 'Crear'],
    ];
@endphp
<x-breadcrumbs :items="$breadcrumbs" />

<div class="surface-card item-form-card entity-form compact-card">
    <div class="item-form-header">
        <div class="app-copy">
            <span class="status-chip small-badge badge-compact">{{ $isEditing ? 'Edicion' : 'Alta' }}</span>
            <h2 class="ops-page-title page-title-compact">{{ $isEditing ? 'Editar cliente' : 'Nuevo cliente' }}</h2>
            <p>Gestiona el maestro de cliente y su direccion de entrega para expediciones y albaranes.</p>
        </div>
    </div>

    <form method="POST" action="{{ $isEditing ? route('clients.update', $client) : route('clients.store') }}" class="item-form">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <div class="item-form-grid">
            <label class="auth-field">
                <span>Nombre</span>
                <input type="text" name="name" value="{{ old('name', $client->name) }}" class="auth-input" maxlength="255" required>
                @error('name')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Codigo</span>
                <input type="text" name="code" value="{{ old('code', $client->code) }}" class="auth-input" maxlength="60" required>
                @error('code')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field item-form-field--full">
                <span>Direccion de entrega</span>
                <textarea name="delivery_address" class="auth-input" rows="3" maxlength="1000">{{ old('delivery_address', $client->delivery_address) }}</textarea>
                @error('delivery_address')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Codigo postal</span>
                <input type="text" name="delivery_postal_code" value="{{ old('delivery_postal_code', $client->delivery_postal_code) }}" class="auth-input" maxlength="20">
                @error('delivery_postal_code')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Ciudad</span>
                <input type="text" name="delivery_city" value="{{ old('delivery_city', $client->delivery_city) }}" class="auth-input" maxlength="120">
                @error('delivery_city')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Provincia</span>
                <input type="text" name="delivery_province" value="{{ old('delivery_province', $client->delivery_province) }}" class="auth-input" maxlength="120">
                @error('delivery_province')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Pais</span>
                <input type="text" name="delivery_country" value="{{ old('delivery_country', $client->delivery_country) }}" class="auth-input" maxlength="120">
                @error('delivery_country')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>
        </div>

        <label class="toggle-field">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" @checked(old('active', $client->active ?? true))>
            <span>Cliente activo para operativa y asignacion de usuarios</span>
        </label>

        <div class="item-form-actions action-buttons">
            <a href="{{ route('clients.index') }}" class="button-secondary compact-button btn-compact">Cancelar</a>
            <button type="submit" class="button-primary compact-button btn-compact">{{ $isEditing ? 'Guardar cambios' : 'Crear cliente' }}</button>
        </div>
    </form>
</div>





