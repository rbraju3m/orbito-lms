<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Models\Plan;
use App\Http\Resources\Platform\PlanResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The plans an academy can be put on.
 *
 * Provisioning and `PUT /admin/tenants/{tenant}/plan` both validate a plan
 * SLUG, and until now nothing told an operator which slugs existed — the
 * registry screens would have had to ask them to type one. This is that list.
 *
 * Deliberately not paginated: `plans` is a handful of rows an operator
 * curates, not a growing table, and a page control over four cards is noise.
 * Every other list endpoint in the API is paginated because every other one
 * can grow.
 */
final class PlanController
{
    public function index(Request $request): JsonResponse
    {
        // Inactive plans are still returned. An academy already sitting on a
        // retired plan has to be legible on its own detail screen, and hiding
        // the row would render its subscription as an unnamed slug.
        $plans = Plan::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return ApiResponse::ok(
            $plans->map(fn (Plan $plan) => PlanResource::make($plan)->resolve($request))->all()
        );
    }
}
