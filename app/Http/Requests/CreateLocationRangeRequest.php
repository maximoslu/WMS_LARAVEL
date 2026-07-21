<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Services\Locations\LocationCatalogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateLocationRangeRequest extends FormRequest
{
    public const LARGE_RANGE_THRESHOLD = 1000;

    public const HUGE_RANGE_THRESHOLD = 10000;

    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) === true;
    }

    protected function prepareForValidation(): void
    {
        $catalog = app(LocationCatalogService::class);

        $this->merge([
            'warehouse_id' => $this->normalizeNullableInteger($this->input('warehouse_id')),
            'type' => $catalog->normalizeType($this->input('type')),
            'from' => $this->normalizeNullableInteger($this->input('from')),
            'to' => $this->normalizeNullableInteger($this->input('to')),
            'range_confirmation' => trim((string) $this->input('range_confirmation')),
        ]);
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'type' => ['required', 'string'],
            'from' => ['required', 'integer', 'min:0'],
            'to' => ['required', 'integer', 'min:0'],
            'range_confirmation' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->from() > $this->to()) {
                $validator->errors()->add('to', 'El valor final debe ser mayor o igual que el inicial.');

                return;
            }

            $count = $this->count();

            if ($count > self::HUGE_RANGE_THRESHOLD && $this->input('range_confirmation') !== $this->hugeConfirmation()) {
                $validator->errors()->add('range_confirmation', 'Para este rango escribe exactamente: '.$this->hugeConfirmation());

                return;
            }

            if ($count > self::HUGE_RANGE_THRESHOLD) {
                return;
            }

            if ($count > self::LARGE_RANGE_THRESHOLD && $this->input('range_confirmation') !== 'CREAR RANGO') {
                $validator->errors()->add('range_confirmation', 'Para rangos de mas de 1000 ubicaciones escribe exactamente: CREAR RANGO.');
            }
        });
    }

    public function warehouseId(): int
    {
        return $this->integer('warehouse_id');
    }

    public function type(): string
    {
        return (string) $this->input('type');
    }

    public function from(): int
    {
        return $this->integer('from');
    }

    public function to(): int
    {
        return $this->integer('to');
    }

    public function count(): int
    {
        return $this->to() - $this->from() + 1;
    }

    public function hugeConfirmation(): string
    {
        return 'CREAR RANGO '.$this->count();
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
