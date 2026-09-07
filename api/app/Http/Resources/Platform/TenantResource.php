<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Platform\Models\Tenant;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * An academy as the platform operator sees it.
 *
 * @mixin Tenant
 */
final class TenantResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_active' => $this->is_active,
            'is_open' => $this->isOpen(),

            'support_email' => $this->support_email,
            // Nullable in the schema; the cast makes it look otherwise.
            'approved_at' => $this->resource->approved_at !== null
                ? $this->resource->approved_at->toIso8601String()
                : null,
            // stancl's base Tenant documents created_at as non-null; a row
            // being serialised has always been persisted.
            'created_at' => $this->created_at->toIso8601String(),

            // Held in the virtual `data` blob; nothing filters on them.
            'suspended_reason' => $this->suspended_reason,
            'rejected_reason' => $this->rejected_reason,

            'subscription' => $this->whenLoaded(
                'subscription',
                fn () => $this->subscription !== null
                    ? SubscriptionResource::make($this->subscription)->resolve($request)
                    : null,
            ),
        ];
    }
}
