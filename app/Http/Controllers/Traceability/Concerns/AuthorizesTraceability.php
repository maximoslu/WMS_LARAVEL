<?php

namespace App\Http\Controllers\Traceability\Concerns;

use App\Models\Role;
use Illuminate\Http\Request;

trait AuthorizesTraceability
{
    private function authorizeTraceabilityRead(Request $request): void
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);
    }

    private function authorizeTraceabilityAdmin(Request $request): void
    {
        abort_unless($request->user()?->canAccessRole(Role::ADMINISTRACION), 403);
    }
}
