<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CompletionMode;
use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Actions\RecalculateCourseProgress;
use App\Domain\Progress\Actions\TrackItemProgress;
use App\Domain\Progress\Events\CourseCompleted;
use App\Domain\Progress\Events\ItemCompleted;
use App\Domain\Progress\Models\ItemProgress;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    seedRegistry();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [2, 2]);
    $this->enrollment = Enrollment::factory()->create(['course_id' => $this->course->id]);
    $this->track = app(TrackItemProgress::class);
});

it('counts every published completable item as the denominator', function (): void {
    expect($this->enrollment->progress->total_items)->toBe(4)
        ->and($this->enrollment->progress->completed_items)->toBe(0)
        ->and((float) $this->enrollment->progress->percent)->toBe(0.0);
});

it('advances the stored percentage as items are completed', function (): void {
    $items = $this->course->items()->orderBy('position')->get();

    $this->track->complete($this->enrollment, $items[0]);
    expect((float) $this->enrollment->fresh('progress')->progress->percent)->toBe(25.0);

    $this->track->complete($this->enrollment, $items[1]);
    expect((float) $this->enrollment->fresh('progress')->progress->percent)->toBe(50.0);
});

it('is idempotent — completing twice does not double count', function (): void {
    $item = $this->course->items()->first();

    $this->track->complete($this->enrollment, $item);
    $this->track->complete($this->enrollment, $item);

    expect($this->enrollment->fresh('progress')->progress->completed_items)->toBe(1);
});

it('excludes unpublished items from the denominator', function (): void {
    $this->course->items()->first()->update(['is_published' => false]);

    app(RecalculateCourseProgress::class)->handle($this->enrollment);

    expect($this->enrollment->fresh('progress')->progress->total_items)->toBe(3);
});

/* A learner does not "finish" a PDF. */
it('excludes downloadable resources from the denominator', function (): void {
    $section = $this->course->sections()->first();
    CourseItem::factory()->inSection($section)->resource()->create(['position' => 99]);

    app(RecalculateCourseProgress::class)->handle($this->enrollment);

    expect($this->enrollment->fresh('progress')->progress->total_items)->toBe(4);
});

it('creates item progress lazily, only for items actually touched', function (): void {
    expect(ItemProgress::where('enrollment_id', $this->enrollment->id)->count())->toBe(0);

    $this->track->view($this->enrollment, $this->course->items()->first());

    // 10k students x 100 items would be a million rows nobody has opened.
    expect(ItemProgress::where('enrollment_id', $this->enrollment->id)->count())->toBe(1);
});

it('remembers where the learner was', function (): void {
    $items = $this->course->items()->orderBy('position')->get();

    $this->track->view($this->enrollment, $items[2]);

    $progress = $this->enrollment->fresh('progress')->progress;
    expect($progress->last_item_id)->toBe($items[2]->id)
        ->and($progress->last_activity_at)->not->toBeNull();
});

it('completes a strict course automatically when everything is done', function (): void {
    $this->course->update(['completion_mode' => CompletionMode::Strict]);
    Event::fake([CourseCompleted::class, ItemCompleted::class]);

    foreach ($this->course->items as $item) {
        $this->track->complete($this->enrollment, $item);
    }

    $fresh = $this->enrollment->fresh(['progress']);
    expect($fresh->progress->completed_at)->not->toBeNull()
        ->and($fresh->status)->toBe(EnrollmentStatus::Completed)
        ->and((float) $fresh->progress->percent)->toBe(100.0);

    Event::assertDispatched(CourseCompleted::class);
});

it('fires ItemCompleted so other contexts can react', function (): void {
    Event::fake([ItemCompleted::class]);

    $this->track->complete($this->enrollment, $this->course->items()->first());

    // Progress knows nothing about gamification, analytics or certificates.
    Event::assertDispatched(ItemCompleted::class);
});

it('un-completing lowers the percentage again', function (): void {
    $item = $this->course->items()->first();

    $this->track->complete($this->enrollment, $item);
    expect((float) $this->enrollment->fresh('progress')->progress->percent)->toBe(25.0);

    $this->track->uncomplete($this->enrollment, $item);
    expect((float) $this->enrollment->fresh('progress')->progress->percent)->toBe(0.0);
});

describe('watch tracking', function (): void {
    it('records the position', function (): void {
        $item = $this->course->items()->first();
        $item->update(['duration_seconds' => 600]);

        $this->track->recordWatch($this->enrollment, $item, 120, 90);

        $progress = ItemProgress::where('course_item_id', $item->id)->first();
        expect($progress->watch_position_seconds)->toBe(120)
            ->and($progress->watch_max_seconds)->toBe(120);
    });

    /* Scrubbing backwards must not un-earn progress already made. */
    it('never lowers the furthest point reached', function (): void {
        $item = $this->course->items()->first();
        $item->update(['duration_seconds' => 600]);

        $this->track->recordWatch($this->enrollment, $item, 400, 90);
        $this->track->recordWatch($this->enrollment, $item, 60, 90);

        $progress = ItemProgress::where('course_item_id', $item->id)->first();
        expect($progress->watch_position_seconds)->toBe(60)
            ->and($progress->watch_max_seconds)->toBe(400);
    });

    it('completes the item once the threshold is reached', function (): void {
        $item = $this->course->items()->first();
        $item->update(['duration_seconds' => 100]);

        $this->track->recordWatch($this->enrollment, $item, 95, 90);

        expect($this->enrollment->fresh('progress')->progress->completed_items)->toBe(1);
    });

    it('does not complete below the threshold', function (): void {
        $item = $this->course->items()->first();
        $item->update(['duration_seconds' => 100]);

        $this->track->recordWatch($this->enrollment, $item, 50, 90);

        expect($this->enrollment->fresh('progress')->progress->completed_items)->toBe(0);
    });

    it('clamps a position beyond the item duration', function (): void {
        $item = $this->course->items()->first();
        $item->update(['duration_seconds' => 100]);

        // A client claiming to be an hour into a 100-second video is either
        // broken or lying.
        $this->track->recordWatch($this->enrollment, $item, 999999, 90);

        expect(ItemProgress::where('course_item_id', $item->id)->first()->watch_max_seconds)->toBe(100);
    });
});

describe('reconciliation', function (): void {
    it('reports nothing when the stored aggregate is correct', function (): void {
        $this->artisan('progress:reconcile')
            ->expectsOutputToContain('consistent')
            ->assertSuccessful();
    });

    /*
     * The aggregate can only drift if a listener dies mid-flight, so drift is a
     * bug alert — hence a command that reports it rather than silently fixing.
     */
    it('detects and corrects drift', function (): void {
        $this->track->complete($this->enrollment, $this->course->items()->first());

        DB::table('course_progress')
            ->where('enrollment_id', $this->enrollment->id)
            ->update(['completed_items' => 99]);

        $this->artisan('progress:reconcile')
            ->expectsOutputToContain('drifted')
            ->assertSuccessful();

        expect($this->enrollment->fresh('progress')->progress->completed_items)->toBe(1);
    });

    it('leaves the data alone on a dry run', function (): void {
        DB::table('course_progress')
            ->where('enrollment_id', $this->enrollment->id)
            ->update(['completed_items' => 99]);

        $this->artisan('progress:reconcile', ['--dry-run' => true])->assertSuccessful();

        expect($this->enrollment->fresh('progress')->progress->completed_items)->toBe(99);
    });
});
