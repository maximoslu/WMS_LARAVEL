@extends('layouts.dashboard')

@section('title', 'Solicitar mercancía | MAXIMO WMS')
@section('topbar_title', 'Solicitar mercancía')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Solicitudes de mercancia', 'href' => route('merchandise-requests.index')],
            ['label' => 'Nuevo pedido'],
        ];
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card compact-card wms-flow-hero">
        <div class="wms-flow-hero-copy">
            <span class="status-chip">Pedido de salida</span>
            <h2 class="ops-page-title page-title-compact">Solicitar mercancía con selección clara de pallets y picos</h2>
            <p>
                Busca por SKU, referencia o descripción, revisa la partida cuando exista stock desglosado y añade cada línea con la forma de salida que realmente necesitas.
            </p>
        </div>
        <div class="wms-flow-hero-side">
            <div class="wms-kpi-tile">
                <span>Cliente</span>
                <strong>{{ $client?->name ?? 'Cliente no asignado' }}</strong>
            </div>
            <div class="wms-kpi-tile">
                <span>Operativa</span>
                <strong>Pallets y picos</strong>
            </div>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-error">
            {{ $errors->first('lines') ?: 'Revisa los datos del pedido antes de enviarlo.' }}
        </div>
    @endif

    @if (! $hasActiveItems)
        <article class="surface-card compact-card wms-empty-state">
            <span class="status-chip">Sin mercancías</span>
            <h3>No hay referencias activas para tu cliente</h3>
            <p>Cuando administración active artículos para este cliente, podrás solicitar pallets o picos desde aquí.</p>
        </article>
    @else
        <form
            method="POST"
            action="{{ route('merchandise-requests.store') }}"
            data-merchandise-request-form
            data-search-endpoint="{{ $searchEndpoint }}"
            data-client-id="{{ $client?->id }}"
        >
            @csrf

            <div class="merchandise-request-builder merchandise-request-builder--search">
                <section class="surface-card compact-card merchandise-request-catalog wms-flow-card">
                    <div class="wms-section-head">
                        <div>
                            <strong>Preparar pedido</strong>
                            <p class="merchandise-request-summary-copy">
                                Cuando exista stock desglosado verás la partida concreta, si es pallet completo o pico, el lote, la ubicación y la disponibilidad visible.
                            </p>
                        </div>
                        <span class="ops-page-meta">Pensado para usuarios poco técnicos</span>
                    </div>

                    <div class="merchandise-request-steps">
                        <article class="merchandise-request-step-card">
                            <span class="status-chip">1</span>
                            <strong>Busca la referencia</strong>
                            <p>El listado muestra coincidencias reales, no un input ciego.</p>
                        </article>
                        <article class="merchandise-request-step-card">
                            <span class="status-chip">2</span>
                            <strong>Elige el tipo</strong>
                            <p>Puedes añadir un pallet completo o un pico concreto si está disponible.</p>
                        </article>
                        <article class="merchandise-request-step-card">
                            <span class="status-chip">3</span>
                            <strong>Confirma el resumen</strong>
                            <p>Antes de enviar, edita o elimina líneas desde el panel derecho.</p>
                        </article>
                    </div>

                    <section class="wms-variant-picker" aria-label="Buscador de mercancía">
                        <div class="auth-field">
                            <span>Buscar mercancía</span>
                            <div
                                class="ajax-autocomplete"
                                data-ajax-autocomplete
                                data-endpoint="{{ $searchEndpoint }}"
                                data-min-chars="2"
                                data-empty-message="Escribe al menos 2 caracteres para buscar en tu catálogo activo."
                                data-no-results-message="Sin resultados"
                                data-searching-message="Buscando..."
                                data-error-message="Error al buscar"
                                data-request-item-picker
                            >
                                <div class="ajax-autocomplete-control">
                                    <input
                                        type="text"
                                        class="auth-input"
                                        placeholder="Buscar por SKU, referencia o descripción..."
                                        autocomplete="off"
                                        data-autocomplete-input
                                    >
                                    <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear hidden>Limpiar</button>
                                </div>
                                <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                                    <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe al menos 2 caracteres...</div>
                                    <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                                </div>
                            </div>
                        </div>

                        <article class="wms-variant-preview" data-request-selection-preview>
                            <strong>Selecciona una referencia para ver el detalle</strong>
                            <p>Lote, unidades, disponibilidad y tipo de línea aparecerán aquí antes de añadirla.</p>
                        </article>

                        <div class="wms-variant-actions">
                            <label class="auth-field wms-quantity-field">
                                <span data-request-picker-label>Cantidad</span>
                                <input type="number" min="1" step="1" value="1" class="auth-input" data-request-picker-quantity>
                            </label>

                            <button type="button" class="button-primary compact-button btn-compact" data-request-add-selected>
                                Añadir al pedido
                            </button>
                        </div>

                        <p class="helper-text" data-request-search-feedback>
                            Escribe al menos 2 caracteres para buscar en tu catálogo activo.
                        </p>
                        <p class="wms-inline-note">
                            Si necesitas varios picos, añade cada pico disponible como línea independiente para que quede claro en preparación y salida.
                        </p>
                    </section>

                    <div class="merchandise-request-hidden-inputs" data-request-hidden-inputs></div>
                    <script type="application/json" data-request-selected-items>@json($selectedItems)</script>
                </section>

                <aside class="surface-card compact-card merchandise-request-summary-card merchandise-request-summary-card--sticky wms-flow-card">
                    <div class="wms-section-head">
                        <div>
                            <strong>Resumen del pedido</strong>
                            <p class="merchandise-request-summary-copy">
                                Revisa líneas, distingue pallets de picos y corrige cantidades antes de enviar.
                            </p>
                        </div>
                    </div>

                    <div class="wms-summary-kpis">
                        <div class="wms-kpi-tile">
                            <span>Líneas</span>
                            <strong data-request-summary-lines>0</strong>
                        </div>
                        <div class="wms-kpi-tile">
                            <span>Pallets</span>
                            <strong data-request-summary-pallets>0</strong>
                        </div>
                        <div class="wms-kpi-tile">
                            <span>Picos</span>
                            <strong data-request-summary-peaks>0</strong>
                        </div>
                    </div>

                    <div class="merchandise-request-summary-empty" data-request-summary-empty>
                        Todavía no hay líneas en el pedido. Busca una referencia y añade pallets completos o picos concretos según necesites.
                    </div>

                    <div class="merchandise-request-summary-list wms-line-editor-list" data-request-summary-rows></div>

                    <div class="item-filter-actions action-buttons page-actions-compact merchandise-request-submit">
                        <button type="submit" class="button-primary compact-button btn-compact" data-request-submit>
                            Enviar pedido
                        </button>
                        <a href="{{ route('merchandise-requests.index') }}" class="button-secondary compact-button btn-compact">Cancelar</a>
                    </div>
                </aside>
            </div>
        </form>
    @endif
@endsection
