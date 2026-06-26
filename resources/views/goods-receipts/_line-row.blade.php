@php($row = is_array($line ?? null) ? $line : [])

<tr data-line-row>
    <td>
        <select name="lines[{{ $index }}][item_id]" class="auth-input">
            <option value="">Sin articulo</option>
            @foreach ($items as $item)
                <option value="{{ $item->id }}" @selected((string) ($row['item_id'] ?? null) === (string) $item->id)>
                    {{ $item->sku }} / {{ $item->description }}
                </option>
            @endforeach
        </select>
        @error("lines.$index.item_id")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="text" name="lines[{{ $index }}][sku]" value="{{ $row['sku'] ?? '' }}" class="auth-input" maxlength="100">
        @error("lines.$index.sku")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="text" name="lines[{{ $index }}][description]" value="{{ $row['description'] ?? '' }}" class="auth-input" maxlength="255">
        @error("lines.$index.description")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="text" name="lines[{{ $index }}][lot]" value="{{ $row['lot'] ?? '' }}" class="auth-input" maxlength="100">
        @error("lines.$index.lot")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="number" min="0" name="lines[{{ $index }}][quantity_units]" value="{{ $row['quantity_units'] ?? 0 }}" class="auth-input">
        @error("lines.$index.quantity_units")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="number" min="1" name="lines[{{ $index }}][units_per_pallet]" value="{{ $row['units_per_pallet'] ?? '' }}" class="auth-input">
        @error("lines.$index.units_per_pallet")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="number" min="0" name="lines[{{ $index }}][pallet_count]" value="{{ $row['pallet_count'] ?? 0 }}" class="auth-input">
        @error("lines.$index.pallet_count")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="number" min="0" name="lines[{{ $index }}][pico_units]" value="{{ $row['pico_units'] ?? '' }}" class="auth-input">
        @error("lines.$index.pico_units")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <select name="lines[{{ $index }}][location_id]" class="auth-input">
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
