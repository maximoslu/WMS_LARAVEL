<?php

namespace App\Services\MerchandiseRequests;

use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestEvent;
use App\Models\MerchandiseRequestLine;
use App\Models\StockPallet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MerchandiseRequestPreparationService
{
    public function prepare(MerchandiseRequest $merchandiseRequest, User $user): MerchandiseRequest
    {
        if ($merchandiseRequest->status === MerchandiseRequest::STATUS_PREPARED) {
            throw ValidationException::withMessages([
                'merchandise_request' => 'La solicitud ya esta preparada y no puede descontar stock dos veces.',
            ]);
        }

        if ($merchandiseRequest->status === MerchandiseRequest::STATUS_SHIPPED) {
            throw ValidationException::withMessages([
                'merchandise_request' => 'La solicitud ya esta enviada y no puede volver a prepararse.',
            ]);
        }

        if ($merchandiseRequest->status === MerchandiseRequest::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'merchandise_request' => 'La solicitud cancelada no puede prepararse.',
            ]);
        }

        return DB::transaction(function () use ($merchandiseRequest, $user): MerchandiseRequest {
            $merchandiseRequest->loadMissing(['client', 'lines.item']);

            if ($merchandiseRequest->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'merchandise_request' => 'La solicitud debe tener al menos una linea para poder prepararse.',
                ]);
            }

            foreach ($merchandiseRequest->lines as $line) {
                $this->prepareLine($merchandiseRequest, $line);
            }

            $merchandiseRequest->forceFill([
                'status' => MerchandiseRequest::STATUS_PREPARED,
                'prepared_by' => $user->id,
                'prepared_at' => now(),
            ])->save();

            $merchandiseRequest->events()->create([
                'user_id' => $user->id,
                'event_type' => 'prepared',
                'title' => 'Pedido preparado',
                'description' => 'Se ha descontado el stock y la solicitud queda lista para expedicion.',
            ]);

            return $merchandiseRequest->refresh();
        });
    }

    private function prepareLine(MerchandiseRequest $merchandiseRequest, MerchandiseRequestLine $line): void
    {
        $item = $line->item;

        if ($item === null || (int) $item->client_id !== (int) $merchandiseRequest->client_id) {
            throw ValidationException::withMessages([
                'merchandise_request' => "La linea {$line->id} no pertenece al cliente de la solicitud.",
            ]);
        }

        $requestedUnits = (int) $line->requested_units;
        $unitsPerPallet = (int) $line->units_per_pallet;

        if ($requestedUnits <= 0 || $unitsPerPallet <= 0) {
            throw ValidationException::withMessages([
                'merchandise_request' => "La linea {$line->id} no tiene una cantidad valida para prepararse.",
            ]);
        }

        $availablePallets = StockPallet::query()
            ->where('client_id', $merchandiseRequest->client_id)
            ->where('item_id', $line->item_id)
            ->where('active', true)
            ->orderByRaw('CASE WHEN quantity_units = ? THEN 0 ELSE 1 END', [$unitsPerPallet])
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        $selection = $this->resolveSelection($availablePallets, $line, $requestedUnits, $unitsPerPallet);

        if ($selection === null) {
            throw ValidationException::withMessages([
                'merchandise_request' => "Stock insuficiente para preparar {$line->requested_pallets} palets de {$item->sku}.",
            ]);
        }

        foreach ($selection as $stockPallet) {
            $stockPallet->forceFill([
                'active' => false,
            ])->save();
        }

        $line->forceFill([
            'prepared_pallets' => (int) $line->requested_pallets,
            'prepared_units' => $requestedUnits,
        ])->save();
    }

    /**
     * @param  Collection<int, StockPallet>  $availablePallets
     * @return Collection<int, StockPallet>|null
     */
    private function resolveSelection(Collection $availablePallets, MerchandiseRequestLine $line, int $requestedUnits, int $unitsPerPallet): ?Collection
    {
        $fullPallets = $availablePallets
            ->filter(fn (StockPallet $stockPallet): bool => (int) $stockPallet->quantity_units === $unitsPerPallet)
            ->values();

        if ($fullPallets->count() >= (int) $line->requested_pallets) {
            return $fullPallets->take((int) $line->requested_pallets)->values();
        }

        return $this->findExactCombination($availablePallets->values(), $requestedUnits);
    }

    /**
     * @param  Collection<int, StockPallet>  $pallets
     * @return Collection<int, StockPallet>|null
     */
    private function findExactCombination(Collection $pallets, int $targetUnits): ?Collection
    {
        $memo = [];

        $search = function (int $index, int $remaining) use ($pallets, &$search, &$memo): ?array {
            if ($remaining === 0) {
                return [];
            }

            if ($remaining < 0 || $index >= $pallets->count()) {
                return null;
            }

            $memoKey = $index.'|'.$remaining;

            if (array_key_exists($memoKey, $memo)) {
                return $memo[$memoKey];
            }

            /** @var StockPallet $current */
            $current = $pallets[$index];

            $withCurrent = $search($index + 1, $remaining - (int) $current->quantity_units);

            if ($withCurrent !== null) {
                return $memo[$memoKey] = [$current->id, ...$withCurrent];
            }

            return $memo[$memoKey] = $search($index + 1, $remaining);
        };

        $selectedIds = $search(0, $targetUnits);

        if ($selectedIds === null) {
            return null;
        }

        return $pallets
            ->whereIn('id', $selectedIds)
            ->sortBy(fn (StockPallet $stockPallet) => array_search($stockPallet->id, $selectedIds, true))
            ->values();
    }
}
