<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Data\CourseData;
use App\Domain\Catalog\Enums\CourseInstructorRole;
use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Events\CourseCreated;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Queries\PlanLimits;
use Illuminate\Support\Facades\DB;

final class CreateCourse
{
    public function __construct(
        private readonly SyncCourseTags $syncTags,
        private readonly PlanLimits $limits,
    ) {}

    public function handle(User $owner, CourseData $data): Course
    {
        /*
         * The plan's course allowance, before anything is written. Catalog
         * asks Platform the same way Assessment asks `CourseAccess` — a limit
         * has to be answered synchronously, so it cannot be an event.
         *
         * A draft counts. Capping only published courses would let an academy
         * build its whole catalogue on Starter and publish it one at a time,
         * and unpublishing to make room is not a thing anyone should be asked
         * to do.
         */
        $this->limits->assert(UsageMetric::CoursesTotal);

        $course = DB::transaction(function () use ($owner, $data): Course {
            $course = new Course([
                'title' => $data->title ?? 'Untitled course',
                'subtitle' => $data->subtitle,
                'description' => $data->description,
                'category_id' => $data->categoryId,
                'level' => $data->level ?? 'all',
                'locale' => $data->locale ?? config('orbito.locales.default'),
                'visibility' => $data->visibility ?? 'public',
                'completion_mode' => $data->completionMode ?? 'flexible',
                'pricing_model' => $data->pricingModel ?? 'free',
            ]);

            // Never mass-assignable: ownership and lifecycle are not client input.
            $course->owner_id = $owner->id;
            $course->status = CourseStatus::Draft;
            $course->save();

            // Every course gets its detail and settings rows up front, so no
            // later code has to defend against a missing one-to-one.
            $course->detail()->create(['course_id' => $course->id]);
            $course->setting()->create(['course_id' => $course->id]);

            $course->instructors()->create([
                'user_id' => $owner->id,
                'role' => CourseInstructorRole::Owner,
                'position' => 0,
            ]);

            if ($data->tags !== null) {
                $this->syncTags->handle($course, $data->tags);
            }

            return $course;
        });

        CourseCreated::dispatch($course);

        return $course->fresh(['detail', 'setting', 'instructors.user', 'category', 'tags']) ?? $course;
    }
}
