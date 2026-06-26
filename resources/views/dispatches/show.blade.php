@extends('layouts.dashboard')

@section('title', 'Detalle de salida | MAXIMO WMS')
@section('topbar_title', 'Detalle de salida')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <a href="{{ route('dispatches.index') }}">Salidas</a>
        <span>/</span>
        <span>{{ $dispatch->dispatchNumber() }}</span>
    </nav>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card compact-card merchandise-request-detail-card">
        <div class="ops-page-headline">
            <div>
                <h2 class="ops-page-title page-title-compact">{{ $dispatch->dispatchNumber() }}</h2>
                <p class="access-request-detail-meta">{{ $dispatch->client?->name ?? 'Sin cliente' }}</p>
            </div>
            <span class="status-badge dispatch-status dispatch-status--{{ $dispatch->status }}">{{ $dispatch->statusLabel() }}</span>
        </div>

        <dl class="access-request-detail-grid">
            <div>
                <dt>Tipo</dt>
                <dd>{{ $dispatch->type === \App\Models\GoodsDispatch::TYPE_REQUEST ? 'Desde solicitud' : 'Manual' }}</dd>
            </div>
            <div>
                <dt>Total pallets</dt>
                <dd>{{ number_format($dispatch->palletsCount(), 0, ',', '.') }}</dd>
            </div>
            <div>
                <dt>Fecha envio</dt>
                <dd>{{ $dispatch->sent_at?->format('d/m/Y H:i') ?: 'Pendiente' }}</dd>
            </div>
            <div>
                <dt>Direccion entrega</dt>
                <dd>{{ $dispatch->client?->formattedDeliveryAddress() ?: 'Pendiente en ficha de cliente' }}</dd>
            </div>
        </dl>
    </section>

    <section class="surface-card compact-card merchandise-request-detail-card">
        <div class="dispatch-actions-grid">
            <form method="POST" action="{{ route('dispatches.update-status', $dispatch) }}" class="dispatch-inline-form">
                @csrf
                @method('PATCH')
                <label class="auth-field">
                    <span>Cambiar estado de salida</span>
                    <select name="status" class="auth-input">
                        @foreach (\App\Models\GoodsDispatch::statuses() as $status)
                            @if (! ($dispatch->merchandise_request_id && $status === \App\Models\GoodsDispatch::STATUS_DRAFT))
                                <option value="{{ $status }}" @selected($dispatch->status === $status)>{{ \Illuminate\Support\Str::headline($status) }}</option>
                            @endif
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="button-primary compact-button btn-compact">Guardar estado</button>
            </form>

            <div class="dispatch-action-stack">
                @if ($dispatch->merchandiseRequest)
                    <a href="{{ route('dispatches.requests.show', $dispatch->merchandiseRequest) }}" class="button-secondary compact-button btn-compact">Ver pedido origen</a>
                @endif

                @if (in_array($dispatch->status, [\App\Models\GoodsDispatch::STATUS_SENT, \App\Models\GoodsDispatch::STATUS_COMPLETED], true))
                    <a href="{{ route('dispatches.delivery-note', $dispatch) }}" class="button-primary compact-button btn-compact">Imprimir albaran</a>
                @endif
            </div>
        </div>
    </section>

    <section class="surface-card stock-table-shell compact-card">
        <div class="ops-section-heading">
            <strong>Lineas de salida</strong>
            <span class="ops-page-meta">{{ $dispatch->lines->count() }} lineas</span>
        </div>

        <div class="data-table-wrap">
            <table class="data-table table-compact">
                <thead>
                    <tr>
                        <th>Mercancia</th>
                        <th>Descripcion</th>
                        <th>Lote</th>
                        <th>Uds/pallet</th>
                        <th>Pallets</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dispatch->lines as $line)
                        <tr>
                            <td><strong>{{ $line->sku }}</strong></td>
                            <td>{{ $line->description }}</td>
                            <td>{{ $line->lot ?: 'Sin lote' }}</td>
                            <td>{{ number_format($line->units_per_pallet ?? 0, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->pallets, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
