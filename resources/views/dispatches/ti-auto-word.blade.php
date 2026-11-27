@extends('layouts.dashboard')

@section('title', 'Salida desde Word | MAXIMO WMS')
@section('topbar_title', 'Salida desde Word')

@section('content')
    <x-breadcrumbs :items="[
        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'Operaciones'],
        ['label' => 'Salidas', 'href' => route('dispatches.index')],
        ['label' => 'Salida desde Word'],
    ]" />

    <div class="goods-receipt-shell">
        <section class="surface-card compact-card goods-receipt-header-card">
            <div class="goods-receipt-toolbar">
                <div class="goods-receipt-toolbar-main">
                    <span class="wms-list-kicker">Operaciones / Salidas</span>
                    <h2 class="goods-receipt-title">Salida desde Word · TI Auto</h2>
                    <p>Sube el pedido de TI Auto en Word. Se crea una salida en borrador, con una referencia y la suma de sus ubicaciones por línea, para revisarla en pantalla.</p>
                </div>
                <a href="{{ route('dispatches.index') }}" class="button-secondary compact-button">Volver a salidas</a>
            </div>
        </section>

        <section class="surface-card compact-card goods-receipt-form-card">
            <div class="wms-inline-note">Si el Word tiene un solo cuadro, se suman las cantidades de todas las ubicaciones de cada referencia. Las matrículas no se tratan como lotes ni crean líneas adicionales. Si hay un segundo cuadro con lotes, se conserva su desglose. Cualquier diferencia con los totales impresos queda señalada para revisión. Las referencias nuevas se crean para TI Auto con descripción y unidades por palé provisionales; revisa su ficha antes de confirmar la carga.</div>
            <form method="POST" action="{{ route('dispatches.ti-auto-word.store') }}" enctype="multipart/form-data" class="goods-receipt-form">
                @csrf
                <label class="auth-field">
                    <span>Pedido de TI Auto (.docx)</span>
                    <input type="file" name="word" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="auth-input" required>
                    @error('word') <small class="form-error">{{ $message }}</small> @enderror
                </label>
                <div class="form-actions">
                    <button type="submit" class="button-primary">Crear salida en borrador</button>
                    <a href="{{ route('dispatches.index') }}" class="button-secondary">Cancelar</a>
                </div>
            </form>
        </section>
    </div>
@endsection
