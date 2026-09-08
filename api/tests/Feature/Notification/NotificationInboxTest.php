<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Models\Notification;

/*
 * The inbox. Its whole authorization story is that every query starts from
 * the caller's own id — so the tests that matter most are the ones proving a
 * stranger's row is unreachable by every verb, and that it 404s rather than
 * 403s.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->user = User::factory()->withRole(RoleKey::Student)->create();
    $this->other = User::factory()->withRole(RoleKey::Student)->create();
});

it('lists my notifications newest first, with the unread badge', function (): void {
    Notification::factory()->for_($this->user)->create(['created_at' => now()->subHour()]);
    $newest = Notification::factory()->for_($this->user)->create(['created_at' => now()]);
    Notification::factory()->for_($this->user)->read()->create(['created_at' => now()->subDay()]);
    Notification::factory()->for_($this->other)->create();

    $this->actingAs($this->user)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $newest->id)
        ->assertJsonPath('data.0.is_read', false)
        // The badge rides along on the request the bell already made.
        ->assertJsonPath('meta.unread_count', 2);
});

it('returns the stored payload, action path included', function (): void {
    $notification = Notification::factory()
        ->for_($this->user)
        ->ofType(NotificationType::CertificateIssued)
        ->create();

    $this->actingAs($this->user)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'certificate.issued')
        ->assertJsonPath('data.0.title', $notification->data['title'])
        // RELATIVE, so the SPA routes internally and the link cannot rot
        // when the academy's address changes.
        ->assertJsonPath('data.0.action_path', $notification->data['action_path']);
});

it('filters to unread', function (): void {
    Notification::factory()->for_($this->user)->create();
    Notification::factory()->for_($this->user)->read()->count(2)->create();

    $this->actingAs($this->user)
        ->getJson('/api/v1/notifications?unread=1')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('counts unread without paging the inbox', function (): void {
    Notification::factory()->for_($this->user)->count(3)->create();
    Notification::factory()->for_($this->user)->read()->create();
    Notification::factory()->for_($this->other)->count(5)->create();

    $this->actingAs($this->user)
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 3);
});

it('marks one read, and marking it again is not an error', function (): void {
    $notification = Notification::factory()->for_($this->user)->create();

    $this->actingAs($this->user)
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('data.is_read', true);

    $readAt = $notification->refresh()->read_at;

    $this->actingAs($this->user)
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk();

    // Idempotent: the second call must not move the timestamp, or "read 3
    // days ago" becomes "read just now" every time somebody clicks.
    expect($notification->refresh()->read_at?->toIso8601String())
        ->toBe($readAt?->toIso8601String());
});

it('clears the whole badge', function (): void {
    Notification::factory()->for_($this->user)->count(4)->create();
    Notification::factory()->for_($this->other)->count(2)->create();

    $this->actingAs($this->user)
        ->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    expect(Notification::query()->whereNull('read_at')->where('notifiable_id', $this->user->id)->count())->toBe(0)
        // Somebody else's inbox is untouched.
        ->and(Notification::query()->whereNull('read_at')->where('notifiable_id', $this->other->id)->count())->toBe(2);
});

it('deletes one of mine', function (): void {
    $notification = Notification::factory()->for_($this->user)->create();

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/notifications/{$notification->id}")
        ->assertNoContent();

    expect(Notification::query()->find($notification->id))->toBeNull();
});

it('404s on somebody else notification, on every verb', function (): void {
    $theirs = Notification::factory()->for_($this->other)->create();

    /*
     * 404, not 403. "This exists but is not yours" is a fact about a
     * stranger's inbox, and the only honest answer is that we have nothing
     * for you at that id.
     */
    $this->actingAs($this->user)
        ->postJson("/api/v1/notifications/{$theirs->id}/read")
        ->assertNotFound();

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/notifications/{$theirs->id}")
        ->assertNotFound();

    expect($theirs->refresh()->read_at)->toBeNull();
});

it('needs a signed-in caller', function (): void {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
});

it('does not let the uuid route swallow the literal one', function (): void {
    // `{notification}` has no pattern, so declaration order is the only thing
    // keeping /notifications/unread-count out of its mouth.
    $this->actingAs($this->user)
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonStructure(['data' => ['unread_count']]);
});
