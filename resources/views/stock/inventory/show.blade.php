@extends('layouts.dashboard')

@section('title', 'Inventario '.$inventorySession->client->name.' | MAXIMO WMS')
@section('topbar_title', $inventorySession->isInProgress() ? 'INVENTARIO EN CURSO' : 'INVENTARIO FINALIZADO')

@section('content')
    @php
        $isCompleted = ! $inventorySession->isInProgress();
        $statusLabels = [
            'pending' => 'Pendiente',
            'checked' => 'Comprobada',
            'needs_review' => 'Revisar de nuevo',
        ];
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Stock', 'href' => route('stock.index', ['client_id' => $inventorySession->client_id])],
            ['label' => 'Inventario', 'href' => route('stock.inventory.index', ['client_id' => $inventorySession->client_id])],
            ['label' => $isCompleted ? 'Histórico' : 'En curso'],
        ];
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-inventory-page wms-inventory-session-page">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif

        <section class="surface-card compact-card wms-list-header wms-inventory-session-header">
            <div>
                <span class="wms-list-eyebrow">{{ $isCompleted ? 'Inventario finalizado' : 'Inventario en curso' }}</span>
                <h2>{{ $inventorySession->client->name }}</h2>
                <p class="wms-list-subtitle">
                    Iniciado {{ $inventorySession->started_at?->format('d/m/Y H:i') }} por {{ $inventorySession->starter?->name ?? 'Usuario no disponible' }}.
                    @if ($isCompleted)
                        Finalizado {{ $inventorySession->completed_at?->format('d/m/Y H:i') }}.
                    @else
                        El progreso se guarda automáticamente.
                    @endif
                </p>
            </div>
            <div class="wms-list-actions">
                <a href="{{ route('stock.inventory.index', ['client_id' => $inventorySession->client_id]) }}" class="button-secondary compact-button btn-compact">Volver a inventario</a>
            </div>
        </section>

        <section class="wms-inventory-session-summary" aria-label="Progreso del inventario">
            <div><span>Total</span><strong>{{ $summary['total'] }}</strong></div>
            <div><span>Comprobadas</span><strong>{{ $summary['checked'] }}</strong></div>
            <div><span>Pendientes</span><strong>{{ $summary['pending'] }}</strong></div>
            <div class="{{ $summary['needs_review'] > 0 ? 'is-warning' : '' }}"><span>Revisar de nuevo</span><strong>{{ $summary['needs_review'] }}</strong></div>
            <div class="wms-inventory-progress-metric">
                <span>Progreso</span>
                <strong>{{ number_format($summary['progress'], 1, ',', '.') }}%</strong>
                <progress max="100" value="{{ min(100, $summary['progress']) }}">{{ $summary['progress'] }}%</progress>
            </div>
        </section>

        <section class="surface-card compact-card wms-inventory-session-filters">
            <form method="GET" action="{{ route('stock.inventory.show', $inventorySession) }}">
                <label class="auth-field">
                    <span>Mostrar</span>
                    <select name="status" class="auth-input">
                        <option value="all" @selected($filters['status'] === 'all')>Todas</option>
                        <option value="pending" @selected($filters['status'] === 'pending')>Pendientes</option>
                        <option value="checked" @selected($filters['status'] === 'checked')>Comprobadas</option>
                        <option value="needs_review" @selected($filters['status'] === 'needs_review')>Revisar de nuevo</option>
                    </select>
                </label>
                <label class="auth-field">
                    <span>Almacén</span>
                    <select name="warehouse_id" class="auth-input">
                        <option value="">Todos</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse['id'] }}" @selected($filters['warehouse_id'] === (string) $warehouse['id'])>
                                {{ $warehouse['name'] ?: $warehouse['code'] }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="auth-field">
                    <span>Ordenar por</span>
                    <select name="order" class="auth-input">
                        <option value="location" @selected($filters['order'] === 'location')>Ubicación</option>
                        <option value="reference" @selected($filters['order'] === 'reference')>Referencia / alfabético</option>
                    </select>
                </label>
                <button type="submit" class="button-primary compact-button btn-compact">Aplicar</button>
            </form>
        </section>

        @if ($locations->isEmpty())
            <section class="surface-card compact-card wms-empty-state wms-inventory-empty">
                No hay ubicaciones que coincidan con el filtro seleccionado.
            </section>
        @else
            <section class="wms-inventory-location-list">
                @foreach ($locations as $entry)
                    @php
                        $location = $entry['model'];
                        $status = $entry['status'];
                    @endphp
                    <article class="surface-card compact-card wms-inventory-location-card is-{{ $status }}">
                        <div class="wms-inventory-location-main">
                            <div class="wms-inventory-location-title">
                                <span class="wms-inventory-status-dot wms-inventory-status-dot--{{ $status }}"></span>
                                <div>
                                    <strong>{{ $location->location_label }}</strong>
                                    <span>{{ $location->warehouse_name ?: ($location->warehouse_code ?: 'Sin almacén') }}</span>
                                </div>
                            </div>
                            <span class="wms-inventory-state-label">{{ $statusLabels[$status] }}</span>
                        </div>

                        <div class="wms-inventory-location-metrics">
                            <span><strong>{{ $entry['references'] }}</strong> referencias</span>
                            <span><strong>{{ $entry['full_pallets'] }}</strong> pallets</span>
                            <span><strong>{{ $entry['peaks'] }}</strong> picos / {{ $entry['peak_units'] }} uds</span>
                            <span><strong>{{ number_format($entry['total_units'], 0, ',', '.') }}</strong> uds totales</span>
                        </div>

                        @if ($location->checked_at)
                            <p class="wms-inventory-check-meta">
                                {{ $status === 'needs_review' ? 'Última comprobación' : 'Comprobada' }}
                                {{ $location->checked_at->format('d/m/Y H:i') }} por {{ $location->checker?->name ?? 'Usuario no disponible' }}.
                                @if ($status === 'needs_review')
                                    Se ha registrado al menos un movimiento físico posterior.
                                @endif
                            </p>
                        @endif

                        <details class="wms-inventory-location-detail">
                            <summary>Ver detalle teórico actual</summary>
                            @if ($entry['rows']->isEmpty())
                                <p>Actualmente no hay stock teórico que coincida con el alcance en esta ubicación.</p>
                            @else
                                <div class="wms-inventory-reference-list">
                                    @foreach ($entry['rows'] as $row)
                                        <div class="wms-inventory-reference-row">
                                            <span>
                                                <strong>{{ $row['sku'] }}</strong>
                                                <small>{{ $row['description'] }} · Lote {{ $row['lot_label'] }}</small>
                                            </span>
                                            <span>{{ $row['full_pallets'] }} pallets</span>
                                            <span>{{ $row['peaks_count'] }} picos / {{ $row['peak_units'] }} uds</span>
                                            <span>{{ number_format($row['quantity_units'], 0, ',', '.') }} uds</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </details>

                        @if ($location->checked_at && is_array($location->checked_snapshot))
                            <details class="wms-inventory-location-detail wms-inventory-location-snapshot">
                                <summary>Ver fotografía de la última comprobación</summary>
                                @if ($location->checked_snapshot === [])
                                    <p>La ubicación no tenía stock teórico dentro del alcance cuando se comprobó.</p>
                                @else
                                    <div class="wms-inventory-reference-list">
                                        @foreach ($location->checked_snapshot as $snapshotRow)
                                            <div class="wms-inventory-reference-row">
                                                <span>
                                                    <strong>{{ $snapshotRow['sku'] }}</strong>
                                                    <small>{{ $snapshotRow['description'] }} · Lote {{ $snapshotRow['lot'] }}</small>
                                                </span>
                                                <span>{{ $snapshotRow['full_pallets'] }} pallets</span>
                                                <span>{{ $snapshotRow['peaks'] }} picos / {{ $snapshotRow['peak_units'] }} uds</span>
                                                <span>{{ number_format($snapshotRow['quantity_units'], 0, ',', '.') }} uds</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </details>
                        @endif

                        @if (! $isCompleted)
                            <form method="POST" action="{{ route('stock.inventory.locations.check', [$inventorySession, $location]) }}" class="wms-inventory-check-action">
                                @csrf
                                @method('PATCH')
                                <label class="auth-field">
                                    <span>Observaciones (opcional)</span>
                                    <input type="text" name="notes" value="{{ $location->notes }}" class="auth-input" maxlength="2000" placeholder="Incidencias o notas de la comprobación">
                                </label>
                                <button type="submit" class="button-primary">
                                    {{ $status === 'pending' ? '✓ Marcar ubicación como comprobada' : '✓ Comprobar de nuevo' }}
                                </button>
                            </form>
                        @elseif (filled($location->notes))
                            <p class="wms-inventory-check-meta"><strong>Observaciones:</strong> {{ $location->notes }}</p>
                        @endif
                    </article>
                @endforeach
            </section>

            @if ($paginator->hasPages())
                <div class="surface-card compact-card wms-inventory-pagination">{{ $paginator->links() }}</div>
            @endif
        @endif

        @if (! $isCompleted)
            <section class="surface-card compact-card wms-inventory-complete-card">
                <div>
                    <strong>Finalizar inventario</strong>
                    @if ($summary['pending'] > 0 || $summary['needs_review'] > 0)
                        <p>No se puede finalizar todavía: quedan {{ $summary['pending'] }} pendientes y {{ $summary['needs_review'] }} para revisar de nuevo.</p>
                    @else
                        <p>Todas las ubicaciones están comprobadas y no tienen movimientos posteriores conocidos.</p>
                    @endif
                </div>
                @if ($summary['pending'] === 0 && $summary['needs_review'] === 0)
                    <form method="POST" action="{{ route('stock.inventory.complete', $inventorySession) }}">
                        @csrf
                        <label class="wms-inventory-complete-confirm">
                            <input type="checkbox" name="confirmed" value="1" required>
                            <span>Confirmo que quiero cerrar y conservar este inventario.</span>
                        </label>
                        <button type="submit" class="button-primary compact-button btn-compact" onclick="return confirm('¿Finalizar definitivamente este inventario?');">Finalizar inventario</button>
                    </form>
                @endif
            </section>
        @endif
    </div>
@endsection
