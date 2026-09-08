<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Gamification\Actions\BuildLeaderboards;
use App\Domain\Gamification\Actions\EvaluateTrigger;
use App\Domain\Gamification\Data\TriggerContext;
use App\Domain\Gamification\Enums\TriggerEvent;
use App\Domain\Gamification\Models\Badge;
use App\Domain\Gamification\Models\GamificationProfile;
use App\Domain\Gamification\Models\GamificationRule;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

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

    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [3]);
    $this->items = $this->course->items()->orderBy('position')->get();

    $this->student = User::factory()->withRole(RoleKey::Student)->create(['name' => 'Rumi Haque']);
    Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $this->student->id]);

    GamificationRule::factory()->worth(10)->create(['key' => 'lesson.completed']);

    $this->earn = function (User $user, int $index = 0) {
        app(EvaluateTrigger::class)->handle(TriggerContext::for(
            TriggerEvent::ItemCompleted,
            $user->id,
            $this->items[$index],
            [],
            $this->course->id,
        ));
    };
});

it('shows a learner their own standing', function (): void {
    Badge::factory()->requiring(['type' => 'points_total', 'threshold' => 5])
        ->create(['key' => 'starter', 'name' => 'Starter']);

    ($this->earn)($this->student);

    $response = $this->actingAs($this->student)
        ->getJson('/api/v1/achievements')
        ->assertOk()
        ->assertJsonPath('data.profile.points_total', 10)
        ->assertJsonPath('data.profile.current_streak_days', 1);

    expect($response->json('data.recent.0.points'))->toBe(10);
});

it('shows unearned badges with their requirement, not a mystery', function (): void {
    /*
     * A shelf of only what you already hold is a trophy cabinet. The next one
     * visible, with the number on it, is a reason to come back.
     */
    Badge::factory()->requiring(['type' => 'points_total', 'threshold' => 1000])
        ->create(['key' => 'thousand', 'name' => 'A thousand']);

    $response = $this->actingAs($this->student)
        ->getJson('/api/v1/achievements')
        ->assertOk();

    expect($response->json('data.badges.0.is_held'))->toBeFalse()
        ->and($response->json('data.badges.0.criteria.threshold'))->toBe(1000);
});

it('gives somebody who has done nothing zeros, not a 404', function (): void {
    $newcomer = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($newcomer)
        ->getJson('/api/v1/achievements')
        ->assertOk()
        ->assertJsonPath('data.profile.points_total', 0);
});

it('ranks the board and tells the caller where they are', function (): void {
    $classmate = User::factory()->withRole(RoleKey::Student)->create(['name' => 'Bina Roy']);

    ($this->earn)($classmate, 0);
    ($this->earn)($classmate, 1);
    ($this->earn)($this->student, 0);

    app(BuildLeaderboards::class)->handle();

    $response = $this->actingAs($this->student)
        ->getJson('/api/v1/leaderboard')
        ->assertOk()
        ->assertJsonPath('data.entries.0.name', 'Bina Roy')
        ->assertJsonPath('data.entries.0.points', 20)
        ->assertJsonPath('data.entries.1.is_you', true);

    // "You are 2nd" is the only thing here useful to somebody outside the top.
    expect($response->json('data.me.rank'))->toBe(2);
});

it('keeps an opted-out learner off the board without leaving a gap', function (): void {
    $shy = User::factory()->withRole(RoleKey::Student)->create(['name' => 'Quiet Person']);

    ($this->earn)($shy, 0);
    ($this->earn)($shy, 1);
    ($this->earn)($this->student, 0);

    GamificationProfile::query()->where('user_id', $shy->id)->update(['is_ranked' => false]);

    app(BuildLeaderboards::class)->handle();

    $response = $this->actingAs($this->student)
        ->getJson('/api/v1/leaderboard')
        ->assertOk();

    /*
     * Excluded at the SOURCE, so the ranks close up. Filtering a rendered
     * board would leave a visible hole at position 1, which tells everybody
     * exactly who opted out.
     */
    expect($response->json('data.entries'))->toHaveCount(1)
        ->and($response->json('data.entries.0.rank'))->toBe(1)
        ->and($response->json('data.entries.0.name'))->toBe('Rumi Haque');
});

it('lets somebody opt out without giving up their points', function (): void {
    ($this->earn)($this->student);

    $this->actingAs($this->student)
        ->patchJson('/api/v1/achievements/ranking', ['is_ranked' => false])
        ->assertOk()
        ->assertJsonPath('data.is_ranked', false)
        // The rest is still theirs.
        ->assertJsonPath('data.points_total', 10);
});

it('refuses a course board to somebody not in the room', function (): void {
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($stranger)
        ->getJson("/api/v1/courses/{$this->course->uuid}/leaderboard")
        ->assertForbidden();

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/leaderboard")
        ->assertOk();
});

it('returns an empty board rather than a 404 before the first build', function (): void {
    $this->actingAs($this->student)
        ->getJson('/api/v1/leaderboard')
        ->assertOk()
        ->assertJsonPath('data.entries', [])
        ->assertJsonPath('data.me', null);
});

it('scopes a period board to its window', function (): void {
    ($this->earn)($this->student);
    app(BuildLeaderboards::class)->handle();

    $this->actingAs($this->student)
        ->getJson('/api/v1/leaderboard?period=weekly')
        ->assertOk()
        ->assertJsonPath('data.entries.0.points', 10);

    // Two weeks on, last week's work is not this week's board.
    $this->travel(15)->days();
    app(BuildLeaderboards::class)->handle();

    $this->actingAs($this->student)
        ->getJson('/api/v1/leaderboard?period=weekly')
        ->assertOk()
        ->assertJsonPath('data.entries', []);

    // ...but all-time still remembers.
    $this->actingAs($this->student)
        ->getJson('/api/v1/leaderboard?period=all_time')
        ->assertOk()
        ->assertJsonPath('data.entries.0.points', 10);
});

it('needs a signed-in caller', function (): void {
    $this->getJson('/api/v1/achievements')->assertUnauthorized();
    $this->getJson('/api/v1/leaderboard')->assertUnauthorized();
});
