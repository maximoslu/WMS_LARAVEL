@extends('layouts.dashboard')

@section('title', 'Nueva salida manual | MAXIMO WMS')
@section('topbar_title', 'Nueva salida manual')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <a href="{{ route('dispatches.index') }}">Salidas</a>
        <span>/</span>
        <span>Salida manual</span>
    </nav>

    @if ($errors->any())
        <div class="alert alert-error">
            {{ $errors->first('quantities') ?: 'Revisa los datos de la salida antes de guardarla.' }}
        </div>
    @endif

    <form method="POST" action="{{ route('dispatches.store') }}" data-goods-dispatch-form>
        @csrf

        <div class="dispatch-builder">
            <section class="surface-card compact-card merchandise-request-catalog">
                <div class="ops-section-heading">
                    <div>
                        <strong>Crear salida manual</strong>
                        <p class="merchandise-request-summary-copy">Selecciona cliente, mercancía y pallets para registrar la expedición.</p>
                    </div>
                </div>

                <label class="auth-field">
                    <span>Cliente</span>
                    <select name="client_id" class="auth-input" data-dispatch-client required>
                        <option value="">Selecciona un cliente</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>
                                {{ $client->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <section class="merchandise-request-picker" aria-label="Selector de salida">
                    <label class="auth-field">
                        <span>Mercancia</span>
                        <select class="auth-input" data-dispatch-picker-item>
                            <option value="">Selecciona una referencia</option>
                            @foreach ($items as $item)
                                <option value="{{ $item->id }}" data-item-client-id="{{ $item->client_id }}">
                                    {{ $item->client?->name }} · {{ $item->sku }} - {{ $item->description }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="auth-field merchandise-request-picker-quantity">
                        <span>Pallets</span>
                        <input type="number" min="1" step="1" value="1" class="auth-input" data-dispatch-picker-quantity>
                    </label>

                    <button type="button" class="button-primary compact-button btn-compact" data-dispatch-add-selected>
                        Añadir a salida
                    </button>
                </section>

                <p class="helper-text" data-dispatch-picker-feedback>
                    Selecciona el cliente primero para filtrar referencias y evitar errores de operativa.
                </p>

                <div class="merchandise-request-hidden-inputs" data-dispatch-hidden-inputs>
                    @foreach (old('quantities', []) as $itemId => $quantity)
                        @if ((int) $quantity > 0)
                            <input type="hidden" name="quantities[{{ $itemId }}]" value="{{ (int) $quantity }}" data-dispatch-hidden-quantity data-item-id="{{ $itemId }}">
                        @endif
                    @endforeach
                </div>

                <script type="application/json" data-dispatch-items>@json($itemsCatalog)</script>

                <label class="auth-field">
                    <span>Observaciones</span>
                    <textarea name="notes" class="auth-input" rows="4" maxlength="2000" placeholder="Opcional">{{ old('notes') }}</textarea>
                </label>
            </section>

            <aside class="surface-card compact-card merchandise-request-summary-card">
                <div class="ops-section-heading">
                    <div>
                        <strong>Resumen de salida</strong>
                        <p class="merchandise-request-summary-copy">Revisa líneas y pallets antes de registrar la salida.</p>
                    </div>
                </div>

                <div class="merchandise-request-summary-totals">
                    <div>
                        <span>Lineas</span>
                        <strong data-dispatch-summary-lines>0</strong>
                    </div>
                    <div>
                        <span>Total pallets</span>
                        <strong data-dispatch-summary-pallets>0</strong>
                    </div>
                </div>

                <div class="merchandise-request-summary-empty" data-dispatch-summary-empty>
                    Todavía no hay líneas en la salida.
                </div>

                <div class="data-table-wrap">
                    <table class="data-table table-compact merchandise-request-summary-table">
                        <thead>
                            <tr>
                                <th>Mercancia</th>
                                <th>Pallets</th>
                                <th>Accion</th>
                            </tr>
                        </thead>
                        <tbody data-dispatch-summary-rows></tbody>
                    </table>
                </div>

                <div class="item-filter-actions action-buttons page-actions-compact merchandise-request-submit">
                    <button type="submit" class="button-primary compact-button btn-compact" data-dispatch-submit>Registrar salida</button>
                    <a href="{{ route('dispatches.index') }}" class="button-secondary compact-button btn-compact">Cancelar</a>
                </div>
            </aside>
        </div>
    </form>
@endsection
