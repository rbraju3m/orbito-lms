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
 * Opens the academy named in a URL's `{tenant}` path segment.
 *
 * Used by the two routes that have no authenticated user AND cannot use a
 * Laravel signature: the payment webhook, whose URL is handed to a provider
 * that will not sign anything of ours, and the public certificate
 * verification page, whose URL is printed on a document and opened by a
 * stranger. (The signed media download is the third such route and solves it
 * differently — see `tenant.signed`.)
 *
 * The tenant here is ATTACKER-CONTROLLABLE, so the security argument cannot be
 * "the id was proven". It is this instead:
 *
 *  - Resolving a tenant only opens a database connection. It authorises
 *    nothing, reads nothing and writes nothing on its own.
 *  - Every route behind it carries its OWN unguessable credential, checked
 *    against that academy's data: the gateway's HMAC over the webhook body,
 *    or the certificate's 32-character verification token. Naming somebody
 *    else's academy just means being checked against data you do not have —
 *    the wrong academy is not a way in, it is a way to fail.
 *
 * So it must never be applied to a route that ACTS before checking such a
 * credential. Unlike `tenant.signed`, this one REQUIRES the parameter:
 * falling through to the central connection would run tenant queries against
 * a schema that has none of those tables.
 */
final class InitializeTenancyByPathTenant
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->route('tenant');

        if (! is_string($tenantId) || $tenantId === '') {
            throw new NotFoundHttpException;
        }

        $tenant = Tenant::find($tenantId);

        /*
         * A 404, and deliberately the SAME 404 for "no such academy" and "that
         * academy is closed". Anything more specific turns these endpoints
         * into an oracle for which academy ids exist, and both are reachable
         * by anybody on the internet.
         */
        if ($tenant === null || ! $tenant->isOpen()) {
            throw new NotFoundHttpException;
        }

        $this->tenancy->initialize($tenant);

        return $next($request);
    }
}
