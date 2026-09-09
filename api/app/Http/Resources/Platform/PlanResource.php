<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Platform\Models\Plan;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A plan as the platform operator sees it.
 *
 * The `slug` is the identifier every write speaks in — `StoreTenantRequest`
 * and `AssignPlanRequest` both validate `exists:mysql.plans,slug` — so it is
 * first here rather than the numeric id.
 *
 * `limits` is returned as stored. It is what an academy is ENTITLED to; what
 * it has USED lives in `usage_counters`, and nothing enforces the two against
 * each other yet (Phase 16). Rendering the entitlement is honest; implying it
 * is enforced would not be.
 *
 * @mixin Plan
 */
final class PlanResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,

            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'billing_period' => $this->billing_period,

            'trial_days' => $this->trial_days,
            'grace_days' => $this->grace_days,

            'limits' => $this->limits ?? [],
            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
        ];
    }
}
