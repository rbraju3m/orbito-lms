<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Actions\CreateLiveSession;
use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Enums\SessionStatus;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\LiveProviderAccount;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\SessionAttendance;

beforeEach(function (): void {
    seedRegistry();

    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(),
        [1],
    );

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $this->student->id]);
});

it('schedules a session with a pasted link', function (): void {
    // The manual provider is the one that works today and the one most
    // academies will use — not a fallback.
    $this->actingAs($this->instructor)
        ->postJson("/api/v1/courses/{$this->course->uuid}/live-sessions", [
            'title' => 'Week 1 call',
            'provider' => 'manual',
            'join_url' => 'https://meet.example.test/abc',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'timezone' => 'Asia/Dhaka',
        ])
        ->assertCreated()
        ->assertJsonPath('data.provider', 'manual')
        ->assertJsonPath('data.timezone', 'Asia/Dhaka')
        ->assertJsonPath('data.status', 'scheduled');
});

it('refuses a pasted link for a provider that issues its own', function (): void {
    /*
     * A link typed in alongside "Zoom" would be silently ignored, and the
     * author would find out at seven o'clock that the class is somewhere else.
     */
    $this->actingAs($this->instructor)
        ->postJson("/api/v1/courses/{$this->course->uuid}/live-sessions", [
            'title' => 'Week 1 call',
            'provider' => 'zoom',
            'join_url' => 'https://meet.example.test/abc',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])
        ->assertStatus(422);
});

it('refuses a manual session with no link at all', function (): void {
    $this->actingAs($this->instructor)
        ->postJson("/api/v1/courses/{$this->course->uuid}/live-sessions", [
            'title' => 'Week 1 call',
            'provider' => 'manual',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])
        ->assertStatus(422);
});

it('refuses a session that ends before it starts', function (): void {
    expect(fn () => app(CreateLiveSession::class)->handle($this->instructor, [
        'title' => 'Backwards',
        'provider' => LiveProvider::Manual,
        'join_url' => 'https://meet.example.test/abc',
        'starts_at' => now()->addDay()->addHour(),
        'ends_at' => now()->addDay(),
    ]))->toThrow(LiveSessionRejected::class);
});

it('refuses to schedule in a course somebody does not staff', function (): void {
    /*
     * Every instructor holds `live.manage.own` globally, so a union check
     * would let any of them schedule a class in anybody's course and mail the
     * roster about it — the § Authorization trap, for the fourth time.
     */
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)
        ->postJson("/api/v1/courses/{$this->course->uuid}/live-sessions", [
            'title' => 'Not mine',
            'provider' => 'manual',
            'join_url' => 'https://meet.example.test/abc',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])
        ->assertForbidden();
});

it('derives live and ended from the clock, never a swept column', function (): void {
    $session = LiveSession::factory()->startingAt(now()->addDay())->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    expect($session->currentStatus())->toBe(SessionStatus::Scheduled);

    // The window opens fifteen minutes early: people arrive early for a class,
    // and a link that refuses them until the second is a support ticket.
    $this->travelTo(now()->addDay()->subMinutes(10));
    expect($session->currentStatus())->toBe(SessionStatus::Live);

    $this->travelTo(now()->addHours(3));
    expect($session->currentStatus())->toBe(SessionStatus::Ended);
});

it('never sends the host link to anybody', function (): void {
    /*
     * On Zoom the start link opens the meeting AS the host. The model hides
     * it and the resource does not name it, so there is no single mistake
     * that hands a learner the room.
     */
    LiveSession::factory()->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
        'host_url' => 'https://zoom.test/s/secret-start-link',
    ]);

    $response = $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/live-sessions")
        ->assertOk();

    expect(json_encode($response->json()))->not->toContain('secret-start-link');
});

it('withholds the join link until the session is joinable', function (): void {
    LiveSession::factory()->startingAt(now()->addWeek())->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    // A link rendered a week early is a link that ends up in a group chat.
    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/live-sessions")
        ->assertOk()
        ->assertJsonPath('data.0.join_url', null)
        ->assertJsonPath('data.0.can_join', false);
});

it('records attendance when a learner follows the link', function (): void {
    $session = LiveSession::factory()->startingAt(now()->addMinutes(5))->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/live-sessions/{$session->uuid}/join")
        ->assertOk()
        ->assertJsonPath('data.join_url', $session->join_url);

    /*
     * Following the link is the only signal every provider has in common —
     * the manual one reports nothing — so the click is what fills the roster.
     */
    expect(SessionAttendance::query()->where('user_id', $this->student->id)->count())->toBe(1);
});

it('counts a rejoin once', function (): void {
    $session = LiveSession::factory()->startingAt(now()->addMinutes(5))->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    foreach (range(1, 3) as $_) {
        $this->actingAs($this->student)
            ->postJson("/api/v1/live-sessions/{$session->uuid}/join")
            ->assertOk();
    }

    // Somebody whose connection drops attended once. A compliance report that
    // counted them three times would be worse than useless.
    expect(SessionAttendance::query()->count())->toBe(1);
});

it('does not put the host on their own roster', function (): void {
    $session = LiveSession::factory()->startingAt(now()->addMinutes(5))->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    $this->actingAs($this->instructor)
        ->postJson("/api/v1/live-sessions/{$session->uuid}/join")
        ->assertOk();

    expect(SessionAttendance::query()->count())->toBe(0);
});

it('refuses to let a stranger join', function (): void {
    $session = LiveSession::factory()->startingAt(now()->addMinutes(5))->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($stranger)
        ->postJson("/api/v1/live-sessions/{$session->uuid}/join")
        ->assertForbidden();
});

it('says the moment has passed with 422, not 403', function (): void {
    $session = LiveSession::factory()->startingAt(now()->subDays(2))->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    // They did nothing wrong. A 403 would read as "you are not allowed" to
    // somebody who simply arrived late.
    $this->actingAs($this->student)
        ->postJson("/api/v1/live-sessions/{$session->uuid}/join")
        ->assertStatus(422);
});

it('resets the reminder when a session moves', function (): void {
    $session = LiveSession::factory()->startingAt(now()->addDay())->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
        'reminder_sent_at' => now(),
    ]);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/live-sessions/{$session->uuid}", [
            'title' => $session->title,
            'provider' => 'manual',
            'join_url' => $session->join_url,
            'starts_at' => now()->addDays(3)->toIso8601String(),
            'ends_at' => now()->addDays(3)->addHour()->toIso8601String(),
        ])
        ->assertOk();

    /*
     * Without this, everybody would arrive on the old day holding an email
     * that told them so.
     */
    expect($session->fresh()->reminder_sent_at)->toBeNull();
});

/*
 * The studio builds its provider picker from this, so it must be the answer
 * scheduling enforces: offering Zoom to an academy with no Zoom account is a
 * form that fails on submit, every time.
 */
it('tells a scheduler which providers are usable, and a learner nothing', function (): void {
    $this->actingAs($this->instructor)
        ->getJson("/api/v1/courses/{$this->course->uuid}/live-sessions")
        ->assertOk()
        ->assertJsonPath('meta.can_manage', true)
        ->assertJsonPath('meta.providers.0.value', 'manual')
        ->assertJsonPath('meta.providers.0.available', true)
        ->assertJsonPath('meta.providers.1.value', 'zoom')
        ->assertJsonPath('meta.providers.1.available', false);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/live-sessions")
        ->assertOk()
        ->assertJsonPath('meta.can_manage', false)
        ->assertJsonPath('meta.providers', []);
});

it('offers a provider once the academy has connected it', function (): void {
    LiveProviderAccount::create([
        'provider' => LiveProvider::Zoom,
        'credentials' => ['account_id' => 'acct', 'client_id' => 'id', 'client_secret' => 'secret'],
        'is_active' => true,
    ]);

    $this->actingAs($this->instructor)
        ->getJson("/api/v1/courses/{$this->course->uuid}/live-sessions")
        ->assertJsonPath('meta.providers.1.value', 'zoom')
        ->assertJsonPath('meta.providers.1.available', true)
        ->assertJsonPath('meta.providers.2.value', 'google_meet')
        ->assertJsonPath('meta.providers.2.available', false);
});

/*
 * The studio never sees a stored link — it is withheld until a session is
 * joinable — so an edit that had to re-paste it would be an edit nobody could
 * make. Left out, it stays; a description sent as null is cleared.
 */
it('keeps a pasted link an edit leaves out, and clears a description sent empty', function (): void {
    $session = LiveSession::factory()->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
        'provider' => LiveProvider::Manual,
        'join_url' => 'https://meet.example.test/keep-me',
        'description' => 'Bring your questions.',
    ]);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/live-sessions/{$session->uuid}", [
            'title' => 'Week 1 call, moved',
            'provider' => 'manual',
            'description' => null,
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(2)->addHour()->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Week 1 call, moved')
        ->assertJsonPath('data.description', null);

    expect($session->refresh()->join_url)->toBe('https://meet.example.test/keep-me');
});

it('cancels without deleting', function (): void {
    $session = LiveSession::factory()->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    $this->actingAs($this->instructor)
        ->deleteJson("/api/v1/live-sessions/{$session->uuid}")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // The attendance, the recording and the fact that it was called off are
    // all things somebody may need later.
    expect(LiveSession::query()->find($session->id))->not->toBeNull();
});
