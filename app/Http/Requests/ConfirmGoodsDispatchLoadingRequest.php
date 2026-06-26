<?php

namespace App\Http\Requests;

use App\Models\Item;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfirmGoodsDispatchLoadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['nullable', 'integer'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.loaded_pallets' => ['required', 'integer', 'min:0'],
            'lines.*.loading_notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.remove' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dispatch = $this->route('goodsDispatch');

            if ($dispatch === null) {
                return;
            }

            $existingLineIds = $dispatch->lines()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $validItemIds = Item::query()
                ->where('client_id', $dispatch->client_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $submittedLines = collect($this->input('lines', []));

            if ($submittedLines->isEmpty()) {
                $validator->errors()->add('lines', 'Debes informar al menos una linea de carga real.');

                return;
            }

            $hasPositiveLoadedLine = false;

            foreach ($submittedLines as $index => $payload) {
                $lineId = isset($payload['line_id'])
                    ? (int) $payload['line_id']
                    : (is_numeric($index) || preg_match('/^\d+$/', (string) $index) ? (int) $index : null);
                $itemId = isset($payload['item_id']) ? (int) $payload['item_id'] : null;
                $loadedPallets = isset($payload['loaded_pallets']) ? (int) $payload['loaded_pallets'] : null;
                $removeLine = filter_var($payload['remove'] ?? false, FILTER_VALIDATE_BOOL);

                if ($lineId !== null && ! in_array($lineId, $existingLineIds, true)) {
                    $validator->errors()->add("lines.$index.line_id", 'Hay lineas de carga no validas para esta salida.');
                }

                if ($lineId === null) {
                    if ($removeLine) {
                        continue;
                    }

                    if ($itemId === null || ! in_array($itemId, $validItemIds, true)) {
                        $validator->errors()->add("lines.$index.item_id", 'Selecciona una mercancia valida para la carga real.');
                    }
                }

                if (! $removeLine && $loadedPallets !== null && $loadedPallets > 0) {
                    $hasPositiveLoadedLine = true;
                }
            }

            if (! $hasPositiveLoadedLine) {
                $validator->errors()->add('lines', 'Debes confirmar al menos una linea con pallets cargados mayores que cero.');
            }
        });
    }
}
