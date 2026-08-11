<?php

namespace App\Services\MerchandiseRequests;

use App\Jobs\ProcessGoodsDispatchStatusChangedJob;
use App\Jobs\ProcessMerchandiseRequestSubmittedNotificationsJob;
use App\Models\GoodsDispatch;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerDispatchDeliveryNoteNotification;
use App\Notifications\CustomerMerchandiseRequestSubmittedNotification;
use App\Notifications\InternalGoodsDispatchCompletedNotification;
use App\Notifications\InternalMerchandiseRequestSubmittedNotification;
use App\Services\Notifications\NotificationDeliveryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class MerchandiseRequestNotificationService
{
    public function __construct(
        private readonly NotificationDeliveryService $deliveries,
    ) {}

    public function notifySubmitted(MerchandiseRequest $merchandiseRequest): void
    {
        ProcessMerchandiseRequestSubmittedNotificationsJob::dispatch($merchandiseRequest->id)->afterCommit();
    }

    public function deliverSubmittedNotifications(MerchandiseRequest $merchandiseRequest): void
    {
        $merchandiseRequest->loadMissing([
            'client.users.role',
            'requestedBy.role',
            'lines.item',
            'lines.stockPallet.location.warehouse',
            'dispatch.lines.stockPallet.location.warehouse',
            'dispatch.lines.allocations.stockPallet.location.warehouse',
            'dispatch.lines.sourceRequestLine',
            'client.dispatchEmailRecipients',
        ]);

        $preparationPdfContent = $this->shouldAttachPreparationPdfToClient($merchandiseRequest)
            ? Pdf::loadView('merchandise-requests.preparation-pdf', [
                'merchandiseRequest' => $merchandiseRequest,
            ])->output()
            : null;
        $preparationPdfName = $preparationPdfContent !== null
            ? 'preparacion-pedido-'.$merchandiseRequest->referenceCode().'.pdf'
            : null;

        $clientRecipients = $this->clientRecipients($merchandiseRequest);

        foreach ($clientRecipients as $recipient) {
            $this->deliveries->deliverToUser(
                'merchandise_request.submitted',
                'merchandise_request',
                $merchandiseRequest->id,
                'submitted',
                $recipient,
                ['database', 'mail'],
                fn (array $channels) => new CustomerMerchandiseRequestSubmittedNotification(
                    $merchandiseRequest,
                    $channels,
                    $preparationPdfContent,
                    $preparationPdfName,
                ),
            );
        }

        $internalRecipients = $this->notifyInternalUsers(
            'merchandise_request.submitted.internal',
            'merchandise_request',
            $merchandiseRequest->id,
            'submitted',
            fn (array $channels) => new InternalMerchandiseRequestSubmittedNotification($merchandiseRequest, $channels),
        );
        $additionalEmails = $this->dispatchEmailRecipients($merchandiseRequest, $clientRecipients->merge($internalRecipients));

        foreach ($additionalEmails as $email) {
            $this->deliveries->deliverToEmail(
                'merchandise_request.submitted.internal',
                'merchandise_request',
                $merchandiseRequest->id,
                'submitted',
                $email,
                fn (array $channels) => new InternalMerchandiseRequestSubmittedNotification($merchandiseRequest, $channels),
            );
        }

        Log::info('Notificaciones de pedido definitivo generadas.', [
            'merchandise_request_id' => $merchandiseRequest->id,
            'client_user_recipients' => $clientRecipients->pluck('id')->values()->all(),
            'internal_user_recipients' => $internalRecipients->pluck('id')->values()->all(),
            'dispatch_email_recipients' => $additionalEmails->values()->all(),
        ]);
    }

    private function shouldAttachPreparationPdfToClient(MerchandiseRequest $merchandiseRequest): bool
    {
        return (bool) $merchandiseRequest->client?->send_order_preparation_pdf_to_client
            && $merchandiseRequest->requestedBy?->hasRole(Role::CLIENTE);
    }

    public function notifyStatusChanged(MerchandiseRequest $merchandiseRequest, string $previousStatus): void
    {
        // Los cambios intermedios de pedido ya no generan comunicaciones.
    }

    public function deliverStatusChangedNotification(MerchandiseRequest $merchandiseRequest, string $previousStatus): void
    {
        // Compatibilidad con jobs antiguos ya encolados: no deben emitir nada.
    }

    public function notifyLoadingConfirmed(GoodsDispatch $dispatch, User $confirmedBy): void
    {
        // La confirmacion de carga es operativa, no un hito de comunicacion.
    }

    public function deliverLoadingConfirmedNotifications(GoodsDispatch $dispatch, User $confirmedBy): void
    {
        // Compatibilidad con jobs antiguos ya encolados: no deben emitir nada.
    }

    public function notifyDispatchStatusChanged(
        GoodsDispatch $dispatch,
        string $previousRequestStatus,
        string $currentStatus,
    ): void {
        if ($currentStatus !== MerchandiseRequest::STATUS_COMPLETED) {
            return;
        }

        ProcessGoodsDispatchStatusChangedJob::dispatch(
            $dispatch->id,
            $dispatch->merchandise_request_id,
            $previousRequestStatus,
            $currentStatus,
        )->afterCommit();
    }

    public function sendDeliveryNoteToClient(GoodsDispatch $dispatch, string $currentStatus): ?string
    {
        if ($dispatch->delivery_note_sent_at !== null) {
            return null;
        }

        $dispatch->loadMissing([
            'client',
            'lines.item',
            'lines.allocations',
            'lines.sourceRequestLine',
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

        $eventVersion = 'completed:'.($dispatch->completed_at?->format('Y-m-d H:i:s.u') ?? $currentStatus);

        $this->notifyInternalUsers(
            'goods_dispatch.completed.internal',
            'goods_dispatch',
            $dispatch->id,
            $eventVersion,
            fn (array $channels) => new InternalGoodsDispatchCompletedNotification($dispatch, $merchandiseRequest, $channels),
        );

        $recipients = $this->clientRecipients($merchandiseRequest);
        $validEmailRecipients = $recipients
            ->filter(fn (User $recipient) => $this->hasValidEmail($recipient))
            ->unique(fn (User $recipient) => mb_strtolower(trim((string) $recipient->email)));
        $additionalEmails = $this->dispatchEmailRecipients($merchandiseRequest, $validEmailRecipients);

        foreach ($recipients as $recipient) {
            $this->deliveries->deliverToUser(
                'goods_dispatch.delivery_note',
                'goods_dispatch',
                $dispatch->id,
                $eventVersion,
                $recipient,
                $this->hasValidEmail($recipient) ? ['database', 'mail'] : ['database'],
                fn (array $channels) => new CustomerDispatchDeliveryNoteNotification(
                    $dispatch,
                    $merchandiseRequest,
                    $pdfContent,
                    $currentStatus,
                    $channels,
                ),
            );
        }

        foreach ($additionalEmails as $email) {
            $this->deliveries->deliverToEmail(
                'goods_dispatch.delivery_note',
                'goods_dispatch',
                $dispatch->id,
                $eventVersion,
                $email,
                fn (array $channels) => new CustomerDispatchDeliveryNoteNotification(
                    $dispatch,
                    $merchandiseRequest,
                    $pdfContent,
                    $currentStatus,
                    $channels,
                ),
            );
        }

        $dispatch->forceFill([
            'delivery_note_sent_at' => now(),
        ])->saveQuietly();

        if ($validEmailRecipients->isEmpty() && $additionalEmails->isEmpty()) {
            Log::warning('No se ha enviado email de albaran porque el cliente no tiene email valido.', [
                'dispatch_id' => $dispatch->id,
                'merchandise_request_id' => $merchandiseRequest->id,
            ]);

            return 'No se ha enviado email porque el cliente no tiene email configurado.';
        }

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
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->all();

        return $merchandiseRequest->client?->dispatchEmailRecipients
            ->pluck('email')
            ->filter(fn (?string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->reject(fn (string $email) => in_array($email, $userEmails, true))
            ->unique()
            ->values() ?? collect();
    }

    /**
     * @return Collection<int, User>
     */
    private function clientRecipients(MerchandiseRequest $merchandiseRequest): Collection
    {
        if ($merchandiseRequest->requestedBy !== null && $merchandiseRequest->requestedBy->hasRole(Role::CLIENTE)) {
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

    /**
     * @return Collection<int, User>
     */
    private function notifyInternalUsers(
        string $type,
        string $sourceType,
        int|string $sourceId,
        ?string $eventVersion,
        Closure $notification,
        ?User $excludeUser = null,
    ): Collection {
        $recipients = $this->internalRecipients()
            ->reject(fn (User $recipient): bool => $excludeUser !== null && $recipient->id === $excludeUser->id)
            ->values();

        if ($recipients->isEmpty()) {
            return $recipients;
        }

        foreach ($recipients as $recipient) {
            $this->deliveries->deliverToUser(
                $type,
                $sourceType,
                $sourceId,
                $eventVersion,
                $recipient,
                ['database', 'mail'],
                $notification,
            );
        }

        return $recipients;
    }

    private function hasValidEmail(User $user): bool
    {
        return filter_var($user->email ?? null, FILTER_VALIDATE_EMAIL) !== false;
    }
}
