<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureDecaDriverAccess
{
    public function handle(Request $request, Closure $next)
    {
        $access = DB::table('deca_driver_access')->find(1);
        if (! $access || ! hash_equals($access->version, (string) $request->session()->get('deca_driver.version'))
            || $request->session()->get('deca_driver.expires', 0) < time()) {
            $request->session()->forget('deca_driver');

            return redirect()->route('driver.login');
        }
        $request->attributes->set('deca_driver_access', $access);

        return $next($request)->header('Cache-Control', 'private, no-store');
    }
}
