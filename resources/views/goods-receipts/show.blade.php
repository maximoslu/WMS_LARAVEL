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
        $isEditable = ! $receipt->isConfirmed() && $receipt->status !== \App\Models\GoodsReceipt::STATUS_CANCELLED;
        $hasDocument = filled($receipt->document_path);
        $canTriggerAi = $hasDocument && $aiEnabled && $isEditable;
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
            $aiStatus === \App\Models\GoodsReceipt::AI_STATUS_FAILED => 'Error IA',
            default => 'IA sin ejecutar',
        };
        $stockStatusLabel = $receipt->hasStockApplied() ? 'Stock aplicado' : 'Pendiente de confirmar';
        $stockStatusTone = $receipt->hasStockApplied() ? 'confirmed' : 'pending';
        $lineCount = count($lineValues);
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card goods-receipt-header-card goods-receipt-ops-band">
        <div class="goods-receipt-ops-grid">
            <article class="goods-receipt-ops-chip goods-receipt-ops-chip--wide">
                <span>Entrada</span>
                <strong>{{ $receipt->receipt_number ?: 'Entrada #'.$receipt->id }}</strong>
                <small>{{ $receipt->statusLabel() }}</small>
            </article>
            <article class="goods-receipt-ops-chip">
                <span>Cliente</span>
                <strong>{{ $receipt->client->name }}</strong>
            </article>
            <article class="goods-receipt-ops-chip">
                <span>Proveedor</span>
                <strong>{{ $receipt->supplier?->name ?: 'Sin proveedor' }}</strong>
            </article>
            <article class="goods-receipt-ops-chip">
                <span>Fecha</span>
                <strong>{{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }}</strong>
            </article>
            <article class="goods-receipt-ops-chip">
                <span>Estado IA</span>
                <strong>{{ $aiStatusLabel }}</strong>
            </article>
            <article class="goods-receipt-ops-chip goods-receipt-ops-chip--{{ $stockStatusTone }}">
                <span>Stock</span>
                <strong>{{ $stockStatusLabel }}</strong>
            </article>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons goods-receipt-header-actions">
            @if ($hasDocument)
                <a href="{{ route('goods-receipts.document', $receipt) }}" target="_blank" rel="noreferrer" class="button-secondary compact-button btn-compact">
                    Descargar PDF
                </a>
            @endif

            @if ($canTriggerAi)
                <form method="POST" action="{{ route('goods-receipts.ai-extract', $receipt) }}">
                    @csrf
                    <button type="submit" class="button-secondary compact-button btn-compact">
                        {{ $receipt->ai_status === \App\Models\GoodsReceipt::AI_STATUS_FAILED ? 'Reintentar IA' : 'Interpretar con IA' }}
                    </button>
                </form>
            @endif

            @if ($isEditable)
                <button type="button" class="button-secondary compact-button btn-compact" data-add-line>Anadir linea manual</button>
                <button type="submit" form="goods-receipt-update-form" class="button-primary compact-button btn-compact">Guardar</button>

                <form method="POST" action="{{ route('goods-receipts.confirm', $receipt) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="button-primary compact-button btn-compact">Confirmar entrada</button>
                </form>
            @endif

            @if ($receipt->status !== \App\Models\GoodsReceipt::STATUS_CANCELLED)
                <form method="POST" action="{{ route('goods-receipts.cancel', $receipt) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="button-secondary compact-button btn-compact">Cancelar</button>
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

    @if ($hasDocument || $isEditable)
        <section class="surface-card compact-card goods-receipt-ai-callout goods-receipt-ai-callout--compact">
            <div class="goods-receipt-ai-callout-head">
                <div class="app-copy">
                    <strong>{{ $receipt->ai_status === \App\Models\GoodsReceipt::AI_STATUS_FAILED ? 'No se pudo interpretar el documento' : ($hasDocument ? 'Documento guardado' : 'Entrada lista para completar') }}</strong>
                    <p class="goods-receipt-ai-callout-copy">
                        @if ($receipt->ai_status === \App\Models\GoodsReceipt::AI_STATUS_FAILED)
                            La entrada y el documento siguen guardados. Puedes reintentar la IA o continuar cargando lineas manualmente.
                        @elseif ($hasDocument)
                            Puedes interpretarlo con IA o anadir lineas manualmente desde esta misma pantalla.
                        @else
                            Puedes completar proveedor, fecha y lineas manualmente. Si adjuntas un documento, tambien podras pedir una propuesta IA.
                        @endif
                    </p>
                </div>
                <span class="goods-receipt-ai-status goods-receipt-ai-status--{{ $aiStatusTone }}">{{ $aiStatusLabel }}</span>
            </div>

            <div class="goods-receipt-inline-state">
                <span>El stock no se aplicara hasta confirmar la entrada.</span>
                @if (! $aiEnabled)
                    <span>La IA esta desactivada en esta configuracion.</span>
                @elseif ($receipt->document_processed_at)
                    <span>Ultima lectura IA: {{ $receipt->document_processed_at->format('d/m/Y H:i') }}</span>
                @elseif ($hasDocument)
                    <span>El documento ya esta disponible para revisar manualmente o reintentar.</span>
                @endif
            </div>
        </section>
    @endif

    <section class="goods-receipt-detail-grid{{ $isEditable ? '' : ' goods-receipt-detail-grid--readonly' }}">
        <article class="surface-card compact-card goods-receipt-card goods-receipt-card--document">
            <div class="ops-index-heading">
                <div>
                    <strong>Documento y estado</strong>
                    <span class="ops-page-meta">Storage privado con descarga protegida</span>
                </div>
                <span class="goods-receipt-ai-status goods-receipt-ai-status--{{ $aiStatusTone }}">{{ $aiStatusLabel }}</span>
            </div>

            <div class="goods-receipt-state-list">
                <div class="goods-receipt-state-item">
                    <span>Creada por</span>
                    <strong>{{ $receipt->creator?->name ?: 'Usuario no disponible' }}</strong>
                </div>
                <div class="goods-receipt-state-item">
                    <span>Confirmada por</span>
                    <strong>{{ $receipt->confirmer?->name ?: 'Pendiente' }}</strong>
                </div>
                <div class="goods-receipt-state-item">
                    <span>Stock aplicado</span>
                    <strong>{{ $receipt->stock_applied_at?->format('d/m/Y H:i') ?: 'Pendiente' }}</strong>
                </div>
            </div>

            @if ($hasDocument)
                <div class="goods-receipt-document-link">
                    <a href="{{ route('goods-receipts.document', $receipt) }}" target="_blank" rel="noreferrer">
                        {{ $receipt->document_original_name ?: 'Abrir documento adjunto' }}
                    </a>
                </div>
            @else
                <p class="goods-receipt-ai-inline-note">Todavia no hay documento adjunto para esta entrada.</p>
            @endif

            @if ($isEditable)
                <form method="POST" action="{{ route('goods-receipts.attach-document', $receipt) }}" enctype="multipart/form-data" class="goods-receipt-document-form">
                    @csrf
                    <label class="auth-field">
                        <span>Documento del proveedor / albaran</span>
                        <input type="file" name="document" class="auth-input" accept=".pdf,.jpg,.jpeg,.png,.webp">
                    </label>
                    <small class="helper-text">Puedes adjuntar o sustituir el PDF o la foto del documento sin perder la entrada.</small>
                    <div class="goods-receipt-document-actions action-buttons">
                        <button type="submit" class="button-secondary compact-button btn-compact">Guardar documento</button>
                    </div>
                </form>
            @endif

            @if ($receipt->ai_error)
                <div class="goods-receipt-ai-error">
                    <strong>Error IA</strong>
                    <p>{{ $receipt->ai_error }}</p>
                </div>
            @endif
        </article>

        @if ($isEditable)
            <form
                id="goods-receipt-update-form"
                method="POST"
                action="{{ route('goods-receipts.update', $receipt) }}"
                class="surface-card compact-card goods-receipt-card goods-receipt-detail-form"
                data-goods-receipt-form
            >
                @csrf
                @method('PUT')

                <input type="hidden" name="client_id" value="{{ old('client_id', $receipt->client_id) }}" data-receipt-client>

                <div class="goods-receipt-editor-grid">
                    <label class="auth-field">
                        <span>Proveedor</span>
                        <select name="supplier_id" class="auth-input">
                            <option value="">Sin proveedor</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((string) old('supplier_id', $receipt->supplier_id) === (string) $supplier->id)>
                                    {{ $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('supplier_id')
                            <small class="form-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label class="auth-field">
                        <span>Numero de albaran</span>
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

                    <label class="auth-field goods-receipt-notes-field">
                        <span>Notas</span>
                        <textarea name="notes" rows="3" class="auth-input">{{ old('notes', $receipt->notes) }}</textarea>
                        @error('notes')
                            <small class="form-error">{{ $message }}</small>
                        @enderror
                    </label>
                </div>

                <section class="goods-receipt-lines-card goods-receipt-lines-card--detail">
                    <div class="goods-receipt-lines-tools">
                        <div class="app-copy">
                            <strong>Lineas operativas</strong>
                            <p>Completa SKU, cantidades, lote y ubicacion. Si el SKU no existe, se creara al guardar con sus uds/palet.</p>
                        </div>

                        <div class="goods-receipt-lines-tools-meta">
                            <span>{{ $lineCount }} {{ \Illuminate\Support\Str::plural('linea', $lineCount) }}</span>
                            <button type="button" class="button-secondary compact-button btn-compact" data-add-line>Anadir linea manual</button>
                        </div>
                    </div>

                    <div class="goods-receipt-inline-state">
                        <span>El stock no se aplicara hasta confirmar la entrada.</span>
                        <span>Puedes guardar cabecera y lineas tantas veces como necesites antes de confirmar.</span>
                    </div>

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
            <article class="surface-card compact-card goods-receipt-card goods-receipt-card--header">
                <div class="ops-index-heading">
                    <strong>Cabecera</strong>
                    <span class="ops-page-meta">Entrada cerrada para edicion</span>
                </div>

                <dl class="goods-receipt-meta">
                    <div>
                        <dt>Albaran</dt>
                        <dd>{{ $receipt->receipt_number ?: '-' }}</dd>
                    </div>
                    <div>
                        <dt>Proveedor</dt>
                        <dd>{{ $receipt->supplier?->name ?: 'Sin proveedor' }}</dd>
                    </div>
                    <div>
                        <dt>Fecha recepcion</dt>
                        <dd>{{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }}</dd>
                    </div>
                    <div>
                        <dt>Confirmada el</dt>
                        <dd>{{ optional($receipt->confirmed_at)->format('d/m/Y H:i') ?: 'Pendiente' }}</dd>
                    </div>
                </dl>

                <div class="app-copy">
                    <strong>Notas</strong>
                    <p>{{ $receipt->notes ?: 'Sin notas operativas.' }}</p>
                </div>
            </article>
        @endif
    </section>

    @if ($hasAiProposal)
        @include('goods-receipts._ai-proposal-panel')
    @endif

    @if (! $isEditable)
        <section class="surface-card stock-table-shell compact-card">
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
