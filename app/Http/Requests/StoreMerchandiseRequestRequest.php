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
                $validator->errors()->add('quantities', 'Debes seleccionar al menos una mercancia con pallets mayores que cero.');
            }

            $allowedItemIds = Item::query()
                ->where('client_id', $this->user()->client_id)
                ->where('active', true)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $requestedItemIds = array_map(
                static fn (array $line): string => (string) $line['item_id'],
                $lines
            );

            $invalidItemIds = array_diff($requestedItemIds, $allowedItemIds);

            if ($invalidItemIds !== []) {
                $validator->errors()->add('quantities', 'Hay mercancias no validas para este cliente.');
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
