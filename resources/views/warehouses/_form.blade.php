@php
    $isEditing = $warehouse->exists;
    $breadcrumbs = [


    ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
    ['label' => 'Gestion'],
    ['label' => 'Almacenes', 'href' => route('warehouses.index')],
    ['label' => $isEditing ? 'Editar' : 'Crear'],
    ];
@endphp
<x-breadcrumbs :items="$breadcrumbs" />

<div class="surface-card item-form-card entity-form compact-card">
    <div class="app-copy">
        <span class="status-chip small-badge badge-compact">{{ $isEditing ? 'Edicion' : 'Alta' }}</span>
        <h2 class="ops-page-title page-title-compact">{{ $isEditing ? 'Editar almacen' : 'Nuevo almacen' }}</h2>
        <p>Define el ambito operativo y su identificacion visible.</p>
    </div>

    <form method="POST" action="{{ $isEditing ? route('warehouses.update', $warehouse) : route('warehouses.store') }}" class="item-form">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <div class="form-grid">
            <label class="auth-field">
                <span>Ambito cliente</span>
                <select name="client_id" class="auth-input">
                    <option value="">Global MAXIMO</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) old('client_id', $warehouse->client_id) === (string) $client->id)>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </select>
                @error('client_id')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Codigo</span>
                <input type="text" name="code" value="{{ old('code', $warehouse->code) }}" class="auth-input" maxlength="50" required>
                @error('code')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field item-form-field--full">
                <span>Nombre</span>
                <input type="text" name="name" value="{{ old('name', $warehouse->name) }}" class="auth-input" maxlength="255" required>
                @error('name')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>
        </div>

        <label class="toggle-field">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" @checked(old('active', $warehouse->active ?? true))>
            <span>Almacen activo para operativa y asignacion de ubicaciones</span>
        </label>

        <div class="item-form-actions action-buttons">
            <a href="{{ route('warehouses.index') }}" class="button-secondary compact-button btn-compact">Cancelar</a>
            <button type="submit" class="button-primary compact-button btn-compact">{{ $isEditing ? 'Guardar cambios' : 'Crear almacen' }}</button>
        </div>
    </form>
</div>





