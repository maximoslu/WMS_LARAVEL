@extends('layouts.dashboard')

@section('title', 'Solicitudes | MAXIMO WMS')
@section('topbar_title', 'Solicitudes')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Operaciones'],
            ['label' => $isClient ? 'Solicitudes' : 'Bookings'],
        ];

        $visibleBookings = $bookings->getCollection();
        $visiblePallets = $visibleBookings->sum(fn ($booking) => (int) ($booking->pallets_expected ?? 0));
        $visibleActionable = $visibleBookings
            ->filter(fn ($booking) => in_array($booking->status, ['solicitado', 'aprobado', 'planificado'], true))
            ->count();
        $activeFilterBadges = [];

        if (! $isClient && filled($filters['client_id'] ?? null)) {
            $filterClient = $clients->firstWhere('id', (int) $filters['client_id']);
            $activeFilterBadges[] = 'Cliente: '.($filterClient?->name ?? $filters['client_id']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $activeFilterBadges[] = 'Desde: '.$filters['date_from'];
        }

        if (filled($filters['date_to'] ?? null)) {
            $activeFilterBadges[] = 'Hasta: '.$filters['date_to'];
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $activeFilterBadges[] = 'Estado: '.($statusOptions[$filters['status']] ?? $filters['status']);
        }

        if (($filters['type'] ?? 'all') !== 'all') {
            $activeFilterBadges[] = 'Tipo: '.($typeOptions[$filters['type']] ?? $filters['type']);
        }

        if (filled($filters['search'] ?? null)) {
            $activeFilterBadges[] = 'Busqueda: '.$filters['search'];
        }
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-booking-page">
        <section class="surface-card compact-card wms-list-header wms-booking-header">
            <div class="wms-list-headline">
                <span class="wms-list-kicker">Operaciones planificadas</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">{{ $isClient ? 'Mis solicitudes de mercancia' : 'Bookings operativos' }}</h2>
                    <span class="wms-list-count">{{ $bookings->total() }} {{ $isClient ? 'solicitudes' : 'registros' }}</span>
                </div>
                <p class="wms-list-subtitle">
                    {{ $isClient ? 'Consulta, filtra y crea solicitudes de entrada o salida previstas.' : 'Listado operativo para coordinar fecha, hora, cliente, transporte, estado y sincronizacion de agenda.' }}
                </p>
            </div>

            <div class="wms-list-actions booking-header-actions">
                <dl class="wms-list-metrics" aria-label="Resumen visible de bookings">
                    <div>
                        <dt>Total</dt>
                        <dd>{{ $bookings->total() }}</dd>
                    </div>
                    <div>
                        <dt>Vista</dt>
                        <dd>{{ $bookings->count() }}</dd>
                    </div>
                    <div>
                        <dt>Pallets</dt>
                        <dd>{{ number_format($visiblePallets, 0, ',', '.') }}</dd>
                    </div>
                </dl>
                <a href="{{ route('bookings.calendar') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Ver agenda</a>
                @if ($canCreate)
                    <a href="{{ route('bookings.create') }}" class="button-primary compact-button btn-compact wms-action-primary wms-list-primary-action">Nueva solicitud</a>
                @endif
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if (session('warning'))
            <div class="alert alert-error">{{ session('warning') }}</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel booking-filter-panel">
            <form method="GET" action="{{ route('bookings.index') }}" class="item-filter-form compact-filters filters-compact wms-filter-grid booking-filter-form">
                @unless ($isClient)
                    <label class="auth-field">
                        <span>Cliente</span>
                        <select name="client_id" class="auth-input">
                            <option value="">Todos los clientes</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected((string) $filters['client_id'] === (string) $client->id)>{{ $client->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endunless

                <label class="auth-field">
                    <span>Fecha desde</span>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="auth-input">
                </label>

                <label class="auth-field">
                    <span>Fecha hasta</span>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="auth-input">
                </label>

                <label class="auth-field">
                    <span>Estado</span>
                    <select name="status" class="auth-input">
                        <option value="all">Todos</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="auth-field">
                    <span>Tipo</span>
                    <select name="type" class="auth-input">
                        <option value="all">Todos</option>
                        @foreach ($typeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="auth-field booking-filter-search wms-filter-search">
                    <span>{{ $isClient ? 'Codigo o referencia' : 'Codigo, transportista o matricula' }}</span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="auth-input" placeholder="{{ $isClient ? 'Buscar solicitud' : 'Buscar booking' }}">
                </label>

                <div class="item-filter-actions action-buttons page-actions-compact wms-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact wms-action-primary">Filtrar</button>
                    <a href="{{ route('bookings.index') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Limpiar</a>
                </div>
            </form>

            <div class="wms-filter-summary" aria-label="Filtros activos">
                @forelse ($activeFilterBadges as $filterBadge)
                    <span class="wms-filter-token">{{ $filterBadge }}</span>
                @empty
                    <span class="wms-filter-muted">Sin filtros activos</span>
                @endforelse
            </div>
        </section>

        @if ($bookings->isEmpty())
            <article class="surface-card compact-card wms-empty-state booking-empty-state">
                <div>
                    <span class="wms-status-chip wms-status-chip--neutral">{{ $isClient ? 'Sin solicitudes' : 'Sin bookings' }}</span>
                    <h3>{{ $isClient ? 'Todavia no has registrado solicitudes' : 'No hay bookings con estos filtros' }}</h3>
                    <p>{{ $isClient ? 'Cuando registres una nueva entrada o salida prevista aparecera aqui para su seguimiento.' : 'Ajusta los filtros para localizar bookings operativos.' }}</p>
                </div>
                @if ($canCreate)
                    <a href="{{ route('bookings.create') }}" class="button-primary compact-button btn-compact wms-action-primary">Nueva solicitud</a>
                @endif
            </article>
        @else
            <section class="surface-card compact-card wms-table-panel booking-table-panel">
                <div class="wms-table-toolbar booking-table-toolbar">
                    <div>
                        <strong>{{ $isClient ? 'Solicitudes filtradas' : 'Agenda operativa filtrada' }}</strong>
                        <span>{{ $bookings->count() }} en esta pagina de {{ $bookings->total() }} totales</span>
                    </div>
                    <div class="wms-table-totals booking-table-totals">
                        <span>{{ $visibleActionable }} requieren seguimiento</span>
                    </div>
                </div>

                <div class="data-table-wrap wms-table-wrap booking-table-wrap">
                    <table class="data-table table-compact wms-data-table booking-table" aria-label="Listado de bookings">
                        <thead>
                            <tr>
                                <th>Codigo</th>
                                @unless ($isClient)
                                    <th>Cliente</th>
                                @endunless
                                <th>Tipo</th>
                                <th>Fecha / hora</th>
                                <th class="wms-table-number">Pallets</th>
                                @unless ($isClient)
                                    <th>Transportista</th>
                                    <th>Matricula</th>
                                @endunless
                                <th>Estado</th>
                                @unless ($isClient)
                                    <th>Google Calendar</th>
                                @endunless
                                <th class="wms-table-actions-cell">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bookings as $booking)
                                <tr>
                                    <td>
                                        <div class="booking-code-cell">
                                            <strong>{{ $booking->referenceCode() }}</strong>
                                            <span>{{ $booking->typeLabel() }}</span>
                                        </div>
                                    </td>
                                    @unless ($isClient)
                                        <td>{{ $booking->client?->name ?? 'Sin cliente' }}</td>
                                    @endunless
                                    <td><span class="wms-status-chip wms-status-chip--{{ $booking->type }}">{{ $booking->typeLabel() }}</span></td>
                                    <td>
                                        <span class="booking-time-value">{{ $booking->scheduledWindowLabel() }}</span>
                                    </td>
                                    <td class="wms-table-number">{{ number_format($booking->pallets_expected ?? 0, 0, ',', '.') }}</td>
                                    @unless ($isClient)
                                        <td>{{ $booking->carrier_name ?: '-' }}</td>
                                        <td>{{ $booking->vehicle_plate ?: 'Pendiente' }}</td>
                                    @endunless
                                    <td>
                                        <span class="status-badge merchandise-request-status merchandise-request-status--{{ $booking->status }} wms-status-badge wms-status-chip wms-status-chip--{{ $booking->status }}">
                                            {{ $booking->statusLabel() }}
                                        </span>
                                    </td>
                                    @unless ($isClient)
                                        @php
                                            $googleState = match ($booking->googleCalendarSyncState()) {
                                                'synced', 'cancelled' => 'connected',
                                                'error' => 'error',
                                                default => 'pending',
                                            };
                                        @endphp
                                        <td>
                                            <span class="status-badge dashboard-google-status dashboard-google-status--{{ $googleState }} wms-status-chip booking-google-chip booking-google-chip--{{ $googleState }}">
                                                {{ $booking->googleCalendarSyncLabel() }}
                                            </span>
                                        </td>
                                    @endunless
                                    <td class="wms-table-actions-cell">
                                        <div class="inline-actions action-buttons wms-row-actions booking-row-actions">
                                            <a href="{{ route('bookings.show', $booking) }}" class="button-secondary compact-button btn-table">Ver</a>
                                            <a href="{{ route('bookings.edit', $booking) }}" class="button-secondary compact-button btn-table">Editar</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($bookings->hasPages())
            <div class="pagination-card surface-card compact-card">
                {{ $bookings->links() }}
            </div>
        @endif
    </div>
@endsection
