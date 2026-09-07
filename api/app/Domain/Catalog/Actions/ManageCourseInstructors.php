<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\CourseInstructorRole;
use App\Domain\Catalog\Exceptions\CourseTransitionRejected;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseInstructor;
use App\Domain\Identity\Models\User;
use App\Support\Exceptions\DomainException;

final class ManageCourseInstructors
{
    public function add(
        Course $course,
        User $user,
        CourseInstructorRole $role = CourseInstructorRole::CoInstructor,
        ?int $revenueShareBp = null,
    ): CourseInstructor {
        if ($role === CourseInstructorRole::Owner) {
            throw new class('A course has exactly one owner; transfer ownership instead.') extends DomainException
            {
                public function errorCode(): string
                {
                    return 'course_owner_immutable';
                }

                public function status(): int
                {
                    return 422;
                }
            };
        }

        return $course->instructors()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'role' => $role,
                'revenue_share_bp' => $revenueShareBp,
                'position' => (int) $course->instructors()->max('position') + 1,
            ],
        );
    }

    public function remove(Course $course, User $user): void
    {
        if ($course->owner_id === $user->id) {
            // Removing the owner would leave the course unmanageable.
            throw CourseTransitionRejected::illegal($course->status, $course->status);
        }

        $course->instructors()->where('user_id', $user->id)->delete();
    }
}
