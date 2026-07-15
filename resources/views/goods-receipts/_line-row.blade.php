@php
    $row = is_array($line ?? null) ? $line : [];
    $peakValues = collect(range(1, 10))
        ->mapWithKeys(fn (int $number): array => [$number => $row['peak_'.$number] ?? null])
        ->filter(fn (mixed $value): bool => $value !== null && $value !== '');

    if ($peakValues->isEmpty() && filled($row['pico_units'] ?? null)) {
        $peakValues->put(1, $row['pico_units']);
    }

    if ($peakValues->isEmpty()) {
        $peakValues->put(1, null);
    }
@endphp

<article class="surface-card compact-card goods-receipt-line-card" data-line-row data-line-index="{{ $index }}">
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
                data-no-results-message="Sin resultados. Crear articulo nuevo."
                data-searching-message="Buscando..."
                data-error-message="Error al buscar"
                data-autocomplete-floating="fixed"
                data-receipt-item-picker
                data-create-item-endpoint="{{ route('goods-receipts.items.quick-create') }}"
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
            <div class="goods-receipt-line-create-item" data-line-create-item hidden>
                <button type="button" class="button-secondary compact-button btn-compact" data-line-create-item-trigger>Crear articulo nuevo</button>
                <small class="helper-text" data-line-create-item-feedback></small>
            </div>
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
            <span>Pallets completos</span>
            <input
                type="number"
                min="0"
                name="lines[{{ $index }}][pallet_count]"
                value="{{ $row['pallet_count'] ?? '' }}"
                class="auth-input"
                data-line-pallet-count
            >
            @error("lines.$index.pallet_count")
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>

        <div class="auth-field goods-receipt-line-field goods-receipt-line-field--wide goods-receipt-peaks" data-line-peaks>
            <div class="goods-receipt-peaks-head">
                <span>Picos</span>
                <button type="button" class="button-secondary compact-button btn-table" data-add-peak>Añadir pico</button>
            </div>
            <input type="hidden" name="lines[{{ $index }}][pico_units]" value="{{ $row['pico_units'] ?? '' }}" data-line-pico-total>
            <div class="goods-receipt-peak-list" data-line-peak-list>
                @foreach ($peakValues as $peakNumber => $peakValue)
                    <div class="goods-receipt-peak-entry" data-line-peak-entry data-peak-number="{{ $peakNumber }}">
                        <label for="line-{{ $index }}-peak-{{ $peakNumber }}">Pico {{ $peakNumber }}</label>
                        <input
                            id="line-{{ $index }}-peak-{{ $peakNumber }}"
                            type="number"
                            min="1"
                            step="1"
                            name="lines[{{ $index }}][peak_{{ $peakNumber }}]"
                            value="{{ $peakValue }}"
                            class="auth-input"
                            inputmode="numeric"
                            data-line-peak
                        >
                        <button type="button" class="button-secondary compact-button btn-table" data-remove-peak aria-label="Quitar pico {{ $peakNumber }}">Quitar</button>
                        @error("lines.$index.peak_$peakNumber")
                            <small class="form-error">{{ $message }}</small>
                        @enderror
                    </div>
                @endforeach
            </div>
            <small class="helper-text">Hasta 10 picos separados. El total de la línea se actualiza automáticamente.</small>
        </div>

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
