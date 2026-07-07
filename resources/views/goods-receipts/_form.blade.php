@php
    use App\Http\Requests\StoreGoodsReceiptRequest;

    $isEditing = $receipt->exists;
    $breadcrumbs = [
        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'Operaciones'],
        ['label' => 'Entradas', 'href' => route('goods-receipts.index')],
        ['label' => $isEditing ? 'Editar' : 'Crear'],
    ];
@endphp
<x-breadcrumbs :items="$breadcrumbs" />

<div class="goods-receipt-shell">
    <section class="surface-card item-form-card entity-form compact-card goods-receipt-form-card">
        <div class="app-copy">
            <span class="status-chip small-badge badge-compact">{{ $isEditing ? 'Edicion' : 'Borrador' }}</span>
            <h2 class="ops-page-title page-title-compact">{{ $isEditing ? 'Editar entrada' : 'Nueva entrada de mercancia' }}</h2>
            @if ($isEditing && $receipt->isConfirmed())
                <p>Esta entrada ya esta confirmada. Al guardar, el stock generado se revertira y se volvera a aplicar con los datos nuevos.</p>
            @else
                <p>Registra la cabecera y las lineas operativas. El stock quedara pendiente hasta confirmar la entrada.</p>
            @endif
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

                @include('goods-receipts._supplier-picker', ['receipt' => $receipt])

                <label class="auth-field">
                    <span>Numero de albaran</span>
                    <input type="text" name="receipt_number" value="{{ old('receipt_number', $receipt->receipt_number) }}" class="auth-input" maxlength="150">
                    @error('receipt_number')
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

                <label class="auth-field item-form-field--full">
                    <span>Documento del proveedor / albaran</span>
                    <input type="file" name="document" class="auth-input" accept=".pdf,.jpg,.jpeg,.png,.webp" data-receipt-document-input>
                    @error('document')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                    <small class="helper-text">Adjunta el albaran en PDF o foto. Puedes crear la entrada manualmente o dejar que la IA proponga las lineas para revision.</small>
                    @if ($receipt->document_original_name)
                        <small class="helper-text">Actual: {{ $receipt->document_original_name }}</small>
                    @endif
                </label>

                <label class="auth-field item-form-field--full goods-receipt-notes-field">
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
                        <p>Busca el articulo, indica cantidad y revisa el paletizado. Si el SKU no existe, podras crearlo desde la propia linea.</p>
                        @if (! $isEditing)
                            <p class="goods-receipt-ai-form-note">Si vas a interpretar un albaran con IA, puedes dejar las lineas vacias. La propuesta aparecera en el siguiente paso para revision.</p>
                        @endif
                    </div>

                    <button type="button" class="button-secondary compact-button btn-compact" data-add-line>Añadir linea</button>
                </div>

                <div class="goods-receipt-line-list" data-receipt-lines aria-label="Lineas de entrada">
                    @foreach ($lineValues as $index => $line)
                        @include('goods-receipts._line-row', ['index' => $index, 'line' => $line])
                    @endforeach
                </div>

                <template data-line-template>
                    @include('goods-receipts._line-row', ['index' => '__INDEX__', 'line' => null])
                </template>

                <p class="helper-text">Las uds/palet pueden ajustarse para esta recepcion sin cambiar el maestro del articulo existente.</p>
            </section>

            <div class="item-form-actions action-buttons">
                <a href="{{ $isEditing ? route('goods-receipts.show', $receipt) : route('goods-receipts.index') }}" class="button-secondary compact-button btn-compact">
                    Cancelar
                </a>

                @if ($isEditing)
                    <button type="submit" class="button-primary compact-button btn-compact">
                        Guardar entrada
                    </button>
                @else
                    <button type="submit" name="action" value="{{ StoreGoodsReceiptRequest::ACTION_CREATE_DRAFT }}" class="button-secondary compact-button btn-compact">
                        Crear borrador
                    </button>
                    <button
                        type="submit"
                        name="action"
                        value="{{ StoreGoodsReceiptRequest::ACTION_CREATE_AND_EXTRACT_AI }}"
                        class="button-primary compact-button btn-compact goods-receipt-ai-submit"
                        data-ai-create-submit
                        disabled
                    >
                        Crear borrador e interpretar con IA
                    </button>
                @endif
            </div>

            @if (! $isEditing)
                <p class="helper-text goods-receipt-ai-submit-help" data-ai-submit-help>Adjunta un albaran para activar la interpretacion IA desde este paso.</p>
            @endif
        </form>
    </section>
</div>
