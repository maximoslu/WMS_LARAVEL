<label class="auth-field">
    <span>Proveedor</span>
    <div
        class="ajax-autocomplete goods-receipt-line-picker"
        data-ajax-autocomplete
        data-endpoint="{{ route('ajax.suppliers') }}"
        data-min-chars="2"
        data-empty-message="Escribe al menos 2 caracteres para buscar proveedores."
        data-no-results-message="Sin resultados. Crear proveedor nuevo."
        data-searching-message="Buscando..."
        data-error-message="Error al buscar"
        data-autocomplete-floating="fixed"
        data-receipt-supplier-picker
        data-create-supplier-endpoint="{{ route('goods-receipts.suppliers.quick-create') }}"
    >
        <div class="ajax-autocomplete-control">
            <input type="hidden" name="supplier_id" value="{{ old('supplier_id', $receipt->supplier_id) }}" data-supplier-id>
            <input
                type="text"
                value="{{ old('supplier_name', $receipt->supplier?->name) }}"
                class="auth-input"
                autocomplete="off"
                placeholder="Buscar proveedor o escribir nombre nuevo"
                data-autocomplete-input
            >
            <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear {{ blank(old('supplier_id', $receipt->supplier_id)) ? 'hidden' : '' }}>Limpiar</button>
        </div>
        <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
            <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe al menos 2 caracteres...</div>
            <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
        </div>
    </div>
    <div class="goods-receipt-line-create-item" data-supplier-create-item hidden>
        <button type="button" class="button-secondary compact-button btn-compact" data-supplier-create-trigger>Crear proveedor nuevo</button>
        <small class="helper-text" data-supplier-create-feedback></small>
    </div>
    @error('supplier_id')
        <small class="form-error">{{ $message }}</small>
    @enderror
</label>
