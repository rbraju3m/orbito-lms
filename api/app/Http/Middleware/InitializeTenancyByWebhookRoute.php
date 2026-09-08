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
 * Opens the academy named in a webhook URL's `{tenant}` path segment.
 *
 * This is the second — and last — route in the system with no authenticated
 * user to resolve tenancy from. The signed media download solved that by
 * putting the academy INSIDE a payload Laravel had signed; a webhook cannot,
 * because the URL is handed to a payment provider that will not sign anything
 * of ours.
 *
 * So the tenant here is ATTACKER-CONTROLLABLE, and the security argument is
 * different in kind:
 *
 *  - Resolving a tenant only opens a database connection. It authorises
 *    nothing, reads nothing and writes nothing on its own.
 *  - Everything downstream is gated on `verifyWebhook()` passing against
 *    THAT academy's own secret. Naming somebody else's academy in the path
 *    means the payload is checked against a secret the caller does not hold,
 *    so it is refused — the wrong academy is not a way in, it is a way to
 *    fail a signature check against a different key.
 *
 * That is why it is safe to route on and why it must never be reused for a
 * route that acts before verifying something. Unlike `tenant.signed`, this one
 * REQUIRES the parameter: a webhook that cannot name its academy has nowhere
 * to be delivered, and falling through to the central connection would run
 * commerce queries against a schema that has no commerce tables.
 */
final class InitializeTenancyByWebhookRoute
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
         * A 404, and deliberately the same 404 for "no such academy" and "that
         * academy is closed". Anything more specific turns this endpoint into
         * an oracle for which academy ids exist, and it is reachable by
         * anybody on the internet.
         */
        if ($tenant === null || ! $tenant->isOpen()) {
            throw new NotFoundHttpException;
        }

        $this->tenancy->initialize($tenant);

        return $next($request);
    }
}
