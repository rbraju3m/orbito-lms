<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Support;

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Which prerequisite courses this user has not finished.
 *
 * "Finished" is read from the enrollment's status, not recomputed from
 * progress — `CourseCompleted` is what writes it, and having two definitions
 * of done is how a learner ends up locked out of a course they completed.
 *
 * Consulted on the enrolment path and by the course detail page, so the button
 * and the server give the same answer. Never by CourseAccess: see the note on
 * the course_prerequisites migration.
 */
final class PrerequisiteCheck
{
    /** @return Collection<int, Course> the prerequisites still outstanding */
    public function unmetFor(?User $user, Course $course): Collection
    {
        $course->loadMissing('prerequisites');

        /** @var Collection<int, Course> $required */
        $required = $course->prerequisites;

        if ($required->isEmpty()) {
            return collect();
        }

        if ($user === null) {
            return $required;
        }

        $completedIds = Enrollment::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $required->pluck('id'))
            ->where('status', EnrollmentStatus::Completed)
            ->pluck('course_id')
            ->all();

        return $required
            ->reject(fn (Course $prerequisite) => in_array($prerequisite->id, $completedIds, true))
            ->values();
    }

    public function isSatisfied(?User $user, Course $course): bool
    {
        return $this->unmetFor($user, $course)->isEmpty();
    }
}
