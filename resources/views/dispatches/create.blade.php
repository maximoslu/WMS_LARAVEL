@extends('layouts.dashboard')

@section('title', 'Nueva salida manual | MAXIMO WMS')
@section('topbar_title', 'Nueva salida manual')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Salidas', 'href' => route('dispatches.index')],
            ['label' => 'Salida manual'],
        ];
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card compact-card wms-flow-hero">
        <div class="wms-flow-hero-copy">
            <span class="status-chip">Expedición manual</span>
            <h2 class="ops-page-title page-title-compact">Registrar una salida con referencias claras, pallets completos y picos</h2>
            <p>
                Selecciona cliente, busca la referencia exacta y deja la salida lista desde el primer momento con una operativa más cercana al trabajo real de almacén.
            </p>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-error">
            {{ $errors->first('lines') ?: 'Revisa los datos de la salida antes de guardarla.' }}
        </div>
    @endif

    <form method="POST" action="{{ route('dispatches.store') }}" data-goods-dispatch-form data-search-endpoint="{{ $searchEndpoint }}">
        @csrf

        <div class="dispatch-builder">
            <section class="surface-card compact-card merchandise-request-catalog wms-flow-card">
                <div class="wms-section-head">
                    <div>
                        <strong>Crear salida manual</strong>
                        <p class="merchandise-request-summary-copy">
                            El cliente filtra el catálogo disponible y el buscador te muestra partidas concretas cuando existen, con su lote, ubicación y disponibilidad visible.
                        </p>
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

                <section class="wms-variant-picker" aria-label="Selector de salida">
                    <div class="auth-field">
                        <span>Buscar mercancía</span>
                        <div
                            class="ajax-autocomplete"
                            data-ajax-autocomplete
                            data-endpoint="{{ $searchEndpoint }}"
                            data-min-chars="2"
                            data-empty-message="Selecciona primero un cliente y escribe al menos 2 caracteres."
                            data-no-results-message="Sin resultados"
                            data-searching-message="Buscando..."
                            data-error-message="Error al buscar"
                            data-dispatch-item-picker
                        >
                            <div class="ajax-autocomplete-control">
                                <input type="text" class="auth-input" autocomplete="off" placeholder="Buscar por SKU o descripción" data-autocomplete-input>
                                <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear hidden>Limpiar</button>
                            </div>
                            <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                                <div class="ajax-autocomplete-status" data-autocomplete-status>Selecciona un cliente y empieza a escribir...</div>
                                <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                            </div>
                        </div>
                    </div>

                    <article class="wms-variant-preview" data-dispatch-selection-preview>
                        <strong>Selecciona el cliente y después una referencia</strong>
                        <p>Cuando elijas una coincidencia verás aquí si estás añadiendo un pallet completo o un pico concreto.</p>
                    </article>

                    <div class="wms-variant-actions">
                        <label class="auth-field wms-quantity-field">
                            <span data-dispatch-picker-label>Cantidad</span>
                            <input type="number" min="1" step="1" value="1" class="auth-input" data-dispatch-picker-quantity>
                        </label>

                        <button type="button" class="button-primary compact-button btn-compact" data-dispatch-add-selected>
                            Añadir a salida
                        </button>
                    </div>

                    <p class="helper-text" data-dispatch-picker-feedback>
                        Selecciona el cliente primero para filtrar referencias y evitar errores de operativa.
                    </p>
                </section>

                <div class="merchandise-request-hidden-inputs" data-dispatch-hidden-inputs></div>
                <script type="application/json" data-dispatch-items>@json($selectedItems)</script>

                <label class="auth-field">
                    <span>Observaciones generales</span>
                    <textarea name="notes" class="auth-input" rows="4" maxlength="2000" placeholder="Opcional">{{ old('notes') }}</textarea>
                </label>

                <label class="auth-field">
                    <span>
                        <input type="hidden" name="camion_propio" value="0">
                        <input type="checkbox" name="camion_propio" value="1" @checked(old('camion_propio'))>
                        Cami&oacute;n propio
                    </span>
                    <small class="helper-text">Marcar si la entrega/salida se realiza con cami&oacute;n propio de MAXIMO.</small>
                </label>
            </section>

            <aside class="surface-card compact-card merchandise-request-summary-card wms-flow-card">
                <div class="wms-section-head">
                    <div>
                        <strong>Resumen de salida</strong>
                        <p class="merchandise-request-summary-copy">Edita cantidades y revisa con claridad qué sale como pallet y qué sale como pico.</p>
                    </div>
                </div>

                <div class="wms-summary-kpis">
                    <div class="wms-kpi-tile">
                        <span>Líneas</span>
                        <strong data-dispatch-summary-lines>0</strong>
                    </div>
                    <div class="wms-kpi-tile">
                        <span>Pallets</span>
                        <strong data-dispatch-summary-pallets>0</strong>
                    </div>
                    <div class="wms-kpi-tile">
                        <span>Picos</span>
                        <strong data-dispatch-summary-peaks>0</strong>
                    </div>
                </div>

                <div class="merchandise-request-summary-empty" data-dispatch-summary-empty>
                    Todavía no hay líneas en la salida.
                </div>

                <div class="wms-line-editor-list" data-dispatch-summary-rows></div>

                <div class="item-filter-actions action-buttons page-actions-compact merchandise-request-submit">
                    <button type="submit" class="button-primary compact-button btn-compact" data-dispatch-submit>Registrar salida</button>
                    <a href="{{ route('dispatches.index') }}" class="button-secondary compact-button btn-compact">Cancelar</a>
                </div>
            </aside>
        </div>
    </form>
@endsection
