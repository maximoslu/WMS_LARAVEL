@php($isEditing = $warehouse->exists)

<nav class="ops-breadcrumb" aria-label="Breadcrumb">
    <a href="{{ route('dashboard') }}">Panel operativo</a>
    <span>/</span>
    <span>Gestion</span>
    <span>/</span>
    <a href="{{ route('warehouses.index') }}">Almacenes</a>
    <span>/</span>
    <span>{{ $isEditing ? 'Editar' : 'Crear' }}</span>
</nav>

<div class="surface-card item-form-card entity-form">
    <div class="app-copy">
        <span class="status-chip">{{ $isEditing ? 'Edicion' : 'Alta' }}</span>
        <h2 class="app-page-title">{{ $isEditing ? 'Editar almacen' : 'Nuevo almacen' }}</h2>
        <p>Define el ambito operativo del almacen y deja preparada la estructura para ubicaciones y stock.</p>
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

        <div class="item-form-actions">
            <a href="{{ route('warehouses.index') }}" class="button-secondary">Cancelar</a>
            <button type="submit" class="button-primary">{{ $isEditing ? 'Guardar cambios' : 'Crear almacen' }}</button>
        </div>
    </form>
</div>
