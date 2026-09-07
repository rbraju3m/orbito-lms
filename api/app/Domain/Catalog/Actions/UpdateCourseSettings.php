<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseSetting;

final class UpdateCourseSettings
{
    /** @param  array<string, mixed>  $attributes */
    public function handle(Course $course, array $attributes): CourseSetting
    {
        $setting = $course->setting()->firstOrCreate(['course_id' => $course->id]);
        $setting->fill($attributes)->save();

        return $setting;
    }
}
