<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\ReconcileUsageCounters;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Support\UsageCounters;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedRegistry();
    $this->counters = app(UsageCounters::class);
});

it('starts every counter at zero', function (): void {
    expect($this->counters->get(UsageMetric::CoursesTotal))->toBe(0);
});

it('increments and decrements platform counters', function (): void {
    $this->counters->increment(UsageMetric::CoursesTotal);
    $this->counters->increment(UsageMetric::CoursesTotal);
    expect($this->counters->get(UsageMetric::CoursesTotal))->toBe(2);

    $this->counters->decrement(UsageMetric::CoursesTotal);
    expect($this->counters->get(UsageMetric::CoursesTotal))->toBe(1);
});

it('keeps per-owner counters separate from the platform total', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->counters->increment(UsageMetric::CoursesTotal, $a, 3);
    $this->counters->increment(UsageMetric::CoursesTotal, $b, 5);
    $this->counters->increment(UsageMetric::CoursesTotal, null, 8);

    expect($this->counters->get(UsageMetric::CoursesTotal, $a))->toBe(3)
        ->and($this->counters->get(UsageMetric::CoursesTotal, $b))->toBe(5)
        ->and($this->counters->get(UsageMetric::CoursesTotal))->toBe(8);
});

/*
 * A double-fired decrement must not drive a count negative — a plan limit
 * comparing against -1 would silently grant unlimited quota.
 */
it('never goes below zero', function (): void {
    $this->counters->decrement(UsageMetric::CoursesTotal);
    $this->counters->decrement(UsageMetric::CoursesTotal);

    expect($this->counters->get(UsageMetric::CoursesTotal))->toBe(0);
});

it('adds byte quantities rather than counting events', function (): void {
    $owner = User::factory()->create();

    $this->counters->increment(UsageMetric::StorageBytes, $owner, 1_500_000);
    $this->counters->increment(UsageMetric::StorageBytes, $owner, 500_000);

    expect($this->counters->get(UsageMetric::StorageBytes, $owner))->toBe(2_000_000);
});

it('does not create duplicate rows when the same counter is touched repeatedly', function (): void {
    $owner = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->counters->increment(UsageMetric::MediaFiles, $owner);
    }

    expect(DB::table('usage_counters')->where('metric', 'media_files')->count())->toBe(1)
        ->and($this->counters->get(UsageMetric::MediaFiles, $owner))->toBe(5);
});

it('reports every counter for an owner in one read', function (): void {
    $owner = User::factory()->create();
    $this->counters->increment(UsageMetric::CoursesTotal, $owner, 2);
    $this->counters->increment(UsageMetric::MediaFiles, $owner, 7);

    expect($this->counters->all($owner))
        ->toBe(['courses_total' => 2, 'media_files' => 7]);
});

describe('reconciliation', function (): void {
    it('reports no drift when counters are correct', function (): void {
        expect(app(ReconcileUsageCounters::class)->handle())->toBe([]);
    });

    it('detects and corrects drift from a dead listener', function (): void {
        $instructor = User::factory()->instructor()->create();
        Course::factory()->ownedBy($instructor)->count(3)->create();

        // Simulate the events never having been handled.
        $this->counters->set(UsageMetric::CoursesTotal, $instructor, 0);
        $this->counters->set(UsageMetric::CoursesTotal, null, 0);

        $drift = app(ReconcileUsageCounters::class)->handle();

        expect($drift)->not->toBe([])
            ->and($this->counters->get(UsageMetric::CoursesTotal, $instructor))->toBe(3)
            ->and($this->counters->get(UsageMetric::CoursesTotal))->toBe(3);
    });

    it('reports drift without correcting it on a dry run', function (): void {
        $instructor = User::factory()->instructor()->create();
        Course::factory()->ownedBy($instructor)->create();
        $this->counters->set(UsageMetric::CoursesTotal, null, 99);

        $drift = app(ReconcileUsageCounters::class)->handle(dryRun: true);

        expect($drift)->not->toBe([])
            ->and($this->counters->get(UsageMetric::CoursesTotal))->toBe(99);
    });
});
