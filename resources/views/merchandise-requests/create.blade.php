@extends('layouts.dashboard')

@section('title', 'Solicitar mercancia | MAXIMO WMS')
@section('topbar_title', 'Solicitar mercancia')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <a href="{{ route('merchandise-requests.index') }}">Solicitudes de mercancia</a>
        <span>/</span>
        <span>Nuevo pedido</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Solicitar mercancia</h2>
            <span class="ops-page-meta">{{ $client?->name ?? 'Cliente no asignado' }}</span>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-danger">
            {{ $errors->first('quantities') ?: 'Revisa los datos del pedido antes de enviarlo.' }}
        </div>
    @endif

    @if ($items->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin mercancias</span>
            <h3>No hay mercancias disponibles para tu cliente</h3>
            <p>Cuando administracion active articulos para este cliente, podras solicitar pallets desde aqui.</p>
        </article>
    @else
        <form method="POST" action="{{ route('merchandise-requests.store') }}" data-merchandise-request-form>
            @csrf

            <div class="merchandise-request-builder">
                <section class="surface-card compact-card merchandise-request-catalog">
                    <div class="ops-section-heading">
                        <div>
                            <strong>Preparar pedido</strong>
                            <p class="merchandise-request-summary-copy">Selecciona una mercancia, indica los pallets y anadela al pedido antes de enviarlo.</p>
                        </div>
                        <span class="ops-page-meta">{{ $items->count() }} articulos activos</span>
                    </div>

                    <div class="merchandise-request-steps">
                        <article class="merchandise-request-step-card">
                            <span class="status-chip small-badge badge-compact">Paso 1</span>
                            <strong>Selecciona la mercancia</strong>
                            <p>Elige una referencia activa de tu catalogo.</p>
                        </article>
                        <article class="merchandise-request-step-card">
                            <span class="status-chip small-badge badge-compact">Paso 2</span>
                            <strong>Indica pallets</strong>
                            <p>Solo se admiten cantidades enteras y mayores que cero.</p>
                        </article>
                        <article class="merchandise-request-step-card">
                            <span class="status-chip small-badge badge-compact">Paso 3</span>
                            <strong>Anade al pedido</strong>
                            <p>Revisa el resumen y envia cuando todo este correcto.</p>
                        </article>
                    </div>

                    <section class="merchandise-request-picker" aria-label="Selector de mercancia">
                        <label class="auth-field">
                            <span>Mercancia</span>
                            <select class="auth-input" data-request-picker-item>
                                <option value="">Selecciona una referencia</option>
                                @foreach ($items as $item)
                                    <option value="{{ $item->id }}">{{ $item->sku }} - {{ $item->description }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="auth-field merchandise-request-picker-quantity">
                            <span>Pallets</span>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                value="1"
                                class="auth-input"
                                data-request-picker-quantity
                            >
                        </label>

                        <button type="button" class="button-primary compact-button btn-compact" data-request-add-selected>
                            Anadir al pedido
                        </button>
                    </section>

                    <p class="helper-text" data-request-picker-feedback>
                        Puedes anadir varias lineas. Si repites una mercancia, se actualizara la cantidad en el resumen.
                    </p>

                    <div class="merchandise-request-hidden-inputs" data-request-hidden-inputs>
                        @foreach (old('quantities', []) as $itemId => $quantity)
                            @if ((int) $quantity > 0)
                                <input type="hidden" name="quantities[{{ $itemId }}]" value="{{ (int) $quantity }}" data-request-hidden-quantity data-item-id="{{ $itemId }}">
                            @endif
                        @endforeach
                    </div>

                    <div class="merchandise-request-catalog-grid">
                        @foreach ($items as $item)
                            <article
                                class="merchandise-request-item-card"
                                data-request-item-card
                                data-item-id="{{ $item->id }}"
                                data-item-sku="{{ $item->sku }}"
                                data-item-description="{{ $item->description }}"
                                data-item-lot="{{ $item->lot }}"
                                data-units-per-pallet="{{ $item->units_per_pallet }}"
                            >
                                <div class="merchandise-request-item-head">
                                    <div>
                                        <span class="module-tag small-badge badge-compact">{{ $client?->name }}</span>
                                        <h3>{{ $item->sku }}</h3>
                                    </div>
                                    <span class="status-badge status-badge--active">Disponible</span>
                                </div>

                                <p class="merchandise-request-item-description">{{ $item->description }}</p>

                                <dl class="merchandise-request-item-meta">
                                    <div>
                                        <dt>Lote</dt>
                                        <dd>{{ $item->lot ?: 'Sin lote' }}</dd>
                                    </div>
                                    <div>
                                        <dt>Unidades por pallet</dt>
                                        <dd>{{ number_format($item->units_per_pallet, 0, ',', '.') }}</dd>
                                    </div>
                                </dl>

                                <div class="merchandise-request-item-footer">
                                    <label class="auth-field merchandise-request-quantity-field">
                                        <span>Pallets</span>
                                        <input
                                            type="number"
                                            min="1"
                                            step="1"
                                            value="{{ max(1, (int) old('quantities.'.$item->id, 1)) }}"
                                            class="auth-input"
                                            data-request-quantity
                                            data-item-id="{{ $item->id }}"
                                        >
                                    </label>

                                    <button
                                        type="button"
                                        class="button-secondary compact-button btn-compact"
                                        data-request-add-item
                                        data-item-id="{{ $item->id }}"
                                    >
                                        Anadir
                                    </button>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                <aside class="surface-card compact-card merchandise-request-summary-card">
                    <div class="ops-section-heading">
                        <div>
                            <strong>Resumen del pedido</strong>
                            <p class="merchandise-request-summary-copy">Ajusta cantidades y revisa el envio antes de confirmar.</p>
                        </div>
                    </div>

                    <div class="merchandise-request-summary-totals">
                        <div>
                            <span>Lineas</span>
                            <strong data-request-summary-lines>0</strong>
                        </div>
                        <div>
                            <span>Total pallets</span>
                            <strong data-request-summary-pallets>0</strong>
                        </div>
                    </div>

                    <div class="merchandise-request-summary-empty" data-request-summary-empty>
                        Todavia no hay lineas en el pedido. Usa el selector superior o los botones de cada tarjeta para anadir mercancia.
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
                            <tbody data-request-summary-rows></tbody>
                        </table>
                    </div>

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
