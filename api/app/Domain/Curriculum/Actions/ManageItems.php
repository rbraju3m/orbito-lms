<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Actions;

use App\Domain\Assessment\Models\Assignment;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Enums\ItemType;
use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Curriculum\Exceptions\CurriculumRejected;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Curriculum\Models\Lesson;
use App\Domain\Curriculum\Models\Resource;
use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Enums\SessionStatus;
use App\Domain\Live\Models\LiveSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ManageItems
{
    public function create(CourseSection $section, ItemType $type, string $title): CourseItem
    {
        if (! $type->isAvailable()) {
            throw CurriculumRejected::typeUnavailable($type);
        }

        $course = $section->loadMissing('course')->course;

        $item = DB::transaction(function () use ($section, $course, $type, $title): CourseItem {
            $itemable = $this->makeItemable($type, $course, $title);

            // New items land at the end of their section, which after
            // normalisation means the correct course-global position.
            $maxInSection = (int) CourseItem::where('section_id', $section->id)->max('position');

            return CourseItem::create([
                'course_id' => $course->id,
                'section_id' => $section->id,
                'position' => $maxInSection + 1,
                'type' => $type,
                'itemable_type' => $itemable->getMorphClass(),
                'itemable_id' => $itemable->getKey(),
                'title' => $title,
                'is_published' => true,
            ]);
        });

        app(NormalisePositions::class)->handle($course);

        CurriculumChanged::dispatch($course);

        return $item->refresh();
    }

    /** @param  array<string, mixed>  $attributes */
    public function update(CourseItem $item, array $attributes): CourseItem
    {
        $item->loadMissing('course');
        $structural = array_intersect_key($attributes, array_flip(['is_published']));

        $item->fill($attributes)->save();

        // Only a change that alters what counts as curriculum needs the
        // counters refreshed; renaming an item does not.
        if ($structural !== [] || array_key_exists('duration_seconds', $attributes)) {
            CurriculumChanged::dispatch($item->course);
        }

        return $item;
    }

    public function delete(CourseItem $item): void
    {
        $course = $item->loadMissing('course')->course;

        DB::transaction(function () use ($item): void {
            $item->itemable?->delete();
            $item->delete();
        });

        app(NormalisePositions::class)->handle($course);

        CurriculumChanged::dispatch($course);
    }

    public function duplicate(
        CourseItem $item,
        ?CourseSection $intoSection = null,
        bool $dispatchEvent = true,
    ): CourseItem {
        // loadMissing, never implicit: strict mode forbids lazy loading, and
        // this runs inside a loop when a whole section is duplicated.
        $item->loadMissing(['course', 'section', 'itemable']);
        $section = $intoSection ?? $item->section;
        $course = $item->course;

        $copy = DB::transaction(function () use ($item, $section, $course): CourseItem {
            $original = $item->itemable;
            $itemable = $original !== null
                ? tap($original->replicate())->save()
                : $this->makeItemable($item->type, $course, $item->title);

            return CourseItem::create([
                'course_id' => $course->id,
                'section_id' => $section->id,
                'position' => (int) CourseItem::where('section_id', $section->id)->max('position') + 1,
                'type' => $item->type,
                'itemable_type' => $itemable->getMorphClass(),
                'itemable_id' => $itemable->getKey(),
                'title' => $item->title.' (copy)',
                // A copy starts unpublished: duplicating is a drafting step, and
                // silently adding a live item to a running course is a surprise.
                'is_published' => false,
                'is_preview' => false,
                'duration_seconds' => $item->duration_seconds,
            ]);
        });

        if ($dispatchEvent) {
            app(NormalisePositions::class)->handle($course);
            CurriculumChanged::dispatch($course);
        }

        return $copy;
    }

    private function makeItemable(ItemType $type, Course $course, string $title): Model
    {
        return match ($type) {
            ItemType::Lesson => Lesson::create([]),
            ItemType::Resource => Resource::create([]),
            ItemType::Quiz => Quiz::create([]),
            ItemType::Assignment => Assignment::create([]),
            ItemType::LiveSession => $this->makeLiveSession($course, $title),
        };
    }

    /**
     * A live session lands as a PLACEHOLDER with no join link.
     *
     * Every other itemable is created empty and filled in afterwards, and this
     * follows that — but a session has required columns a bare row cannot
     * satisfy, so it gets a time a day out and the course owner as its host.
     * Deliberately NO `join_url`: the learner's pane says the link has not
     * been added rather than offering a dead button, which is the same
     * treatment a lesson with no body gets.
     *
     * Scheduling it properly goes through CreateLiveSession, which is where
     * the provider call lives. This one never talks to anybody.
     */
    private function makeLiveSession(Course $course, string $title): LiveSession
    {
        return LiveSession::create([
            'course_id' => $course->id,
            'provider' => LiveProvider::Manual,
            'host_id' => $course->owner_id,
            'title' => $title,
            'starts_at' => now()->addDay()->startOfHour(),
            'ends_at' => now()->addDay()->startOfHour()->addHour(),
            'timezone' => 'UTC',
            'status' => SessionStatus::Scheduled,
        ]);
    }
}
