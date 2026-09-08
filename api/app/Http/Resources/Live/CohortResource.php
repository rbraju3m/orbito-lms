<?php

declare(strict_types=1);

namespace App\Http\Resources\Live;

use App\Domain\Live\Models\Cohort;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin Cohort
 */
final class CohortResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'timezone' => $this->timezone,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'capacity' => $this->capacity,
            // Null means uncapped, which is not the same as zero left — the
            // UI has to be able to tell those apart.
            'places_remaining' => $this->placesRemaining(),
            'enrollment_deadline' => $this->enrollment_deadline?->toIso8601String(),

            /*
             * The three questions answered as one, from the same method the
             * enrolment path calls — so the button and the server cannot
             * disagree about whether this run is open.
             */
            'is_joinable' => $this->isJoinable(),

            'session_count' => $this->whenCounted('sessions'),
            'enrollment_count' => $this->whenCounted('enrollments'),
        ];
    }
}
