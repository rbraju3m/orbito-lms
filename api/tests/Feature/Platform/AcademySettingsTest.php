<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\RegistrationMode;
use App\Domain\Platform\Models\Tenant;

/*
 * The academy administering ITSELF — who may join it, and how to reach its
 * support. Distinct from the platform registry, which is the operator's and
 * lives behind a central flag rather than a permission.
 */
beforeEach(function (): void {
    seedRegistry();

    $this->academy = Tenant::findOrFail(tenancy()->tenant->getTenantKey());
    $this->admin = User::factory()->withRole(RoleKey::Admin)->create();
});

it('shows the academy, its signup policy and the link to share', function (): void {
    $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/academy')->assertOk();

    expect($response->json('data.slug'))->toBe('test-academy')
        // Never chosen, so the default.
        ->and($response->json('data.registration_mode'))->toBe('open')
        // Relative: the SPA routes on it and renders it absolute only at the
        // moment of sharing, so it survives the installation changing address.
        ->and($response->json('data.signup_path'))->toBe('/register?academy=test-academy');
});

it('lists the modes and says which are not built yet', function (): void {
    $modes = collect(
        $this->actingAs($this->admin)->getJson('/api/v1/admin/academy')->json('data.registration_modes')
    )->keyBy('value');

    expect($modes['open']['available'])->toBeTrue()
        ->and($modes['closed']['available'])->toBeTrue()
        // Declared so an academy that picks it does not silently fall back to
        // open — but there is no invitations table, and the UI has to say so.
        ->and($modes['invite']['available'])->toBeFalse();
});

it('closes sign-ups', function (): void {
    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/academy', ['registration_mode' => 'closed'])
        ->assertOk()
        ->assertJsonPath('data.registration_mode', 'closed');

    expect($this->academy->refresh()->registrationMode())->toBe(RegistrationMode::Closed);
});

it('refuses invitation-only, because invitations do not exist yet', function (): void {
    expect($this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/academy', ['registration_mode' => 'invite'])
        ->assertStatus(422))->toBeApiError('validation_failed');

    expect($this->academy->refresh()->registrationMode())->toBe(RegistrationMode::Open);
});

it('rejects a mode that is not a mode', function (): void {
    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/academy', ['registration_mode' => 'whenever'])
        ->assertStatus(422);
});

it('denies the settings to a student', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($student)->getJson('/api/v1/admin/academy')->assertStatus(403);
    $this->actingAs($student)
        ->patchJson('/api/v1/admin/academy', ['registration_mode' => 'closed'])
        ->assertStatus(403);
});

it('denies the settings to an instructor', function (): void {
    $this->actingAs(User::factory()->instructor()->create())
        ->getJson('/api/v1/admin/academy')->assertStatus(403);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/admin/academy')->assertStatus(401);
});

/*
 * There is no {academy} in the path, and that is the point: the caller's own
 * academy is the only one the endpoint will ever touch. This asserts the
 * settings really are read from the CALLER rather than anything they send.
 */
it('ignores an academy named in the body', function (): void {
    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/academy', [
            'slug' => 'somebody-elses-academy',
            'registration_mode' => 'closed',
        ])
        ->assertOk()
        ->assertJsonPath('data.slug', 'test-academy');

    expect($this->academy->refresh()->slug)->toBe('test-academy');
});
