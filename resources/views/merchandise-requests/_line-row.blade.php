@php($row = is_array($line ?? null) ? $line : [])

<tr data-request-line-row>
    <td>
        <select name="lines[{{ $index }}][item_id]" class="auth-input" data-request-item>
            <option value="">Selecciona articulo</option>
            @foreach ($items as $item)
                <option
                    value="{{ $item->id }}"
                    data-item-client-id="{{ $item->client_id }}"
                    @selected((string) ($row['item_id'] ?? null) === (string) $item->id)
                >
                    {{ $item->sku }} / {{ $item->description }}
                </option>
            @endforeach
        </select>
        @error("lines.$index.item_id")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="text" name="lines[{{ $index }}][lot]" value="{{ $row['lot'] ?? '' }}" class="auth-input" maxlength="100" data-request-lot>
        @error("lines.$index.lot")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="number" min="1" name="lines[{{ $index }}][requested_pallets]" value="{{ $row['requested_pallets'] ?? '' }}" class="auth-input" data-request-pallets>
        @error("lines.$index.requested_pallets")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="number" min="1" name="lines[{{ $index }}][units_per_pallet]" value="{{ $row['units_per_pallet'] ?? '' }}" class="auth-input merchandise-request-derived-field" data-request-units-per-pallet readonly>
        @error("lines.$index.units_per_pallet")
            <small class="form-error">{{ $message }}</small>
        @enderror
    </td>
    <td>
        <input type="number" min="1" name="lines[{{ $index }}][requested_units]" value="{{ $row['requested_units'] ?? '' }}" class="auth-input merchandise-request-total-field" data-request-total-units readonly>
        @error("lines.$index.requested_units")
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
        <button type="button" class="button-secondary compact-button btn-table" data-remove-request-line>Quitar</button>
    </td>
</tr>
