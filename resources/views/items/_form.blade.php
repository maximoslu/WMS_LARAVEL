@php($isEditing = $item->exists)

<nav class="ops-breadcrumb" aria-label="Breadcrumb">
    <a href="{{ route('dashboard') }}">Panel de control</a>
    <span>/</span>
    <span>Stock</span>
    <span>/</span>
    <a href="{{ route('items.index') }}">Articulos</a>
    <span>/</span>
    <span>{{ $isEditing ? 'Editar' : 'Crear' }}</span>
</nav>

<div class="surface-card item-form-card entity-form compact-card">
    <div class="item-form-header">
        <div class="app-copy">
            <span class="status-chip small-badge badge-compact">{{ $isEditing ? 'Edicion' : 'Alta' }}</span>
            <h2 class="ops-page-title page-title-compact">{{ $isEditing ? 'Editar articulo' : 'Nuevo articulo' }}</h2>
            <p>Define cliente, SKU, lote y paletizado estandar.</p>
        </div>
    </div>

    <form method="POST" action="{{ $isEditing ? route('items.update', $item) : route('items.store') }}" class="item-form">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <div class="item-form-grid">
            <label class="auth-field">
                <span>Cliente propietario</span>
                <select name="client_id" class="auth-input" required>
                    <option value="">Selecciona un cliente</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) old('client_id', $item->client_id) === (string) $client->id)>
                            {{ $client->name }}{{ $client->active ? '' : ' (inactivo)' }}
                        </option>
                    @endforeach
                </select>
                @error('client_id')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>SKU</span>
                <input
                    type="text"
                    name="sku"
                    value="{{ old('sku', $item->sku) }}"
                    class="auth-input"
                    maxlength="100"
                    required
                >
                @error('sku')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field item-form-field--full">
                <span>Descripcion</span>
                <input
                    type="text"
                    name="description"
                    value="{{ old('description', $item->description) }}"
                    class="auth-input"
                    maxlength="255"
                    required
                >
                @error('description')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Lote</span>
                <input
                    type="text"
                    name="lot"
                    value="{{ old('lot', $item->lot) }}"
                    class="auth-input"
                    maxlength="100"
                    placeholder="Opcional"
                >
                @error('lot')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Cantidad por palet</span>
                <input
                    type="number"
                    min="1"
                    step="1"
                    name="units_per_pallet"
                    value="{{ old('units_per_pallet', $item->units_per_pallet) }}"
                    class="auth-input"
                    required
                >
                @error('units_per_pallet')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>
        </div>

        <label class="toggle-field">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" @checked(old('active', $item->active ?? true))>
            <span>Articulo activo para operativa y alta de stock futura</span>
        </label>

        <div class="item-form-hint">
            <strong>Nota operativa</strong>
            <p>La cantidad por palet fija el estandar de referencia para stock y picos.</p>
        </div>

        <div class="item-form-actions action-buttons">
            <a href="{{ route('items.index') }}" class="button-secondary compact-button btn-compact">Cancelar</a>
            <button type="submit" class="button-primary compact-button btn-compact">{{ $isEditing ? 'Guardar cambios' : 'Crear articulo' }}</button>
        </div>
    </form>
</div>
