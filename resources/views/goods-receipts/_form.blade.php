@php($isEditing = $receipt->exists)

<nav class="ops-breadcrumb" aria-label="Breadcrumb">
    <a href="{{ route('dashboard') }}">Panel de control</a>
    <span>/</span>
    <span>Operaciones</span>
    <span>/</span>
    <a href="{{ route('goods-receipts.index') }}">Entradas</a>
    <span>/</span>
    <span>{{ $isEditing ? 'Editar' : 'Crear' }}</span>
</nav>

<div class="goods-receipt-shell">
    <section class="surface-card item-form-card entity-form compact-card">
        <div class="app-copy">
            <span class="status-chip small-badge badge-compact">{{ $isEditing ? 'Edicion' : 'Borrador' }}</span>
            <h2 class="ops-page-title page-title-compact">{{ $isEditing ? 'Editar entrada' : 'Nueva entrada de mercancía' }}</h2>
            <p>Registra cabecera, adjunto y líneas. La generación de stock se realiza al confirmar la entrada.</p>
        </div>

        @if ($errors->has('goods_receipt'))
            <div class="alert alert-error">{{ $errors->first('goods_receipt') }}</div>
        @endif

        <form
            method="POST"
            action="{{ $isEditing ? route('goods-receipts.update', $receipt) : route('goods-receipts.store') }}"
            class="item-form goods-receipt-form"
            enctype="multipart/form-data"
            data-goods-receipt-form
        >
            @csrf
            @if ($isEditing)
                @method('PUT')
            @endif

            <div class="form-grid form-grid--tight">
                <label class="auth-field">
                    <span>Cliente</span>
                    <select name="client_id" class="auth-input" required data-receipt-client>
                        <option value="">Selecciona cliente</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) old('client_id', $receipt->client_id) === (string) $client->id)>
                                {{ $client->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Proveedor</span>
                    <select name="supplier_id" class="auth-input">
                        <option value="">Sin proveedor</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((string) old('supplier_id', $receipt->supplier_id) === (string) $supplier->id)>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('supplier_id')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Numero de albaran</span>
                    <input type="text" name="receipt_number" value="{{ old('receipt_number', $receipt->receipt_number) }}" class="auth-input" maxlength="150">
                    @error('receipt_number')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Documento externo</span>
                    <input
                        type="text"
                        name="external_document_number"
                        value="{{ old('external_document_number', $receipt->external_document_number) }}"
                        class="auth-input"
                        maxlength="150"
                    >
                    @error('external_document_number')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Fecha recepcion</span>
                    <input
                        type="date"
                        name="received_at"
                        value="{{ old('received_at', optional($receipt->received_at)->format('Y-m-d')) }}"
                        class="auth-input"
                    >
                    @error('received_at')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="auth-field">
                    <span>Adjunto albaran</span>
                    <input type="file" name="document" class="auth-input" accept=".pdf,.jpg,.jpeg,.png,.webp">
                    @error('document')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                    @if ($receipt->document_original_name)
                        <small class="helper-text">Actual: {{ $receipt->document_original_name }}</small>
                    @endif
                </label>

                <label class="auth-field item-form-field--full">
                    <span>Notas</span>
                    <textarea name="notes" rows="4" class="auth-input">{{ old('notes', $receipt->notes) }}</textarea>
                    @error('notes')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>

            <section class="goods-receipt-lines-card">
                <div class="goods-receipt-lines-tools">
                    <div class="app-copy">
                        <strong>Lineas de entrada</strong>
                        <p>Selecciona articulo y cantidad; el sistema calcula palets y pico.</p>
                    </div>

                    <button type="button" class="button-secondary compact-button btn-compact" data-add-line>Añadir línea</button>
                </div>

                <div class="data-table-wrap goods-receipt-lines-wrap">
                    <table class="data-table table-compact goods-receipt-lines-table" aria-label="Lineas de entrada">
                        <thead>
                            <tr>
                                <th>Articulo</th>
                                <th>SKU</th>
                                <th>Descripcion</th>
                                <th>Lote</th>
                                <th>Total uds</th>
                                <th>Uds/palet</th>
                                <th>Palets</th>
                                <th>Pico</th>
                                <th>Ubicacion</th>
                                <th>Notas</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody data-receipt-lines>
                            @foreach ($lineValues as $index => $line)
                                @include('goods-receipts._line-row', ['index' => $index, 'line' => $line])
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <template data-line-template>
                    @include('goods-receipts._line-row', ['index' => '__INDEX__', 'line' => null])
                </template>

                <p class="helper-text">Puedes ajustar manualmente las uds/palet de una entrada concreta sin cambiar el maestro del articulo.</p>
            </section>

            <div class="item-form-actions action-buttons">
                <a href="{{ $isEditing ? route('goods-receipts.show', $receipt) : route('goods-receipts.index') }}" class="button-secondary compact-button btn-compact">
                    Cancelar
                </a>
                <button type="submit" class="button-primary compact-button btn-compact">
                    {{ $isEditing ? 'Guardar entrada' : 'Crear borrador' }}
                </button>
            </div>
        </form>
    </section>
</div>

<script type="application/json" data-goods-receipt-items>@json($itemsCatalog)</script>
