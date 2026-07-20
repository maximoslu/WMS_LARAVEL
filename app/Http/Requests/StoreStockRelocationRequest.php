<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreStockRelocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_id' => $this->normalizeInteger($this->input('client_id')),
            'item_id' => $this->normalizeInteger($this->input('item_id')),
            'stock_pallet_id' => $this->normalizeInteger($this->input('stock_pallet_id')),
            'destination_location_id' => $this->normalizeInteger($this->input('destination_location_id')),
        ]);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'stock_pallet_id' => ['required', 'integer', 'exists:stock_pallets,id'],
            'destination_location_id' => ['required', 'integer', 'exists:locations,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $clientId = $this->integer('client_id');
            $itemId = $this->integer('item_id');
            $stockPallet = StockPallet::query()
                ->with(['item', 'location.warehouse'])
                ->whereKey($this->integer('stock_pallet_id'))
                ->first();
            $destination = Location::query()
                ->with('warehouse')
                ->whereKey($this->integer('destination_location_id'))
                ->first();

            if (! $stockPallet instanceof StockPallet) {
                return;
            }

            if ((int) $stockPallet->client_id !== $clientId || (int) $stockPallet->item_id !== $itemId) {
                $validator->errors()->add('stock_pallet_id', 'La partida seleccionada no pertenece al cliente y referencia indicados.');
            }

            if (! (bool) $stockPallet->active || ! $this->hasPhysicalStock($stockPallet)) {
                $validator->errors()->add('stock_pallet_id', 'La partida seleccionada no tiene stock fisico activo para reubicar.');
            }

            if (! $destination instanceof Location || ! (bool) $destination->active || ! (bool) $destination->warehouse?->active) {
                $validator->errors()->add('destination_location_id', 'La ubicacion destino debe estar activa.');

                return;
            }

            $destinationClientId = $destination->warehouse?->client_id;
            if ($destinationClientId !== null && (int) $destinationClientId !== $clientId) {
                $validator->errors()->add('destination_location_id', 'La ubicacion destino no pertenece a un almacen compatible con el cliente.');
            }

            if ((int) $stockPallet->location_id === (int) $destination->id) {
                $validator->errors()->add('destination_location_id', 'La ubicacion destino debe ser distinta de la ubicacion actual.');
            }
        });
    }

    public function clientId(): int
    {
        return $this->integer('client_id');
    }

    public function itemId(): int
    {
        return $this->integer('item_id');
    }

    public function stockPalletId(): int
    {
        return $this->integer('stock_pallet_id');
    }

    public function destinationLocationId(): int
    {
        return $this->integer('destination_location_id');
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function hasPhysicalStock(StockPallet $stockPallet): bool
    {
        $peakTotal = collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
            ->sum(fn (int $peakNumber): int => (int) ($stockPallet->{'peak_'.$peakNumber} ?? 0));

        return (int) $stockPallet->quantity_units > 0
            || (int) $stockPallet->full_pallets > 0
            || (float) ($stockPallet->warehouse_pallets ?? 0) > 0.0
            || $peakTotal > 0;
    }
}
