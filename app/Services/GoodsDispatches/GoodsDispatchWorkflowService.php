<?php

namespace App\Services\GoodsDispatches;

use App\Models\GoodsDispatch;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\User;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsDispatchWorkflowService
{
    public function __construct(
        private readonly MerchandiseRequestNotificationService $notificationService,
    ) {}

    /**
     * @param  array<string, array{
     *     line_id?:int|string|null,
     *     item_id?:int|string|null,
     *     loaded_pallets:int|string,
     *     loading_notes?:?string,
     *     remove?:mixed
     * }>  $linePayload
     */
    public function confirmLoading(GoodsDispatch $dispatch, array $linePayload, User $user): void
    {
        $dispatch->loadMissing(['lines', 'merchandiseRequest']);

        DB::transaction(function () use ($dispatch, $linePayload, $user): void {
            $confirmedAt = now();
            $existingLines = $dispatch->lines->keyBy('id');

            foreach ($linePayload as $rowKey => $payload) {
                $lineId = isset($payload['line_id'])
                    ? (int) $payload['line_id']
                    : (is_numeric($rowKey) || preg_match('/^\d+$/', (string) $rowKey) ? (int) $rowKey : null);
                $loadedPallets = (int) $payload['loaded_pallets'];
                $loadingNotes = filled($payload['loading_notes'] ?? null)
                    ? trim((string) $payload['loading_notes'])
                    : null;
                $removeLine = filter_var($payload['remove'] ?? false, FILTER_VALIDATE_BOOL);

                if ($lineId !== null && $existingLines->has($lineId)) {
                    $line = $existingLines->get($lineId);

                    if ($line->is_extra_line && $removeLine) {
                        $line->delete();

                        continue;
                    }

                    $line->update([
                        'loaded_pallets' => $loadedPallets,
                        'loading_notes' => $loadingNotes,
                        'confirmed_by' => $user->id,
                        'confirmed_at' => $confirmedAt,
                    ]);

                    continue;
                }

                if ($removeLine) {
                    continue;
                }

                $item = Item::query()
                    ->whereKey((int) $payload['item_id'])
                    ->where('client_id', $dispatch->client_id)
                    ->first();

                if ($item === null) {
                    throw ValidationException::withMessages([
                        'lines' => 'Alguna referencia extra no es valida para el cliente seleccionado.',
                    ]);
                }

                $dispatch->lines()->create([
                    'item_id' => $item->id,
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'lot' => $item->lot,
                    'units_per_pallet' => $item->units_per_pallet,
                    'pallets' => 0,
                    'requested_units' => 0,
                    'requested_pallets' => 0,
                    'loaded_pallets' => $loadedPallets,
                    'loading_notes' => $loadingNotes,
                    'confirmed_by' => $user->id,
                    'confirmed_at' => $confirmedAt,
                    'is_extra_line' => true,
                ]);
            }

            if ($dispatch->status === GoodsDispatch::STATUS_DRAFT) {
                $dispatch->update([
                    'status' => GoodsDispatch::STATUS_PREPARING,
                ]);
            }

            if ($dispatch->merchandiseRequest !== null && $dispatch->merchandiseRequest->status === MerchandiseRequest::STATUS_PENDING) {
                $dispatch->merchandiseRequest->update([
                    'status' => MerchandiseRequest::STATUS_PREPARING,
                    'prepared_by' => $user->id,
                    'prepared_at' => $dispatch->merchandiseRequest->prepared_at ?? now(),
                ]);
            }
        });

        $freshDispatch = $dispatch->fresh([
            'client',
            'lines.item',
            'merchandiseRequest',
        ]);

        $this->notificationService->notifyLoadingConfirmed($freshDispatch, $user);
    }

    public function changeStatus(GoodsDispatch $dispatch, string $newStatus, User $user): ?string
    {
        $dispatch->loadMissing(['client', 'merchandiseRequest', 'lines.item']);
        $previousDispatchStatus = $dispatch->status;

        if ($previousDispatchStatus === $newStatus) {
            return null;
        }

        $this->guardStatusTransition($dispatch, $newStatus);

        $previousRequestStatus = $dispatch->merchandiseRequest?->status;

        DB::transaction(function () use ($dispatch, $newStatus, $user): void {
            $dispatchPayload = [
                'status' => $newStatus,
            ];

            if (in_array($newStatus, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true) && $dispatch->sent_at === null) {
                $dispatchPayload['sent_at'] = now();
            }

            if ($newStatus === GoodsDispatch::STATUS_COMPLETED && $dispatch->completed_at === null) {
                $dispatchPayload['completed_at'] = now();
            }

            $dispatch->update($dispatchPayload);

            $merchandiseRequest = $dispatch->merchandiseRequest;

            if ($merchandiseRequest === null) {
                return;
            }

            $requestPayload = [
                'status' => $newStatus,
            ];

            if ($newStatus === MerchandiseRequest::STATUS_PREPARING) {
                $requestPayload['prepared_by'] = $user->id;
                $requestPayload['prepared_at'] = $merchandiseRequest->prepared_at ?? now();
            }

            if (in_array($newStatus, [MerchandiseRequest::STATUS_SENT, MerchandiseRequest::STATUS_COMPLETED], true)
                && $merchandiseRequest->shipped_at === null) {
                $requestPayload['shipped_by'] = $user->id;
                $requestPayload['shipped_at'] = now();
            }

            if ($newStatus === MerchandiseRequest::STATUS_COMPLETED && $merchandiseRequest->completed_at === null) {
                $requestPayload['completed_at'] = now();
            }

            if ($newStatus === MerchandiseRequest::STATUS_CANCELLED) {
                $requestPayload['cancelled_at'] = $merchandiseRequest->cancelled_at ?? now();
            }

            $merchandiseRequest->update($requestPayload);
        });

        if ($dispatch->merchandiseRequest !== null && $previousRequestStatus !== null) {
            $this->notificationService->notifyDispatchStatusChanged(
                $dispatch->fresh(['merchandiseRequest']),
                $previousRequestStatus,
                $newStatus,
            );
        }

        return null;
    }

    public function ensureDeliveryNoteCanBeGenerated(GoodsDispatch $dispatch): void
    {
        $dispatch->loadMissing(['client', 'lines', 'merchandiseRequest']);

        if (! $dispatch->hasConfirmedLoading()) {
            throw ValidationException::withMessages([
                'dispatch' => 'Debes confirmar primero las cantidades realmente cargadas antes de generar el albaran.',
            ]);
        }

        if ($dispatch->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'dispatch' => 'La salida no tiene lineas y no puede generar un albaran.',
            ]);
        }

        if (! $dispatch->hasDeliveredLine()) {
            throw ValidationException::withMessages([
                'dispatch' => 'La salida no tiene ninguna linea cargada con pallets superiores a cero.',
            ]);
        }

    }

    private function guardStatusTransition(GoodsDispatch $dispatch, string $newStatus): void
    {
        if (in_array($newStatus, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true)) {
            if (! $dispatch->hasConfirmedLoading()) {
                throw ValidationException::withMessages([
                    'status' => 'Debes confirmar las lineas cargadas antes de marcar la salida como enviada o completada.',
                ]);
            }

            if (! $dispatch->hasDeliveredLine()) {
                throw ValidationException::withMessages([
                    'status' => 'Debes tener al menos una linea cargada con pallets mayores que cero antes de enviar o completar la salida.',
                ]);
            }

            $this->ensureDeliveryNoteCanBeGenerated($dispatch);
        }
    }
}
