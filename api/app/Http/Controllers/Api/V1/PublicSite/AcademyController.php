<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicSite;

use App\Domain\Platform\Models\Tenant;
use App\Http\Resources\Platform\PublicAcademyResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public site's own header: whose site is this, and can I join it?
 *
 * Read from the request rather than the route, because `tenant.public` has
 * already resolved the academy on the CENTRAL connection and the request is
 * now running on the academy's own. Re-resolving `Tenant` here would work by
 * accident — the model is pinned to `mysql` (§ Multi-tenancy) — and would be
 * a second lookup of something already in hand.
 */
final class AcademyController
{
    public function __invoke(Request $request): JsonResponse
    {
        $academy = $request->attributes->get('academy');

        abort_unless($academy instanceof Tenant, 404);

        return ApiResponse::ok(PublicAcademyResource::make($academy));
    }
}
