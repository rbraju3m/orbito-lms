<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Platform\Models\Tenant;
use Tests\Concerns\SwitchesTenants;

// The command walks every academy, so the academy cannot be transacted.
uses(SwitchesTenants::class);

/*
 * Retention. The event log names people — `actor_id` directly, `ip_hash` as a
 * pseudonym — so it has a finite life. Rollups do not: they are counts with
 * nobody in them.
 */

beforeEach(function (): void {
    $this->centrally = function (string $command, array $args = [], bool $expectSuccess = true): void {
        tenancy()->end();

        $run = $this->artisan($command, $args);
        $expectSuccess ? $run->assertSuccessful() : $run->assertFailed();

        tenancy()->initialize(Tenant::find($this->sharedTenantId()));
    };
});

it('deletes what is past the window and keeps what is not, with no tenant open', function (): void {
    AnalyticsEvent::factory()->at(now()->subDays(500))->count(3)->create();
    AnalyticsEvent::factory()->at(now()->subDays(399))->count(2)->create();
    AnalyticsEvent::factory()->at(now())->create();

    ($this->centrally)('analytics:prune');

    expect(AnalyticsEvent::query()->count())->toBe(3);
});

it('takes an override', function (): void {
    AnalyticsEvent::factory()->at(now()->subDays(10))->count(4)->create();
    AnalyticsEvent::factory()->at(now()->subDays(2))->create();

    ($this->centrally)('analytics:prune', ['--days' => 5]);

    expect(AnalyticsEvent::query()->count())->toBe(1);
});

it('refuses a retention of nothing', function (): void {
    // `--days=0` would delete the log up to this instant, which is never what
    // anybody meant to type.
    AnalyticsEvent::factory()->create();

    ($this->centrally)('analytics:prune', ['--days' => 0], expectSuccess: false);

    expect(AnalyticsEvent::query()->count())->toBe(1);
});
