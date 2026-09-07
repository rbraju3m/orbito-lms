<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Platform\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Opens the academy named in a SIGNED url's `tenant` parameter.
 *
 * Only ever used behind the `signed` middleware. That ordering is the whole
 * security argument: the signature covers the query string, so by the time
 * this runs the tenant id has already been proven to be the one the server
 * minted. On its own — without `signed` in front — this would be an open door
 * to any academy, so it must never be applied to an unsigned route.
 *
 * It exists because a signed media URL has no authenticated user to read a
 * tenant from; see MediaUrlGenerator::signed().
 */
final class InitializeTenancyBySignedRoute
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->query('tenant');

        // No tenant in the link means it was minted centrally. Nothing to open.
        if (! is_string($tenantId) || $tenantId === '') {
            return $next($request);
        }

        $tenant = Tenant::find($tenantId);

        // A 404, not a 403: the link is cryptographically valid, so the only
        // way here is an academy deleted since it was minted — and the file it
        // points at genuinely no longer exists.
        if ($tenant === null || ! $tenant->isOpen()) {
            throw new NotFoundHttpException;
        }

        $this->tenancy->initialize($tenant);

        return $next($request);
    }
}
