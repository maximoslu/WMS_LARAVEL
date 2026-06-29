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
            <span class="ops-page-meta">Control operativo y base de facturación diaria de pallets.</span>
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

    <section class="surface-card compact-card daily-ops-date-card">
        <form method="GET" action="{{ route('daily-operations.index') }}" class="stock-filter-actions action-buttons">
            <label class="auth-field">
                <span>Fecha operativa</span>
                <input type="date" name="date" value="{{ $selectedDate->format('Y-m-d') }}" class="auth-input">
            </label>

            <div class="item-form-actions action-buttons">
                <button type="submit" class="button-primary compact-button btn-compact">Ver día</button>
            </div>
        </form>
    </section>

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
            <strong>Pallets previstos mañana</strong>
            <span>{{ number_format($day?->expected_pallets_tomorrow ?? 0, 0, ',', '.') }}</span>
        </article>
    </section>

    <section class="daily-ops-grid">
        <article class="surface-card compact-card daily-ops-card">
            <div class="ops-index-heading">
                <strong>Resumen del día</strong>
                <span class="ops-page-meta">{{ $selectedDate->format('d/m/Y') }}</span>
            </div>

            <form method="POST" action="{{ route('daily-operations.day.upsert') }}" class="item-form">
                @csrf
                <input type="hidden" name="operation_date" value="{{ $selectedDate->format('Y-m-d') }}">

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
                        <span>Pallets previstos mañana</span>
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
                <strong>{{ $lineBeingEdited ? 'Editar línea' : 'Nueva línea operativa' }}</strong>
                <span class="ops-page-meta">Descarga, carga, gestión o transporte</span>
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

                <div class="form-grid">
                    <label class="auth-field">
                        <span>Sección</span>
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
                        <span>Pallets</span>
                        <input type="number" min="0" name="pallets" value="{{ old('pallets', $lineBeingEdited?->pallets) }}" class="auth-input" required>
                    </label>

                    <label class="auth-field item-form-field--full">
                        <span>Observaciones</span>
                        <textarea name="observations" rows="3" class="auth-input">{{ old('observations', $lineBeingEdited?->observations) }}</textarea>
                    </label>
                </div>

                <div class="item-form-actions action-buttons">
                    @if ($lineBeingEdited)
                        <a href="{{ route('daily-operations.index', ['date' => $selectedDate->format('Y-m-d')]) }}" class="button-secondary compact-button btn-compact">Cancelar edición</a>
                    @endif
                    <button type="submit" class="button-primary compact-button btn-compact">{{ $lineBeingEdited ? 'Guardar cambios' : 'Añadir línea' }}</button>
                </div>
            </form>

            <p class="helper-text">TODO: Pendiente marcar sin booking y facturación FF.</p>
        </article>
    </section>

    <section class="surface-card compact-card daily-ops-card">
        <div class="ops-index-heading">
            <strong>Movimiento diario</strong>
            <span class="ops-status badge-compact">{{ number_format($day?->linesTotal() ?? 0, 0, ',', '.') }} pallets</span>
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
                No hay líneas registradas para esta fecha.
            </div>
        @else
            <div class="data-table-wrap">
                <table class="data-table table-compact daily-ops-table" aria-label="Líneas de operaciones diarias">
                    <thead>
                        <tr>
                            <th>Sección</th>
                            <th>Proveedor / transporte / destino</th>
                            <th>Pallets</th>
                            <th>Observaciones</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($day->lines->sortBy(['section', 'sort_order', 'id']) as $line)
                            <tr>
                                <td>{{ $line->sectionLabel() }}</td>
                                <td>{{ $line->counterparty_name }}</td>
                                <td>{{ number_format($line->pallets, 0, ',', '.') }}</td>
                                <td>{{ $line->observations ?: '-' }}</td>
                                <td>
                                    <div class="inline-actions action-buttons">
                                        <a href="{{ route('daily-operations.index', ['date' => $selectedDate->format('Y-m-d'), 'edit_line' => $line->id]) }}" class="button-secondary compact-button btn-table">Editar</a>
                                        <form method="POST" action="{{ route('daily-operations.lines.destroy', $line) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="button-secondary compact-button btn-table">Borrar</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
