<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Enums\RoleKey;
use Tests\Concerns\SwitchesTenants;

/*
 * Route-model binding must resolve INSIDE the academy.
 *
 * `{course}`, `{bundle}` and every other bound tenant model is looked up by
 * `SubstituteBindings`, which Laravel's middleware priority places right
 * after authentication. `tenant` is not in that list, so without saying where
 * it belongs it ran AFTER the binding — and the lookup went to the central
 * database, where none of these tables exist. Every studio page that opens
 * one record answered 500 in the running app.
 *
 * The suite could not see it: the harness leaves an academy open for the
 * whole test, so the binding found its table whatever the middleware did.
 * `tenancy()->end()` is what makes these tests real — the same trick
 * `ScheduledCommandTest` and `PlatformOwnerTest` use — and why the academy
 * cannot be transacted here: ending tenancy purges the connection, which
 * would roll the fixtures back before the request could find them.
 */

uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
    $this->course = Course::factory()->create(['title' => 'Bound by uuid']);
    $this->bundle = Bundle::factory()->create(['title' => 'Also bound by uuid']);
});

it('resolves a bound tenant model when the request arrives with no academy open', function (): void {
    tenancy()->end();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/studio/courses/{$this->course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Bound by uuid');
});

it('resolves every other bound purchasable the same way', function (): void {
    tenancy()->end();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/studio/bundles/{$this->bundle->uuid}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Also bound by uuid');
});

/* A missing record is still a 404, not a 500 about a missing table. */
it('answers 404 for an id that does not exist, with no academy open', function (): void {
    tenancy()->end();

    $this->actingAs($this->admin)
        ->getJson('/api/v1/studio/courses/01a00000-0000-7000-8000-000000000000')
        ->assertNotFound();
});
