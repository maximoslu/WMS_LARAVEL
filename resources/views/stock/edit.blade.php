@extends('layouts.dashboard')

@section('title', 'Editar partida de stock | MAXIMO WMS')

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @php
        $breadcrumbs = [


        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'Stock', 'href' => route('stock.index', ['client_id' => $stockPallet->client_id])],
        ['label' => 'Editar partida'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="surface-card item-form-card entity-form compact-card">
        <div class="item-form-header">
            <div class="app-copy">
                <span class="status-chip small-badge badge-compact">Superadmin</span>
                <h2 class="ops-page-title page-title-compact">Editar partida de stock</h2>
                <p>Actualiza la partida sin tocar el articulo maestro. Si no existe paletizado real, deja `0` en unidades por pallet.</p>
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
                    <input type="text" name="lot" value="{{ old('lot', $stockPallet->lot) }}" class="auth-input" maxlength="255">
                    @error('lot')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Cantidad total</span>
                    <input type="number" min="0" step="1" name="quantity_units" value="{{ old('quantity_units', $stockPallet->quantity_units) }}" class="auth-input" required>
                    @error('quantity_units')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Unidades por pallet</span>
                    <input type="number" min="0" step="1" name="units_per_pallet" value="{{ old('units_per_pallet', $stockPallet->units_per_pallet) }}" class="auth-input" required>
                    <small class="helper-text">Usa `0` cuando la partida tenga stock operativo pero no paletizado fiable.</small>
                    @error('units_per_pallet')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Fecha entrada</span>
                    <input type="date" name="received_at" value="{{ old('received_at', optional($stockPallet->received_at)->format('Y-m-d')) }}" class="auth-input">
                    @error('received_at')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Estado</span>
                    <select name="status" class="auth-input" required>
                        @foreach (\App\Models\StockPallet::statusOptions() as $status => $label)
                            <option value="{{ $status }}" @selected(old('status', $stockPallet->status) === $status)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field item-form-field--full">
                    <span>Ubicacion vinculada</span>
                    <select name="location_id" class="auth-input">
                        <option value="">Sin ubicacion vinculada</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((string) old('location_id', $stockPallet->location_id) === (string) $location->id)>
                                {{ $location->code }}{{ $location->warehouse ? ' / '.$location->warehouse->code : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('location_id')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field item-form-field--full">
                    <span>Texto libre de ubicacion</span>
                    <input type="text" name="location_text" value="{{ old('location_text', $stockPallet->location_id ? null : $stockPallet->location_text) }}" class="auth-input" maxlength="255">
                    <small class="helper-text">Solo se usa si no seleccionas una ubicacion vinculada.</small>
                    @error('location_text')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field item-form-field--full">
                    <span>Motivo bloqueo</span>
                    <input type="text" name="blocked_reason" value="{{ old('blocked_reason', $stockPallet->blocked_reason) }}" class="auth-input" maxlength="255">
                    @error('blocked_reason')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>

            <div class="item-form-hint">
                <strong>Nota operativa</strong>
                <p>Al guardar, el sistema recalcula pallets y picos solo cuando `units_per_pallet` es mayor que cero.</p>
            </div>

            <div class="item-form-actions action-buttons">
                <a href="{{ route('stock.index', ['client_id' => $stockPallet->client_id]) }}" class="button-secondary compact-button btn-compact">Cancelar</a>
                <button type="submit" class="button-primary compact-button btn-compact">Guardar cambios</button>
            </div>
        </form>
    </div>
@endsection





