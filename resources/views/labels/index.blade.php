@extends('layouts.dashboard')

@section('title', 'Etiquetas | MAXIMO WMS')
@section('topbar_title', 'Etiquetas')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Operaciones'],
            ['label' => 'Etiquetas'],
        ];
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    <div class="labels-workbench">
        <section class="surface-card compact-card labels-header">
            <div class="app-copy">
                <span class="module-tag small-badge">Trazabilidad</span>
                <h2 class="ops-page-title page-title-compact">Etiquetas</h2>
                <p>Generacion rapida por entrada o partida de stock.</p>
            </div>
            <div class="labels-header-facts">
                <span><strong>A4</strong><small>Formato</small></span>
                <span><strong>2</strong><small>Etiquetas por hoja</small></span>
                <span><strong>Interno</strong><small>Usuarios WMS</small></span>
                <span><strong>Lectura</strong><small>Sin tocar stock</small></span>
            </div>
        </section>

        <section class="surface-card compact-card labels-origin-panel">
            <div class="labels-panel-heading">
                <div>
                    <h3>Buscar origen</h3>
                    <p>Elige la entrada o la partida desde sus pantallas operativas.</p>
                </div>
            </div>

            <div class="labels-origin-grid">
                <article>
                    <div>
                        <strong>Desde entrada</strong>
                        <span>Etiqueta toda la entrada o una linea concreta.</span>
                    </div>
                    <a href="{{ route('goods-receipts.index') }}" class="button-primary compact-button btn-compact">Abrir entradas</a>
                </article>
                <article>
                    <div>
                        <strong>Desde stock</strong>
                        <span>Etiqueta una partida existente del inventario.</span>
                    </div>
                    <a href="{{ route('stock.index') }}" class="button-secondary compact-button btn-compact">Abrir stock</a>
                </article>
            </div>
        </section>

        <section class="labels-grid">
            <article class="surface-card compact-card labels-list-panel">
                <div class="labels-panel-heading">
                    <div>
                        <h3>Entradas recientes</h3>
                        <p>Accesos directos a etiqueta o detalle.</p>
                    </div>
                </div>
                <div class="labels-quick-list">
                    @forelse ($recentReceipts as $receipt)
                        <div class="labels-quick-row">
                            <a href="{{ route('goods-receipts.show', $receipt) }}" class="labels-quick-main">
                                <strong>{{ $receipt->receipt_number ?: 'Entrada #'.$receipt->id }}</strong>
                                <span>{{ $receipt->client?->name }} &middot; {{ optional($receipt->received_at)->format('d/m/Y') ?: 'Sin fecha' }}</span>
                            </a>
                            <div class="labels-quick-actions">
                                <a href="{{ route('labels.goods-receipt', $receipt) }}" target="_blank" rel="noopener noreferrer" class="button-primary compact-button btn-table">Generar etiquetas</a>
                                <a href="{{ route('goods-receipts.show', $receipt) }}" class="button-secondary compact-button btn-table">Abrir</a>
                            </div>
                        </div>
                    @empty
                        <p class="helper-text">Todavia no hay entradas recientes.</p>
                    @endforelse
                </div>
            </article>

            <article class="surface-card compact-card labels-list-panel">
                <div class="labels-panel-heading">
                    <div>
                        <h3>Stock reciente</h3>
                        <p>Partidas actuales listas para imprimir.</p>
                    </div>
                </div>
                <div class="labels-quick-list">
                    @forelse ($recentStock as $stockPallet)
                        <div class="labels-quick-row">
                            <a href="{{ route('stock.index', ['client_id' => $stockPallet->client_id, 'item_id' => $stockPallet->item_id, 'lot' => $stockPallet->lot]) }}" class="labels-quick-main">
                                <strong>{{ $stockPallet->item?->sku ?: 'SKU no identificado' }}</strong>
                                <span>{{ $stockPallet->client?->name }} &middot; {{ $stockPallet->lot ?: 'SIN LOTE' }}</span>
                            </a>
                            <div class="labels-quick-actions">
                                <a href="{{ route('labels.stock-pallet', $stockPallet) }}" target="_blank" rel="noopener noreferrer" class="button-primary compact-button btn-table">Generar etiquetas</a>
                                <a href="{{ route('stock.index', ['client_id' => $stockPallet->client_id, 'item_id' => $stockPallet->item_id, 'lot' => $stockPallet->lot]) }}" class="button-secondary compact-button btn-table">Abrir</a>
                            </div>
                        </div>
                    @empty
                        <p class="helper-text">Todavia no hay partidas recientes.</p>
                    @endforelse
                </div>
            </article>
        </section>
    </div>
@endsection
