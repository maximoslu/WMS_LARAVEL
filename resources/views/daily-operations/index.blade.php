@extends('layouts.dashboard')

@section('title', 'Operaciones diarias | MAXIMO WMS')
@section('topbar_title', 'Operaciones diarias')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Operaciones</span>
        <span>/</span>
        <span>Operaciones diarias</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card daily-ops-hero-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Operaciones diarias</h2>
            <span class="ops-page-meta">Control operativo y base de facturacion diaria por cliente.</span>
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

    <section class="surface-card compact-card daily-ops-date-card daily-ops-toolbar">
        <form method="GET" action="{{ route('daily-operations.index') }}" class="item-form">
            <div class="form-grid daily-ops-toolbar-grid">
                <label class="auth-field">
                    <span>Cliente</span>
                    <select name="client_id" class="auth-input" required>
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
                <button type="submit" class="button-primary compact-button btn-compact">Ver dia</button>
            </div>
        </form>

        @if ($selectedClient)
            <aside class="daily-ops-toolbar-note">
                <strong>{{ $selectedClient->name }}</strong>
                <span>El recalcado conserva las lineas manuales y reconstruye solo la base automatica para la fecha seleccionada.</span>
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
        <section class="daily-ops-summary">
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Pallets iniciales</strong>
                <span>{{ number_format($day?->opening_pallets ?? 0, 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Pallets almacenados hoy</strong>
                <span>{{ number_format($day?->stored_pallets_today ?? 0, 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Pallets movidos hoy</strong>
                <span>{{ number_format($day?->moved_pallets_today ?? 0, 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Pallets previstos manana</strong>
                <span>{{ number_format($day?->expected_pallets_tomorrow ?? 0, 0, ',', '.') }}</span>
            </article>
        </section>

        <section class="daily-ops-recalc-grid">
            <article class="surface-card compact-card daily-ops-card daily-ops-card--recalc">
                <div class="ops-index-heading">
                    <strong>Recalculo desde operativa</strong>
                    <span class="ops-page-meta">Entradas confirmadas, salidas enviadas/completadas y stock activo</span>
                </div>

                <p class="daily-ops-recalc-copy">
                    Genera automaticamente descarga, carga, gestion camion, viaje camion y almacenaje sin duplicar lineas ya recalculadas.
                </p>

                <form method="POST" action="{{ route('daily-operations.recalculate') }}" class="item-form">
                    @csrf
                    <input type="hidden" name="operation_date" value="{{ $selectedDate->format('Y-m-d') }}">
                    <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">

                    <div class="item-form-actions action-buttons">
                        <button type="submit" class="button-primary compact-button btn-compact">Recalcular desde operativa</button>
                    </div>
                </form>
            </article>

            <article class="surface-card compact-card daily-ops-card daily-ops-card--note">
                <div class="ops-index-heading">
                    <strong>Reglas actuales</strong>
                    <span class="ops-page-meta">Base semi-automatica para facturacion</span>
                </div>

                <ul class="audit-note-list daily-ops-note-list">
                    <li>Las lineas manuales se conservan.</li>
                    <li>Las lineas automaticas se regeneran sin duplicados.</li>
                    <li>El resumen diario se actualiza con una aproximacion editable.</li>
                </ul>

                <p class="helper-text">TODO: marcar sin booking y conectar con reglas reales de avisos/horarios de empresa.</p>
            </article>
        </section>

        <section class="daily-ops-grid">
            <article class="surface-card compact-card daily-ops-card">
                <div class="ops-index-heading">
                    <strong>Resumen del dia</strong>
                    <span class="ops-page-meta">{{ $selectedDate->format('d/m/Y') }} - {{ $selectedClient->name }}</span>
                </div>

                <form method="POST" action="{{ route('daily-operations.day.upsert') }}" class="item-form">
                    @csrf
                    <input type="hidden" name="operation_date" value="{{ $selectedDate->format('Y-m-d') }}">
                    <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">

                    <div class="form-grid">
                        <label class="auth-field">
                            <span>Pallets iniciales</span>
                            <input type="number" min="0" name="opening_pallets" value="{{ old('opening_pallets', $day?->opening_pallets) }}" class="auth-input">
                        </label>

                        <label class="auth-field">
                            <span>Pallets almacenados hoy</span>
                            <input type="number" min="0" name="stored_pallets_today" value="{{ old('stored_pallets_today', $day?->stored_pallets_today) }}" class="auth-input">
                        </label>

                        <label class="auth-field">
                            <span>Pallets movidos hoy</span>
                            <input type="number" min="0" name="moved_pallets_today" value="{{ old('moved_pallets_today', $day?->moved_pallets_today) }}" class="auth-input">
                        </label>

                        <label class="auth-field">
                            <span>Pallets previstos manana</span>
                            <input type="number" min="0" name="expected_pallets_tomorrow" value="{{ old('expected_pallets_tomorrow', $day?->expected_pallets_tomorrow) }}" class="auth-input">
                        </label>

                        <label class="auth-field item-form-field--full">
                            <span>Notas operativas</span>
                            <textarea name="notes" rows="4" class="auth-input">{{ old('notes', $day?->notes) }}</textarea>
                        </label>
                    </div>

                    <div class="item-form-actions action-buttons">
                        <button type="submit" class="button-secondary compact-button btn-compact">Guardar resumen</button>
                    </div>
                </form>
            </article>

            <article class="surface-card compact-card daily-ops-card">
                <div class="ops-index-heading">
                    <strong>{{ $lineBeingEdited ? 'Editar linea' : 'Nueva linea operativa' }}</strong>
                    <span class="ops-page-meta">Descarga, carga, gestion, camion, almacenaje o transporte</span>
                </div>

                <form
                    method="POST"
                    action="{{ $lineBeingEdited ? route('daily-operations.lines.update', $lineBeingEdited) : route('daily-operations.lines.store') }}"
                    class="item-form"
                >
                    @csrf
                    @if ($lineBeingEdited)
                        @method('PUT')
                    @endif

                    <input type="hidden" name="operation_date" value="{{ old('operation_date', $selectedDate->format('Y-m-d')) }}">
                    <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">

                    <div class="form-grid">
                        <label class="auth-field">
                            <span>Seccion</span>
                            <select name="section" class="auth-input" required>
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
                            <span>Unidades facturables</span>
                            <input type="number" min="0" name="pallets" value="{{ old('pallets', $lineBeingEdited?->pallets) }}" class="auth-input" required>
                        </label>

                        <label class="auth-field item-form-field--full">
                            <span>Observaciones</span>
                            <textarea name="observations" rows="3" class="auth-input">{{ old('observations', $lineBeingEdited?->observations) }}</textarea>
                        </label>
                    </div>

                    <div class="item-form-actions action-buttons">
                        @if ($lineBeingEdited)
                            <a href="{{ route('daily-operations.index', ['date' => $selectedDate->format('Y-m-d'), 'client_id' => $selectedClient->id]) }}" class="button-secondary compact-button btn-compact">Cancelar edicion</a>
                        @endif
                        <button type="submit" class="button-primary compact-button btn-compact">{{ $lineBeingEdited ? 'Guardar cambios' : 'Anadir linea' }}</button>
                    </div>
                </form>

                <p class="helper-text">Usa lineas manuales para ajustes especiales que no deban sobrescribirse al recalcular.</p>
            </article>
        </section>

        <section class="surface-card compact-card daily-ops-card daily-ops-card--ledger">
            <div class="ops-index-heading">
                <strong>Movimiento diario</strong>
                <span class="ops-status badge-compact">{{ number_format($day?->linesTotal() ?? 0, 0, ',', '.') }} uds.</span>
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
                    No hay lineas registradas para esta fecha y cliente.
                </div>
            @else
                <div class="data-table-wrap">
                    <table class="data-table table-compact daily-ops-table" aria-label="Lineas de operaciones diarias">
                        <thead>
                            <tr>
                                <th>Seccion</th>
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
                                            {{ $line->is_auto_generated ? 'Automatica' : 'Manual' }}
                                        </span>
                                    </td>
                                    <td>{{ $line->observations ?: '-' }}</td>
                                    <td>
                                        @if ($line->is_auto_generated)
                                            <span class="helper-text">Se actualiza al recalcular</span>
                                        @else
                                            <div class="inline-actions action-buttons">
                                                <a href="{{ route('daily-operations.index', ['date' => $selectedDate->format('Y-m-d'), 'client_id' => $selectedClient->id, 'edit_line' => $line->id]) }}" class="button-secondary compact-button btn-table">Editar</a>
                                                <form method="POST" action="{{ route('daily-operations.lines.destroy', $line) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="button-secondary compact-button btn-table">Borrar</button>
                                                </form>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
@endsection
