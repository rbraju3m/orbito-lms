<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Actions\PublishAnnouncement;
use App\Domain\Engagement\Models\Announcement;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Models\NotificationPreference;
use App\Domain\Notification\Notifications\DomainNotification;
use App\Domain\Notification\Support\NotificationPreferences;
use Illuminate\Support\Facades\Notification;

/*
 * The switches. NotificationPreferences is rendered by the settings screen
 * AND consulted by every delivery, so the tests below assert both ends of
 * that: what the matrix says, and what actually gets sent.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->user = User::factory()->withRole(RoleKey::Student)->create();
});

it('returns every type, grouped, with nothing stored yet', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/notification-preferences')
        ->assertOk();

    $groups = collect($response->json('data.groups'));
    $types = $groups->flatMap(fn (array $g) => $g['types']);

    expect($types)->toHaveCount(count(NotificationType::cases()))
        // The matrix is computed, not stored: a new type appears without a
        // backfill across every academy.
        ->and(NotificationPreference::query()->count())->toBe(0);

    $announcement = $types->firstWhere('key', 'announcement.published');

    expect(collect($announcement['channels'])->firstWhere('channel', 'mail')['enabled'])->toBeTrue()
        ->and(collect($announcement['channels'])->firstWhere('channel', 'mail')['locked'])->toBeFalse();
});

it('renders the in-app channel as locked rather than hiding it', function (): void {
    /*
     * A switch that is missing reads as a bug. A switch that is present and
     * disabled explains itself.
     */
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/notification-preferences')
        ->assertOk();

    $channels = collect($response->json('data.groups'))
        ->flatMap(fn (array $g) => $g['types'])
        ->flatMap(fn (array $t) => $t['channels'])
        ->where('channel', 'database');

    expect($channels)->not->toBeEmpty()
        ->and($channels->every(fn (array $c) => $c['locked'] === true && $c['enabled'] === true))->toBeTrue();
});

it('stores only the switch that moved', function (): void {
    $this->actingAs($this->user)
        ->putJson('/api/v1/notification-preferences', [
            'preferences' => [
                ['type' => 'announcement.published', 'channel' => 'mail', 'enabled' => false],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.groups.0.types.0.key', 'announcement.published');

    expect(NotificationPreference::query()->count())->toBe(1);

    $stored = NotificationPreference::query()->firstOrFail();

    expect($stored->user_id)->toBe($this->user->id)
        ->and($stored->event_key)->toBe('announcement.published')
        ->and($stored->channel)->toBe(NotificationChannel::Mail)
        ->and($stored->enabled)->toBeFalse();
});

it('returns the whole matrix after a write, not just what changed', function (): void {
    $response = $this->actingAs($this->user)
        ->putJson('/api/v1/notification-preferences', [
            'preferences' => [
                ['type' => 'certificate.issued', 'channel' => 'mail', 'enabled' => false],
            ],
        ])
        ->assertOk();

    $types = collect($response->json('data.groups'))->flatMap(fn (array $g) => $g['types']);

    expect($types)->toHaveCount(count(NotificationType::cases()));

    $certificate = $types->firstWhere('key', 'certificate.issued');

    expect(collect($certificate['channels'])->firstWhere('channel', 'mail')['enabled'])->toBeFalse();
});

it('refuses to switch off the in-app record', function (): void {
    $this->actingAs($this->user)
        ->putJson('/api/v1/notification-preferences', [
            'preferences' => [
                ['type' => 'announcement.published', 'channel' => 'database', 'enabled' => false],
            ],
        ])
        ->assertStatus(422);

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('rejects a type it does not know', function (): void {
    $this->actingAs($this->user)
        ->putJson('/api/v1/notification-preferences', [
            'preferences' => [
                ['type' => 'course.deleted', 'channel' => 'mail', 'enabled' => false],
            ],
        ])
        ->assertStatus(422);
});

it('silences the email and keeps the inbox entry', function (): void {
    /*
     * The point of the whole asymmetry: turning a notification "off" quiets
     * the interruption without destroying the record of what happened.
     */
    $instructor = User::factory()->instructor()->create();
    $course = courseWithCurriculum(Course::factory()->ownedBy($instructor)->published()->create(), [1]);

    Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $this->user->id]);

    app(NotificationPreferences::class)->set(
        $this->user->id,
        NotificationType::AnnouncementPublished,
        NotificationChannel::Mail,
        false,
    );

    $announcement = Announcement::factory()->create([
        'course_id' => $course->id,
        'author_id' => $instructor->id,
    ]);

    Notification::fake();

    app(PublishAnnouncement::class)->handle($announcement);

    Notification::assertSentTo(
        $this->user,
        DomainNotification::class,
        fn (DomainNotification $n, array $channels): bool => $channels === ['database'],
    );
});

it('leaves somebody else preferences alone', function (): void {
    $other = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($this->user)
        ->putJson('/api/v1/notification-preferences', [
            'preferences' => [
                ['type' => 'announcement.published', 'channel' => 'mail', 'enabled' => false],
            ],
        ])
        ->assertOk();

    // The body names no user, so there is nothing to authorize — but that is
    // only true while the id comes from the caller and never from the payload.
    expect(app(NotificationPreferences::class)
        ->channelsFor($other->id, NotificationType::AnnouncementPublished))
        ->toBe(['database', 'mail']);
});

it('needs a signed-in caller', function (): void {
    $this->getJson('/api/v1/notification-preferences')->assertUnauthorized();
    $this->putJson('/api/v1/notification-preferences', ['preferences' => []])->assertUnauthorized();
});
