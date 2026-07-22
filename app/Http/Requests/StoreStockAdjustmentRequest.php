<?php

namespace App\Http\Requests;

use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Services\Locations\LocationIntegrityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStockAdjustmentRequest extends FormRequest
{
    public const ACTION_ADD = 'add';

    public const ACTION_REMOVE = 'remove';

    public const MODE_EXISTING = 'existing';

    public const MODE_NEW = 'new';

    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::SUPERADMIN) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'action' => $this->normalizeString($this->input('action')),
            'mode' => $this->normalizeString($this->input('mode')) ?: self::MODE_EXISTING,
            'client_id' => $this->normalizeInteger($this->input('client_id')),
            'item_id' => $this->normalizeInteger($this->input('item_id')),
            'stock_pallet_id' => $this->normalizeInteger($this->input('stock_pallet_id')),
            'location_id' => $this->normalizeInteger($this->input('location_id')),
            'full_pallets' => $this->normalizeInteger($this->input('full_pallets')),
            'units_per_pallet' => $this->normalizeInteger($this->input('units_per_pallet')),
            'peak_units' => $this->normalizeInteger($this->input('peak_units')) ?? 0,
            'lot' => $this->normalizeString($this->input('lot')),
            'status' => $this->normalizeString($this->input('status')) ?: StockPallet::STATUS_AVAILABLE,
            'stock_category' => $this->normalizeString($this->input('stock_category')) ?: StockPallet::CATEGORY_IN_USE,
            'note' => $this->normalizeString($this->input('note')),
        ]);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'action' => ['required', Rule::in([self::ACTION_ADD, self::ACTION_REMOVE])],
            'mode' => ['required', Rule::in([self::MODE_EXISTING, self::MODE_NEW])],
            'stock_pallet_id' => ['nullable', 'integer', 'exists:stock_pallets,id'],
            'lot' => ['nullable', 'string', 'max:100'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'status' => ['required', Rule::in(StockPallet::statuses())],
            'stock_category' => ['required', Rule::in(StockPallet::stockCategories())],
            'full_pallets' => ['required', 'integer', 'min:0', 'max:999999'],
            'units_per_pallet' => ['required', 'integer', 'min:1', 'max:999999999'],
            'peak_units' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:1000'],
            'confirmed' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmed.accepted' => 'Debes confirmar la regularizacion manual.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $item = Item::query()->whereKey($this->itemId())->first();

            if (! $item instanceof Item || (int) $item->client_id !== $this->clientId()) {
                $validator->errors()->add('item_id', 'La referencia seleccionada no pertenece al cliente indicado.');
            }

            if ($this->quantityDelta() <= 0) {
                $validator->errors()->add('full_pallets', 'El ajuste debe ser mayor que cero.');
            }

            if ($this->action() === self::ACTION_REMOVE && $this->mode() !== self::MODE_EXISTING) {
                $validator->errors()->add('mode', 'Para quitar stock debes seleccionar una partida existente.');
            }

            if ($this->requiresExistingStock() && $this->stockPalletId() === null) {
                $validator->errors()->add('stock_pallet_id', 'Selecciona la partida concreta que quieres regularizar.');
            }

            $stockPallet = $this->stockPalletId() !== null
                ? StockPallet::query()->whereKey($this->stockPalletId())->first()
                : null;

            if ($stockPallet instanceof StockPallet) {
                if ((int) $stockPallet->client_id !== $this->clientId() || (int) $stockPallet->item_id !== $this->itemId()) {
                    $validator->errors()->add('stock_pallet_id', 'La partida seleccionada no pertenece al cliente y referencia indicados.');
                }

                if ((int) $stockPallet->units_per_pallet > 0 && (int) $stockPallet->units_per_pallet !== $this->unitsPerPallet()) {
                    $validator->errors()->add('units_per_pallet', 'Las unidades por pallet deben coincidir con la partida existente.');
                }

                if ($this->action() === self::ACTION_REMOVE && $this->quantityDelta() > (int) $stockPallet->quantity_units) {
                    $validator->errors()->add('full_pallets', 'No puedes quitar mas stock del disponible en la partida seleccionada.');
                }
            }

            if ($this->locationId() !== null) {
                $location = Location::query()
                    ->with('warehouse')
                    ->whereKey($this->locationId())
                    ->first();

                if (! $location instanceof Location || ! (bool) $location->active || ! (bool) $location->warehouse?->active) {
                    $validator->errors()->add('location_id', 'La ubicacion debe estar activa.');

                    return;
                }

                $warehouseClientId = $location->warehouse?->client_id;
                if ($warehouseClientId !== null && (int) $warehouseClientId !== $this->clientId()) {
                    $validator->errors()->add('location_id', 'La ubicacion no pertenece a un almacen compatible con el cliente.');
                }

                $canonicalLocation = app(LocationIntegrityService::class)
                    ->canonicalActiveLocations(Location::query()
                        ->with('warehouse')
                        ->where('warehouse_id', $location->warehouse_id)
                        ->where('active', true)
                        ->get())
                    ->first(fn (Location $candidate): bool => (int) $candidate->id === (int) $location->id);

                if (! $canonicalLocation instanceof Location) {
                    $validator->errors()->add('location_id', 'La ubicacion esta duplicada. Usa la ubicacion canonica activa.');
                }
            }
        });
    }

    public function action(): string
    {
        return (string) $this->input('action');
    }

    public function mode(): string
    {
        return (string) $this->input('mode');
    }

    public function clientId(): int
    {
        return $this->integer('client_id');
    }

    public function itemId(): int
    {
        return $this->integer('item_id');
    }

    public function stockPalletId(): ?int
    {
        $value = $this->integer('stock_pallet_id');

        return $value > 0 ? $value : null;
    }

    public function locationId(): ?int
    {
        $value = $this->integer('location_id');

        return $value > 0 ? $value : null;
    }

    public function fullPallets(): int
    {
        return $this->integer('full_pallets');
    }

    public function unitsPerPallet(): int
    {
        return $this->integer('units_per_pallet');
    }

    public function peakUnits(): int
    {
        return $this->integer('peak_units');
    }

    public function lot(): string
    {
        $lot = $this->normalizeString($this->input('lot'));

        return $lot !== null && $lot !== '' ? $lot : 'SIN LOTE';
    }

    public function stockStatus(): string
    {
        return (string) $this->input('status');
    }

    public function stockCategory(): string
    {
        return (string) $this->input('stock_category');
    }

    public function note(): ?string
    {
        return $this->normalizeString($this->input('note'));
    }

    public function quantityDelta(): int
    {
        return ($this->fullPallets() * $this->unitsPerPallet()) + $this->peakUnits();
    }

    public function signedQuantityDelta(): int
    {
        return $this->action() === self::ACTION_REMOVE
            ? -1 * $this->quantityDelta()
            : $this->quantityDelta();
    }

    public function requiresExistingStock(): bool
    {
        return $this->action() === self::ACTION_REMOVE || $this->mode() === self::MODE_EXISTING;
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
