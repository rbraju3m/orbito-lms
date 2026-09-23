<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Platform\Models\Tenant;
use Tests\Concerns\SwitchesTenants;

/*
 * The academy's default is one of the resolver's answers, so `SetLocale` has
 * to run AFTER the academy opens — and it is a group middleware, which runs
 * before a route's `tenant` unless the priority list says otherwise.
 *
 * The harness hides the mistake: it leaves an academy open all test, so the
 * default would be found whatever the order. These end tenancy first, the
 * `RouteBindingTenancyTest` trick.
 */
uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();

    $academy = Tenant::findOrFail($this->sharedTenantId());
    $academy->default_locale = 'bn';
    $academy->save();

    $this->student = userWithRole(RoleKey::Student);
});

it('applies the academy default on a members route that opens the academy itself', function (): void {
    tenancy()->end();

    $this->actingAs($this->student)
        ->getJson('/api/v1/account/profile')
        ->assertOk()
        ->assertHeader('Content-Language', 'bn');
});

it('tells a stranger the academy\'s language on the public site', function (): void {
    tenancy()->end();

    $this->getJson('/api/v1/public/test-academy')
        ->assertOk()
        ->assertHeader('Content-Language', 'bn')
        ->assertJsonPath('data.locale.code', 'bn')
        ->assertJsonPath('data.locale.available.*.code', ['en', 'bn']);
});
