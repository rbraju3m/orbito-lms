<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Data\CourseData;
use App\Domain\Catalog\Enums\CompletionMode;
use App\Domain\Catalog\Enums\CourseLevel;
use App\Domain\Catalog\Enums\CourseVisibility;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Events\CoursePricingChanged;
use App\Domain\Catalog\Models\Course;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Support\Facades\DB;

final class UpdateCourse
{
    public function __construct(
        private readonly SyncCourseTags $syncTags,
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $supplied  the keys actually present in the request
     */
    public function handle(Course $course, CourseData $data, array $supplied): Course
    {
        $pricingWas = $course->pricing_model;

        $updated = DB::transaction(function () use ($course, $data, $supplied): Course {
            $map = [
                'title' => $data->title,
                'subtitle' => $data->subtitle,
                'description' => $this->sanitizer->clean($data->description),
                'category_id' => $data->categoryId,
                // Enum-backed columns are converted here rather than relying on
                // the cast to coerce a raw string.
                'level' => $data->level !== null ? CourseLevel::from($data->level) : null,
                'locale' => $data->locale,
                'visibility' => $data->visibility !== null ? CourseVisibility::from($data->visibility) : null,
                'completion_mode' => $data->completionMode !== null
                    ? CompletionMode::from($data->completionMode)
                    : null,
                'pricing_model' => $data->pricingModel !== null
                    ? PricingModel::from($data->pricingModel)
                    : null,
                'thumbnail_media_id' => $data->thumbnailMediaId,
                'intro_video_media_id' => $data->introVideoMediaId,
                'intro_video_url' => $data->introVideoUrl,
            ];

            // Only touch what the caller actually sent. A PATCH that omits a
            // field must leave it alone, not null it.
            foreach ($map as $column => $value) {
                if (array_key_exists($column, $supplied)) {
                    $course->{$column} = $value;
                }
            }

            $course->save();

            if ($data->tags !== null) {
                $this->syncTags->handle($course, $data->tags);
            }

            if ($data->detail !== null) {
                $course->detail()->updateOrCreate(
                    ['course_id' => $course->id],
                    array_intersect_key($data->detail, array_flip([
                        'objectives', 'requirements', 'target_audience', 'materials', 'faq',
                    ])),
                );
            }

            return $course->fresh(['detail', 'setting', 'instructors.user', 'category', 'tags', 'thumbnail'])
                ?? $course;
        });

        /*
         * Commerce needs to know when a course becomes sellable, and Catalog
         * must not reach into it to say so. Announced only on a real change —
         * every other save leaves the product alone.
         */
        if ($updated->pricing_model !== $pricingWas) {
            CoursePricingChanged::dispatch($updated, $pricingWas, $updated->pricing_model);
        }

        return $updated;
    }
}
