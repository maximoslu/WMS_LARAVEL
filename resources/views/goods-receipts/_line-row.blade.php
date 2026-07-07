@php($row = is_array($line ?? null) ? $line : [])

<article class="surface-card compact-card goods-receipt-line-card" data-line-row>
    <div class="goods-receipt-line-card-head">
        <div class="goods-receipt-line-card-copy">
            <span class="status-chip small-badge badge-compact" data-line-number>Linea</span>
            <span class="goods-receipt-line-card-meta">SKU, lote, cantidades y ubicacion.</span>
        </div>

        <button type="button" class="button-secondary compact-button btn-table" data-remove-line>Quitar linea</button>
    </div>

    <div class="goods-receipt-line-grid">
        <label class="auth-field goods-receipt-line-field goods-receipt-line-field--wide">
            <span>Articulo</span>
            <div
                class="ajax-autocomplete goods-receipt-line-picker"
                data-ajax-autocomplete
                data-endpoint="{{ $searchEndpoint }}"
                data-min-chars="2"
                data-empty-message="Escribe al menos 2 caracteres para buscar articulos."
                data-no-results-message="Sin resultados"
                data-searching-message="Buscando..."
                data-error-message="Error al buscar"
                data-autocomplete-floating="fixed"
                data-receipt-item-picker
            >
                <div class="ajax-autocomplete-control">
                    <input type="hidden" name="lines[{{ $index }}][item_id]" value="{{ $row['item_id'] ?? '' }}" data-line-item-id>
                    <input
                        type="text"
                        name="lines[{{ $index }}][item_search]"
                        value="{{ old('lines.'.$index.'.item_search', $row['item_search'] ?? '') }}"
                        class="auth-input"
                        autocomplete="off"
                        placeholder="Buscar articulo o escribir SKU"
                        data-autocomplete-input
                    >
                    <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear {{ blank($row['item_id'] ?? null) ? 'hidden' : '' }}>Limpiar</button>
                </div>
                <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                    <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe al menos 2 caracteres...</div>
                    <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                </div>
            </div>
            @error("lines.$index.item_id")
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>

        <label class="auth-field goods-receipt-line-field">
            <span>SKU</span>
            <input
                type="text"
                name="lines[{{ $index }}][sku]"
                value="{{ $row['sku'] ?? '' }}"
                class="auth-input goods-receipt-derived-field"
                maxlength="100"
                placeholder="SKU"
                data-line-sku
                data-autofill-target="sku"
            >
            @error("lines.$index.sku")
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>

        <label class="auth-field goods-receipt-line-field goods-receipt-line-field--wide">
            <span>Descripcion</span>
            <input
                type="text"
                name="lines[{{ $index }}][description]"
                value="{{ $row['description'] ?? '' }}"
                class="auth-input goods-receipt-derived-field"
                maxlength="255"
                placeholder="Descripcion del articulo"
                data-line-description
                data-autofill-target="description"
            >
            @error("lines.$index.description")
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>

        <label class="auth-field goods-receipt-line-field">
            <span>Lote</span>
            <input
                type="text"
                name="lines[{{ $index }}][lot]"
                value="{{ $row['lot'] ?? '' }}"
                class="auth-input"
                maxlength="100"
                data-line-lot
            >
            <small class="helper-text goods-receipt-inline-help">Trazabilidad de esta entrada. No se guarda como maestro del articulo.</small>
            @error("lines.$index.lot")
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>

        <label class="auth-field goods-receipt-line-field">
            <span>Total uds</span>
            <input
                type="number"
                min="0"
                name="lines[{{ $index }}][quantity_units]"
                value="{{ $row['quantity_units'] ?? '' }}"
                class="auth-input goods-receipt-total-input"
                data-line-quantity
            >
            @error("lines.$index.quantity_units")
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>

        <label class="auth-field goods-receipt-line-field">
            <span>Uds/palet</span>
            <input
                type="number"
                min="1"
                name="lines[{{ $index }}][units_per_pallet]"
                value="{{ $row['units_per_pallet'] ?? '' }}"
                class="auth-input goods-receipt-derived-field"
                data-line-units
                data-autofill-target="units_per_pallet"
            >
            <small class="helper-text goods-receipt-inline-help">Puedes ajustar este paletizado solo para la entrada actual.</small>
            @error("lines.$index.units_per_pallet")
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>

        <label class="auth-field goods-receipt-line-field">
            <span>Pallets calculados</span>
            <input
                type="number"
                min="0"
                name="lines[{{ $index }}][pallet_count]"
                value="{{ $row['pallet_count'] ?? '' }}"
                class="auth-input goods-receipt-derived-output"
                data-line-pallet-count
                readonly
            >
            @error("lines.$index.pallet_count")
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>

        <label class="auth-field goods-receipt-line-field">
            <span>Pico calculado</span>
            <input
                type="number"
                min="0"
                name="lines[{{ $index }}][pico_units]"
                value="{{ $row['pico_units'] ?? '' }}"
                class="auth-input goods-receipt-derived-output"
                data-line-pico
                readonly
            >
            @error("lines.$index.pico_units")
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>

        <label class="auth-field goods-receipt-line-field goods-receipt-line-field--wide">
            <span>Ubicacion</span>
            <select name="lines[{{ $index }}][location_id]" class="auth-input" data-line-location>
                <option value="">Sin ubicacion</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected((string) ($row['location_id'] ?? null) === (string) $location->id)>
                        {{ $location->code }}{{ $location->warehouse ? ' / '.$location->warehouse->code : '' }}
                    </option>
                @endforeach
            </select>
            @error("lines.$index.location_id")
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>
    </div>

    <p class="goods-receipt-line-warning" data-line-new-item-warning hidden></p>
</article>
