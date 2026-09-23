<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the application locale for one request (docs/I18N.md).
 *
 * In the `api` group, and placed in the PRIORITY LIST after every tenancy
 * middleware (`bootstrap/app.php`): the academy's default is one of the
 * answers, and a group middleware otherwise runs before a route's `tenant`.
 * Routes with no academy — login, register — resolve without one.
 */
final class SetLocale
{
    public function __construct(private readonly LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $academy = tenancy()->initialized ? tenant() : null;

        $locale = $this->resolver->resolve(
            $request,
            $user instanceof User ? $user : null,
            $academy instanceof Tenant ? $academy : null,
        );

        app()->setLocale($locale->value);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale->value);

        return $response;
    }
}
