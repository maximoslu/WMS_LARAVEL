<?php

namespace App\Services\GoodsReceipts;

use App\Jobs\ProcessGoodsReceiptDocumentNotificationsJob;
use App\Models\ClientReceiptEmailRecipient;
use App\Models\GoodsReceipt;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ClientGoodsReceiptDocumentAvailableNotification;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GoodsReceiptDocumentNotificationService
{
    public function __construct(
        private readonly NotificationDeliveryService $deliveries,
    ) {}

    public function notifyDocumentAvailable(GoodsReceipt $receipt): void
    {
        ProcessGoodsReceiptDocumentNotificationsJob::dispatch($receipt->id)->afterCommit();
    }

    public function deliverDocumentAvailableNotifications(GoodsReceipt $receipt): void
    {
        $receipt->loadMissing(['client', 'supplier']);

        $userRecipients = $this->clientRecipients($receipt);
        $userEmails = $userRecipients
            ->map(fn (User $user): string => mb_strtolower(trim((string) $user->email)))
            ->filter()
            ->all();

        $additionalEmails = $this->additionalRecipientEmails($receipt, $userEmails);

        if ($userRecipients->isEmpty() && $additionalEmails->isEmpty()) {
            Log::info('No hay destinatarios (usuarios cliente ni emails adicionales) para notificar el albaran de una entrada.', [
                'goods_receipt_id' => $receipt->id,
                'client_id' => $receipt->client_id,
            ]);

            return;
        }

        $eventVersion = hash('sha256', (string) $receipt->document_path);

        foreach ($userRecipients as $recipient) {
            $this->deliveries->deliverToUser(
                'goods_receipt.document_available',
                'goods_receipt',
                $receipt->id,
                $eventVersion,
                $recipient,
                ['database', 'mail'],
                fn (array $channels) => new ClientGoodsReceiptDocumentAvailableNotification($receipt, $channels),
            );
        }

        foreach ($additionalEmails as $email) {
            $this->deliveries->deliverToEmail(
                'goods_receipt.document_available',
                'goods_receipt',
                $receipt->id,
                $eventVersion,
                $email,
                fn (array $channels) => new ClientGoodsReceiptDocumentAvailableNotification($receipt, $channels),
            );
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function clientRecipients(GoodsReceipt $receipt): Collection
    {
        if ($receipt->client_id === null) {
            return collect();
        }

        return User::query()
            ->where('active', true)
            ->where('client_id', $receipt->client_id)
            ->whereHas('role', fn ($query) => $query->where('slug', Role::CLIENTE))
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * Additional (non-platform-user) email recipients configured on the
     * client's file, excluding any that already match a notified user's
     * email so nobody receives the same alert twice.
     *
     * @param  array<int, string>  $excludeEmails  lower-cased emails already notified as users
     * @return Collection<int, string>
     */
    private function additionalRecipientEmails(GoodsReceipt $receipt, array $excludeEmails): Collection
    {
        if ($receipt->client_id === null) {
            return collect();
        }

        return ClientReceiptEmailRecipient::query()
            ->where('client_id', $receipt->client_id)
            ->pluck('email')
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->unique()
            ->reject(fn (string $email): bool => in_array($email, $excludeEmails, true))
            ->values();
    }
}
