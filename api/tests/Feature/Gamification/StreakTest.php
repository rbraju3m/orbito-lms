<?php

declare(strict_types=1);

use App\Domain\Gamification\Actions\TouchStreak;
use App\Domain\Gamification\Models\GamificationProfile;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;

/*
 * Streaks. The number is only worth having if it is true, so nothing here
 * forgives a gap.
 */

beforeEach(function (): void {
    seedRegistry();
    $this->student = User::factory()->withRole(RoleKey::Student)->create();

    $this->touch = fn (string $date) => app(TouchStreak::class)
        ->handle($this->student->id, CarbonImmutable::parse($date, 'UTC')->startOfDay());

    $this->profile = fn () => GamificationProfile::query()->findOrFail($this->student->id);
});

it('starts at one', function (): void {
    ($this->touch)('2026-09-01');

    expect(($this->profile)()->current_streak_days)->toBe(1)
        ->and(($this->profile)()->longest_streak_days)->toBe(1);
});

it('counts consecutive days', function (): void {
    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $day) {
        ($this->touch)($day);
    }

    expect(($this->profile)()->current_streak_days)->toBe(3);
});

it('treats twenty lessons in one day as one day', function (): void {
    foreach (range(1, 20) as $_) {
        ($this->touch)('2026-09-01');
    }

    expect(($this->profile)()->current_streak_days)->toBe(1);
});

it('restarts after a gap, and does not forgive it', function (): void {
    /*
     * A grace day that quietly forgives a miss makes the number a lie —
     * somebody shown a 40-day streak they did not earn stops believing any of
     * it.
     */
    ($this->touch)('2026-09-01');
    ($this->touch)('2026-09-02');
    ($this->touch)('2026-09-05');

    expect(($this->profile)()->current_streak_days)->toBe(1)
        // ...but the best run is kept, because it happened.
        ->and(($this->profile)()->longest_streak_days)->toBe(2);
});

it('remembers the longest run after the current one breaks', function (): void {
    foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'] as $day) {
        ($this->touch)($day);
    }

    ($this->touch)('2026-09-10');

    expect(($this->profile)()->current_streak_days)->toBe(1)
        ->and(($this->profile)()->longest_streak_days)->toBe(4);
});
