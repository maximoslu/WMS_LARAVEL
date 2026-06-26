<?php

namespace App\Services\MerchandiseRequests;

use App\Models\GoodsDispatch;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerMerchandiseRequestStatusChangedNotification;
use App\Notifications\CustomerMerchandiseRequestSubmittedNotification;
use App\Notifications\InternalMerchandiseRequestSubmittedNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class MerchandiseRequestNotificationService
{
    public function notifySubmitted(MerchandiseRequest $merchandiseRequest): void
    {
        $merchandiseRequest->loadMissing(['client', 'requestedBy', 'lines.item']);

        if ($merchandiseRequest->requestedBy !== null && filled($merchandiseRequest->requestedBy->email)) {
            $merchandiseRequest->requestedBy->notify(
                new CustomerMerchandiseRequestSubmittedNotification($merchandiseRequest)
            );
        }

        $internalUsers = User::query()
            ->with('role')
            ->where('active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('slug', [
                Role::ALMACEN,
                Role::ADMINISTRACION,
                Role::SUPERADMIN,
            ]))
            ->get();

        if ($internalUsers->isNotEmpty()) {
            Notification::send($internalUsers, new InternalMerchandiseRequestSubmittedNotification($merchandiseRequest));
        }
    }

    public function notifyStatusChanged(MerchandiseRequest $merchandiseRequest, string $previousStatus): void
    {
        $merchandiseRequest->loadMissing(['client', 'requestedBy', 'lines.item', 'client.users.role']);

        if ($previousStatus === $merchandiseRequest->status) {
            return;
        }

        $recipients = $this->clientRecipients($merchandiseRequest);

        foreach ($recipients as $recipient) {
            $recipient->notify(
                new CustomerMerchandiseRequestStatusChangedNotification($merchandiseRequest, $previousStatus)
            );
        }
    }

    public function sendCompletedDeliveryNote(GoodsDispatch $dispatch): void
    {
        $dispatch->loadMissing([
            'client',
            'lines.item',
            'merchandiseRequest.client',
            'merchandiseRequest.requestedBy',
            'merchandiseRequest.lines.item',
        ]);

        $merchandiseRequest = $dispatch->merchandiseRequest;

        if ($merchandiseRequest === null) {
            return;
        }

        try {
            $pdfContent = Pdf::loadView('dispatches.delivery-note-pdf', [
                'dispatch' => $dispatch,
            ])->output();
        } catch (Throwable $exception) {
            Log::warning('No se ha podido adjuntar el albaran al email de completado.', [
                'dispatch_id' => $dispatch->id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $recipients = $this->clientRecipients($merchandiseRequest);

        foreach ($recipients as $recipient) {
            $recipient->notify(new CustomerMerchandiseRequestStatusChangedNotification(
                $merchandiseRequest,
                MerchandiseRequest::STATUS_SENT,
                [
                    'name' => $dispatch->dispatchNumber().'.pdf',
                    'content' => $pdfContent,
                ]
            ));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function clientRecipients(MerchandiseRequest $merchandiseRequest): Collection
    {
        if ($merchandiseRequest->requestedBy !== null) {
            return collect([$merchandiseRequest->requestedBy]);
        }

        return $merchandiseRequest->client?->users
            ->filter(fn (User $user) => $user->active && $user->hasRole(Role::CLIENTE))
            ->unique('id')
            ->values() ?? collect();
    }
}
