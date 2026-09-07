<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Policies;

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;

/**
 * Deliberately the same shape as QuizPolicy: authoring and grading both
 * resolve through the parent course, so a course-scoped role grants exactly
 * the rights it should, on that course only.
 */
final class AssignmentPolicy
{
    public function manage(User $actor, Course $course): bool
    {
        return $actor->hasPermission('assignment.manage.any')
            || ($actor->can('update', $course) && $actor->hasPermission('assignment.manage.own'))
            || $actor->hasScopedPermission('assignment.manage.own', $course);
    }

    /** A Teaching Assistant grades without being able to author. */
    public function grade(User $actor, Course $course): bool
    {
        return $actor->hasPermission('assignment.grade.any')
            || ($actor->can('update', $course) && $actor->hasPermission('assignment.grade.own'))
            || $actor->hasScopedPermission('assignment.grade.own', $course);
    }

    public function viewSubmissions(User $actor, Course $course): bool
    {
        return $this->grade($actor, $course)
            || $actor->hasPermission('assignment.submission.view.any');
    }
}
