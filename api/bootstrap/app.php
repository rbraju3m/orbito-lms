<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\InitializeTenancyByAcademySlug;
use App\Http\Middleware\InitializeTenancyByAuthenticatedUser;
use App\Http\Middleware\InitializeTenancyByPathTenant;
use App\Http\Middleware\InitializeTenancyBySignedRoute;
use App\Http\Middleware\SetLocale;
use App\Support\Exceptions\ApiExceptionRenderer;
use App\Support\Http\RequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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

        // Placed by the priority list below, not by being appended here.
        $middleware->api(append: [SetLocale::class]);

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
            // The public marketing surface: no user, no signature, and no
            // credential of its own — every route behind it exposes only
            // published data, on purpose. See the class docblock.
            'tenant.public' => InitializeTenancyByAcademySlug::class,

            // Applied after `tenant`, so the academy has resolved. Gates
            // WRITES only — a lapsed academy keeps reading and exporting.
            'subscription' => EnsureActiveSubscription::class,

            'super_admin' => EnsureSuperAdmin::class,
        ]);

        /*
         * WHERE the tenant middlewares sit, which is not something the alias
         * above decides.
         *
         * `SubstituteBindings` resolves `{course}`, `{bundle}`, `{download}`
         * — every bound tenant model — and Laravel's priority list puts it
         * directly after authentication. `tenant` is not in that list, so it
         * ran after the binding: the lookup went to the CENTRAL database and
         * every studio page that opens one record answered 500. The list is
         * the only place this can be said; a route declaring
         * `['auth:sanctum', 'tenant']` in that order does not get it, because
         * priority reorders what it names around what it does not.
         *
         * Before `SubstituteBindings` and therefore still AFTER
         * `Authenticate` — which is the whole point, and the opposite of
         * `makeTenancyMiddlewareHighestPriority()`: `tenant` reads the
         * authenticated user, so it cannot run first (see
         * TenancyServiceProvider and § Multi-tenancy).
         *
         * The harness hides this completely — it leaves an academy open for
         * the whole test — so `RouteBindingTenancyTest` ends tenancy first.
         */
        foreach ([
            InitializeTenancyByAuthenticatedUser::class,
            InitializeTenancyBySignedRoute::class,
            InitializeTenancyByPathTenant::class,
            InitializeTenancyByAcademySlug::class,
        ] as $tenancy) {
            $middleware->prependToPriorityList(SubstituteBindings::class, $tenancy);
        }

        /*
         * The locale AFTER every academy has resolved — its default is one of
         * the answers — and after authentication, whose user's choice is
         * another. A group middleware otherwise runs before a route's
         * `tenant`, and every academy's default would silently be ignored.
         */
        $middleware->prependToPriorityList(SubstituteBindings::class, SetLocale::class);

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
