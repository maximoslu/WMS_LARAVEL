<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Services\Locations\LocationIntegrityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStockPalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'location_id' => $this->normalizeNullableInteger($this->input('location_id')),
        ]);
    }

    public function rules(): array
    {
        return [
            'location_id' => ['nullable', 'exists:locations,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $locationId = $this->locationId();

                if ($locationId === null) {
                    return;
                }

                $stockPallet = $this->route('stockPallet');
                $location = Location::query()->with('warehouse')->find($locationId);

                if (! $stockPallet instanceof StockPallet || ! $location instanceof Location) {
                    return;
                }

                if (! $location->active || ! $location->warehouse?->active) {
                    $validator->errors()->add('location_id', 'Selecciona una ubicacion activa.');

                    return;
                }

                if ($location->warehouse->client_id !== null && $location->warehouse->client_id !== $stockPallet->client_id) {
                    $validator->errors()->add('location_id', 'La ubicacion seleccionada no pertenece a este cliente.');

                    return;
                }

                $canonicalIds = app(LocationIntegrityService::class)
                    ->canonicalActiveLocationsForStock($stockPallet)
                    ->pluck('id')
                    ->all();

                if (! in_array($location->id, $canonicalIds, true)) {
                    $validator->errors()->add('location_id', 'Selecciona la ubicacion canonica activa.');
                }
            },
        ];
    }

    public function locationId(): ?int
    {
        $locationId = $this->integer('location_id');

        return $locationId > 0 ? $locationId : null;
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
