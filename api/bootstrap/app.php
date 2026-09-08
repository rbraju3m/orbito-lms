<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\InitializeTenancyByAuthenticatedUser;
use App\Http\Middleware\InitializeTenancyByPathTenant;
use App\Http\Middleware\InitializeTenancyBySignedRoute;
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

        /*
         * `tenant` is applied per route group, never globally, and always
         * AFTER auth:sanctum — it reads the authenticated user to decide which
         * academy's schema to open. Auth, the platform admin surface and the
         * signed media route stay outside it; see routes/api.php.
         */
        $middleware->alias([
            'tenant' => InitializeTenancyByAuthenticatedUser::class,
            // ONLY behind `signed`. See the class docblock.
            'tenant.signed' => InitializeTenancyBySignedRoute::class,
            // For routes with no user AND no Laravel signature: the payment
            // webhook and the public certificate verification page. The
            // academy comes from the PATH and is untrusted until that route's
            // own credential checks out. See the class docblock.
            'tenant.path' => InitializeTenancyByPathTenant::class,

            // Applied after `tenant`, so the academy has resolved. Gates
            // WRITES only — a lapsed academy keeps reading and exporting.
            'subscription' => EnsureActiveSubscription::class,

            'super_admin' => EnsureSuperAdmin::class,
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
