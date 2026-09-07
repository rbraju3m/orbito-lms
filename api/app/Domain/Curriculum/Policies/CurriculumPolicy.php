<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Policies;

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;

/**
 * Curriculum authorization always resolves through the parent course, so a
 * course-scoped role (Course Manager, TA) grants exactly the rights it should
 * and nothing beyond that course.
 */
final class CurriculumPolicy
{
    public function view(User $actor, Course $course): bool
    {
        return $actor->can('viewUnpublished', $course);
    }

    public function manage(User $actor, Course $course): bool
    {
        return $actor->hasPermission('curriculum.manage.any')
            || ($actor->can('update', $course) && $actor->hasPermission('curriculum.manage.own'))
            || $actor->hasScopedPermission('curriculum.manage.own', $course);
    }

    public function reorder(User $actor, Course $course): bool
    {
        return $this->manage($actor, $course)
            && ($actor->hasPermission('curriculum.reorder')
                || $actor->hasScopedPermission('curriculum.reorder', $course));
    }
}
