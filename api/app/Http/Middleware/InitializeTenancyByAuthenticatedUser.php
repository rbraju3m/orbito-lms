<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Platform\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the authenticated user's `tenant_id` and activates that academy's
 * schema for the rest of the request.
 *
 * Applied to every /api/v1 route EXCEPT auth (register, login, password reset),
 * the platform admin surface, and the signed media route — see routes/api.php.
 * Anything inside this middleware may assume the tenant connection is live.
 *
 * It MUST run after `auth:sanctum`: there is no user to read before that, and
 * raising this middleware's priority would make it run first and find none.
 * That is why TenancyServiceProvider does not call
 * makeTenancyMiddlewareHighestPriority().
 */
final class InitializeTenancyByAuthenticatedUser
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Authentication is required.');
        }

        // A platform super-admin belongs to no academy and works only on the
        // central database. Their own routes sit outside this middleware;
        // letting them through here is what keeps /auth/me working.
        if ($user->is_super_admin) {
            return $next($request);
        }

        if ($user->tenant_id === null) {
            abort(403, 'This account is not attached to an academy.');
        }

        $tenant = Tenant::find($user->tenant_id);

        if ($tenant === null) {
            abort(403, 'Academy not found.');
        }

        // Checked BEFORE initialize(), deliberately. A suspended academy may be
        // mid-restore or have no schema at all, and a query against a missing
        // database raises "Unknown database" — a 500 the caller cannot read and
        // the client cannot retry. This is the last point early enough to
        // answer honestly instead.
        if (! $tenant->isOpen()) {
            abort(403, 'This academy is not currently available.');
        }

        $this->tenancy->initialize($tenant);

        return $next($request);
    }
}
