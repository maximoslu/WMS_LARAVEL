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
            <span class="ops-page-meta">Base operativa diaria por cliente para almacenaje, movimientos y servicios asociados.</span>
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
        <div class="daily-ops-toolbar-main">
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
                        <span>Fecha operativa</span>
                        <input type="date" name="date" value="{{ $selectedDate->format('Y-m-d') }}" class="auth-input">
                    </label>
                </div>

                <div class="item-form-actions action-buttons daily-ops-toolbar-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Ver día</button>
                </div>
            </form>
        </div>

        @if ($selectedClient)
            <aside class="daily-ops-toolbar-note">
                <strong>{{ $selectedClient->name }}</strong>
                <span>Los cálculos se mantienen aislados por cliente y fecha. Las líneas manuales se conservan y el recálculo solo reconstruye la parte automática de la operativa real.</span>
            </aside>
        @endif
    </section>

    @if ($selectedClient === null)
        <section class="surface-card compact-card daily-ops-card">
            <div class="item-empty-state">
                No hay clientes activos disponibles para registrar operaciones diarias.
            </div>
        </section>
    @else
        <section class="daily-ops-summary daily-ops-summary--metrics">
            <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
                <strong>STOCK BASE CLIENTE</strong>
                <span>{{ number_format($day?->opening_pallets ?? 0, 0, ',', '.') }}</span>
                <small>Stock base calculado desde inventario actual del cliente.</small>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
                <strong>ALMACENAJE FACTURABLE</strong>
                <span>{{ number_format($day?->stored_pallets_today ?? 0, 0, ',', '.') }}</span>
                <small>Stock base cliente mas descargas del dia.</small>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
                <strong>PALLETS MOVIDOS HOY</strong>
                <span>{{ number_format($day?->moved_pallets_today ?? 0, 0, ',', '.') }}</span>
                <small>Descargas mas cargas y envios del dia.</small>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
                <strong>BASE PREVISTA MANANA</strong>
                <span>{{ number_format($day?->expected_pallets_tomorrow ?? 0, 0, ',', '.') }}</span>
                <small>Stock base mas descargas menos cargas y envios.</small>
            </article>
        </section>

        <section class="daily-ops-recalc-grid">
            <article class="surface-card compact-card daily-ops-card daily-ops-card--recalc">
                <div class="ops-index-heading">
                    <strong>Recálculo desde operativa</strong>
                    <span class="ops-page-meta">Entradas confirmadas, envíos expedidos y stock activo del cliente</span>
                </div>

                <p class="daily-ops-recalc-copy">
                    Genera automáticamente descargas, envíos, gestiones de camión y viajes de camión sin duplicar líneas ya recalculadas. Las líneas manuales se mantienen.
                </p>

                <form method="POST" action="{{ route('daily-operations.recalculate') }}" class="item-form">
                    @csrf
                    <input type="hidden" name="operation_date" value="{{ $selectedDate->format('Y-m-d') }}">
                    <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">

                    <div class="item-form-actions action-buttons daily-ops-recalc-actions">
                        <button type="submit" class="button-primary compact-button btn-compact">Recalcular desde operativa</button>
                    </div>
                </form>
            </article>

            <article class="surface-card compact-card daily-ops-card daily-ops-card--note">
                <div class="ops-index-heading">
                    <strong>Reglas actuales</strong>
                    <span class="ops-page-meta">Facturacion operativa del dia</span>
                </div>

                <ul class="audit-note-list daily-ops-note-list">
                    <li>Cada descarga, carga, envío y viaje de camión genera gestión de camión asociada.</li>
                    <li>Los pallets movidos solo cuentan en descarga, carga y envío.</li>
                    <li>La gestión de camión y el viaje de camión se facturan aparte y no alteran stock.</li>
                    <li>El stock base sale del inventario actual del cliente, incluyendo bloqueados y excluyendo obsoletos o stock cero.</li>
                    <li>El almacenaje facturable del día es stock base cliente más descargas del día.</li>
                    <li>Las horas operario quedan preparadas como línea operativa específica.</li>
                </ul>

                <p class="helper-text">TODO: configurar horarios reales de empresa para avisos de solicitud de mercancía y tarifas de horas operario.</p>
            </article>
        </section>

        <section class="daily-ops-grid">
            <article class="surface-card compact-card daily-ops-card daily-ops-card--summary">
                <div class="ops-index-heading">
                    <strong>Resumen del día</strong>
                    <span class="ops-page-meta">{{ $selectedDate->format('d/m/Y') }} · {{ $selectedClient->name }}</span>
                </div>

                <form method="POST" action="{{ route('daily-operations.day.upsert') }}" class="item-form daily-ops-summary-form">
                    @csrf
                    <input type="hidden" name="operation_date" value="{{ $selectedDate->format('Y-m-d') }}">
                    <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">

                    <div class="daily-ops-summary-panel">
                        <div class="daily-ops-derived-grid">
                            <article class="daily-ops-derived-card">
                                <strong>Stock base cliente</strong>
                                <span>{{ number_format($day?->opening_pallets ?? 0, 0, ',', '.') }}</span>
                            </article>
                            <article class="daily-ops-derived-card">
                                <strong>Almacenaje facturable</strong>
                                <span>{{ number_format($day?->stored_pallets_today ?? 0, 0, ',', '.') }}</span>
                            </article>
                            <article class="daily-ops-derived-card">
                                <strong>Movidos hoy</strong>
                                <span>{{ number_format($day?->moved_pallets_today ?? 0, 0, ',', '.') }}</span>
                            </article>
                            <article class="daily-ops-derived-card">
                                <strong>Base prevista manana</strong>
                                <span>{{ number_format($day?->expected_pallets_tomorrow ?? 0, 0, ',', '.') }}</span>
                            </article>
                        </div>

                        <p class="helper-text">Stock base calculado desde inventario actual del cliente. El ajuste manual queda reservado para una fase posterior.</p>

                        <label class="auth-field item-form-field--full">
                            <span>Notas operativas</span>
                            <textarea name="notes" rows="5" class="auth-input">{{ old('notes', $day?->notes) }}</textarea>
                        </label>
                    </div>

                    <div class="item-form-actions action-buttons daily-ops-summary-actions">
                        <button type="submit" class="button-secondary compact-button btn-compact">Guardar notas del dia</button>
                    </div>
                </form>
            </article>

            <article class="surface-card compact-card daily-ops-card daily-ops-card--entry">
                <div class="ops-index-heading">
                    <strong>{{ $lineBeingEdited ? 'Editar línea operativa' : 'Nueva línea operativa' }}</strong>
                    <span class="ops-page-meta">Descarga, carga, envío, gestión de camión, viaje de camión, horas operario y servicios</span>
                </div>

                <form
                    method="POST"
                    action="{{ $lineBeingEdited ? route('daily-operations.lines.update', $lineBeingEdited) : route('daily-operations.lines.store') }}"
                    class="item-form daily-ops-entry-form"
                >
                    @csrf
                    @if ($lineBeingEdited)
                        @method('PUT')
                    @endif

                    <input type="hidden" name="operation_date" value="{{ old('operation_date', $selectedDate->format('Y-m-d')) }}">
                    <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">

                    <div class="form-grid daily-ops-entry-grid">
                        <label class="auth-field">
                            <span>Sección</span>
                            <select name="section" class="auth-input daily-ops-select" required>
                                @foreach ($sectionOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('section', $lineBeingEdited?->section) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="auth-field">
                            <span>Proveedor / transporte / destino</span>
                            <input type="text" name="counterparty_name" value="{{ old('counterparty_name', $lineBeingEdited?->counterparty_name) }}" class="auth-input" required>
                        </label>

                        <label class="auth-field">
                            <span>{{ old('section', $lineBeingEdited?->section) === \App\Models\DailyOperationLine::SECTION_HORAS_OPERARIO ? 'Horas' : 'Unidades facturables' }}</span>
                            <input type="number" min="0" name="pallets" value="{{ old('pallets', $lineBeingEdited?->pallets) }}" class="auth-input" required>
                        </label>

                        <label class="auth-field item-form-field--full">
                            <span>Observaciones</span>
                            <textarea name="observations" rows="4" class="auth-input">{{ old('observations', $lineBeingEdited?->observations) }}</textarea>
                        </label>
                    </div>

                    <div class="item-form-actions action-buttons daily-ops-entry-actions">
                        @if ($lineBeingEdited)
                            <a href="{{ route('daily-operations.index', ['date' => $selectedDate->format('Y-m-d'), 'client_id' => $selectedClient->id]) }}" class="button-secondary compact-button btn-compact">Cancelar edición</a>
                        @endif
                        <button type="submit" class="button-primary compact-button btn-compact">{{ $lineBeingEdited ? 'Guardar cambios' : 'Añadir línea' }}</button>
                    </div>
                </form>

                <p class="helper-text">Las líneas manuales de descarga, carga y viaje crean gestión de camión asociada. Los envíos además crean un viaje de camión inicial editable.</p>
            </article>
        </section>

        <section class="surface-card compact-card daily-ops-card daily-ops-card--ledger">
            <div class="ops-index-heading">
                <strong>Movimiento diario</strong>
                <span class="ops-status badge-compact">{{ number_format($day?->lines?->count() ?? 0, 0, ',', '.') }} líneas registradas</span>
            </div>

            <div class="daily-ops-totals">
                @foreach ($sectionOptions as $value => $label)
                    <article class="daily-ops-total-chip">
                        <strong>{{ $label }}</strong>
                        <span>{{ number_format($sectionTotals[$value] ?? 0, 0, ',', '.') }}</span>
                    </article>
                @endforeach
            </div>

            @if ($day === null || $day->lines->isEmpty())
                <div class="item-empty-state">
                    No hay líneas registradas para esta fecha y cliente.
                </div>
            @else
                <div class="data-table-wrap daily-ops-table-wrap">
                    <table class="data-table table-compact daily-ops-table" aria-label="Líneas de operaciones diarias">
                        <thead>
                            <tr>
                                <th>Sección</th>
                                <th>Contraparte</th>
                                <th>Unidades</th>
                                <th>Origen</th>
                                <th>Observaciones</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($day->lines as $line)
                                <tr>
                                    <td>{{ $line->sectionLabel() }}</td>
                                    <td>{{ $line->counterparty_name }}</td>
                                    <td>{{ number_format($line->pallets, 0, ',', '.') }}</td>
                                    <td>
                                        <span class="daily-ops-origin-chip {{ $line->is_auto_generated ? 'daily-ops-origin-chip--auto' : 'daily-ops-origin-chip--manual' }}">
                                            {{ $line->is_auto_generated ? ($line->source_type === \App\Models\DailyOperationLine::SOURCE_MANUAL_LINE ? 'Asociada' : 'Operativa') : 'Manual' }}
                                        </span>
                                    </td>
                                    <td>{{ $line->observations ?: '-' }}</td>
                                    <td>
                                        @if ($line->canBeManuallyManaged())
                                            <div class="inline-actions action-buttons">
                                                <a href="{{ route('daily-operations.index', ['date' => $selectedDate->format('Y-m-d'), 'client_id' => $selectedClient->id, 'edit_line' => $line->id]) }}" class="button-secondary compact-button btn-table">Editar</a>
                                                <form method="POST" action="{{ route('daily-operations.lines.destroy', $line) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="button-secondary compact-button btn-table">Borrar</button>
                                                </form>
                                            </div>
                                        @else
                                            <span class="helper-text">Se actualiza al recalcular</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="daily-ops-mobile-list">
                    @foreach ($day->lines as $line)
                        <article class="surface-card daily-ops-mobile-card">
                            <div class="daily-ops-mobile-card-head">
                                <strong>{{ $line->sectionLabel() }}</strong>
                                <span class="daily-ops-origin-chip {{ $line->is_auto_generated ? 'daily-ops-origin-chip--auto' : 'daily-ops-origin-chip--manual' }}">
                                    {{ $line->is_auto_generated ? ($line->source_type === \App\Models\DailyOperationLine::SOURCE_MANUAL_LINE ? 'Asociada' : 'Operativa') : 'Manual' }}
                                </span>
                            </div>
                            <div class="daily-ops-mobile-card-body">
                                <div><strong>Contraparte:</strong> {{ $line->counterparty_name }}</div>
                                <div><strong>Unidades:</strong> {{ number_format($line->pallets, 0, ',', '.') }}</div>
                                <div><strong>Observaciones:</strong> {{ $line->observations ?: '-' }}</div>
                            </div>
                            <div class="daily-ops-mobile-card-actions">
                                @if ($line->canBeManuallyManaged())
                                    <a href="{{ route('daily-operations.index', ['date' => $selectedDate->format('Y-m-d'), 'client_id' => $selectedClient->id, 'edit_line' => $line->id]) }}" class="button-secondary compact-button btn-table">Editar</a>
                                    <form method="POST" action="{{ route('daily-operations.lines.destroy', $line) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button-secondary compact-button btn-table">Borrar</button>
                                    </form>
                                @else
                                    <span class="helper-text">Se actualiza al recalcular</span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
@endsection





