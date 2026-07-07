@extends('layouts.dashboard')

@section('title', 'Detalle de entrada | MAXIMO WMS')
@section('topbar_title', 'Detalle de entrada')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Operaciones'],
            ['label' => 'Entradas', 'href' => route('goods-receipts.index')],
            ['label' => $receipt->receipt_number ?: 'Entrada #'.$receipt->id],
        ];
        $hasAiProposal = is_array($receipt->ai_extracted_data) && $receipt->ai_extracted_data !== [];
        $canSuperEdit = auth()->user()?->canAccessRole(\App\Models\Role::SUPERADMIN) ?? false;
        $isEditingConfirmed = $receipt->isConfirmed() && $canSuperEdit;
        $isEditable = ($isEditingConfirmed || ! $receipt->isConfirmed()) && $receipt->status !== \App\Models\GoodsReceipt::STATUS_CANCELLED;
        $hasDocument = filled($receipt->document_path);
        $canTriggerAi = $hasDocument && $aiEnabled && $isEditable;
        $canCreateSuppliers = auth()->user()?->canAccessRole(\App\Models\Role::ALMACEN) ?? false;
        $aiStatus = $receipt->ai_status ?: \App\Models\GoodsReceipt::AI_STATUS_PENDING;
        $aiStatusTone = match (true) {
            ! $hasDocument => 'idle',
            ! $aiEnabled && $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_PENDING => 'disabled',
            $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_PROCESSING => 'processing',
            $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_COMPLETED => 'completed',
            $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_REVIEWED => 'reviewed',
            $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_FAILED => 'failed',
            default => 'pending',
        };
        $aiStatusLabel = match (true) {
            ! $hasDocument => 'Sin documento',
            ! $aiEnabled && $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_PENDING => 'IA desactivada',
            $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_PROCESSING => 'Interpretando',
            $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_COMPLETED => 'Propuesta lista',
            $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_REVIEWED => 'Lineas aplicadas',
            $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_FAILED => 'IA fallida',
            default => 'IA sin ejecutar',
        };
        $aiStatusMessage = match (true) {
            ! $aiEnabled => 'IA desactivada. Puedes introducir lineas manualmente.',
            $receipt->ai_status === \App\Models\GoodsReceipt::AI_STATUS_FAILED => 'No se pudo interpretar el documento. Revisa el error o introduce lineas manualmente.',
            $hasDocument && $receipt->document_processed_at !== null => 'La propuesta IA ya esta lista para revisar y aplicar.',
            $hasDocument => 'Documento guardado. Puedes interpretar o introducir lineas manualmente.',
            default => 'Adjunta un documento si quieres probar la interpretacion IA.',
        };
        $stockStatusLabel = $receipt->hasStockApplied() ? 'Stock aplicado' : 'Pendiente de confirmar';
        $stockStatusTone = $receipt->hasStockApplied() ? 'confirmed' : 'pending';
        $lineCount = count($lineValues);
        $hasPersistedLines = $receipt->lines->isNotEmpty();
        $visibleLineCount = $hasPersistedLines ? $receipt->lines->count() : 0;
        $matchedSupplierId = data_get($receipt->ai_extracted_data, 'matched_supplier_id');
        $detectedSupplierName = data_get($receipt->ai_extracted_data, 'supplier_name');
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card goods-receipt-header-card goods-receipt-toolbar">
        <div class="goods-receipt-toolbar-main">
            <div class="goods-receipt-toolbar-title">
                <h2 class="ops-page-title page-title-compact">{{ $receipt->receipt_number ?: 'Entrada #'.$receipt->id }}</h2>
                <span class="receipt-status-pill receipt-status-pill--{{ $receipt->status }}">{{ $receipt->statusLabel() }}</span>
            </div>

            <div class="goods-receipt-toolbar-meta">
                <span><strong>Cliente:</strong> {{ $receipt->client->name }}</span>
                <span><strong>Proveedor:</strong> {{ $receipt->supplier?->name ?: 'Sin proveedor' }}</span>
                <span><strong>Fecha:</strong> {{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }}</span>
                <span><strong>IA:</strong> {{ $aiStatusLabel }}</span>
                <span><strong>Stock:</strong> {{ $stockStatusLabel }}</span>
            </div>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons goods-receipt-header-actions">
            @if ($isEditable)
                <a href="{{ route('goods-receipts.edit', $receipt) }}" class="button-secondary compact-button btn-compact">Editar</a>
                <button type="button" class="button-primary compact-button btn-compact goods-receipt-manual-action" data-add-line>Añadir línea manual</button>
                <button type="submit" form="goods-receipt-update-form" class="button-primary compact-button btn-compact">Guardar</button>

                @unless ($receipt->isConfirmed())
                    <form method="POST" action="{{ route('goods-receipts.confirm', $receipt) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="button-primary compact-button btn-compact">Confirmar entrada</button>
                    </form>
                @endunless
            @endif

            @if ($receipt->status !== \App\Models\GoodsReceipt::STATUS_CANCELLED)
                <form method="POST" action="{{ route('goods-receipts.cancel', $receipt) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="button-secondary compact-button btn-compact">Cancelar</button>
                </form>
            @endif

            @if ($canTriggerAi)
                <form method="POST" action="{{ route('goods-receipts.ai-extract', $receipt) }}">
                    @csrf
                    <button type="submit" class="button-secondary compact-button btn-compact goods-receipt-ai-action">
                        {{ $receipt->ai_status === \App\Models\GoodsReceipt::AI_STATUS_FAILED ? 'Reintentar IA' : 'Interpretar IA' }}
                    </button>
                </form>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    @if ($isEditingConfirmed)
        <div class="alert alert-error">
            Estas editando una entrada CONFIRMADA como superadmin. Al guardar, el stock generado se revertira y se volvera a aplicar con los datos nuevos.
        </div>
    @endif

    @if ($isEditable)
        <form
            id="goods-receipt-update-form"
            method="POST"
            action="{{ route('goods-receipts.update', $receipt) }}"
            enctype="multipart/form-data"
            class="surface-card compact-card goods-receipt-workbench"
            data-goods-receipt-form
        >
            @csrf
            @method('PUT')

            <input type="hidden" name="client_id" value="{{ old('client_id', $receipt->client_id) }}" data-receipt-client>

            <div class="goods-receipt-workbench-head">
                <div>
                    <strong>Datos básicos</strong>
                </div>
                <span class="goods-receipt-ai-status goods-receipt-ai-status--{{ $aiStatusTone }}">{{ $aiStatusLabel }}</span>
            </div>

            <div class="goods-receipt-compact-grid goods-receipt-compact-grid--dense">
                <div class="goods-receipt-readonly-field">
                    <span>Cliente</span>
                    <strong>{{ $receipt->client->name }}</strong>
                </div>

                <div>
                    @include('goods-receipts._supplier-picker', ['receipt' => $receipt])
                    @if (filled($detectedSupplierName))
                        <small class="helper-text">Detectado por IA: {{ $detectedSupplierName }}</small>
                    @endif
                    @if (filled($detectedSupplierName) && blank($matchedSupplierId) && $canCreateSuppliers)
                        <small class="helper-text">
                            No hay coincidencia automatica. <a href="{{ route('suppliers.create') }}">Crear proveedor</a>.
                        </small>
                    @endif
                </div>

                <label class="auth-field">
                    <span>Albaran</span>
                    <input type="text" name="receipt_number" value="{{ old('receipt_number', $receipt->receipt_number) }}" class="auth-input" maxlength="150">
                    @error('receipt_number')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Fecha recepcion</span>
                    <input type="date" name="received_at" value="{{ old('received_at', optional($receipt->received_at)->format('Y-m-d')) }}" class="auth-input">
                    @error('received_at')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <div class="goods-receipt-readonly-field">
                    <span>Stock</span>
                    <strong>{{ $stockStatusLabel }}</strong>
                </div>

                <label class="auth-field goods-receipt-notes-field">
                    <span>Notas</span>
                    <textarea name="notes" rows="1" class="auth-input">{{ old('notes', $receipt->notes) }}</textarea>
                    @error('notes')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>

            <div class="goods-receipt-document-strip">
                <div class="goods-receipt-document-strip-copy">
                    <span>Documento</span>
                    <strong>{{ $receipt->document_original_name ?: 'Sin documento adjunto' }}</strong>
                    <small>{{ $aiStatusMessage }}</small>
                </div>

                <div class="goods-receipt-document-strip-actions">
                    @if ($hasDocument)
                        <a href="{{ route('goods-receipts.document', $receipt) }}" target="_blank" rel="noreferrer" class="button-secondary compact-button btn-compact">
                            Ver/Descargar
                        </a>
                    @endif

                    <label class="button-secondary compact-button btn-compact goods-receipt-file-trigger">
                        {{ $hasDocument ? 'Cambiar archivo' : 'Adjuntar archivo' }}
                        <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp">
                    </label>
                </div>
            </div>

            @error('document')
                <small class="form-error goods-receipt-document-error">{{ $message }}</small>
            @enderror

            <div class="goods-receipt-inline-state goods-receipt-inline-state--workbench">
                <span>El stock no se aplica hasta confirmar entrada.</span>
                @if ($receipt->document_processed_at)
                    <span>Ultima lectura IA: {{ $receipt->document_processed_at->format('d/m/Y H:i') }}</span>
                @endif
            </div>

            @if ($receipt->ai_status === \App\Models\GoodsReceipt::AI_STATUS_FAILED && filled($receipt->ai_error))
                <div class="alert alert-error">
                    <strong>Error IA</strong>
                    <div>{{ $receipt->ai_error }}</div>
                </div>
            @endif

            <section class="goods-receipt-lines-card goods-receipt-lines-card--workbench">
                <div class="goods-receipt-lines-tools goods-receipt-lines-tools--tight">
                    <div class="app-copy">
                        <strong>Lineas de entrada</strong>
                        <p>SKU, descripcion, lote, cantidades y ubicacion. Si el SKU no existe, se creara al guardar.</p>
                    </div>

                    <div class="goods-receipt-lines-tools-meta">
                        <span>{{ $visibleLineCount }} {{ \Illuminate\Support\Str::plural('linea', $visibleLineCount) }}</span>
                        <button type="button" class="button-primary compact-button btn-compact goods-receipt-manual-action" data-add-line>Añadir línea manual</button>
                    </div>
                </div>

                @if (! $hasPersistedLines)
                    <div class="goods-receipt-empty-lines">
                        <span>Sin líneas todavía.</span>
                        <button type="button" class="button-secondary compact-button btn-compact" data-add-line>Añadir línea manual</button>
                    </div>
                @endif

                <div class="goods-receipt-line-list" data-receipt-lines aria-label="Lineas de entrada">
                    @foreach ($lineValues as $index => $line)
                        @include('goods-receipts._line-row', ['index' => $index, 'line' => $line])
                    @endforeach
                </div>

                <template data-line-template>
                    @include('goods-receipts._line-row', ['index' => '__INDEX__', 'line' => null])
                </template>
            </section>
        </form>
    @else
        <section class="surface-card compact-card goods-receipt-workbench goods-receipt-workbench--readonly">
            <div class="goods-receipt-workbench-head">
                <div>
                    <strong>Datos de entrada</strong>
                    <span class="ops-page-meta">Entrada cerrada para edicion</span>
                </div>
                <span class="goods-receipt-ai-status goods-receipt-ai-status--{{ $aiStatusTone }}">{{ $aiStatusLabel }}</span>
            </div>

            <div class="goods-receipt-compact-grid goods-receipt-compact-grid--readonly">
                <div class="goods-receipt-readonly-field">
                    <span>Cliente</span>
                    <strong>{{ $receipt->client->name }}</strong>
                </div>
                <div class="goods-receipt-readonly-field">
                    <span>Proveedor</span>
                    <strong>{{ $receipt->supplier?->name ?: 'Sin proveedor' }}</strong>
                </div>
                <div class="goods-receipt-readonly-field">
                    <span>Albaran</span>
                    <strong>{{ $receipt->receipt_number ?: '-' }}</strong>
                </div>
                <div class="goods-receipt-readonly-field">
                    <span>Fecha recepcion</span>
                    <strong>{{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }}</strong>
                </div>
                <div class="goods-receipt-readonly-field goods-receipt-readonly-field--wide">
                    <span>Documento</span>
                    <strong>{{ $receipt->document_original_name ?: 'Sin documento adjunto' }}</strong>
                    @if ($hasDocument)
                        <small><a href="{{ route('goods-receipts.document', $receipt) }}" target="_blank" rel="noreferrer">Descargar documento</a></small>
                    @endif
                </div>
                <div class="goods-receipt-readonly-field goods-receipt-readonly-field--wide">
                    <span>Notas</span>
                    <strong>{{ $receipt->notes ?: 'Sin notas operativas.' }}</strong>
                </div>
            </div>

            <div class="goods-receipt-inline-state goods-receipt-inline-state--workbench">
                <span>{{ $stockStatusLabel }}</span>
                @if ($receipt->stock_applied_at)
                    <span>Stock aplicado el {{ $receipt->stock_applied_at->format('d/m/Y H:i') }}</span>
                @endif
            </div>
        </section>

        <section class="surface-card stock-table-shell compact-card goods-receipt-readonly-lines">
            <div class="ops-index-heading">
                <strong>Lineas registradas</strong>
                <span class="ops-page-meta">{{ $receipt->lines->count() }} {{ \Illuminate\Support\Str::plural('linea', $receipt->lines->count()) }}</span>
            </div>

            <div class="data-table-wrap goods-receipt-lines-wrap">
                <table class="data-table table-compact goods-receipt-lines-table" aria-label="Lineas de entrada">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Descripcion</th>
                            <th>Lote</th>
                            <th>Total uds</th>
                            <th>Uds/pallet</th>
                            <th>Pallets</th>
                            <th>Pico</th>
                            <th>Ubicacion</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receipt->lines as $line)
                            <tr>
                                <td>{{ $line->item?->sku ?: ($line->sku ?: '-') }}</td>
                                <td>{{ $line->description ?: ($line->item?->description ?: '-') }}</td>
                                <td>{{ $line->lot ?: '-' }}</td>
                                <td>{{ number_format($line->quantity_units, 0, ',', '.') }}</td>
                                <td>{{ $line->units_per_pallet ? number_format($line->units_per_pallet, 0, ',', '.') : '-' }}</td>
                                <td>{{ number_format($line->pallet_count, 0, ',', '.') }}</td>
                                <td>{{ $line->pico_units !== null ? number_format($line->pico_units, 0, ',', '.') : '-' }}</td>
                                <td>{{ $line->location?->code ?: 'Sin ubicacion' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($hasAiProposal)
        @include('goods-receipts._ai-proposal-panel')
    @endif

    @if ($receipt->stockPallets->isNotEmpty())
        <section class="surface-card stock-table-shell compact-card">
            <div class="ops-index-heading">
                <strong>Stock generado</strong>
                <span class="ops-page-meta">{{ $receipt->stockPallets->count() }} {{ \Illuminate\Support\Str::plural('partida', $receipt->stockPallets->count()) }} activas</span>
            </div>

            <div class="data-table-wrap goods-receipt-lines-wrap">
                <table class="data-table table-compact" aria-label="Stock generado por la entrada">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Lote</th>
                            <th>Unidades</th>
                            <th>Uds/pallet</th>
                            <th>Pallets</th>
                            <th>Picos</th>
                            <th>Pico 1</th>
                            <th>Ubicacion</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receipt->stockPallets as $stockPallet)
                            <tr>
                                <td>{{ $stockPallet->item?->sku ?: '-' }}</td>
                                <td>{{ $stockPallet->lot ?: '-' }}</td>
                                <td>{{ number_format($stockPallet->quantity_units, 0, ',', '.') }}</td>
                                <td>{{ number_format($stockPallet->units_per_pallet ?? 0, 0, ',', '.') }}</td>
                                <td>{{ number_format($stockPallet->full_pallets ?? 0, 0, ',', '.') }}</td>
                                <td>{{ number_format($stockPallet->peaks_count ?? 0, 0, ',', '.') }}</td>
                                <td>{{ number_format($stockPallet->peak_1 ?? 0, 0, ',', '.') }}</td>
                                <td>{{ $stockPallet->location?->code ?: ($stockPallet->location_text ?: 'Sin ubicacion') }}</td>
                                <td>{{ optional($stockPallet->received_at)->format('d/m/Y') ?: '-' }}</td>
                                <td>{{ $stockPallet->statusLabel() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
