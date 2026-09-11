<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\Refund;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One refund, as the order shows it — to the learner as well as to staff, so
 * `reason` is written for the learner to read. No gateway ids.
 *
 * @mixin Refund
 */
final class RefundResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reason' => $this->reason,
            'revokes_access' => $this->revokes_access,
            'failure_reason' => $this->failure_reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
