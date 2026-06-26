<?php

namespace App\Http\Requests;

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
            'lines.*.loaded_pallets' => ['required', 'integer', 'min:0'],
            'lines.*.loading_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dispatch = $this->route('goodsDispatch');

            if ($dispatch === null) {
                return;
            }

            $existingLineIds = $dispatch->lines()->pluck('id')->map(fn ($id) => (string) $id)->all();
            $submittedLineIds = collect($this->input('lines', []))->keys()->all();

            if (array_diff($submittedLineIds, $existingLineIds) !== []) {
                $validator->errors()->add('lines', 'Hay lineas de carga no validas para esta salida.');
            }
        });
    }
}
