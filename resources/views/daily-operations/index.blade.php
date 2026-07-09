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
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact compact-card daily-ops-hero-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Operaciones diarias</h2>
            <span class="ops-page-meta">Facturacion diaria por cliente: almacenaje, movimientos, gestiones y viajes.</span>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    <section class="surface-card compact-card daily-ops-toolbar">
        <form method="GET" action="{{ route('daily-operations.index') }}" class="item-form daily-ops-toolbar-form">
            <div class="form-grid daily-ops-toolbar-grid">
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
            </div>

            <div class="item-form-actions action-buttons daily-ops-toolbar-actions">
                <button type="submit" class="button-secondary compact-button btn-compact">Ver dia</button>
            </div>
        </form>

        @if ($selectedClient)
            <form method="POST" action="{{ route('daily-operations.recalculate') }}" class="item-form daily-ops-recalc-inline">
                @csrf
                <input type="hidden" name="operation_date" value="{{ $selectedDate->format('Y-m-d') }}">
                <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">
                <button type="submit" class="button-primary compact-button btn-compact">Recalcular</button>
            </form>
        @endif
    </section>

    @if ($selectedClient === null)
        <section class="surface-card compact-card daily-ops-card">
            <div class="item-empty-state">No hay clientes activos disponibles.</div>
        </section>
    @else
        <section class="daily-ops-summary daily-ops-summary--metrics">
            <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
                <strong>PALLETS FACTURABLES DEL DIA</strong>
                <span>{{ number_format($day?->stored_pallets_today ?? 0, 0, ',', '.') }}</span>
                <small>Stock base de apertura + entradas descargadas del dia.</small>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
                <strong>PALLETS MOVIDOS DEL DIA</strong>
                <span>{{ number_format($day?->moved_pallets_today ?? 0, 0, ',', '.') }}</span>
                <small>Descargas + salidas/envios, contando pallets y picos.</small>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
                <strong>GESTIONES DE CAMION</strong>
                <span>{{ number_format($sectionTotals[\App\Models\DailyOperationLine::SECTION_GESTION_CAMION] ?? 0, 0, ',', '.') }}</span>
                <small>Una gestion por entrada o salida independiente.</small>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
                <strong>VIAJES</strong>
                <span>{{ number_format($sectionTotals[\App\Models\DailyOperationLine::SECTION_VIAJE_CAMION] ?? 0, 0, ',', '.') }}</span>
                <small>Solo documentos marcados como camion propio.</small>
            </article>
        </section>

        <section class="surface-card compact-card daily-ops-card daily-ops-card--summary">
            <div class="ops-index-heading">
                <strong>{{ $selectedDate->format('d/m/Y') }} · {{ $selectedClient->name }}</strong>
                <span class="ops-page-meta">
                    Base inicio: {{ number_format($day?->opening_pallets ?? 0, 0, ',', '.') }}
                    · Base manana: {{ number_format($day?->expected_pallets_tomorrow ?? 0, 0, ',', '.') }}
                </span>
            </div>

            @if ($day === null || $billingDetails === [])
                <div class="item-empty-state">
                    No hay entradas ni salidas recalculadas para este cliente y dia.
                </div>
            @else
                <div class="data-table-wrap daily-ops-table-wrap">
                    <table class="data-table table-compact daily-ops-table" aria-label="Detalle minimo de facturacion diaria">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Documento</th>
                                <th>Pallets/picos</th>
                                <th>Gestion camion</th>
                                <th>Viaje</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($billingDetails as $detail)
                                <tr>
                                    <td>{{ $detail['type'] }}</td>
                                    <td>{{ $detail['document'] }}</td>
                                    <td>{{ number_format($detail['pallets'], 0, ',', '.') }}</td>
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
@endsection
