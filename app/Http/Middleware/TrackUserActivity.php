<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Activity\UserActivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackUserActivity
{
    public function __construct(private readonly UserActivityService $activity) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();

        if ($user instanceof User && $response->getStatusCode() < 400) {
            $this->activity->recordVisit($request, $user);
        }

        return $response;
    }
}
