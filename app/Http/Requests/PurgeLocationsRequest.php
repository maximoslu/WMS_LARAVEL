<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PurgeLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::SUPERADMIN) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'scope' => in_array($this->input('scope'), ['warehouse', 'all'], true) ? $this->input('scope') : 'warehouse',
            'warehouse_id' => $this->normalizeNullableInteger($this->input('warehouse_id')),
            'confirmation' => trim((string) $this->input('confirmation')),
        ]);
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', 'in:warehouse,all'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'confirmation' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->scope() === 'warehouse' && $this->warehouseId() === null) {
                $validator->errors()->add('warehouse_id', 'Selecciona un almacen para purgar sus ubicaciones.');

                return;
            }

            $expected = $this->expectedConfirmation();

            if ($this->input('confirmation') !== $expected) {
                $validator->errors()->add('confirmation', 'Debes escribir exactamente: '.$expected);
            }
        });
    }

    public function scope(): string
    {
        return (string) $this->input('scope', 'warehouse');
    }

    public function warehouseId(): ?int
    {
        $warehouseId = $this->integer('warehouse_id');

        return $warehouseId > 0 ? $warehouseId : null;
    }

    public function expectedConfirmation(): string
    {
        if ($this->scope() === 'all') {
            return 'ELIMINAR UBICACIONES';
        }

        $warehouseCode = Warehouse::query()->whereKey($this->warehouseId())->value('code');

        return 'ELIMINAR UBICACIONES '.($warehouseCode ?: $this->warehouseId());
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
