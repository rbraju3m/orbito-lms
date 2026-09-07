<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ForceJsonResponse;
use App\Support\Exceptions\ApiExceptionRenderer;
use App\Support\Http\RequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlation id first: everything downstream, including the exception
        // handler, needs it.
        $middleware->prepend(AssignRequestId::class);

        $middleware->api(prepend: [
            HandleCors::class,
            // Cookie auth for the first-party SPA; bearer tokens for everyone else.
            EnsureFrontendRequestsAreStateful::class,
            ForceJsonResponse::class,
        ]);

        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(new ApiExceptionRenderer);

        $exceptions->context(fn (): array => [
            'request_id' => RequestId::current(),
        ]);
    })->create();
