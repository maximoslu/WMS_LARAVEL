<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDecaDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['tractor_plate', 'trailer_plate', 'shipper_tax_id'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => mb_strtoupper(trim($this->input($field)))]);
            }
        }
        if (is_string($this->input('weight_kg'))) {
            $this->merge(['weight_kg' => str_replace(',', '.', $this->input('weight_kg'))]);
        }
    }

    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'carrier_key' => ['required', Rule::in(array_keys(config('deca.carriers')))],
            'shipper_name' => ['required', 'string', 'max:180'],
            'shipper_tax_id' => ['required', 'string', 'max:30'],
            'shipper_address' => ['required', 'string', 'max:300'],
            'origin' => ['required', 'string', 'max:300'],
            'destination' => ['required', 'string', 'max:300'],
            'transport_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Europe/Madrid')->toDateString()],
            'goods' => ['required', 'string', 'max:1200'],
            'weight_kg' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,3'],
            'tractor_plate' => ['required', 'string', 'max:20'],
            'articulated' => ['required', 'boolean'],
            'trailer_plate' => ['exclude_unless:articulated,1', 'required', 'string', 'max:20'],
            'special_authorization' => ['required', 'boolean'],
            'authorization_number' => ['exclude_unless:special_authorization,1', 'required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'confirmed' => ['accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $key = $this->input('carrier_key');
            if (is_string($key) && array_key_exists($key, config('deca.carriers'))
                && blank(config('deca.carriers.'.$key.'.tax_id'))) {
                $validator->errors()->add('carrier_key', 'Falta configurar el NIF del transportista. Contacta con administración.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'carrier_key' => 'empresa transportista', 'shipper_name' => 'cargador contractual',
            'shipper_tax_id' => 'NIF del cargador', 'shipper_address' => 'domicilio del cargador',
            'origin' => 'origen', 'destination' => 'destino', 'transport_date' => 'fecha del transporte',
            'goods' => 'mercancía', 'weight_kg' => 'peso en kg', 'tractor_plate' => 'matrícula del vehículo',
            'trailer_plate' => 'matrícula del remolque', 'authorization_number' => 'autorización especial',
            'confirmed' => 'confirmación previa a la salida', 'notes' => 'observaciones',
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Completa el campo :attribute.',
            'accepted' => 'Confirma que has revisado los datos y que el transporte no ha comenzado.',
            'max.string' => 'El campo :attribute no puede superar :max caracteres.',
            'weight_kg.numeric' => 'Introduce un peso válido en kg, sin separadores de miles.',
            'weight_kg.gt' => 'El peso debe ser mayor que cero.',
            'weight_kg.decimal' => 'El peso admite hasta tres decimales.',
            'transport_date.after_or_equal' => 'La fecha del transporte no puede ser anterior a hoy.',
            'carrier_key.in' => 'Selecciona uno de los dos transportistas disponibles.',
        ];
    }
}
