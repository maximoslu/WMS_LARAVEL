<?php

namespace App\Http\Requests;

use App\Models\Item;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMerchandiseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::CLIENTE) && $this->user()?->client_id !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'quantities' => collect($this->input('quantities', []))
                ->mapWithKeys(fn ($value, $key) => [(string) $key => $value === '' ? null : $value])
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lines = $this->validatedLines();

            if ($lines === []) {
                $validator->errors()->add('quantities', 'Debes seleccionar al menos una mercancía con pallets mayores que cero.');
                return;
            }

            $requestedItemIds = collect($lines)
                ->pluck('item_id')
                ->map(fn ($itemId) => (int) $itemId)
                ->unique()
                ->values();

            $allowedItemIds = Item::query()
                ->where('client_id', $this->user()->client_id)
                ->where('active', true)
                ->whereIn('id', $requestedItemIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $invalidItemIds = $requestedItemIds
                ->reject(fn (int $itemId) => in_array($itemId, $allowedItemIds, true))
                ->all();

            if ($invalidItemIds !== []) {
                $validator->errors()->add('quantities', 'Hay mercancías no válidas para este cliente.');
            }
        });
    }

    /**
     * @return array<int, array{item_id: int, requested_pallets: int}>
     */
    public function validatedLines(): array
    {
        return collect($this->input('quantities', []))
            ->map(function ($pallets, $itemId): ?array {
                $requestedPallets = (int) $pallets;

                if ($requestedPallets <= 0 || ! is_numeric((string) $itemId)) {
                    return null;
                }

                return [
                    'item_id' => (int) $itemId,
                    'requested_pallets' => $requestedPallets,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
