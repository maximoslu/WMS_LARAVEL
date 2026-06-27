@php($row = is_array($line ?? null) ? $line : [])

<tr data-line-row>
    <td>
        <div
            class="ajax-autocomplete"
            data-ajax-autocomplete
            data-endpoint="{{ $searchEndpoint }}"
            data-min-chars="2"
            data-empty-message="Escribe al menos 2 caracteres para buscar artículos."
            data-no-results-message="Sin resultados"
            data-searching-message="Buscando..."
            data-error-message="Error al buscar"
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
                    placeholder="Buscar artículo"
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
    </td>
    <td>
        <input
            type="text"
            name="lines[{{ $index }}][sku]"
            value="{{ $row['sku'] ?? '' }}"
            class="auth-input goods-receipt-derived-field"
            maxlength="100"
            placeholder="Autocompletado"
            data-line-sku
            data-autofill-target="sku"
        >
        <small class="helper-text goods-receipt-inline-help">Se completa desde el articulo si lo seleccionas.</small>
        @error("lines.$index.sku")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input
            type="text"
            name="lines[{{ $index }}][description]"
            value="{{ $row['description'] ?? '' }}"
            class="auth-input goods-receipt-derived-field"
            maxlength="255"
            placeholder="Autocompletado"
            data-line-description
            data-autofill-target="description"
        >
        @error("lines.$index.description")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input
            type="text"
            name="lines[{{ $index }}][lot]"
            value="{{ $row['lot'] ?? '' }}"
            class="auth-input"
            maxlength="100"
            data-line-lot
            data-autofill-target="lot"
        >
        @error("lines.$index.lot")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
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
    </td>
    <td>
        <input
            type="number"
            min="1"
            name="lines[{{ $index }}][units_per_pallet]"
            value="{{ $row['units_per_pallet'] ?? '' }}"
            class="auth-input goods-receipt-derived-field"
            data-line-units
            data-autofill-target="units_per_pallet"
        >
        <small class="helper-text goods-receipt-inline-help">Editable si esta entrada viene con otro paletizado.</small>
        @error("lines.$index.units_per_pallet")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
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
    </td>
    <td>
        <input
            type="number"
            min="0"
            name="lines[{{ $index }}][pico_units]"
            value="{{ $row['pico_units'] ?? '' }}"
            class="auth-input"
            data-line-pico
        >
        @error("lines.$index.pico_units")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
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
    </td>
    <td>
        <textarea name="lines[{{ $index }}][notes]" rows="2" class="auth-input">{{ $row['notes'] ?? '' }}</textarea>
        @error("lines.$index.notes")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td class="goods-receipt-line-actions">
        <button type="button" class="button-secondary compact-button btn-table" data-remove-line>Quitar</button>
    </td>
</tr>
