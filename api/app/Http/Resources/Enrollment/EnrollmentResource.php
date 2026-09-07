<?php

declare(strict_types=1);

namespace App\Http\Resources\Enrollment;

use App\Domain\Enrollment\Models\Enrollment;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One enrollment as its owner or a staff member sees it. Carries no learner
 * identity — the roster adds that, because a learner reading their own
 * enrollment does not need to be told who they are.
 *
 * @mixin Enrollment
 */
final class EnrollmentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source->value,
            'is_active' => $this->isActive(),

            'enrolled_at' => $this->enrolled_at->toIso8601String(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspended_reason' => $this->suspended_reason,
        ];
    }
}
