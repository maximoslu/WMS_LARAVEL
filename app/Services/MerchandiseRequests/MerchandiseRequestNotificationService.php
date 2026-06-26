<?php

namespace App\Services\MerchandiseRequests;

use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\BrevoMailService;

class MerchandiseRequestNotificationService
{
    public function __construct(
        private readonly BrevoMailService $brevoMailService,
    ) {
    }

    public function sendCreated(MerchandiseRequest $merchandiseRequest): void
    {
        $emails = User::query()
            ->where('active', true)
            ->whereNotNull('email')
            ->whereHas('role', fn ($query) => $query->whereIn('slug', [
                Role::ALMACEN,
                Role::ADMINISTRACION,
                Role::SUPERADMIN,
            ]))
            ->pluck('email')
            ->filter(fn (?string $email): bool => filled($email))
            ->map(fn (string $email): string => trim(mb_strtolower($email)))
            ->unique()
            ->values()
            ->all();

        if ($emails === []) {
            return;
        }

        $merchandiseRequest->loadMissing(['client', 'requester', 'lines.item']);

        $this->brevoMailService->sendMerchandiseRequestCreated($emails, $merchandiseRequest);
    }
}
