<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Platform\Models\Subscription;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin Subscription
 */
final class SubscriptionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // The fact the SPA actually branches on: whether the studio's
            // save buttons will work.
            'permits_writes' => $this->permitsWrites(),

            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $this->current_period_ends_at?->toIso8601String(),
            'cover_ends_at' => $this->coverEndsAt()?->toIso8601String(),
            'grace_ends_at' => $this->graceEndsAt()?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),

            'plan' => $this->whenLoaded('plan', fn () => [
                'slug' => $this->plan->slug,
                'name' => $this->plan->name,
                'price_minor' => $this->plan->price_minor,
                'currency' => $this->plan->currency,
                'limits' => $this->plan->limits ?? [],
            ]),
        ];
    }
}
