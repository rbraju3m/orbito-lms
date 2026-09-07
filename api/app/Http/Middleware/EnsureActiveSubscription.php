<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Platform\Exceptions\SubscriptionLapsed;
use App\Domain\Platform\Queries\SubscriptionState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates WRITES on a lapsed subscription. Reads are never gated.
 *
 * An academy that has not paid must still be able to see — and export — its
 * own courses, learners and results. Taking the data away is neither leverage
 * nor a product; it is what turns a lapsed customer into a former one, and
 * into a support case about export.
 *
 * Registered as `subscription` and applied AFTER `auth:sanctum` and `tenant`,
 * so the user and the academy have both resolved.
 *
 * The gate reads the ACADEMY, not the actor. A super-admin acting inside a
 * lapsed academy is blocked from writes exactly as its owner is — the route
 * back is the platform admin surface, which sits outside this middleware
 * precisely so the way to fix it survives the gate.
 */
final class EnsureActiveSubscription
{
    /**
     * Named routes that stay open regardless.
     *
     * Logout is the real entry: an academy whose subscription lapsed must not
     * trap its users in a session they cannot end.
     *
     * @var list<string>
     */
    public const EXEMPT_ROUTES = [
        'auth.logout',
    ];

    public function __construct(private readonly SubscriptionState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        // GET / HEAD / OPTIONS always pass. This IS the read-only rule.
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if (in_array((string) $request->route()?->getName(), self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        $user = $request->user();

        // No academy means no subscription to check — a platform operator is
        // somebody else's gate.
        if ($user === null || $user->tenant_id === null) {
            return $next($request);
        }

        $subscription = $this->state->for($user->tenant_id);

        // Fail CLOSED on a missing row rather than open. Reads still work, so
        // the blast radius of being wrong here is an academy that cannot write
        // for as long as it takes an operator to notice — where failing open
        // would be a permanent revenue hole nobody would ever notice.
        if ($subscription === null || ! $subscription->permitsWrites()) {
            throw SubscriptionLapsed::from($subscription);
        }

        return $next($request);
    }
}
