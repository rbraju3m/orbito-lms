<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform operator's surface.
 *
 * Deliberately a flag on the central user row rather than a role: roles live
 * inside an academy's schema, and the one account that belongs to no academy
 * is precisely the one that could not hold one.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Authentication is required.');
        }

        if (! $user->is_super_admin) {
            abort(403, 'This is a platform operator endpoint.');
        }

        return $next($request);
    }
}
