<?php

namespace App\Services\GoodsReceipts;

use App\Jobs\ProcessGoodsReceiptDocumentNotificationsJob;
use App\Models\ClientReceiptEmailRecipient;
use App\Models\GoodsReceipt;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ClientGoodsReceiptDocumentAvailableNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class GoodsReceiptDocumentNotificationService
{
    public function notifyDocumentAvailable(GoodsReceipt $receipt): void
    {
        ProcessGoodsReceiptDocumentNotificationsJob::dispatch($receipt->id)->afterResponse();
    }

    public function deliverDocumentAvailableNotifications(GoodsReceipt $receipt): void
    {
        $receipt->loadMissing(['client', 'supplier']);

        $userRecipients = $this->clientRecipients($receipt);
        $userEmails = $userRecipients
            ->map(fn (User $user): string => mb_strtolower((string) $user->email))
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

        foreach ($userRecipients as $recipient) {
            $recipient->notify(new ClientGoodsReceiptDocumentAvailableNotification($receipt));
        }

        foreach ($additionalEmails as $email) {
            Notification::route('mail', $email)
                ->notify(new ClientGoodsReceiptDocumentAvailableNotification($receipt, ['mail']));
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
            ->map(fn (string $email): string => mb_strtolower($email))
            ->unique()
            ->reject(fn (string $email): bool => in_array($email, $excludeEmails, true))
            ->values();
    }
}
