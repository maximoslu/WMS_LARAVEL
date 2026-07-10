@extends('layouts.dashboard')

@section('title', 'NUEVO PEDIDO | MAXIMO WMS')
@section('topbar_title', 'NUEVO PEDIDO')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'PEDIDOS', 'href' => route('merchandise-requests.index')],
            ['label' => 'NUEVO PEDIDO'],
        ];
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact compact-card merchandise-request-page-head">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">NUEVO PEDIDO</h2>
            <span class="ops-page-meta">{{ $client?->name ?? 'Cliente no asignado' }}</span>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-error">
            {{ $errors->first('lines') ?: 'Revisa las líneas del pedido antes de enviarlo.' }}
        </div>
    @endif

    @if ($contractualWindowWarning)
        <div class="alert alert-warning">
            {{ $contractualWindowWarning }}
        </div>
    @endif

    @if (! $hasActiveItems)
        <article class="surface-card compact-card wms-empty-state">
            <h3>Sin referencias activas</h3>
            <p>No hay artículos disponibles para crear pedidos.</p>
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

            <section class="surface-card compact-card wms-flow-card merchandise-request-new-shell">
                <input type="hidden" name="camion_propio" value="0">

                <div class="merchandise-request-header-strip">
                    <div>
                        <span>Cliente</span>
                        <strong>{{ $client?->name ?? 'Cliente no asignado' }}</strong>
                    </div>
                </div>

                <section class="merchandise-request-entry-row" aria-label="Nueva línea de pedido">
                    <label class="auth-field merchandise-request-reference-field">
                        <span>Referencia / SKU</span>
                        <div
                            class="ajax-autocomplete"
                            data-ajax-autocomplete
                            data-endpoint="{{ $searchEndpoint }}"
                            data-min-chars="2"
                            data-empty-message="Escribe 2 caracteres."
                            data-no-results-message="Sin resultados"
                            data-searching-message="Buscando..."
                            data-error-message="Error al buscar"
                            data-request-item-picker
                        >
                            <div class="ajax-autocomplete-control">
                                <input
                                    type="text"
                                    class="auth-input"
                                    placeholder="Buscar referencia..."
                                    autocomplete="off"
                                    data-autocomplete-input
                                >
                                <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear hidden>Limpiar</button>
                            </div>
                            <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                                <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe 2 caracteres.</div>
                                <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                            </div>
                        </div>
                    </label>

                    <div hidden data-request-selection-preview></div>

                    <label class="auth-field wms-quantity-field merchandise-request-entry-quantity">
                        <span data-request-picker-label>Cantidad</span>
                        <input type="number" min="1" step="1" value="1" class="auth-input" data-request-picker-quantity>
                    </label>

                    <button type="button" class="button-primary compact-button btn-compact merchandise-request-entry-add" data-request-add-selected>
                        Añadir línea
                    </button>

                    <p class="helper-text" data-request-search-feedback>Busca una referencia.</p>
                </section>

                <div class="merchandise-request-hidden-inputs" data-request-hidden-inputs></div>
                <script type="application/json" data-request-selected-items>@json($selectedItems)</script>

                <div class="merchandise-request-lines-head">
                    <strong>Líneas</strong>
                    <div class="merchandise-request-totals-inline" aria-label="Resumen del pedido">
                        <span><strong data-request-summary-lines>0</strong> líneas</span>
                        <span><strong data-request-summary-pallets>0</strong> pallets</span>
                        <span><strong data-request-summary-peaks>0</strong> picos</span>
                    </div>
                </div>

                <div class="merchandise-request-line-table-head" aria-hidden="true">
                    <span>Línea</span>
                    <span>Referencia</span>
                    <span>Pallets</span>
                    <span>Picos</span>
                    <span></span>
                </div>

                <div class="merchandise-request-summary-empty merchandise-request-summary-empty--compact" data-request-summary-empty>
                    Sin líneas.
                </div>

                <div class="merchandise-request-summary-list merchandise-request-line-list" data-request-summary-rows></div>

                <div class="item-filter-actions action-buttons page-actions-compact merchandise-request-submit merchandise-request-submit--inline">
                    <button type="submit" class="button-primary compact-button btn-compact" data-request-submit disabled>
                        ENVIAR PEDIDO
                    </button>
                    <a href="{{ route('merchandise-requests.index') }}" class="button-secondary compact-button btn-compact">Cancelar</a>
                </div>
            </section>
        </form>
    @endif

    <section class="surface-card compact-card client-pending-orders">
        <div class="client-pending-orders-head">
            <strong>PEDIDOS PENDIENTES</strong>
            <span>{{ $pendingRequests->count() }}</span>
        </div>

        @if ($pendingRequests->isEmpty())
            <div class="client-pending-orders-empty">Sin pedidos pendientes.</div>
        @else
            <div class="client-pending-orders-table-wrap">
                <table class="data-table table-compact client-pending-orders-table" aria-label="Pedidos pendientes">
                    <thead>
                        <tr>
                            <th>Nº pedido</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th>Líneas / pallets</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingRequests as $pendingRequest)
                            <tr>
                                <td><strong>{{ $pendingRequest->referenceCode() }}</strong></td>
                                <td>{{ $pendingRequest->submittedAt()?->format('d/m/Y') }}</td>
                                <td>
                                    <span class="status-badge merchandise-request-status merchandise-request-status--{{ $pendingRequest->status }}">
                                        {{ $pendingRequest->statusLabel() }}
                                    </span>
                                </td>
                                <td>
                                    {{ $pendingRequest->lines->count() }} {{ $pendingRequest->lines->count() === 1 ? 'línea' : 'líneas' }} /
                                    {{ number_format($pendingRequest->requestedPalletsCount(), 0, ',', '.') }} pallets
                                </td>
                                <td class="table-actions-cell">
                                    <a href="{{ route('merchandise-requests.show', $pendingRequest) }}" class="button-secondary compact-button btn-table">Ver</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
