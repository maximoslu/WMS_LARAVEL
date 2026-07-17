<?php

namespace App\Services\MerchandiseRequests;

use App\Jobs\ProcessGoodsDispatchLoadingConfirmedNotificationsJob;
use App\Jobs\ProcessGoodsDispatchStatusChangedJob;
use App\Jobs\ProcessMerchandiseRequestStatusChangedJob;
use App\Jobs\ProcessMerchandiseRequestSubmittedNotificationsJob;
use App\Models\GoodsDispatch;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerDispatchDeliveryNoteNotification;
use App\Notifications\CustomerMerchandiseRequestStatusChangedNotification;
use App\Notifications\CustomerMerchandiseRequestSubmittedNotification;
use App\Notifications\InternalGoodsDispatchLoadingConfirmedNotification;
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
        ProcessMerchandiseRequestSubmittedNotificationsJob::dispatch($merchandiseRequest->id)->afterResponse();
    }

    public function deliverSubmittedNotifications(MerchandiseRequest $merchandiseRequest): void
    {
        $merchandiseRequest->loadMissing(['client', 'requestedBy', 'lines.item']);

        if ($merchandiseRequest->requestedBy !== null && $this->hasValidEmail($merchandiseRequest->requestedBy)) {
            $merchandiseRequest->requestedBy->notify(
                new CustomerMerchandiseRequestSubmittedNotification($merchandiseRequest)
            );
        }

        $this->notifyInternalUsers(
            new InternalMerchandiseRequestSubmittedNotification($merchandiseRequest, ['database']),
            new InternalMerchandiseRequestSubmittedNotification($merchandiseRequest, ['mail'])
        );
    }

    public function notifyStatusChanged(MerchandiseRequest $merchandiseRequest, string $previousStatus): void
    {
        ProcessMerchandiseRequestStatusChangedJob::dispatch($merchandiseRequest->id, $previousStatus)->afterResponse();
    }

    public function deliverStatusChangedNotification(MerchandiseRequest $merchandiseRequest, string $previousStatus): void
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

    public function notifyLoadingConfirmed(GoodsDispatch $dispatch, User $confirmedBy): void
    {
        ProcessGoodsDispatchLoadingConfirmedNotificationsJob::dispatch($dispatch->id, $confirmedBy->id)->afterResponse();
    }

    public function deliverLoadingConfirmedNotifications(GoodsDispatch $dispatch, User $confirmedBy): void
    {
        $dispatch->loadMissing(['client', 'lines.item', 'merchandiseRequest']);

        $this->notifyInternalUsers(
            new InternalGoodsDispatchLoadingConfirmedNotification($dispatch, $confirmedBy, ['database']),
            null,
            $confirmedBy
        );
    }

    public function notifyDispatchStatusChanged(
        GoodsDispatch $dispatch,
        string $previousRequestStatus,
        string $currentStatus,
    ): void {
        ProcessGoodsDispatchStatusChangedJob::dispatch(
            $dispatch->id,
            $dispatch->merchandise_request_id,
            $previousRequestStatus,
            $currentStatus,
        )->afterResponse();
    }

    public function sendDeliveryNoteToClient(GoodsDispatch $dispatch, string $currentStatus): ?string
    {
        $dispatch->loadMissing([
            'client',
            'lines.item',
            'lines.allocations',
            'merchandiseRequest.client',
            'merchandiseRequest.requestedBy',
            'merchandiseRequest.lines.item',
            'merchandiseRequest.client.users.role',
            'merchandiseRequest.client.dispatchEmailRecipients',
        ]);

        $merchandiseRequest = $dispatch->merchandiseRequest;

        if ($merchandiseRequest === null) {
            return null;
        }

        try {
            $pdfContent = Pdf::loadView('dispatches.delivery-note-pdf', [
                'dispatch' => $dispatch,
            ])->output();
        } catch (Throwable $exception) {
            Log::warning('No se ha podido adjuntar el albaran al email del cliente.', [
                'dispatch_id' => $dispatch->id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $recipients = $this->clientRecipients($merchandiseRequest);
        $validEmailRecipients = $recipients
            ->filter(fn (User $recipient) => $this->hasValidEmail($recipient))
            ->unique(fn (User $recipient) => mb_strtolower((string) $recipient->email));
        $additionalEmails = $this->dispatchEmailRecipients($merchandiseRequest, $validEmailRecipients);

        foreach ($recipients as $recipient) {
            $recipient->notify(new CustomerDispatchDeliveryNoteNotification(
                $dispatch,
                $merchandiseRequest,
                $pdfContent,
                $currentStatus,
                ['database'],
            ));
        }

        foreach ($validEmailRecipients as $recipient) {
            $recipient->notify(new CustomerDispatchDeliveryNoteNotification(
                $dispatch,
                $merchandiseRequest,
                $pdfContent,
                $currentStatus,
                ['mail'],
            ));
        }

        foreach ($additionalEmails as $email) {
            Notification::route('mail', $email)->notify(new CustomerDispatchDeliveryNoteNotification(
                $dispatch,
                $merchandiseRequest,
                $pdfContent,
                $currentStatus,
                ['mail'],
            ));
        }

        if ($validEmailRecipients->isEmpty() && $additionalEmails->isEmpty()) {
            Log::warning('No se ha enviado email de albaran porque el cliente no tiene email valido.', [
                'dispatch_id' => $dispatch->id,
                'merchandise_request_id' => $merchandiseRequest->id,
            ]);

            return 'No se ha enviado email porque el cliente no tiene email configurado.';
        }

        $dispatch->forceFill([
            'delivery_note_sent_at' => now(),
        ])->saveQuietly();

        return null;
    }

    /**
     * @param  Collection<int, User>  $userRecipients
     * @return Collection<int, string>
     */
    private function dispatchEmailRecipients(MerchandiseRequest $merchandiseRequest, Collection $userRecipients): Collection
    {
        $userEmails = $userRecipients
            ->pluck('email')
            ->filter()
            ->map(fn (string $email) => mb_strtolower($email))
            ->all();

        return $merchandiseRequest->client?->dispatchEmailRecipients
            ->pluck('email')
            ->filter(fn (?string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->map(fn (string $email) => mb_strtolower($email))
            ->reject(fn (string $email) => in_array($email, $userEmails, true))
            ->unique()
            ->values() ?? collect();
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

    /**
     * @return Collection<int, User>
     */
    private function internalRecipients(): Collection
    {
        return User::query()
            ->with('role')
            ->where('active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('slug', [
                Role::ALMACEN,
                Role::ADMINISTRACION,
                Role::SUPERADMIN,
            ]))
            ->get()
            ->unique('id')
            ->values();
    }

    private function notifyInternalUsers(object $databaseNotification, ?object $mailNotification, ?User $excludeUser = null): void
    {
        $recipients = $this->internalRecipients()
            ->reject(fn (User $recipient): bool => $excludeUser !== null && $recipient->id === $excludeUser->id)
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $recipient) {
            $recipient->notify($databaseNotification);
        }

        if ($mailNotification !== null) {
            $recipients
                ->filter(fn (User $recipient) => $this->hasValidEmail($recipient))
                ->unique(fn (User $recipient) => mb_strtolower((string) $recipient->email))
                ->each(fn (User $recipient) => $recipient->notify($mailNotification));
        }
    }

    private function hasValidEmail(User $user): bool
    {
        return filter_var($user->email ?? null, FILTER_VALIDATE_EMAIL) !== false;
    }
}
