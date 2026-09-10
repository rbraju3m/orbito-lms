<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\Order;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One order, for the learner who placed it or for staff reading the queue.
 *
 * Carries no payment credentials and no gateway ids: what the learner needs is
 * whether it is paid, and what they were charged.
 *
 * @mixin Order
 */
final class OrderResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'grants_access' => $this->status->grantsAccess(),

            'currency' => $this->currency,
            // As typed at checkout, frozen — never the coupon's current code.
            'coupon_code' => $this->coupon_code,
            'subtotal_minor' => $this->subtotal_minor,
            'discount_minor' => $this->discount_minor,
            'total_minor' => $this->total_minor,

            'placed_at' => $this->placed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),

            'items' => OrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
