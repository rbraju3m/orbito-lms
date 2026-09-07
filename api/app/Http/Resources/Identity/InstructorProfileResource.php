<?php

declare(strict_types=1);

namespace App\Http\Resources\Identity;

use App\Domain\Identity\Models\InstructorProfile;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin InstructorProfile
 */
final class InstructorProfileResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwnerOrStaff = $viewer !== null
            && ($viewer->id === $this->user_id || $viewer->hasPermission('instructor.view'));

        return [
            // Needed to address the review endpoint. Not sensitive: every
            // instructor route authorizes independently.
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'course_count' => $this->course_count,
            'student_count' => $this->student_count,

            // The reviewer's note can be blunt; it is for the applicant and
            // staff, not for a public profile page.
            'review_note' => $this->when($isOwnerOrStaff, fn () => $this->review_note),
            'application_message' => $this->when($isOwnerOrStaff, fn () => $this->application_message),

            'user' => $this->whenLoaded('user', fn () => UserResource::make($this->user)),
        ];
    }
}
