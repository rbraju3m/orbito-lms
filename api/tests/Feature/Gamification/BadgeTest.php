<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Gamification\Actions\AwardBadges;
use App\Domain\Gamification\Actions\EvaluateTrigger;
use App\Domain\Gamification\Actions\TouchStreak;
use App\Domain\Gamification\Data\TriggerContext;
use App\Domain\Gamification\Enums\TriggerEvent;
use App\Domain\Gamification\Events\BadgeAwarded;
use App\Domain\Gamification\Models\Badge;
use App\Domain\Gamification\Models\GamificationProfile;
use App\Domain\Gamification\Models\GamificationRule;
use App\Domain\Gamification\Models\UserBadge;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    seedRegistry();

    /*
     * A newly provisioned academy now SHIPS with the default rules and badges
     * (TenantDatabaseSeeder), so an engine test that wants to control its own
     * rules has to start from an empty table. Without this, the seeded
     * `lesson.completed` rule pays out alongside whatever the test created —
     * which is the correct product behaviour and the wrong test fixture.
     */
    GamificationRule::query()->delete();
    Badge::query()->delete();

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [3]);

    $this->complete = function (int $index) {
        $item = $this->course->items()->orderBy('position')->get()[$index];

        return app(EvaluateTrigger::class)->handle(TriggerContext::for(
            TriggerEvent::ItemCompleted,
            $this->student->id,
            $item,
            [],
            $this->course->id,
        ));
    };
});

it('awards on a threshold, once', function (): void {
    GamificationRule::factory()->worth(10)->create(['key' => 'lesson.completed']);
    Badge::factory()->requiring(['type' => 'lessons_completed', 'threshold' => 2])
        ->create(['key' => 'two.lessons', 'name' => 'Two lessons']);

    ($this->complete)(0);
    expect(UserBadge::query()->count())->toBe(0);

    ($this->complete)(1);
    expect(UserBadge::query()->count())->toBe(1);

    // Doing more does not award it again — the unique index is the guarantee.
    ($this->complete)(2);
    expect(UserBadge::query()->count())->toBe(1);
});

it('counts distinct sources, so a re-tick cannot inflate it', function (): void {
    GamificationRule::factory()->worth(10)->create(['key' => 'lesson.completed']);
    Badge::factory()->requiring(['type' => 'lessons_completed', 'threshold' => 2])->create();

    ($this->complete)(0);
    ($this->complete)(0);
    ($this->complete)(0);

    expect(UserBadge::query()->count())->toBe(0);
});

it('awards a badge added long after the qualifying work', function (): void {
    /*
     * Evaluated from scratch each time rather than incrementally, which is
     * the point: a badge added months later is earned by whoever already
     * qualifies, with no backfill job.
     */
    GamificationRule::factory()->worth(500)->create(['key' => 'lesson.completed']);
    ($this->complete)(0);

    Badge::factory()->requiring(['type' => 'points_total', 'threshold' => 100])->create();

    app(AwardBadges::class)->handle($this->student->id);

    expect(UserBadge::query()->count())->toBe(1);
});

it('uses the LONGEST streak, so a broken one does not take a badge back', function (): void {
    Badge::factory()->requiring(['type' => 'streak_days', 'threshold' => 3])->create();

    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $day) {
        app(TouchStreak::class)->handle($this->student->id, CarbonImmutable::parse($day, 'UTC'));
    }

    expect(UserBadge::query()->count())->toBe(1);

    // The streak breaks. A badge is never revoked.
    app(TouchStreak::class)->handle($this->student->id, CarbonImmutable::parse('2026-09-20', 'UTC'));

    expect(UserBadge::query()->count())->toBe(1)
        ->and(GamificationProfile::query()->find($this->student->id)->current_streak_days)->toBe(1);
});

it('awards nothing for a criteria type it does not know', function (): void {
    Badge::factory()->requiring(['type' => 'vibes', 'threshold' => 1])->create();

    app(TouchStreak::class)->handle($this->student->id);

    expect(UserBadge::query()->count())->toBe(0);
});

it('awards nothing for a badge with no threshold', function (): void {
    // Otherwise it is earned by everybody the moment it is created.
    Badge::factory()->requiring(['type' => 'points_total'])->create();

    app(TouchStreak::class)->handle($this->student->id);

    expect(UserBadge::query()->count())->toBe(0);
});

it('fires BadgeAwarded once', function (): void {
    Event::fake([BadgeAwarded::class]);

    Badge::factory()->requiring(['type' => 'streak_days', 'threshold' => 1])->create();

    app(TouchStreak::class)->handle($this->student->id);
    app(AwardBadges::class)->handle($this->student->id);

    Event::assertDispatchedTimes(BadgeAwarded::class, 1);
});

it('tells the learner, because nobody watches a threshold they cannot see', function (): void {
    Badge::factory()->requiring(['type' => 'streak_days', 'threshold' => 1])
        ->create(['name' => 'First day']);

    app(TouchStreak::class)->handle($this->student->id);

    $notification = Notification::query()
        ->where('notifiable_id', $this->student->id)
        ->where('type', 'badge.awarded')
        ->sole();

    expect($notification->data['title'])->toContain('First day');
});
