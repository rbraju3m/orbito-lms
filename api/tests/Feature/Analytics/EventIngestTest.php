<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\EventName;
use App\Domain\Analytics\Enums\EventSource;
use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;

/*
 * The client half of the log. Its whole design is one allowlist: a browser may
 * raise only what the server cannot see for itself.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [1]);
    $this->item = $this->course->items()->first();
});

it('records a batch, even of one', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/analytics/track', [
            'events' => [
                ['name' => 'course_viewed', 'course_id' => $this->course->uuid],
            ],
        ])
        ->assertAccepted()
        ->assertJsonPath('data.recorded', 1);

    $event = AnalyticsEvent::query()->sole();

    expect($event->name)->toBe(EventName::CourseViewed)
        ->and($event->course_id)->toBe($this->course->id)
        ->and($event->actor_id)->toBe($this->student->id)
        ->and($event->source)->toBe(EventSource::Web);
});

it('takes several events in one request, each with its own moment', function (): void {
    $earlier = now()->subMinutes(30);

    $this->actingAs($this->student)
        ->postJson('/api/v1/analytics/track', [
            'events' => [
                [
                    'name' => 'course_viewed',
                    'course_id' => $this->course->uuid,
                    'occurred_at' => $earlier->toIso8601String(),
                ],
                [
                    'name' => 'item_started',
                    'course_id' => $this->course->uuid,
                    'course_item_id' => $this->item->uuid,
                ],
            ],
        ])
        ->assertAccepted()
        ->assertJsonPath('data.recorded', 2);

    // A beacon fired after a spell offline must land in the right day.
    $viewed = AnalyticsEvent::query()->where('name', EventName::CourseViewed)->sole();

    expect($viewed->occurred_at->diffInMinutes(now()))->toBeGreaterThan(25);

    $started = AnalyticsEvent::query()->where('name', EventName::ItemStarted)->sole();

    expect($started->course_item_id)->toBe($this->item->id);
});

it('refuses an event only the server can establish', function (): void {
    /*
     * THE security property of this endpoint. A client that could post
     * `payment_completed` would be writing revenue into the dashboards
     * without paying anybody.
     */
    foreach (['payment_completed', 'course_completed', 'quiz_passed', 'certificate_issued'] as $forged) {
        $this->actingAs($this->student)
            ->postJson('/api/v1/analytics/track', ['events' => [['name' => $forged]]])
            ->assertStatus(422);
    }

    expect(AnalyticsEvent::query()->count())->toBe(0);
});

it('refuses a name it has never heard of', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/analytics/track', ['events' => [['name' => 'free_money']]])
        ->assertStatus(422);
});

it('clamps a clock from the future', function (): void {
    // A device with a wrong year would otherwise write into a report nothing
    // ever removes it from.
    $this->actingAs($this->student)
        ->postJson('/api/v1/analytics/track', [
            'events' => [[
                'name' => 'course_viewed',
                'occurred_at' => now()->addYear()->toIso8601String(),
            ]],
        ])
        ->assertAccepted();

    expect(AnalyticsEvent::query()->sole()->occurred_at->isFuture())->toBeFalse();
});

it('clamps a clock from too far in the past', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/analytics/track', [
            'events' => [[
                'name' => 'course_viewed',
                'occurred_at' => now()->subYear()->toIso8601String(),
            ]],
        ])
        ->assertAccepted();

    // A beacon is not a backfill tool.
    expect(AnalyticsEvent::query()->sole()->occurred_at->isAfter(now()->subDay()))->toBeTrue();
});

it('hashes the address rather than storing it', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/analytics/track', ['events' => [['name' => 'course_viewed']]])
        ->assertAccepted();

    $event = AnalyticsEvent::query()->sole();

    expect($event->ip_hash)->not->toBeNull()
        ->and(strlen((string) $event->ip_hash))->toBe(64)
        ->and($event->ip_hash)->not->toContain('127.0.0.1');
});

it('caps a batch', function (): void {
    $events = array_fill(0, 21, ['name' => 'course_viewed']);

    $this->actingAs($this->student)
        ->postJson('/api/v1/analytics/track', ['events' => $events])
        ->assertStatus(422);
});

it('refuses a course id that is not one', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/analytics/track', [
            'events' => [['name' => 'course_viewed', 'course_id' => (string) Str::uuid7()]],
        ])
        ->assertStatus(422);
});

it('needs a signed-in caller, because an academy resolves from one', function (): void {
    // There is no anonymous surface (§ Multi-tenancy): a request with no user belongs to
    // no academy, so there is no log to write to.
    $this->postJson('/api/v1/analytics/track', ['events' => [['name' => 'course_viewed']]])
        ->assertUnauthorized();
});
