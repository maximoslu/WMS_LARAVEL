<?php

namespace App\Services\GoodsReceipts;

use App\Jobs\ProcessGoodsReceiptDocumentNotificationsJob;
use App\Models\GoodsReceipt;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ClientGoodsReceiptDocumentAvailableNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GoodsReceiptDocumentNotificationService
{
    public function notifyDocumentAvailable(GoodsReceipt $receipt): void
    {
        ProcessGoodsReceiptDocumentNotificationsJob::dispatch($receipt->id)->afterResponse();
    }

    public function deliverDocumentAvailableNotifications(GoodsReceipt $receipt): void
    {
        $receipt->loadMissing(['client', 'supplier']);

        $recipients = $this->clientRecipients($receipt);

        if ($recipients->isEmpty()) {
            Log::info('No hay usuarios cliente activos para notificar el albaran de una entrada.', [
                'goods_receipt_id' => $receipt->id,
                'client_id' => $receipt->client_id,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            $recipient->notify(new ClientGoodsReceiptDocumentAvailableNotification($receipt));
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
}
