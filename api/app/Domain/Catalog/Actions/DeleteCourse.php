<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Events\CourseDeleted;
use App\Domain\Catalog\Models\Course;

final class DeleteCourse
{
    /**
     * Soft delete. A course may have enrollments, orders and certificates
     * hanging off it; destroying the row would orphan a learner's history.
     * Hard deletion, if it ever exists, is a separate deliberate operation.
     */
    public function handle(Course $course): void
    {
        $wasPublished = $course->status === CourseStatus::Published;
        $courseId = $course->id;
        $ownerId = $course->owner_id;

        $course->delete();

        CourseDeleted::dispatch($courseId, $ownerId, $wasPublished);
    }
}
