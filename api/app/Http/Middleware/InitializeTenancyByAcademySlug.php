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
 * Opens the academy named by the `{academy}` SLUG in a public URL.
 *
 * This is the first anonymous surface in the product, and it exists because
 * an academy cannot sell anything to somebody who has to sign in to see it
 * (§ Multi-tenancy states the rule this narrows: tenancy resolves from the
 * authenticated user, and everything else is members-only).
 *
 * Resolved by SLUG, not by id like `tenant.path`, because this URL is one a
 * human reads and an academy prints: `/a/dhaka-art-school`, the same slug the
 * registration link already carries. The two middlewares are separate for
 * that reason and because the security arguments differ.
 *
 * THE SECURITY ARGUMENT. `tenant.path` can say "every route behind me carries
 * its own unguessable credential" — an HMAC, a verification token. Nothing
 * here does: the caller is a stranger with no account. What makes it safe is
 * narrower and has to be enforced by every route in the group:
 *
 *  - Only PUBLISHED, deliberately-public data is exposed. A draft course, an
 *    unpublished webinar, a member's name, anybody's progress or money is not
 *    reachable through these endpoints, and the resources are read with no
 *    viewer so nothing viewer-scoped can be computed.
 *  - Naming an academy is not a privilege. The slug is public by design —
 *    it is in the link they hand out — so guessing one grants exactly what
 *    visiting the site grants.
 *  - A closed academy is a 404, and the SAME 404 as an unknown one, so this
 *    is not an oracle for which academies exist or which were suspended.
 *
 * So a route may join this group only if an anonymous stranger seeing its
 * every field is the intended outcome. Anything else belongs behind `tenant`.
 */
final class InitializeTenancyByAcademySlug
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('academy');

        if (! is_string($slug) || $slug === '') {
            throw new NotFoundHttpException;
        }

        $academy = Tenant::query()->where('slug', $slug)->first();

        if ($academy === null || ! $academy->isOpen()) {
            throw new NotFoundHttpException;
        }

        $this->tenancy->initialize($academy);

        /*
         * Kept for the resources, which need the academy's own name and logo
         * without a second central query — and, more importantly, must not
         * re-resolve it from a request that is now running on the academy's
         * connection.
         */
        $request->attributes->set('academy', $academy);

        return $next($request);
    }
}
