@php($isEditing = $location->exists)

<nav class="ops-breadcrumb" aria-label="Breadcrumb">
    <a href="{{ route('dashboard') }}">Panel operativo</a>
    <span>/</span>
    <span>Stock</span>
    <span>/</span>
    <a href="{{ route('locations.index') }}">Ubicaciones</a>
    <span>/</span>
    <span>{{ $isEditing ? 'Editar' : 'Crear' }}</span>
</nav>

<div class="surface-card item-form-card entity-form compact-card">
    <div class="app-copy">
        <span class="status-chip small-badge badge-compact">{{ $isEditing ? 'Edicion' : 'Alta' }}</span>
        <h2 class="ops-page-title page-title-compact">{{ $isEditing ? 'Editar ubicacion' : 'Nueva ubicacion' }}</h2>
        <p>Codigo visible con estructura opcional por zona, pasillo, rack, nivel y posicion.</p>
    </div>

    <form method="POST" action="{{ $isEditing ? route('locations.update', $location) : route('locations.store') }}" class="item-form">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <div class="form-grid">
            <label class="auth-field">
                <span>Almacen</span>
                <select name="warehouse_id" class="auth-input" required>
                    <option value="">Selecciona un almacen</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $location->warehouse_id) === (string) $warehouse->id)>
                            {{ $warehouse->code }} / {{ $warehouse->name }}
                        </option>
                    @endforeach
                </select>
                @error('warehouse_id')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Codigo</span>
                <input type="text" name="code" value="{{ old('code', $location->code) }}" class="auth-input" maxlength="80" required>
                @error('code')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field item-form-field--full">
                <span>Nombre</span>
                <input type="text" name="name" value="{{ old('name', $location->name) }}" class="auth-input" maxlength="255" placeholder="Opcional">
                @error('name')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Zona</span>
                <input type="text" name="zone" value="{{ old('zone', $location->zone) }}" class="auth-input" maxlength="50" placeholder="Opcional">
                @error('zone')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Pasillo</span>
                <input type="text" name="aisle" value="{{ old('aisle', $location->aisle) }}" class="auth-input" maxlength="50" placeholder="Opcional">
                @error('aisle')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Rack</span>
                <input type="text" name="rack" value="{{ old('rack', $location->rack) }}" class="auth-input" maxlength="50" placeholder="Opcional">
                @error('rack')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Nivel</span>
                <input type="text" name="level" value="{{ old('level', $location->level) }}" class="auth-input" maxlength="50" placeholder="Opcional">
                @error('level')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Posicion</span>
                <input type="text" name="position" value="{{ old('position', $location->position) }}" class="auth-input" maxlength="50" placeholder="Opcional">
                @error('position')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>
        </div>

        <label class="toggle-field">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" @checked(old('active', $location->active ?? true))>
            <span>Ubicacion activa para asignacion operativa</span>
        </label>

        <div class="item-form-actions action-buttons">
            <a href="{{ route('locations.index') }}" class="button-secondary compact-button btn-compact">Cancelar</a>
            <button type="submit" class="button-primary compact-button btn-compact">{{ $isEditing ? 'Guardar cambios' : 'Crear ubicacion' }}</button>
        </div>
    </form>
</div>
