<?php

declare(strict_types=1);

use App\Domain\Gamification\Models\Badge;
use App\Domain\Gamification\Models\GamificationRule;
use App\Domain\Gamification\Support\GamificationRegistry;

/*
 * Config is the SEED, not the source of truth. A sync that overwrote would
 * make the file the truth and quietly revert every academy's tuning on the
 * next deploy.
 */

it('creates the shipped rules and badges', function (): void {
    app(GamificationRegistry::class)->sync();

    expect(GamificationRule::query()->count())->toBe(count(config('gamification.rules')))
        ->and(Badge::query()->count())->toBe(count(config('gamification.badges')));
});

it('never overwrites an academy tuning', function (): void {
    app(GamificationRegistry::class)->sync();

    GamificationRule::query()->where('key', 'lesson.completed')
        ->update(['points' => 999, 'is_active' => false]);

    Badge::query()->where('key', 'first.lesson')->update(['name' => 'Our own name']);

    app(GamificationRegistry::class)->sync();

    $rule = GamificationRule::query()->where('key', 'lesson.completed')->sole();

    expect($rule->points)->toBe(999)
        ->and($rule->is_active)->toBeFalse()
        ->and(Badge::query()->where('key', 'first.lesson')->sole()->name)->toBe('Our own name');
});

it('is safe to run twice', function (): void {
    app(GamificationRegistry::class)->sync();
    $created = app(GamificationRegistry::class)->sync();

    expect($created['rules'])->toBe(0)
        ->and($created['badges'])->toBe(0);
});

it('adds a rule that did not exist before, without touching the rest', function (): void {
    app(GamificationRegistry::class)->sync();
    GamificationRule::query()->where('key', 'quiz.passed')->delete();

    $created = app(GamificationRegistry::class)->sync();

    expect($created['rules'])->toBe(1)
        ->and(GamificationRule::query()->where('key', 'quiz.passed')->exists())->toBeTrue();
});
