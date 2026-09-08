<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
});

it('lists published webinars and hides drafts from learners', function (): void {
    Webinar::factory()->published()->create(['title' => 'Open evening']);
    Webinar::factory()->create(['title' => 'Not ready']);

    $this->actingAs($this->student)
        ->getJson('/api/v1/webinars')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Open evening')
        ->assertJsonPath('meta.can_manage', false);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/webinars')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('404s a draft rather than admitting it exists', function (): void {
    $draft = Webinar::factory()->create();

    $this->actingAs($this->student)
        ->getJson("/api/v1/webinars/{$draft->uuid}")
        ->assertNotFound();
});

it('registers, idempotently', function (): void {
    $webinar = Webinar::factory()->published()->create();

    foreach (range(1, 3) as $_) {
        $this->actingAs($this->student)
            ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
            ->assertCreated()
            ->assertJsonPath('data.is_registered', true);
    }

    // Clicking twice is somebody clicking twice, not two places taken.
    expect(WebinarRegistration::query()->count())->toBe(1);
});

it('keys a registration on the EMAIL, for the public path that comes later', function (): void {
    $webinar = Webinar::factory()->published()->create();

    $this->actingAs($this->student)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertCreated();

    /*
     * Keyed on the email so that when the public path lands in P16, a
     * stranger registering with the address they later sign up with cannot
     * end up holding two places.
     */
    expect(WebinarRegistration::query()->sole()->email)->toBe($this->student->email);
});

it('holds a webinar to its capacity', function (): void {
    $webinar = Webinar::factory()->published()->withCapacity(1)->create();

    $this->actingAs($this->student)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertCreated();

    $second = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($second)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertStatus(409);
});

it('frees the place on cancellation, and lets them change their mind', function (): void {
    $webinar = Webinar::factory()->published()->withCapacity(1)->create();

    $this->actingAs($this->student)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertCreated();

    $this->actingAs($this->student)
        ->deleteJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertOk()
        ->assertJsonPath('data.is_registered', false);

    $second = User::factory()->withRole(RoleKey::Student)->create();

    // The freed place is real.
    $this->actingAs($second)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertCreated();

    // ...and the first person cannot have it back, because it is taken. The
    // record of their cancellation is kept either way.
    $this->actingAs($this->student)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertStatus(409);

    expect(WebinarRegistration::query()->count())->toBe(2);
});

it('refuses registration for a draft', function (): void {
    $webinar = Webinar::factory()->create();

    $this->actingAs($this->student)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertStatus(409);
});

it('needs a signed-in caller, because there is no anonymous surface', function (): void {
    // Tenancy resolves from the authenticated user, so a public registration
    // path cannot exist until there is a public site to register FROM (P16).
    $webinar = Webinar::factory()->published()->create();

    $this->postJson("/api/v1/webinars/{$webinar->uuid}/register")->assertUnauthorized();
});
