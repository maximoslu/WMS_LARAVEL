@extends('layouts.dashboard')

@section('title', 'Editar ubicacion de stock | MAXIMO WMS')

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Stock', 'href' => route('stock.index', ['client_id' => $stockPallet->client_id])],
            ['label' => 'Editar ubicacion'],
        ];
        $peakUnits = collect(range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS))
            ->sum(fn (int $peakNumber): int => (int) ($stockPallet->{'peak_'.$peakNumber} ?? 0));
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="surface-card item-form-card entity-form compact-card">
        <div class="item-form-header">
            <div class="app-copy">
                <span class="status-chip small-badge badge-compact">Stock</span>
                <h2 class="ops-page-title page-title-compact">Editar ubicacion de partida</h2>
                <p>Esta pantalla solo cambia la ubicacion fisica. No modifica cantidades, lote, estado ni paletizado.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('stock.batches.update', $stockPallet) }}" class="item-form">
            @csrf
            @method('PUT')

            <div class="item-form-grid">
                <label class="auth-field">
                    <span>Cliente</span>
                    <input type="text" class="auth-input" value="{{ $stockPallet->client?->name ?? 'Cliente' }}" disabled>
                </label>

                <label class="auth-field">
                    <span>SKU</span>
                    <input type="text" class="auth-input" value="{{ $stockPallet->item?->sku ?? 'Sin SKU' }}" disabled>
                </label>

                <label class="auth-field item-form-field--full">
                    <span>Descripcion</span>
                    <input type="text" class="auth-input" value="{{ $stockPallet->item?->description ?? 'Sin descripcion' }}" disabled>
                </label>

                <label class="auth-field">
                    <span>Lote</span>
                    <input type="text" class="auth-input" value="{{ $stockPallet->lot ?: 'SIN LOTE' }}" disabled>
                </label>

                <label class="auth-field">
                    <span>Unidades</span>
                    <input type="text" class="auth-input" value="{{ number_format((int) $stockPallet->quantity_units, 0, ',', '.') }} uds" disabled>
                </label>

                <label class="auth-field">
                    <span>Pallets / picos</span>
                    <input type="text" class="auth-input" value="{{ number_format((int) $stockPallet->full_pallets, 0, ',', '.') }} pallets / {{ number_format((int) $stockPallet->peaks_count, 0, ',', '.') }} picos" disabled>
                </label>

                <label class="auth-field">
                    <span>Unidades en picos</span>
                    <input type="text" class="auth-input" value="{{ number_format($peakUnits, 0, ',', '.') }} uds" disabled>
                </label>

                <label class="auth-field">
                    <span>Estado</span>
                    <input type="text" class="auth-input" value="{{ $stockPallet->statusLabel() }}" disabled>
                </label>

                <label class="auth-field">
                    <span>Ubicacion actual</span>
                    <input type="text" class="auth-input" value="{{ $stockPallet->pickingLocationLabel() ?? 'Sin ubicacion registrada' }}" disabled>
                </label>

                <label class="auth-field item-form-field--full">
                    <span>Nueva ubicacion</span>
                    <select name="location_id" class="auth-input">
                        <option value="">Sin ubicacion</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((string) old('location_id', $stockPallet->location_id) === (string) $location->id)>
                                {{ $location->displayLabel() }}
                            </option>
                        @endforeach
                    </select>
                    @error('location_id')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>

            <div class="item-form-hint">
                <strong>Accion controlada</strong>
                <p>Al guardar se actualiza solo la ubicacion de esta partida. El stock, el lote, el estado y las unidades quedan igual.</p>
            </div>

            <div class="item-form-actions action-buttons">
                <a href="{{ route('stock.index', ['client_id' => $stockPallet->client_id]) }}" class="button-secondary compact-button btn-compact">Cancelar</a>
                <button type="submit" class="button-primary compact-button btn-compact">Guardar ubicacion</button>
            </div>
        </form>
    </div>
@endsection
