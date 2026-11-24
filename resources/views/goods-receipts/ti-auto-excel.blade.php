@extends('layouts.dashboard')

@section('title', 'Entrada desde Excel | MAXIMO WMS')
@section('topbar_title', 'Entrada desde Excel')

@section('content')
    <x-breadcrumbs :items="[
        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'Operaciones'],
        ['label' => 'Entradas', 'href' => route('goods-receipts.index')],
        ['label' => 'Entrada desde Excel'],
    ]" />

    <div class="goods-receipt-shell">
        <section class="surface-card compact-card goods-receipt-header-card">
            <div class="goods-receipt-toolbar">
                <div class="goods-receipt-toolbar-main">
                    <span class="wms-list-kicker">Operaciones / Entradas</span>
                    <h2 class="goods-receipt-title">Entrada desde Excel</h2>
                    <p>Importación exclusiva para <strong>TI Automotive</strong>. El archivo crea un borrador: el stock solo se aplica cuando confirmas la entrada.</p>
                </div>
                <div class="goods-receipt-header-actions">
                    <a href="{{ route('goods-receipts.index') }}" class="button-secondary compact-button">Volver a entradas</a>
                </div>
            </div>
        </section>

        <section class="surface-card compact-card goods-receipt-form-card">
            <div class="goods-receipt-form-card-head">
                <div>
                    <h3>Subir Excel de TI Auto</h3>
                    <p>Usa la hoja de detalle con <strong>Customer Part Number</strong>, <strong>Description</strong> y la columna de cantidades con fecha (YYYYMMDD).</p>
                </div>
                <span class="wms-status-chip wms-status-chip--draft">TI Auto</span>
            </div>

            <div class="wms-inline-note">
                Cada etiqueta se interpreta como un bulto físico. Las iguales se agrupan: por ejemplo, cuatro filas de 75 uds pasan a <strong>4 palés × 75 uds</strong>. Si una referencia mezcla tamaños, se conservan en líneas separadas.
            </div>

            <form method="POST" action="{{ route('goods-receipts.ti-auto-excel.store') }}" enctype="multipart/form-data" class="goods-receipt-form">
                @csrf
                <label class="auth-field">
                    <span>Excel de entrada (.xlsx)</span>
                    <input type="file" name="excel" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="auth-input" required>
                    @error('excel')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <div class="form-actions">
                    <button type="submit" class="button-primary">Crear entrada en borrador</button>
                    <a href="{{ route('goods-receipts.index') }}" class="button-secondary">Cancelar</a>
                </div>
            </form>
        </section>
    </div>
@endsection
