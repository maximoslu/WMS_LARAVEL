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
        return true;
    }

    protected function prepareForValidation(): void
    {
        $user = $this->user();

        $lines = collect($this->input('lines', []))
            ->map(function (mixed $line): array {
                $line = is_array($line) ? $line : [];

                $itemId = $this->normalizeNullableInteger($line['item_id'] ?? null);
                $item = $itemId !== null ? Item::query()->find($itemId) : null;
                $requestedPallets = $this->normalizeInteger($line['requested_pallets'] ?? 0);
                $unitsPerPallet = $item?->units_per_pallet !== null
                    ? (int) $item->units_per_pallet
                    : $this->normalizeInteger($line['units_per_pallet'] ?? 0);

                return [
                    'item_id' => $itemId,
                    'lot' => $this->normalizeNullableUpper($line['lot'] ?? null) ?? $this->normalizeNullableUpper($item?->lot),
                    'requested_pallets' => $requestedPallets,
                    'units_per_pallet' => $unitsPerPallet,
                    'requested_units' => $requestedPallets > 0 && $unitsPerPallet > 0
                        ? $requestedPallets * $unitsPerPallet
                        : 0,
                    'notes' => $this->normalizeNullableText($line['notes'] ?? null),
                ];
            })
            ->filter(fn (array $line): bool => $line['item_id'] !== null || $line['requested_pallets'] > 0 || $line['notes'] !== null)
            ->values()
            ->all();

        $payload = [
            'delivery_reference' => $this->normalizeNullableUpper($this->input('delivery_reference')),
            'delivery_address' => $this->normalizeNullableText($this->input('delivery_address')),
            'notes' => $this->normalizeNullableText($this->input('notes')),
            'lines' => $lines,
        ];

        if ($user !== null && $user->hasRole(Role::CLIENTE)) {
            $payload['client_id'] = $user->client_id;
        }

        $this->merge($payload);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'delivery_reference' => ['nullable', 'string', 'max:150'],
            'delivery_address' => ['nullable', 'string'],
            'requested_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.lot' => ['nullable', 'string', 'max:100'],
            'lines.*.requested_pallets' => ['required', 'integer', 'min:1'],
            'lines.*.units_per_pallet' => ['required', 'integer', 'min:1'],
            'lines.*.requested_units' => ['required', 'integer', 'min:1'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $clientId = $this->integer('client_id');

            foreach ($this->input('lines', []) as $index => $line) {
                $itemId = (int) ($line['item_id'] ?? 0);

                if ($itemId <= 0) {
                    continue;
                }

                $item = Item::query()->find($itemId);

                if ($item === null) {
                    $validator->errors()->add("lines.$index.item_id", 'El articulo seleccionado no existe.');
                    continue;
                }

                if ((int) $item->client_id !== $clientId) {
                    $validator->errors()->add("lines.$index.item_id", 'El articulo debe pertenecer al cliente de la solicitud.');
                }

                if ((int) ($line['requested_units'] ?? 0) !== ((int) ($line['requested_pallets'] ?? 0) * (int) ($line['units_per_pallet'] ?? 0))) {
                    $validator->errors()->add("lines.$index.requested_pallets", 'Los palets solicitados y las uds/palet deben cuadrar con el total.');
                }
            }

            if ($this->user()?->hasRole(Role::CLIENTE) && (int) $this->user()?->client_id !== $clientId) {
                $validator->errors()->add('client_id', 'El cliente solo puede crear solicitudes para su propio cliente.');
            }
        });
    }

    private function normalizeInteger(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeNullableUpper(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_strtoupper($normalized);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
