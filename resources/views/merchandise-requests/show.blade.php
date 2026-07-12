@extends('layouts.dashboard')

@section('title', 'Pedido '.$merchandiseRequest->referenceCode().' | MAXIMO WMS')
@section('topbar_title', 'PEDIDO')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'PEDIDOS', 'href' => route('merchandise-requests.index')],
            ['label' => $merchandiseRequest->referenceCode()],
        ];
        $requestedPallets = $merchandiseRequest->requestedPalletsCount();
        $requestedPeaks = $merchandiseRequest->requestedPeaksCount();
        $requestedUnits = (int) $merchandiseRequest->lines->sum('requested_units');
        $timeline = [
            ['label' => 'Registrado', 'date' => $merchandiseRequest->submittedAt()],
            ['label' => 'Preparación', 'date' => $merchandiseRequest->prepared_at],
            ['label' => 'Enviado', 'date' => $merchandiseRequest->shipped_at],
            ['label' => 'Completado', 'date' => $merchandiseRequest->completed_at],
        ];
        $currentStepIndex = null;
        foreach ($timeline as $stepIndex => $step) {
            if (! $step['date']) {
                $currentStepIndex = $stepIndex;
                break;
            }
        }
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="order-detail">
        @if (session('status'))
            <div class="order-alert order-alert--success" role="status">
                <span class="order-alert-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="18" height="18"><path d="M7.5 10.5l1.8 1.8 3.5-4.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="10" r="7.2" stroke="currentColor" stroke-width="1.6"/></svg>
                </span>
                <div class="order-alert-copy"><p>{{ session('status') }}</p></div>
            </div>
        @endif

        @if (session('scheduleWarning'))
            <div class="order-alert order-alert--warning" role="alert">
                <span class="order-alert-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="18" height="18"><path d="M10 2.8l7.2 12.6H2.8L10 2.8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 8.2v3.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="13.6" r="0.9" fill="currentColor"/></svg>
                </span>
                <div class="order-alert-copy">
                    <strong>Pedido fuera de horario operativo</strong>
                    <p>Lo tramitaremos con la mayor diligencia posible, pero no podemos garantizar su preparación o expedición para el siguiente día hábil.</p>
                </div>
            </div>
        @elseif (session('warning'))
            <div class="order-alert order-alert--warning" role="alert">
                <span class="order-alert-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="18" height="18"><path d="M10 2.8l7.2 12.6H2.8L10 2.8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 8.2v3.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="13.6" r="0.9" fill="currentColor"/></svg>
                </span>
                <div class="order-alert-copy"><p>{{ session('warning') }}</p></div>
            </div>
        @endif

        @if ($errors->any())
            <div class="order-alert order-alert--error" role="alert">
                <div class="order-alert-copy"><p>{{ $errors->first() }}</p></div>
            </div>
        @endif

        <section class="surface-card compact-card order-header">
            <div class="order-header-main">
                <span class="order-type-chip">Pedido {{ $isClient ? 'cliente' : 'interno' }}</span>
                <h2 class="order-code">{{ $merchandiseRequest->referenceCode() }}</h2>
                <span class="status-badge merchandise-request-status merchandise-request-status--{{ $merchandiseRequest->status }}">
                    {{ $merchandiseRequest->statusLabel() }}
                </span>
            </div>

            <dl class="order-meta">
                <div class="order-meta-item">
                    <dt>Cliente</dt>
                    <dd>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Solicitante</dt>
                    <dd>{{ $merchandiseRequest->requestedBy?->name ?? 'Sin usuario' }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Fecha</dt>
                    <dd>{{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Pallets</dt>
                    <dd>{{ number_format($requestedPallets, 0, ',', '.') }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Picos</dt>
                    <dd>{{ number_format($requestedPeaks, 0, ',', '.') }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Unidades</dt>
                    <dd>{{ number_format($requestedUnits, 0, ',', '.') }}</dd>
                </div>
            </dl>
        </section>

        <section class="surface-card compact-card order-track" aria-label="Seguimiento del pedido">
            <ol class="order-steps">
                @foreach ($timeline as $step)
                    @php
                        $stepState = $step['date']
                            ? 'is-complete'
                            : ($loop->index === $currentStepIndex ? 'is-current' : 'is-pending');
                    @endphp
                    <li class="order-step {{ $stepState }}">
                        <span class="order-step-dot" aria-hidden="true"></span>
                        <span class="order-step-label">{{ $step['label'] }}</span>
                        <span class="order-step-date">{{ $step['date']?->format('d/m/Y H:i') ?: 'Pendiente' }}</span>
                    </li>
                @endforeach
            </ol>
        </section>

        @unless ($isClient)
            <section class="surface-card compact-card wms-flow-card">
                <div class="wms-section-head">
                    <div>
                        <strong>Acciones</strong>
                    </div>
                </div>

                <div class="wms-action-grid">
                    <form method="POST" action="{{ route('merchandise-requests.update-status', $merchandiseRequest) }}" class="wms-action-card">
                        @csrf
                        @method('PATCH')

                        <strong>Cambiar estado</strong>

                        <label class="auth-field">
                            <span>Nuevo estado</span>
                            <select name="status" class="auth-input">
                                @foreach (\App\Models\MerchandiseRequest::statusOptions() as $status => $label)
                                    <option value="{{ $status }}" @selected($merchandiseRequest->status === $status)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        <button type="submit" class="button-primary compact-button btn-compact">Guardar estado</button>
                    </form>

                    <div class="wms-action-card">
                        <strong>Documentos y salida</strong>

                        <a href="{{ route('merchandise-requests.preparation-pdf', $merchandiseRequest) }}" class="button-secondary compact-button btn-compact wms-button-with-icon" target="_blank" rel="noopener noreferrer">
                            <span class="wms-button-icon" aria-hidden="true"><x-module-icon name="printer" /></span>
                            Imprimir preparación
                        </a>

                        @if ($merchandiseRequest->dispatch)
                            <a href="{{ route('dispatches.show', $merchandiseRequest->dispatch) }}" class="button-secondary compact-button btn-compact">
                                Ver salida asociada
                            </a>

                            @if (in_array($merchandiseRequest->status, [\App\Models\MerchandiseRequest::STATUS_SENT, \App\Models\MerchandiseRequest::STATUS_COMPLETED], true))
                                <a href="{{ route('dispatches.delivery-note', $merchandiseRequest->dispatch) }}" class="button-primary compact-button btn-compact wms-button-with-icon" target="_blank" rel="noopener noreferrer">
                                    <span class="wms-button-icon" aria-hidden="true"><x-module-icon name="printer" /></span>
                                    Imprimir albarán
                                </a>
                            @endif
                        @else
                            <form method="POST" action="{{ route('dispatches.requests.generate', $merchandiseRequest) }}">
                                @csrf
                                <button type="submit" class="button-primary compact-button btn-compact">Generar salida</button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        @endunless

        <section class="surface-card compact-card order-lines-card">
            <div class="order-lines-head">
                <strong>Líneas</strong>
                <span class="ops-page-meta">{{ $merchandiseRequest->lines->count() }} líneas</span>
            </div>

            <div class="order-table-wrap">
                <table class="order-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Descripción</th>
                            <th>Lote</th>
                            <th>Cantidad</th>
                            <th>Uds/pallet</th>
                            <th>Tipo</th>
                            @unless ($isClient)
                                <th>Cargado</th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($merchandiseRequest->lines as $line)
                            @php
                                $dispatchLine = $merchandiseRequest->dispatch?->lines->first(
                                    fn ($candidate) => (int) $candidate->source_request_line_id === (int) $line->id
                                ) ?? $merchandiseRequest->dispatch?->lines->first(
                                    fn ($candidate) => ! $candidate->is_extra_line && (int) $candidate->item_id === (int) $line->item_id
                                        && (string) $candidate->line_type === (string) $line->line_type
                                        && (int) ($candidate->stock_peak_index ?? 0) === (int) ($line->stock_peak_index ?? 0)
                                );
                            @endphp
                            <tr>
                                <td class="order-table-strong">{{ $line->item?->sku ?? 'Articulo eliminado' }}</td>
                                <td>{{ $line->item?->description ?? 'Sin descripción disponible' }}</td>
                                <td>{{ $line->lot ?: 'Sin lote' }}</td>
                                <td>{{ $line->requestedQuantityLabel() }}</td>
                                <td>{{ $line->unitsLabel() }}</td>
                                <td><span class="wms-line-type-pill wms-line-type-pill--{{ $line->lineType() }}">{{ $line->lineTypeLabel() }}</span></td>
                                @unless ($isClient)
                                    <td>{{ $dispatchLine ? $dispatchLine->loadedQuantityLabel() : '—' }}</td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($merchandiseRequest->dispatch && $merchandiseRequest->dispatch->lines->contains(fn ($line) => $line->is_extra_line))
            <section class="surface-card compact-card order-lines-card">
                <div class="order-lines-head">
                    <strong>Carga real adicional</strong>
                    <span class="ops-page-meta">{{ $merchandiseRequest->dispatch->lines->where('is_extra_line', true)->count() }} líneas</span>
                </div>

                <div class="order-table-wrap">
                    <table class="order-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Descripción</th>
                                <th>Lote</th>
                                <th>Cargado</th>
                                <th>Uds/pallet</th>
                                <th>Tipo</th>
                                <th>Observaciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($merchandiseRequest->dispatch->lines->where('is_extra_line', true) as $extraLine)
                                <tr>
                                    <td class="order-table-strong">{{ $extraLine->sku }}</td>
                                    <td>{{ $extraLine->description }}</td>
                                    <td>{{ $extraLine->lot ?: 'Sin lote' }}</td>
                                    <td>{{ $extraLine->loadedQuantityLabel() }}</td>
                                    <td>{{ $extraLine->unitsLabel() }}</td>
                                    <td><span class="wms-line-type-pill wms-line-type-pill--{{ $extraLine->lineType() }}">{{ $extraLine->lineTypeLabel() }}</span></td>
                                    <td>{{ $extraLine->loading_notes ?: 'Sin observaciones' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
