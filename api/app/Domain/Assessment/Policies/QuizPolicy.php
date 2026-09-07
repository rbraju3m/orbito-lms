<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Policies;

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;

/**
 * Quiz authoring and grading resolve through the parent course, so a
 * course-scoped role grants exactly the rights it should on that course only.
 */
final class QuizPolicy
{
    public function manage(User $actor, Course $course): bool
    {
        return $actor->hasPermission('quiz.manage.any')
            || ($actor->can('update', $course) && $actor->hasPermission('quiz.manage.own'))
            || $actor->hasScopedPermission('quiz.manage.own', $course);
    }

    /**
     * A Teaching Assistant can grade without being able to author — that is
     * the whole point of the role.
     */
    public function grade(User $actor, Course $course): bool
    {
        return $actor->hasPermission('quiz.grade.any')
            || ($actor->can('update', $course) && $actor->hasPermission('quiz.grade.own'))
            || $actor->hasScopedPermission('quiz.grade.own', $course);
    }

    public function viewAttempts(User $actor, Course $course): bool
    {
        return $this->grade($actor, $course) || $actor->hasPermission('quiz.attempt.view.any');
    }
}
