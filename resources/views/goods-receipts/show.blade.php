@extends('layouts.dashboard')

@section('title', 'Detalle de entrada | MAXIMO WMS')
@section('topbar_title', 'Detalle de entrada')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Operaciones</span>
        <span>/</span>
        <a href="{{ route('goods-receipts.index') }}">Entradas</a>
        <span>/</span>
        <span>{{ $receipt->receipt_number ?: 'Entrada #'.$receipt->id }}</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card goods-receipt-header-card">
        <div class="ops-page-headline">
            <div class="goods-receipt-title">
                <h2 class="ops-page-title page-title-compact">{{ $receipt->receipt_number ?: 'Entrada #'.$receipt->id }}</h2>
                <span class="receipt-status-pill receipt-status-pill--{{ $receipt->status }}">{{ $receipt->statusLabel() }}</span>
            </div>
            <span class="ops-page-meta">
                {{ $receipt->supplier?->name ?: 'Sin proveedor' }} / {{ $receipt->client->name }}
            </span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons goods-receipt-header-actions">
            @if (! $receipt->isConfirmed() && $receipt->status !== \App\Models\GoodsReceipt::STATUS_CANCELLED)
                <a href="{{ route('goods-receipts.edit', $receipt) }}" class="button-secondary compact-button btn-compact">Editar</a>

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

    <section class="goods-receipt-summary goods-receipt-summary--detail">
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Cliente</strong>
            <span>{{ $receipt->client->name }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Proveedor</strong>
            <span>{{ $receipt->supplier?->name ?: 'Sin proveedor' }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Fecha recepcion</strong>
            <span>{{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Partidas generadas</strong>
            <span>{{ number_format($receipt->stockPallets->count(), 0, ',', '.') }}</span>
        </article>
    </section>

    <section class="goods-receipt-grid">
        <article class="surface-card compact-card goods-receipt-card goods-receipt-card--header">
            <div class="ops-index-heading">
                <strong>Cabecera</strong>
                <span class="ops-page-meta">Creada por {{ $receipt->creator?->name ?: 'Usuario no disponible' }}</span>
            </div>

            <dl class="goods-receipt-meta">
                <div>
                    <dt>Albaran</dt>
                    <dd>{{ $receipt->receipt_number ?: '-' }}</dd>
                </div>
                <div>
                    <dt>Documento externo</dt>
                    <dd>{{ $receipt->external_document_number ?: '-' }}</dd>
                </div>
                <div>
                    <dt>Confirmada por</dt>
                    <dd>{{ $receipt->confirmer?->name ?: 'Pendiente' }}</dd>
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

        <article class="surface-card compact-card goods-receipt-card goods-receipt-card--document">
            <div class="ops-index-heading">
                <strong>Documento e IA futura</strong>
                <span class="receipt-status-pill receipt-status-pill--ai">{{ $receipt->aiStatusLabel() }}</span>
            </div>

            <div class="goods-receipt-document">
                @if ($receipt->document_url)
                    <p>
                        <a href="{{ $receipt->document_url }}" target="_blank" rel="noreferrer">
                            {{ $receipt->document_original_name ?: 'Abrir documento adjunto' }}
                        </a>
                    </p>
                @else
                    <p>Sin documento adjunto todavia.</p>
                @endif

                <form method="POST" action="{{ route('goods-receipts.attach-document', $receipt) }}" enctype="multipart/form-data" class="goods-receipt-document-form">
                    @csrf
                    <label class="auth-field">
                        <span>Adjuntar o reemplazar documento</span>
                        <input type="file" name="document" class="auth-input" accept=".pdf,.jpg,.jpeg,.png,.webp">
                    </label>
                    <div class="goods-receipt-document-actions action-buttons">
                        <button type="submit" class="button-secondary compact-button btn-compact">Guardar documento</button>
                        <button type="button" class="button-secondary compact-button btn-compact" disabled>Procesar con IA (proximamente)</button>
                    </div>
                </form>

                @if ($receipt->ai_extracted_data)
                    <pre class="goods-receipt-ai-dump">{{ json_encode($receipt->ai_extracted_data, JSON_PRETTY_PRINT) }}</pre>
                @else
                    <p class="helper-text">La arquitectura ya guarda documento, estado de IA y datos extraidos para una futura fase OCR/IA.</p>
                @endif
            </div>
        </article>
    </section>

    <section class="surface-card stock-table-shell compact-card">
        <div class="ops-index-heading">
            <strong>Lineas y stock previsto</strong>
            <span class="ops-page-meta">{{ $receipt->lines->count() }} lineas</span>
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
                        <th>Notas</th>
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
                            <td>{{ $line->notes ?: '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($receipt->stockPallets->isNotEmpty())
        <section class="surface-card stock-table-shell compact-card">
            <div class="ops-index-heading">
                <strong>Partidas generadas</strong>
                <span class="ops-page-meta">{{ $receipt->stockPallets->count() }} lineas activas</span>
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
