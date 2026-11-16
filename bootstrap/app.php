<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureSessionVersion;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            \Illuminate\Support\Facades\Route::get('/ready', [\App\Http\Controllers\LibraryPageController::class, 'ready'])
                ->name('library.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->alias(['role' => EnsureUserHasRole::class, 'active' => EnsureAccountIsActive::class]);
        $middleware->redirectGuestsTo(fn ($request) => $request->expectsJson() ? null : '/#login');
        $middleware->appendToGroup('web', EnsureAccountIsActive::class);
        $middleware->appendToGroup('web', EnsureSessionVersion::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
