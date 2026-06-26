@php($isEditing = $merchandiseRequest->exists)
@php($isClientUser = auth()->user()->hasRole(\App\Models\Role::CLIENTE))

<nav class="ops-breadcrumb" aria-label="Breadcrumb">
    <a href="{{ route('dashboard') }}">Panel operativo</a>
    <span>/</span>
    <span>Operaciones</span>
    <span>/</span>
    <a href="{{ route('merchandise-requests.index') }}">Solicitudes</a>
    <span>/</span>
    <span>{{ $isEditing ? 'Editar' : 'Crear' }}</span>
</nav>

<section class="merchandise-request-shell">
    <div class="surface-card item-form-card compact-card">
        <div class="app-copy">
            <span class="status-chip small-badge badge-compact">{{ $isEditing ? 'Edicion' : 'Solicitud' }}</span>
            <h2 class="ops-page-title page-title-compact">{{ $isEditing ? 'Editar solicitud de mercancia' : 'Nueva solicitud de mercancia' }}</h2>
            <p>El cliente solicita por palets. El sistema calcula automaticamente las unidades totales.</p>
        </div>

        @if ($errors->has('merchandise_request'))
            <div class="alert alert-error">{{ $errors->first('merchandise_request') }}</div>
        @endif

        <form method="POST" action="{{ $isEditing ? route('merchandise-requests.update', $merchandiseRequest) : route('merchandise-requests.store') }}" class="item-form merchandise-request-form" data-merchandise-request-form>
            @csrf
            @if ($isEditing)
                @method('PUT')
            @endif

            <div class="form-grid form-grid--tight">
                @if ($isClientUser)
                    <input type="hidden" name="client_id" value="{{ old('client_id', $merchandiseRequest->client_id) }}">
                    <div class="auth-field">
                        <span>Cliente</span>
                        <div class="auth-input merchandise-request-locked-field">
                            {{ $clients->firstWhere('id', old('client_id', $merchandiseRequest->client_id))?->name ?: 'Cliente no asignado' }}
                        </div>
                    </div>
                @else
                    <label class="auth-field">
                        <span>Cliente</span>
                        <select name="client_id" class="auth-input" data-request-client required>
                            <option value="">Selecciona cliente</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected((string) old('client_id', $merchandiseRequest->client_id) === (string) $client->id)>
                                    {{ $client->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('client_id')
                            <small class="form-error">{{ $message }}</small>
                        @enderror
                    </label>
                @endif

                @if ($isClientUser)
                    <input type="hidden" data-request-client value="{{ old('client_id', $merchandiseRequest->client_id) }}">
                @endif

                <label class="auth-field">
                    <span>Referencia</span>
                    <input type="text" name="delivery_reference" value="{{ old('delivery_reference', $merchandiseRequest->delivery_reference) }}" class="auth-input" maxlength="150">
                    @error('delivery_reference')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Fecha solicitud</span>
                    <input type="date" name="requested_date" value="{{ old('requested_date', optional($merchandiseRequest->requested_date)->format('Y-m-d')) }}" class="auth-input">
                    @error('requested_date')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field item-form-field--full">
                    <span>Direccion de entrega</span>
                    <textarea name="delivery_address" rows="3" class="auth-input">{{ old('delivery_address', $merchandiseRequest->delivery_address) }}</textarea>
                    @error('delivery_address')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field item-form-field--full">
                    <span>Notas</span>
                    <textarea name="notes" rows="3" class="auth-input">{{ old('notes', $merchandiseRequest->notes) }}</textarea>
                    @error('notes')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>

            <section class="goods-receipt-lines-card merchandise-request-lines-card">
                <div class="goods-receipt-lines-tools">
                    <div class="app-copy">
                        <strong>Lineas de solicitud</strong>
                        <p>Selecciona articulo y palets; el sistema calcula las unidades.</p>
                    </div>

                    <button type="button" class="button-secondary compact-button btn-compact" data-add-request-line>Anadir linea</button>
                </div>

                <div class="data-table-wrap goods-receipt-lines-wrap">
                    <table class="data-table table-compact merchandise-request-lines-table" aria-label="Lineas de solicitud">
                        <thead>
                            <tr>
                                <th>Articulo</th>
                                <th>Lote</th>
                                <th>Palets solicitados</th>
                                <th>Uds/palet</th>
                                <th>Total uds</th>
                                <th>Notas</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody data-request-lines>
                            @foreach ($lineValues as $index => $line)
                                @include('merchandise-requests._line-row', ['index' => $index, 'line' => $line])
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <template data-request-line-template>
                    @include('merchandise-requests._line-row', ['index' => '__INDEX__', 'line' => null])
                </template>
            </section>

            <div class="item-form-actions action-buttons">
                <a href="{{ $isEditing ? route('merchandise-requests.show', $merchandiseRequest) : route('merchandise-requests.index') }}" class="button-secondary compact-button btn-compact">Cancelar</a>
                <button type="submit" class="button-primary compact-button btn-compact">{{ $isEditing ? 'Guardar solicitud' : 'Crear solicitud' }}</button>
            </div>
        </form>
    </div>
</section>

<script type="application/json" data-merchandise-request-items>@json($itemsCatalog)</script>
