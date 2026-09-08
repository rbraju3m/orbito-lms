<?php

declare(strict_types=1);

use App\Domain\Analytics\Actions\BuildItemFunnel;
use App\Domain\Analytics\Models\ItemFunnel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Models\ItemProgress;

/*
 * The stall heatmap — the one rollup that does not read the event log.
 *
 * A funnel asks "of everybody who reached this item, how many got past it?",
 * which is a question about the present state of every learner rather than
 * about a day. `item_progress` already holds exactly that.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [3]);
    $this->items = $this->course->items()->orderBy('position')->get();
});

function progressFor(Course $course, int $itemId, string $status, int $seconds = 0): void
{
    $user = User::factory()->withRole(RoleKey::Student)->create();
    $enrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $user->id,
    ]);

    ItemProgress::create([
        'enrollment_id' => $enrollment->id,
        'course_item_id' => $itemId,
        'course_id' => $course->id,
        'user_id' => $user->id,
        'status' => $status,
        'first_seen_at' => now(),
        'completed_at' => $status === 'completed' ? now() : null,
        'watch_max_seconds' => $seconds,
    ]);
}

it('finds where a course loses people', function (): void {
    $stall = (int) $this->items[1]->id;

    // Four reach it, one gets past it.
    progressFor($this->course, $stall, 'completed', 300);
    progressFor($this->course, $stall, 'in_progress', 120);
    progressFor($this->course, $stall, 'in_progress', 60);
    progressFor($this->course, $stall, 'in_progress', 90);

    app(BuildItemFunnel::class)->handle();

    $row = ItemFunnel::query()->where('course_item_id', $stall)->sole();

    expect($row->started)->toBe(4)
        ->and($row->completed)->toBe(1)
        ->and((float) $row->drop_off_rate)->toBe(0.75)
        // The FURTHEST point reached, so scrubbing back does not read as
        // having watched less.
        ->and($row->avg_seconds)->toBe(143);
});

it('says nothing rather than zero when nobody watched', function (): void {
    // A text lesson has no seconds to average, and "nobody opened this" is a
    // different fact from "everybody left immediately".
    progressFor($this->course, (int) $this->items[0]->id, 'completed', 0);

    app(BuildItemFunnel::class)->handle();

    expect(ItemFunnel::query()->sole()->avg_seconds)->toBeNull();
});

it('is idempotent', function (): void {
    progressFor($this->course, (int) $this->items[0]->id, 'completed', 10);

    app(BuildItemFunnel::class)->handle();
    app(BuildItemFunnel::class)->handle();

    expect(ItemFunnel::query()->count())->toBe(1)
        ->and(ItemFunnel::query()->sole()->started)->toBe(1);
});

it('drops a row for an item nobody has progress on any more', function (): void {
    $item = (int) $this->items[0]->id;

    progressFor($this->course, $item, 'completed', 10);
    app(BuildItemFunnel::class)->handle();

    ItemProgress::query()->delete();
    app(BuildItemFunnel::class)->handle();

    /*
     * An upsert only touches what it writes, so a restructured course would
     * otherwise keep a stale row reporting a stall in a lesson that no longer
     * has anybody in it.
     */
    expect(ItemFunnel::query()->where('course_item_id', $item)->exists())->toBeFalse();
});

it('can rebuild one course without touching another', function (): void {
    $other = courseWithCurriculum(Course::factory()->published()->create(), [1]);
    $otherItem = (int) $other->items()->sole()->id;

    progressFor($this->course, (int) $this->items[0]->id, 'completed', 10);
    progressFor($other, $otherItem, 'in_progress', 10);

    app(BuildItemFunnel::class)->handle();

    ItemProgress::query()->where('course_id', $other->id)->delete();
    app(BuildItemFunnel::class)->handle($this->course);

    // Scoped rebuild: the other course's row is stale but untouched, which is
    // what "scoped" has to mean or the argument is a lie.
    expect(ItemFunnel::query()->where('course_item_id', $otherItem)->exists())->toBeTrue();
});
