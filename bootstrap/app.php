<?php

use App\Http\Middleware\ApplySettings;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [ApplySettings::class, EnsureUserIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An exchange's key and secret are not kept in the session when a form is refused.
        $exceptions->dontFlash(['api_key', 'secret']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
