<?php

namespace App\Services\GoodsDispatches;

use App\Models\GoodsDispatch;
use App\Models\MerchandiseRequest;
use App\Models\User;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class GoodsDispatchWorkflowService
{
    public function __construct(
        private readonly MerchandiseRequestNotificationService $notificationService,
    ) {}

    /**
     * @param  array<string, array{loaded_pallets:int, loading_notes:?string}>  $linePayload
     */
    public function confirmLoading(GoodsDispatch $dispatch, array $linePayload, User $user): void
    {
        $dispatch->loadMissing(['lines', 'merchandiseRequest']);

        DB::transaction(function () use ($dispatch, $linePayload, $user): void {
            foreach ($dispatch->lines as $line) {
                $payload = $linePayload[(string) $line->id] ?? null;

                if ($payload === null) {
                    continue;
                }

                $line->update([
                    'loaded_pallets' => (int) $payload['loaded_pallets'],
                    'loading_notes' => filled($payload['loading_notes'] ?? null)
                        ? trim((string) $payload['loading_notes'])
                        : null,
                    'confirmed_by' => $user->id,
                    'confirmed_at' => now(),
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
    }

    public function changeStatus(GoodsDispatch $dispatch, string $newStatus, User $user): void
    {
        $dispatch->loadMissing(['client', 'merchandiseRequest', 'lines.item']);
        $previousDispatchStatus = $dispatch->status;

        if ($previousDispatchStatus === $newStatus) {
            return;
        }

        $this->guardStatusTransition($dispatch, $newStatus);

        DB::transaction(function () use ($dispatch, $newStatus, $user, $previousDispatchStatus): void {
            $dispatchPayload = [
                'status' => $newStatus,
            ];

            if ($newStatus === GoodsDispatch::STATUS_SENT && $dispatch->sent_at === null) {
                $dispatchPayload['sent_at'] = now();
            }

            if ($newStatus === GoodsDispatch::STATUS_COMPLETED && $dispatch->completed_at === null) {
                $dispatchPayload['completed_at'] = now();
            }

            $dispatch->update($dispatchPayload);

            $merchandiseRequest = $dispatch->merchandiseRequest;

            if ($merchandiseRequest !== null) {
                $previousRequestStatus = $merchandiseRequest->status;
                $requestPayload = [
                    'status' => $newStatus,
                ];

                if ($newStatus === MerchandiseRequest::STATUS_PREPARING) {
                    $requestPayload['prepared_by'] = $user->id;
                    $requestPayload['prepared_at'] = $merchandiseRequest->prepared_at ?? now();
                }

                if ($newStatus === MerchandiseRequest::STATUS_SENT) {
                    $requestPayload['shipped_by'] = $user->id;
                    $requestPayload['shipped_at'] = $merchandiseRequest->shipped_at ?? now();
                }

                if ($newStatus === MerchandiseRequest::STATUS_COMPLETED && $merchandiseRequest->completed_at === null) {
                    $requestPayload['completed_at'] = now();
                }

                if ($newStatus === MerchandiseRequest::STATUS_CANCELLED) {
                    $requestPayload['cancelled_at'] = $merchandiseRequest->cancelled_at ?? now();
                }

                $merchandiseRequest->update($requestPayload);

                if ($newStatus !== GoodsDispatch::STATUS_COMPLETED) {
                    $this->notificationService->notifyStatusChanged(
                        $merchandiseRequest->fresh(['client', 'requestedBy', 'lines.item', 'dispatch.lines']),
                        $previousRequestStatus
                    );
                }
            }

            if ($newStatus === GoodsDispatch::STATUS_COMPLETED && $dispatch->merchandiseRequest !== null) {
                $this->notificationService->sendCompletedDeliveryNote($dispatch->fresh([
                    'client',
                    'merchandiseRequest.client',
                    'merchandiseRequest.requestedBy',
                    'merchandiseRequest.lines.item',
                    'lines.item',
                ]));
            }
        });
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

        try {
            Pdf::loadView('dispatches.delivery-note-pdf', [
                'dispatch' => $dispatch,
            ])->output();
        } catch (Throwable $exception) {
            Log::warning('No se ha podido generar el albaran de salida.', [
                'dispatch_id' => $dispatch->id,
                'message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'dispatch' => 'No se ha podido generar el albaran definitivo con los datos actuales.',
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

            $this->ensureDeliveryNoteCanBeGenerated($dispatch);
        }

        if ($newStatus === GoodsDispatch::STATUS_COMPLETED && $dispatch->status !== GoodsDispatch::STATUS_SENT) {
            throw ValidationException::withMessages([
                'status' => 'Marca primero la salida como enviada antes de completarla.',
            ]);
        }
    }
}
