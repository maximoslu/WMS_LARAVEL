<?php

namespace App\Http\Controllers\Traceability;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Activity\UserActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityHeartbeatController extends Controller
{
    public function __invoke(Request $request, UserActivityService $activity): JsonResponse
    {
        $validated = $request->validate([
            'route_name' => ['required', 'string', 'max:150'],
            'visible' => ['required', 'boolean'],
        ]);
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return response()->json($activity->heartbeat(
            $request,
            $user,
            $validated['route_name'],
            (bool) $validated['visible'],
        ));
    }
}
