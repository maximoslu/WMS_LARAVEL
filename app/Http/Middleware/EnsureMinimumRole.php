<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMinimumRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $roleSlug): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->canAccessRole($roleSlug), 403);

        return $next($request);
    }
}
