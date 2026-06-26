<?php

namespace App\Services\MerchandiseRequests;

use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerMerchandiseRequestStatusChangedNotification;
use App\Notifications\CustomerMerchandiseRequestSubmittedNotification;
use App\Notifications\InternalMerchandiseRequestSubmittedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

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
            if (! filled($recipient->email)) {
                continue;
            }

            $recipient->notify(
                new CustomerMerchandiseRequestStatusChangedNotification($merchandiseRequest, $previousStatus)
            );
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
