<?php

declare(strict_types=1);

namespace App\Http\Resources\Enrollment;

use App\Domain\Enrollment\Models\Enrollment;
use App\Http\Resources\Progress\CourseProgressResource;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A row of the instructor's roster: the enrollment, who holds it, and how far
 * they have got.
 *
 * @mixin Enrollment
 */
final class CourseStudentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'enrollment' => EnrollmentResource::make($this->resource)->resolve($request),

            'student' => [
                'id' => $this->user?->uuid,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ],

            // Absent, not zero: a learner who has not started and one who
            // scored nothing are different facts (Phase 8).
            'progress' => $this->whenLoaded(
                'progress',
                fn () => $this->progress !== null
                    ? CourseProgressResource::make($this->progress)->resolve($request)
                    : null,
            ),
        ];
    }
}
