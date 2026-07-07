@php
    $proposal = is_array($receipt->ai_extracted_data) ? $receipt->ai_extracted_data : [];
    $proposalLines = old('lines', data_get($proposal, 'reviewed_payload.lines', data_get($proposal, 'lines', [])));
    $selectedSupplierId = old('supplier_id', data_get($proposal, 'reviewed_payload.supplier_id', data_get($proposal, 'matched_supplier_id', $receipt->supplier_id)));
    $proposalReceiptNumber = old('receipt_number', data_get($proposal, 'reviewed_payload.receipt_number', data_get($proposal, 'delivery_note_number', $receipt->receipt_number)));
    $proposalReceivedAt = old('received_at', data_get($proposal, 'reviewed_payload.received_at', data_get($proposal, 'received_date', optional($receipt->received_at)->format('Y-m-d'))));
    $proposalWarnings = collect(data_get($proposal, 'warnings', []))
        ->filter(fn ($warning) => is_string($warning) && trim($warning) !== '')
        ->values();
@endphp

<section class="surface-card compact-card goods-receipt-card goods-receipt-ai-card">
    <div class="ops-index-heading">
        <div>
            <strong>Propuesta IA del albaran</strong>
            <span class="ops-page-meta">La IA propone datos a partir del documento. Aplicar lineas no suma stock: el stock solo se aplica al confirmar la entrada.</span>
        </div>
        <span class="goods-receipt-ai-status goods-receipt-ai-status--{{ $receipt->ai_status ?: 'pending' }}">{{ $receipt->aiStatusLabel() }}</span>
    </div>

    @if ($proposalWarnings->isNotEmpty())
        <div class="goods-receipt-ai-warning-list">
            @foreach ($proposalWarnings as $warning)
                <p>{{ $warning }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('goods-receipts.ai-apply', $receipt) }}" class="goods-receipt-ai-form">
        @csrf

        <div class="goods-receipt-ai-header-grid">
            <label class="auth-field">
                <span>Proveedor</span>
                <select name="supplier_id" class="auth-input">
                    <option value="">Sin proveedor</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((string) $selectedSupplierId === (string) $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
                @if (filled($proposal['supplier_name'] ?? null))
                    <small class="helper-text">Detectado por IA: {{ $proposal['supplier_name'] }}</small>
                @endif
                @if (filled($proposal['supplier_name'] ?? null) && blank($selectedSupplierId))
                    <small class="helper-text">No hay coincidencia automatica. <a href="{{ route('suppliers.create') }}">Crear proveedor</a>.</small>
                @endif
            </label>

            <label class="auth-field">
                <span>Numero de albaran</span>
                <input type="text" name="receipt_number" value="{{ $proposalReceiptNumber }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Fecha recepcion</span>
                <input type="date" name="received_at" value="{{ $proposalReceivedAt }}" class="auth-input">
            </label>

            <div class="goods-receipt-ai-confidence">
                <strong>Confianza</strong>
                <span>
                    @if (($proposal['confidence'] ?? null) !== null)
                        {{ number_format(((float) $proposal['confidence']) * 100, 0, ',', '.') }}%
                    @else
                        No disponible
                    @endif
                </span>
            </div>
        </div>

        <div class="goods-receipt-ai-line-list">
            @foreach ($proposalLines as $index => $line)
                @php
                    $lineWarnings = collect($line['warnings'] ?? [])->filter(fn ($warning) => is_string($warning) && trim($warning) !== '');
                    $quantityUnits = $line['quantity_units'] ?? $line['total_units'] ?? null;
                    $palletCount = $line['pallet_count'] ?? $line['full_pallets'] ?? 0;
                    $peakUnits = $line['pico_units'] ?? $line['peak_units'] ?? null;
                @endphp
                <article class="surface-card goods-receipt-ai-line-card">
                    <div class="goods-receipt-ai-line-head">
                        <div>
                            <strong>Linea {{ $index + 1 }}</strong>
                            <span class="ops-page-meta">
                                @if (($line['confidence'] ?? null) !== null)
                                    Confianza {{ number_format(((float) $line['confidence']) * 100, 0, ',', '.') }}%
                                @else
                                    Confianza no disponible
                                @endif
                            </span>
                        </div>
                    </div>

                    @if ($lineWarnings->isNotEmpty())
                        <div class="goods-receipt-ai-line-warnings">
                            @foreach ($lineWarnings as $warning)
                                <p>{{ $warning }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div class="goods-receipt-ai-line-grid">
                        <input type="hidden" name="lines[{{ $index }}][item_id]" value="{{ $line['item_id'] ?? '' }}">

                        <label class="auth-field">
                            <span>SKU</span>
                            <input type="text" name="lines[{{ $index }}][sku]" value="{{ $line['sku'] ?? '' }}" class="auth-input">
                        </label>

                        <label class="auth-field goods-receipt-ai-line-field--wide">
                            <span>Descripcion</span>
                            <input type="text" name="lines[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" class="auth-input">
                        </label>

                        <label class="auth-field">
                            <span>Lote</span>
                            <input type="text" name="lines[{{ $index }}][lot]" value="{{ $line['lot'] ?? '' }}" class="auth-input">
                        </label>

                        <label class="auth-field">
                            <span>Total uds</span>
                            <input type="number" min="0" step="1" name="lines[{{ $index }}][quantity_units]" value="{{ $quantityUnits }}" class="auth-input">
                        </label>

                        <label class="auth-field">
                            <span>Uds/pallet</span>
                            <input type="number" min="1" step="1" name="lines[{{ $index }}][units_per_pallet]" value="{{ $line['units_per_pallet'] ?? '' }}" class="auth-input">
                        </label>

                        <label class="auth-field">
                            <span>Pallets</span>
                            <input type="number" min="0" step="1" name="lines[{{ $index }}][pallet_count]" value="{{ $palletCount }}" class="auth-input">
                        </label>

                        <label class="auth-field">
                            <span>Pico</span>
                            <input type="number" min="0" step="1" name="lines[{{ $index }}][pico_units]" value="{{ $peakUnits }}" class="auth-input">
                        </label>

                        <label class="auth-field goods-receipt-ai-line-field--wide">
                            <span>Ubicacion</span>
                            <select name="lines[{{ $index }}][location_id]" class="auth-input">
                                <option value="">Sin ubicacion</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" @selected((string) ($line['location_id'] ?? '') === (string) $location->id)>
                                        {{ $location->code }}{{ $location->warehouse ? ' / '.$location->warehouse->code : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="goods-receipt-document-actions action-buttons">
            <button type="submit" class="button-primary compact-button btn-compact">Aplicar lineas</button>
        </div>
    </form>
</section>
