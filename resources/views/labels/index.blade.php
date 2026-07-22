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

    <section class="surface-card compact-card labels-header">
        <div class="app-copy">
            <span class="module-tag small-badge">Trazabilidad</span>
            <h2 class="ops-page-title page-title-compact">Etiquetas</h2>
            <p>Genera etiquetas imprimibles por pallet, pico, lote y articulo.</p>
        </div>
        <div class="labels-header-facts">
            <span><strong>A4</strong>2 etiquetas por hoja</span>
            <span><strong>Acceso</strong>Usuarios internos</span>
            <span><strong>Stock</strong>Solo lectura</span>
        </div>
    </section>

    <section class="surface-card compact-card labels-origin-panel">
        <div class="backup-panel-header">
            <div>
                <h3>Buscar origen</h3>
                <p>En esta fase puedes generar etiquetas desde el detalle de una entrada o desde Stock.</p>
            </div>
        </div>

        <div class="labels-origin-grid">
            <article>
                <strong>Desde entrada</strong>
                <span>Abre una entrada y usa Generar etiquetas o Sacar etiqueta por linea.</span>
                <a href="{{ route('goods-receipts.index') }}" class="button-primary compact-button btn-compact">Abrir entradas</a>
            </article>
            <article>
                <strong>Desde stock</strong>
                <span>Filtra una partida actual y usa Sacar etiqueta desde el detalle.</span>
                <a href="{{ route('stock.index') }}" class="button-secondary compact-button btn-compact">Abrir stock</a>
            </article>
        </div>
    </section>

    <section class="labels-grid">
        <article class="surface-card compact-card labels-list-panel">
            <div class="backup-panel-header">
                <div>
                    <h3>Entradas recientes</h3>
                    <p>Accesos rapidos para generar etiquetas por entrada.</p>
                </div>
            </div>
            <div class="labels-quick-list">
                @forelse ($recentReceipts as $receipt)
                    <a href="{{ route('goods-receipts.show', $receipt) }}">
                        <strong>{{ $receipt->receipt_number ?: 'Entrada #'.$receipt->id }}</strong>
                        <span>{{ $receipt->client?->name }} · {{ optional($receipt->received_at)->format('d/m/Y') ?: 'Sin fecha' }}</span>
                    </a>
                @empty
                    <p class="helper-text">Todavia no hay entradas recientes.</p>
                @endforelse
            </div>
        </article>

        <article class="surface-card compact-card labels-list-panel">
            <div class="backup-panel-header">
                <div>
                    <h3>Stock reciente</h3>
                    <p>Partidas actuales con acceso a etiqueta desde Stock.</p>
                </div>
            </div>
            <div class="labels-quick-list">
                @forelse ($recentStock as $stockPallet)
                    <a href="{{ route('stock.index', ['client_id' => $stockPallet->client_id, 'item_id' => $stockPallet->item_id, 'lot' => $stockPallet->lot]) }}">
                        <strong>{{ $stockPallet->item?->sku ?: 'SKU no identificado' }}</strong>
                        <span>{{ $stockPallet->client?->name }} · {{ $stockPallet->lot ?: 'SIN LOTE' }}</span>
                    </a>
                @empty
                    <p class="helper-text">Todavia no hay partidas recientes.</p>
                @endforelse
            </div>
        </article>
    </section>
@endsection
