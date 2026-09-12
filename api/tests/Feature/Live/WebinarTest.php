<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Enums\SessionStatus;
use App\Domain\Live\Models\LiveSession;
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

/*
 * Authoring. Until P16 a webinar could only be created by a factory: the
 * model, the registration flow and the screens all existed, and nothing in
 * the product could make one — the dead-wire shape `SyncCourseProduct` had.
 */

it('creates a webinar and the session it happens at, as a draft', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/webinars', [
            'title' => 'Open evening',
            'description' => 'Come and see what we teach.',
            'capacity' => 50,
            'provider' => 'manual',
            'join_url' => 'https://meet.example.test/open-evening',
            'starts_at' => now()->addWeek()->toIso8601String(),
            'ends_at' => now()->addWeek()->addHour()->toIso8601String(),
            'timezone' => 'Asia/Dhaka',
        ])
        ->assertCreated()
        // A draft, always: publishing is its own decision, so filling in a
        // form does not put an event in front of the academy.
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.capacity', 50)
        ->assertJsonPath('data.is_publishable', true);

    $webinar = Webinar::query()->where('uuid', $response->json('data.id'))->sole();

    // A session with no course and no cohort is what "standalone" means.
    expect($webinar->session)->not->toBeNull()
        ->and($webinar->session->course_id)->toBeNull()
        ->and($webinar->session->cohort_id)->toBeNull()
        ->and($webinar->slug)->toBe('open-evening');
});

it('refuses an author who cannot manage webinars', function (): void {
    // A webinar belongs to no course, so there is nothing to scope against —
    // an instructor's `live.manage.own` does not reach it.
    $instructor = User::factory()->instructor()->create();

    $this->actingAs($instructor)
        ->postJson('/api/v1/webinars', [
            'title' => 'Mine now',
            'provider' => 'manual',
            'join_url' => 'https://meet.example.test/x',
            'starts_at' => now()->addWeek()->toIso8601String(),
            'ends_at' => now()->addWeek()->addHour()->toIso8601String(),
        ])
        ->assertForbidden();
});

it('refuses a webinar with no time', function (): void {
    expect($this->actingAs($this->admin)
        ->postJson('/api/v1/webinars', ['title' => 'When?'])
        ->assertStatus(422))->toBeApiError('validation_failed');
});

it('publishes, unpublishes and calls one off, and refuses the moves that are not legal', function (): void {
    // The factory leaves the session null on purpose: a webinar with no
    // time is exactly what publishing has to refuse.
    $webinar = Webinar::factory()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/status", ['status' => 'published'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'webinar_needs_session');

    $scheduled = Webinar::factory()->withSession()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/webinars/{$scheduled->uuid}/status", ['status' => 'published'])
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.available_actions', ['draft', 'cancelled']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/webinars/{$scheduled->uuid}/status", ['status' => 'cancelled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        // A cancelled event returns to DRAFT, so somebody has to look at the
        // date before registrations reopen.
        ->assertJsonPath('data.available_actions', ['draft']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/webinars/{$scheduled->uuid}/status", ['status' => 'published'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'webinar_transition_rejected')
        ->assertJsonPath('error.meta.available_actions', ['draft']);
});

it('edits the words and the places, and leaves the time to the session endpoint', function (): void {
    $webinar = Webinar::factory()->withSession()->create(['capacity' => 10]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/webinars/{$webinar->uuid}", [
            'title' => 'Renamed',
            // Present-and-null clears: uncapped is not the same as full.
            'capacity' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed')
        ->assertJsonPath('data.capacity', null)
        ->assertJsonPath('data.places_remaining', null);

    // The link somebody may already hold does not move with the title.
    expect($webinar->refresh()->slug)->not->toBe('renamed');
});

it('refuses to delete a webinar somebody has registered for', function (): void {
    $webinar = Webinar::factory()->withSession()->published()->create();

    $this->actingAs($this->student)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertCreated();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/webinars/{$webinar->uuid}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'webinar_in_use');

    // And the list says so before the button is offered.
    $this->actingAs($this->admin)
        ->getJson('/api/v1/webinars')
        ->assertOk()
        ->assertJsonPath('data.0.is_deletable', false);

    expect(Webinar::query()->whereKey($webinar->id)->exists())->toBeTrue();
});

it('deletes one nobody registered for and calls its session off rather than deleting it', function (): void {
    $webinar = Webinar::factory()->withSession()->create();
    $sessionId = $webinar->live_session_id;

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/webinars/{$webinar->uuid}")
        ->assertNoContent();

    expect(Webinar::query()->whereKey($webinar->id)->exists())->toBeFalse();

    // A live session row is never deleted anywhere in this domain: the
    // attendance and any recording live on it.
    $session = LiveSession::query()->find($sessionId);

    expect($session)->not->toBeNull()
        ->and($session->status)->toBe(SessionStatus::Cancelled);
});

it('tells a learner nothing about what an author may do', function (): void {
    Webinar::factory()->published()->create();

    $response = $this->actingAs($this->student)->getJson('/api/v1/webinars')->assertOk();

    expect($response->json('data.0'))->not->toHaveKeys([
        'available_actions', 'is_publishable', 'is_deletable',
    ]);
});
