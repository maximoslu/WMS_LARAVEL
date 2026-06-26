<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\Item;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGoodsDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $clientId = $this->integer('client_id');
            $clientExists = Client::query()->whereKey($clientId)->exists();

            if (! $clientExists) {
                return;
            }

            $lines = $this->validatedLines();

            if ($lines === []) {
                $validator->errors()->add('quantities', 'Debes anadir al menos una linea valida a la salida.');
            }

            $allowedItemIds = Item::query()
                ->where('client_id', $clientId)
                ->where('active', true)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $requestedItemIds = array_map(
                static fn (array $line): string => (string) $line['item_id'],
                $lines
            );

            if (array_diff($requestedItemIds, $allowedItemIds) !== []) {
                $validator->errors()->add('quantities', 'Hay referencias no validas para el cliente seleccionado.');
            }
        });
    }

    /**
     * @return array<int, array{item_id: int, pallets: int}>
     */
    public function validatedLines(): array
    {
        return collect($this->input('quantities', []))
            ->map(function ($pallets, $itemId): ?array {
                $normalized = (int) $pallets;

                if ($normalized <= 0 || ! is_numeric((string) $itemId)) {
                    return null;
                }

                return [
                    'item_id' => (int) $itemId,
                    'pallets' => $normalized,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
