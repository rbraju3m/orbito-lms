<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Actions;

use App\Domain\Curriculum\Enums\VideoProvider;
use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\Lesson;
use Illuminate\Support\Facades\DB;

final class UpsertLesson
{
    /**
     * @param  array<string, mixed>  $lessonAttributes
     * @param  array<string, mixed>  $itemAttributes
     */
    public function handle(CourseItem $item, array $lessonAttributes, array $itemAttributes = []): CourseItem
    {
        $item->loadMissing(['course', 'itemable']);

        DB::transaction(function () use ($item, $lessonAttributes, $itemAttributes): void {
            /** @var Lesson $lesson */
            $lesson = $item->itemable;
            $lesson->fill($lessonAttributes)->save();

            // Clear whichever video field the chosen provider does not use, so
            // a switch from "uploaded" to "YouTube" cannot leave a stale media
            // id pointing at a file the lesson no longer shows.
            if (array_key_exists('video_provider', $lessonAttributes)) {
                $provider = $lesson->video_provider;

                $lesson->forceFill([
                    'video_media_id' => $provider->usesMedia() ? $lesson->video_media_id : null,
                    'video_url' => $provider->usesUrl() ? $lesson->video_url : null,
                    'video_duration_seconds' => $provider === VideoProvider::None
                        ? 0
                        : $lesson->video_duration_seconds,
                ])->save();
            }

            $item->fill($itemAttributes);

            // The item's duration is what the curriculum and progress read;
            // keep it in step with the lesson's video.
            $item->duration_seconds = max(
                (int) ($itemAttributes['duration_seconds'] ?? 0),
                $lesson->video_duration_seconds,
            );

            $item->save();
        });

        CurriculumChanged::dispatch($item->course);

        return $item->refresh()->load('itemable');
    }
}
