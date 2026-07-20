@extends('layouts.dashboard')

@section('title', 'Operaciones diarias | MAXIMO WMS')
@section('topbar_title', 'Operaciones diarias')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Operaciones'],
            ['label' => 'Operaciones diarias'],
        ];
        $operationDateLabel = $selectedDate->format('d/m/Y');
        $selectedClientName = $selectedClient?->name ?? 'Sin cliente';
        $billingRows = collect($billingDetails);
        $billingRowsCount = $billingRows->count();
        $billingPallets = $billingRows->sum('pallets');
        $openingPallets = $day?->opening_pallets ?? 0;
        $storedPallets = $day?->stored_pallets_today ?? 0;
        $movedPallets = $day?->moved_pallets_today ?? 0;
        $expectedTomorrow = $day?->expected_pallets_tomorrow ?? 0;
        $truckManagement = $sectionTotals[\App\Models\DailyOperationLine::SECTION_GESTION_CAMION] ?? 0;
        $truckTrips = $sectionTotals[\App\Models\DailyOperationLine::SECTION_VIAJE_CAMION] ?? 0;
        $visibleSectionTotals = collect($sectionOptions)
            ->map(fn (string $label, string $section) => [
                'label' => $label,
                'value' => $sectionTotals[$section] ?? 0,
            ])
            ->filter(fn (array $section) => (int) $section['value'] !== 0);
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-daily-ops-page">
        <section class="surface-card compact-card wms-list-header wms-daily-ops-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Operaciones / Facturacion diaria</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Operaciones diarias</h2>
                    <span class="wms-list-count">{{ $operationDateLabel }}</span>
                </div>
                <p class="wms-list-subtitle">
                    Revision diaria por cliente de almacenaje, movimientos, gestiones de camion y viajes.
                </p>
            </div>

            <div class="wms-list-actions wms-daily-ops-header-actions">
                <dl class="wms-list-metrics wms-daily-ops-header-metrics" aria-label="Resumen seleccionado">
                    <div>
                        <dt>Cliente</dt>
                        <dd>{{ $selectedClientName }}</dd>
                    </div>
                    <div>
                        <dt>Detalle</dt>
                        <dd>{{ number_format($billingRowsCount, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Base manana</dt>
                        <dd>{{ number_format($expectedTomorrow, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success wms-daily-ops-notice">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error wms-daily-ops-notice">
                @foreach ($errors->all() as $message)
                    <div>{{ $message }}</div>
                @endforeach
            </div>
        @endif

        <section class="surface-card compact-card wms-filter-panel wms-daily-ops-toolbar">
            <div class="wms-daily-ops-toolbar-copy">
                <strong>Consulta operativa</strong>
                <span>Elige cliente y dia; recalcula solo cuando quieras reconstruir la foto desde entradas, salidas y stock activo.</span>
            </div>

            <div class="wms-daily-ops-toolbar-forms">
                <form method="GET" action="{{ route('daily-operations.index') }}" class="daily-ops-toolbar-form wms-daily-ops-filter-form">
                    <div class="wms-daily-ops-filter-grid">
                        <label class="auth-field">
                            <span>Cliente</span>
                            <select name="client_id" class="auth-input daily-ops-select" required>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->id }}" @selected((string) $selectedClient?->id === (string) $client->id)>{{ $client->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="auth-field">
                            <span>Dia</span>
                            <input type="date" name="date" value="{{ $selectedDate->format('Y-m-d') }}" class="auth-input">
                        </label>

                        <div class="wms-filter-actions daily-ops-toolbar-actions">
                            <button type="submit" class="button-secondary compact-button btn-compact">Ver dia</button>
                        </div>
                    </div>
                </form>

                @if ($selectedClient)
                    <form method="POST" action="{{ route('daily-operations.recalculate') }}" class="daily-ops-recalc-inline wms-daily-ops-recalc-form">
                        @csrf
                        <input type="hidden" name="operation_date" value="{{ $selectedDate->format('Y-m-d') }}">
                        <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">
                        <button type="submit" class="button-primary compact-button btn-compact">Recalcular</button>
                    </form>
                @endif
            </div>

            <div class="wms-filter-summary" aria-label="Seleccion actual">
                @if ($selectedClient)
                    <span class="wms-filter-token">Cliente: {{ $selectedClient->name }}</span>
                    <span class="wms-filter-token">Dia: {{ $operationDateLabel }}</span>
                @else
                    <span class="wms-filter-muted">Sin cliente seleccionado</span>
                @endif
            </div>
        </section>

        @if ($selectedClient === null)
            <article class="surface-card compact-card wms-empty-state wms-daily-ops-empty">
                <span class="wms-status-chip wms-status-chip--neutral">Sin clientes</span>
                <div>
                    <h3>No hay clientes activos disponibles.</h3>
                    <p>Activa un cliente para poder revisar sus operaciones diarias.</p>
                </div>
            </article>
        @else
            <section class="wms-daily-ops-kpis" aria-label="KPIs de operaciones diarias">
                <article class="surface-card compact-card wms-daily-ops-kpi wms-daily-ops-kpi--primary">
                    <strong>PALLETS FACTURABLES DEL DIA</strong>
                    <span>{{ number_format($storedPallets, 0, ',', '.') }}</span>
                    <small>Stock base de apertura + entradas descargadas del dia.</small>
                </article>
                <article class="surface-card compact-card wms-daily-ops-kpi">
                    <strong>PALLETS MOVIDOS DEL DIA</strong>
                    <span>{{ number_format($movedPallets, 0, ',', '.') }}</span>
                    <small>Descargas + salidas/envios, contando pallets y picos.</small>
                </article>
                <article class="surface-card compact-card wms-daily-ops-kpi">
                    <strong>GESTIONES DE CAMION</strong>
                    <span>{{ number_format($truckManagement, 0, ',', '.') }}</span>
                    <small>Una gestion por entrada o salida independiente.</small>
                </article>
                <article class="surface-card compact-card wms-daily-ops-kpi">
                    <strong>VIAJES</strong>
                    <span>{{ number_format($truckTrips, 0, ',', '.') }}</span>
                    <small>Solo documentos marcados como camion propio.</small>
                </article>
            </section>

            <section class="surface-card compact-card wms-daily-ops-balance">
                <div class="wms-daily-ops-balance-item">
                    <span>Base inicio</span>
                    <strong>{{ number_format($openingPallets, 0, ',', '.') }}</strong>
                </div>
                <div class="wms-daily-ops-balance-item">
                    <span>Facturable hoy</span>
                    <strong>{{ number_format($storedPallets, 0, ',', '.') }}</strong>
                </div>
                <div class="wms-daily-ops-balance-item">
                    <span>Movido hoy</span>
                    <strong>{{ number_format($movedPallets, 0, ',', '.') }}</strong>
                </div>
                <div class="wms-daily-ops-balance-item">
                    <span>Base manana</span>
                    <strong>{{ number_format($expectedTomorrow, 0, ',', '.') }}</strong>
                </div>
            </section>

            <section class="surface-card compact-card wms-table-panel wms-daily-ops-breakdown">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>{{ $operationDateLabel }} - {{ $selectedClient->name }}</strong>
                        <span>Desglose facturable calculado para el dia seleccionado</span>
                    </div>
                    <div class="wms-table-totals" aria-label="Totales visibles">
                        <span>{{ number_format($billingPallets, 0, ',', '.') }} pallets/picos</span>
                    </div>
                </div>

                @if ($visibleSectionTotals->isNotEmpty())
                    <div class="wms-daily-ops-section-strip" aria-label="Resumen por seccion">
                        @foreach ($visibleSectionTotals as $section)
                            <div>
                                <span>{{ $section['label'] }}</span>
                                <strong>{{ number_format($section['value'], 0, ',', '.') }}</strong>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="wms-daily-ops-section-strip wms-daily-ops-section-strip--empty" aria-label="Resumen por seccion">
                        <span class="wms-muted-value">Sin secciones con importe operativo para este dia.</span>
                    </div>
                @endif

                @if ($day === null || $billingDetails === [])
                    <article class="wms-empty-state wms-daily-ops-empty">
                        <span class="wms-status-chip wms-status-chip--neutral">Sin detalle</span>
                        <div>
                            <h3>No hay entradas ni salidas recalculadas para este cliente y dia.</h3>
                            <p>Usa Ver dia para consultar otra fecha o Recalcular para reconstruir la foto local si procede.</p>
                        </div>
                    </article>
                @else
                    <div class="wms-table-wrap daily-ops-table-wrap">
                        <table class="wms-data-table daily-ops-table wms-daily-ops-table" aria-label="Detalle minimo de facturacion diaria">
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Documento</th>
                                    <th class="wms-table-number">Pallets/picos</th>
                                    <th>Gestion camion</th>
                                    <th>Viaje</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($billingDetails as $detail)
                                    <tr>
                                        <td>
                                            <span class="wms-status-chip wms-status-chip--neutral">{{ $detail['type'] }}</span>
                                        </td>
                                        <td>
                                            <div class="wms-daily-ops-document">
                                                <strong>{{ $detail['document'] }}</strong>
                                                <span>{{ $operationDateLabel }} - {{ $selectedClient->name }}</span>
                                            </div>
                                        </td>
                                        <td class="wms-table-number">{{ number_format($detail['pallets'], 0, ',', '.') }}</td>
                                        <td>{{ $detail['management'] ? 'Si' : 'No' }}</td>
                                        <td>{{ $detail['trip'] ? 'Camion propio' : '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>
@endsection
