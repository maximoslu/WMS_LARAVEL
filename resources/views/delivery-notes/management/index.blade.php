@extends('layouts.dashboard')

@section('title', 'Gestion de albaranes | MAXIMO WMS')
@section('topbar_title', 'Gestion de albaranes')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Gestion'],
            ['label' => 'Albaranes'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Albaranes</h2>
            <span class="ops-page-meta">{{ $documents->total() }} documentos</span>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('delivery-notes.management.index') }}" class="stock-filters compact-filters filters-compact goods-receipts-filter-form">
            <label class="auth-field">
                <span>Cliente</span>
                <select name="client_id" class="auth-input" required>
                    <option value="">Selecciona cliente</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) $filters['client_id'] === (string) $client->id)>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="auth-field">
                <span>Tipo</span>
                <select name="type" class="auth-input">
                    @foreach ($typeOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            @if ($filters['type'] !== 'dispatch')
                <label class="auth-field">
                    <span>Proveedor</span>
                    <select name="supplier_id" class="auth-input" @disabled(! $hasClientFilter)>
                        <option value="">Todos</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((string) $filters['supplier_id'] === (string) $supplier->id)>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if ($filters['type'] !== 'entry')
                <label class="auth-field">
                    <span>Estado salida</span>
                    <select name="dispatch_status" class="auth-input">
                        <option value="">Todos</option>
                        @foreach ($dispatchStatuses as $value => $label)
                            <option value="{{ $value }}" @selected($filters['dispatch_status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="auth-field">
                <span>Fecha desde</span>
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Fecha hasta</span>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Buscar</span>
                <input
                    type="text"
                    name="search"
                    value="{{ $filters['search'] }}"
                    class="auth-input"
                    placeholder="Numero, proveedor, pedido, destino"
                >
            </label>

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Buscar</button>
                <a href="{{ route('delivery-notes.management.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if (! $hasClientFilter)
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Consulta controlada</span>
            <h3>Selecciona un cliente para buscar albaranes</h3>
            <p>No se cargan documentos de todos los clientes sin criterio.</p>
        </article>
    @elseif ($documents->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay albaranes con estos filtros</h3>
            <p>Ajusta cliente, fechas, tipo o busqueda para localizar el documento.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact goods-receipts-table" aria-label="Gestion interna de albaranes">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Cliente</th>
                            <th>Fecha</th>
                            <th>Albaran</th>
                            <th>Numero interno</th>
                            <th>Proveedor</th>
                            <th>Estado</th>
                            <th>Pedido / referencia</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr>
                                <td>
                                    <span class="receipt-status-pill receipt-status-pill--{{ $document['type'] }}">
                                        {{ $document['type_label'] }}
                                    </span>
                                </td>
                                <td>{{ $document['client'] }}</td>
                                <td>{{ $document['date_label'] }}</td>
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $document['display_name'] }}</strong>
                                    </div>
                                </td>
                                <td>{{ $document['number'] }}</td>
                                <td>{{ $document['supplier'] ?: '-' }}</td>
                                <td>{{ $document['status'] ?: '-' }}</td>
                                <td>{{ $document['related'] ?: '-' }}</td>
                                <td>
                                    <div class="inline-actions action-buttons">
                                        <a href="{{ $document['download_url'] }}" class="button-secondary compact-button btn-table">Descargar</a>
                                        <a href="{{ $document['detail_url'] }}" class="button-secondary compact-button btn-table">Abrir origen</a>
                                        @if ($document['request_url'])
                                            <a href="{{ $document['request_url'] }}" class="button-secondary compact-button btn-table">Pedido</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($documents->hasPages())
            <div class="pagination-card surface-card compact-card">
                {{ $documents->links() }}
            </div>
        @endif
    @endif
@endsection
