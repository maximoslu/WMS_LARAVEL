@php($isEditing = $item->exists)

<div class="surface-card item-form-card">
    <div class="item-form-header">
        <div class="app-copy">
            <span class="status-chip">{{ $isEditing ? 'Edicion' : 'Alta' }}</span>
            <h2 class="app-page-title">{{ $isEditing ? 'Editar articulo' : 'Nuevo articulo' }}</h2>
            <p>El articulo define el paletizado estandar. El stock real y los picos se gestionaran en movimientos/palets.</p>
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
            <p>La cantidad por palet guarda el estandar de paletizado del articulo. Los picos se resolveran mas adelante a nivel de palet o movimiento, sin alterar este maestro.</p>
        </div>

        <div class="item-form-actions">
            <a href="{{ route('items.index') }}" class="button-secondary">Cancelar</a>
            <button type="submit" class="button-primary">{{ $isEditing ? 'Guardar cambios' : 'Crear articulo' }}</button>
        </div>
    </form>
</div>
