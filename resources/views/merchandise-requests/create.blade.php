@extends('layouts.dashboard')

@section('title', 'Solicitar mercancía | MAXIMO WMS')
@section('topbar_title', 'Solicitar mercancía')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <a href="{{ route('merchandise-requests.index') }}">Solicitudes de mercancía</a>
        <span>/</span>
        <span>Nuevo pedido</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Solicitar mercancía</h2>
            <span class="ops-page-meta">{{ $client?->name ?? 'Cliente no asignado' }}</span>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-danger">
            {{ $errors->first('quantities') ?: 'Revisa los datos del pedido antes de enviarlo.' }}
        </div>
    @endif

    @if (! $hasActiveItems)
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin mercancías</span>
            <h3>No hay mercancías disponibles para tu cliente</h3>
            <p>Cuando administración active artículos para este cliente, podrás solicitar pallets desde aquí.</p>
        </article>
    @else
        <form
            method="POST"
            action="{{ route('merchandise-requests.store') }}"
            data-merchandise-request-form
            data-search-endpoint="{{ $searchEndpoint }}"
        >
            @csrf

            <div class="merchandise-request-builder merchandise-request-builder--search">
                <section class="surface-card compact-card merchandise-request-catalog">
                    <div class="ops-section-heading">
                        <div>
                            <strong>Preparar pedido</strong>
                            <p class="merchandise-request-summary-copy">
                                Busca por SKU, referencia, lote o descripción, añade pallets y revisa el pedido antes de enviarlo.
                            </p>
                        </div>
                        <span class="ops-page-meta">Búsqueda dinámica para catálogos grandes</span>
                    </div>

                    <div class="merchandise-request-steps">
                        <article class="merchandise-request-step-card">
                            <span class="status-chip small-badge badge-compact">Paso 1</span>
                            <strong>Busca la mercancía</strong>
                            <p>Empieza a escribir para consultar solo las referencias que necesitas.</p>
                        </article>
                        <article class="merchandise-request-step-card">
                            <span class="status-chip small-badge badge-compact">Paso 2</span>
                            <strong>Indica pallets</strong>
                            <p>Selecciona la línea adecuada y marca una cantidad entera mayor que cero.</p>
                        </article>
                        <article class="merchandise-request-step-card">
                            <span class="status-chip small-badge badge-compact">Paso 3</span>
                            <strong>Confirma el pedido</strong>
                            <p>El resumen se actualiza al instante, sin cargar miles de artículos en pantalla.</p>
                        </article>
                    </div>

                    <section class="merchandise-request-search-panel" aria-label="Buscador de mercancía">
                        <label class="auth-field">
                            <span>Buscar mercancía</span>
                            <input
                                type="search"
                                class="auth-input"
                                placeholder="Buscar por SKU, referencia, lote o descripción..."
                                autocomplete="off"
                                data-request-search
                            >
                        </label>

                        <p class="helper-text" data-request-search-feedback>
                            Escribe al menos 2 caracteres para buscar en tu catálogo activo.
                        </p>

                        <div class="merchandise-request-results" data-request-results></div>
                    </section>

                    <div class="merchandise-request-hidden-inputs" data-request-hidden-inputs>
                        @foreach (old('quantities', []) as $itemId => $quantity)
                            @if ((int) $quantity > 0)
                                <input
                                    type="hidden"
                                    name="quantities[{{ $itemId }}]"
                                    value="{{ (int) $quantity }}"
                                    data-request-hidden-quantity
                                    data-item-id="{{ $itemId }}"
                                >
                            @endif
                        @endforeach
                    </div>

                    <script type="application/json" data-request-selected-items>@json($selectedItems)</script>
                </section>

                <aside class="surface-card compact-card merchandise-request-summary-card merchandise-request-summary-card--sticky">
                    <div class="ops-section-heading">
                        <div>
                            <strong>Resumen del pedido</strong>
                            <p class="merchandise-request-summary-copy">
                                Ajusta cantidades, elimina líneas si hace falta y revisa el envío antes de confirmar.
                            </p>
                        </div>
                    </div>

                    <div class="merchandise-request-summary-totals">
                        <div>
                            <span>Líneas</span>
                            <strong data-request-summary-lines>0</strong>
                        </div>
                        <div>
                            <span>Total pallets</span>
                            <strong data-request-summary-pallets>0</strong>
                        </div>
                    </div>

                    <div class="merchandise-request-summary-empty" data-request-summary-empty>
                        Todavía no hay líneas en el pedido. Busca una mercancía para empezar a añadir artículos.
                    </div>

                    <div class="merchandise-request-summary-list" data-request-summary-rows></div>

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
