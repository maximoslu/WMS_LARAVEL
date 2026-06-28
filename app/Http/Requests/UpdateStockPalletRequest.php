<?php

namespace App\Http\Requests;

use App\Models\StockPallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStockPalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'lot' => $this->normalizeNullableText($this->input('lot')),
            'location_id' => $this->normalizeNullableInteger($this->input('location_id')),
            'location_text' => $this->normalizeNullableText($this->input('location_text')),
            'received_at' => $this->normalizeNullableText($this->input('received_at')),
            'blocked_reason' => $this->normalizeNullableText($this->input('blocked_reason')),
            'status' => (string) $this->input('status', StockPallet::STATUS_AVAILABLE),
        ]);
    }

    public function rules(): array
    {
        return [
            'lot' => ['nullable', 'string', 'max:255'],
            'quantity_units' => ['required', 'integer', 'min:0'],
            'units_per_pallet' => ['required', 'integer', 'min:0'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'received_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(StockPallet::statuses())],
            'blocked_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $locationId = $this->integer('location_id');
        $status = (string) $this->string('status');

        return [
            'lot' => $this->input('lot'),
            'quantity_units' => $this->integer('quantity_units'),
            'units_per_pallet' => $this->integer('units_per_pallet'),
            'location_id' => $locationId > 0 ? $locationId : null,
            'location_text' => $locationId > 0 ? null : $this->input('location_text'),
            'received_at' => $this->input('received_at'),
            'status' => $status,
            'blocked_reason' => $status === StockPallet::STATUS_BLOCKED
                ? $this->input('blocked_reason')
                : null,
        ];
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
