<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Models\Tenant;

/*
 * Enforcement is READ-ONLY.
 *
 * A lapsed academy keeps seeing and exporting everything it has; only writes
 * stop. Locking a customer out of their own courses is not leverage, it is how
 * a lapsed account becomes a former one — so every test here pairs a blocked
 * write with a read that still works.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(),
        [1],
    );

    // The shared academy's subscription, which the harness created active.
    $this->subscription = Subscription::where('tenant_id', tenancy()->tenant->getTenantKey())
        ->firstOrFail();

    $this->setStatus = function (SubscriptionStatus $status): void {
        $this->subscription->forceFill([
            'status' => $status,
            'current_period_ends_at' => now()->subMonth(),
        ])->save();
    };
});

it('lets an active academy write', function (): void {
    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}", ['title' => 'Renamed'])
        ->assertOk();
});

it('blocks writes once the subscription has expired, with 402', function (): void {
    ($this->setStatus)(SubscriptionStatus::Expired);

    $response = $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}", ['title' => 'Renamed'])
        ->assertStatus(402);

    expect($response)->toBeApiError('subscription_lapsed')
        ->and($response->json('error.meta.status'))->toBe('expired');
});

/* The whole point of the read-only rule. */
it('still serves every read to an expired academy', function (): void {
    ($this->setStatus)(SubscriptionStatus::Expired);

    $this->actingAs($this->instructor)->getJson('/api/v1/courses')->assertOk();
    $this->actingAs($this->instructor)
        ->getJson("/api/v1/studio/courses/{$this->course->uuid}/curriculum")->assertOk();
    $this->actingAs($this->instructor)->getJson('/api/v1/auth/me')->assertOk();
});

it('blocks a cancelled academy too', function (): void {
    ($this->setStatus)(SubscriptionStatus::Canceled);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}", ['title' => 'Renamed'])
        ->assertStatus(402);
});

/*
 * past_due IS the grace window. Taking the platform away the day an invoice
 * slips is how you lose the customer, not how you get paid.
 */
it('still lets a past-due academy write', function (): void {
    ($this->setStatus)(SubscriptionStatus::PastDue);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}", ['title' => 'Renamed'])
        ->assertOk();
});

it('still lets a trialing academy write', function (): void {
    ($this->setStatus)(SubscriptionStatus::Trialing);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}", ['title' => 'Renamed'])
        ->assertOk();
});

/* An academy with no subscription row fails CLOSED — a revenue hole nobody
 * would notice is worse than an academy an operator has to unblock. */
it('blocks writes when there is no subscription at all', function (): void {
    $this->subscription->delete();

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}", ['title' => 'Renamed'])
        ->assertStatus(402);
});

/*
 * A learner in a lapsed academy cannot record progress. That is a deliberate
 * consequence of one platform-wide rule rather than a decision about learners,
 * and it is the sharpest edge of the model.
 */
it('blocks a learner from recording progress in a lapsed academy', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $student->id,
    ]);
    $item = $this->course->items()->first();

    // Reading the lesson still works.
    $this->actingAs($student)->getJson("/api/v1/learn/items/{$item->uuid}")->assertOk();

    ($this->setStatus)(SubscriptionStatus::Expired);

    $this->actingAs($student)->getJson("/api/v1/learn/items/{$item->uuid}")->assertOk();
    $this->actingAs($student)
        ->postJson("/api/v1/learn/items/{$item->uuid}/complete")->assertStatus(402);
});

/* A user who cannot sign out of a lapsed academy is trapped in it. */
it('never blocks logout', function (): void {
    // A real token, because logout revokes the CALLING device's token and has
    // nothing to revoke for an actingAs() user with no session or token.
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $this->instructor->email,
        'password' => 'password',
        'device_name' => 'Laptop',
    ])->assertOk()->json('data.token');

    ($this->setStatus)(SubscriptionStatus::Expired);

    $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])
        ->assertNoContent();
});

/*
 * Renewal is the way OUT. The status only ever degrades in the nightly sweep,
 * so without this an academy that paid would stay 402'd until somebody
 * noticed — the payment would change nothing observable.
 */
it('restores writes when an operator renews the plan', function (): void {
    ($this->setStatus)(SubscriptionStatus::Expired);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}", ['title' => 'Renamed'])
        ->assertStatus(402);

    $admin = User::factory()->superAdmin()->create();
    $plan = Plan::factory()->create(['slug' => 'renewal']);

    $this->actingAs($admin)
        ->putJson('/api/v1/admin/tenants/'.tenancy()->tenant->slug.'/plan', ['plan' => 'renewal'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.permits_writes', true);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}", ['title' => 'Renamed'])
        ->assertOk();
});

/*
 * The gate reads the ACADEMY, not the actor: the way to unblock a lapsed
 * academy is the platform surface, which sits outside this middleware.
 */
it('leaves the platform admin surface reachable', function (): void {
    ($this->setStatus)(SubscriptionStatus::Expired);

    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->getJson('/api/v1/admin/tenants')->assertOk();
    $this->actingAs($admin)
        ->patchJson('/api/v1/admin/tenants/'.Tenant::first()->slug, ['action' => 'suspend'])
        ->assertOk();
});
