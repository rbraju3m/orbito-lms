<?php

declare(strict_types=1);

use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Str;

/*
 * The one place a subscription degrades.
 *
 * Nothing recomputes lapse from dates on a read path, so an academy's ability
 * to write changes at a moment that appears in a log rather than silently
 * between two requests.
 */

beforeEach(function (): void {
    $this->plan = Plan::factory()->create(['grace_days' => 7]);

    /*
     * The tenants row is inserted directly rather than through the model.
     * `Tenant::create()` fires TenantCreated, which provisions and migrates a
     * real 36-table schema — none of which this command touches, since
     * `subscriptions` is central. Seven schemas per run to test a central
     * sweep would be pure cost.
     */
    $this->subscriptionFor = function (array $attributes): Subscription {
        $id = 'sub-'.Str::lower(Str::random(8));

        Tenant::query()->getConnection()->table('tenants')->insert([
            'id' => $id,
            'slug' => $id,
            'name' => 'Academy '.$id,
            'status' => 'active',
            'is_active' => true,
            'data' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Subscription::factory()->create($attributes + [
            'tenant_id' => $id,
            'plan_id' => $this->plan->id,
            'grace_days' => 7,
        ]);
    };
});

it('leaves a subscription still in cover alone', function (): void {
    $s = ($this->subscriptionFor)([
        'status' => SubscriptionStatus::Active,
        'current_period_ends_at' => now()->addWeek(),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($s->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('moves a lapsed subscription into the grace window', function (): void {
    $s = ($this->subscriptionFor)([
        'status' => SubscriptionStatus::Active,
        'current_period_ends_at' => now()->subDay(),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    // past_due still writes — that is what grace means.
    expect($s->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($s->fresh()->permitsWrites())->toBeTrue();
});

it('expires it once the grace window is spent', function (): void {
    $s = ($this->subscriptionFor)([
        'status' => SubscriptionStatus::PastDue,
        'current_period_ends_at' => now()->subDays(10),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($s->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($s->fresh()->permitsWrites())->toBeFalse();
});

/*
 * Grace runs from when cover ENDED, not from tonight. A sweep that missed
 * three nights must not hand out three extra days — so a subscription lapsed
 * longer ago than its window crosses BOTH cliffs in one pass.
 */
it('crosses both cliffs in one pass when the sweep has not run for a while', function (): void {
    $s = ($this->subscriptionFor)([
        'status' => SubscriptionStatus::Active,
        'current_period_ends_at' => now()->subDays(30),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($s->fresh()->status)->toBe(SubscriptionStatus::Expired);
});

it('ends a trial by its trial date, not its period date', function (): void {
    $s = ($this->subscriptionFor)([
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->subDay(),
        'current_period_ends_at' => now()->addYear(),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($s->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('does not resurrect a cancelled subscription', function (): void {
    $s = ($this->subscriptionFor)([
        'status' => SubscriptionStatus::Canceled,
        'current_period_ends_at' => now()->subDay(),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($s->fresh()->status)->toBe(SubscriptionStatus::Canceled);
});

it('is idempotent', function (): void {
    $s = ($this->subscriptionFor)([
        'status' => SubscriptionStatus::Active,
        'current_period_ends_at' => now()->subDays(30),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();
    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($s->fresh()->status)->toBe(SubscriptionStatus::Expired);
});
