<?php

use App\Http\Middleware\EnsureMinimumRole;
use App\Http\Middleware\TrackUserActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            TrackUserActivity::class,
        ]);

        $middleware->alias([
            'minimum.role' => EnsureMinimumRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if ($request->is('stock/importar/previsualizar')) {
                $maximumMegabytes = (int) ceil((int) config('wms.stock_imports.max_file_kilobytes', 2048) / 1024);

                return back()
                    ->withErrors([
                        'file' => 'El fichero de stock no puede superar '.$maximumMegabytes.' MB.',
                    ]);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'El documento no puede superar los 50 MB.',
                ], 413);
            }

            return back()
                ->withInput($request->except('document'))
                ->withErrors([
                    'document' => 'El documento no puede superar los 50 MB.',
                ]);
        });
    })->create();
