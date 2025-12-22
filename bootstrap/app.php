<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\ContentSecurityPolicy;

use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
        'role' => RoleMiddleware::class,
        ]);


         // =========================================
        // CSP → hanya untuk WEB (Blade)
        // =========================================
        $middleware->appendToGroup('web', [
            ContentSecurityPolicy::class,
            EnsureUserIsActive::class,
        ]);

        // =========================================================
        // === TAMBAHKAN BLOK KODE INI ===
        // =========================================================
        // Middleware ini akan memeriksa cookie sesi pada request API
        $middleware->appendToGroup('api', [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            EnsureUserIsActive::class,
        ]);
        // =========================================================
    })
    ->withExceptions(function (Exceptions $exceptions): void {
         $exceptions->render(function (\DomainException $e, Request $request) {

        // API response
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        // Web response
        return redirect()
            ->back()
            ->withErrors($e->getMessage());
        });

    })->create();
