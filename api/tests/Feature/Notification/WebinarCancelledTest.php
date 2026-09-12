<?php

declare(strict_types=1);

use App\Domain\Commerce\Models\Order;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Actions\ChangeWebinarStatus;
use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Notification\Models\Notification as InboxNotification;
use App\Domain\Notification\Notifications\DomainNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

/*
 * Telling the room an event is off.
 *
 * `ChangeWebinarStatus` leaves the registrations standing on purpose — the
 * place somebody held is a record of who was coming — which is precisely why
 * somebody has to be told. Everything here is one question: does the person
 * who was going to turn up find out, and does the message say something the
 * system can actually keep?
 */

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
    $this->learner = User::factory()->withRole(RoleKey::Student)->create();
});

/** A live place at $webinar, bought through $orderId or given away. */
function holdPlace(Webinar $webinar, User $user, ?int $orderId = null): WebinarRegistration
{
    return WebinarRegistration::create([
        'webinar_id' => $webinar->id,
        'user_id' => $user->id,
        'order_id' => $orderId,
        'email' => $user->email,
        'name' => $user->name,
        'status' => WebinarRegistration::STATUS_REGISTERED,
        'registered_at' => now(),
    ]);
}

function callOff(Webinar $webinar, User $actor): Webinar
{
    return app(ChangeWebinarStatus::class)->handle($webinar, WebinarStatus::Cancelled, $actor);
}

it('tells everybody holding a place, and never the person who called it off', function (): void {
    $webinar = Webinar::factory()->published()->withSession()->create(['title' => 'Open evening']);
    $classmate = User::factory()->withRole(RoleKey::Student)->create();

    holdPlace($webinar, $this->learner);
    holdPlace($webinar, $classmate);
    // The admin cancelling is also registered: they watched themselves do it.
    holdPlace($webinar, $this->admin);

    Notification::fake();

    callOff($webinar, $this->admin);

    Notification::assertSentTo([$this->learner, $classmate], DomainNotification::class);
    Notification::assertNotSentTo($this->admin, DomainNotification::class);
});

it('says nothing to somebody who had already pulled out', function (): void {
    $webinar = Webinar::factory()->published()->withSession()->create();

    $gone = User::factory()->withRole(RoleKey::Student)->create();
    holdPlace($webinar, $this->learner);
    holdPlace($webinar, $gone)->update(['status' => WebinarRegistration::STATUS_CANCELLED]);

    Notification::fake();

    callOff($webinar, $this->admin);

    // They gave the place up; the event being off is no longer their business.
    Notification::assertSentTo($this->learner, DomainNotification::class);
    Notification::assertNotSentTo($gone, DomainNotification::class);
});

it('tells a paid place a refund is owed, and a free place at the same event nothing about money', function (): void {
    $webinar = Webinar::factory()->published()->withSession()->paid()->create();

    $buyer = User::factory()->withRole(RoleKey::Student)->create();
    $order = Order::factory()->paid()->create(['user_id' => $buyer->id]);
    holdPlace($webinar, $buyer, orderId: $order->id);
    // A place the academy gave away at a ticketed event owes nobody anything.
    holdPlace($webinar, $this->learner);

    Notification::fake();

    callOff($webinar, $this->admin);

    Notification::assertSentTo($buyer, DomainNotification::class,
        function (DomainNotification $notification): bool {
            return $notification->payload->meta['was_purchased'] === true
                && str_contains($notification->payload->body, 'refundable');
        });

    Notification::assertSentTo($this->learner, DomainNotification::class,
        function (DomainNotification $notification): bool {
            return $notification->payload->meta['was_purchased'] === false
                && ! str_contains($notification->payload->body, 'refundable');
        });
});

it('stores the message with the time it was going to happen, and nothing to open', function (): void {
    $webinar = Webinar::factory()->published()->withSession()->create(['title' => 'Open evening']);
    holdPlace($webinar, $this->learner);

    callOff($webinar, $this->admin);

    $row = InboxNotification::query()
        ->where('notifiable_id', $this->learner->id)
        ->firstOrFail();

    expect($row->type)->toBe('webinar.cancelled')
        ->and($row->data['title'])->toBe('Open evening has been called off')
        ->and($row->data['meta']['starts_at'])->not->toBeNull()
        // A cancelled webinar leaves the learner's list, so a link would land
        // on a page that no longer mentions it.
        ->and($row->data['action_path'])->toBeNull();
});

it('says when it was going to happen in the zone it was scheduled in', function (): void {
    $session = LiveSession::factory()->create([
        'course_id' => null,
        'cohort_id' => null,
        'starts_at' => CarbonImmutable::parse('2026-10-01 03:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-01 04:00', 'UTC'),
        'timezone' => 'Asia/Dhaka',
    ]);

    $webinar = Webinar::factory()->published()->create(['live_session_id' => $session->id]);
    holdPlace($webinar, $this->learner);

    Notification::fake();

    callOff($webinar, $this->admin);

    Notification::assertSentTo($this->learner, DomainNotification::class,
        // 03:00 UTC is 09:00 in Dhaka. Printing the instant and labelling it
        // with the zone would read as authoritative and be six hours out.
        fn (DomainNotification $notification): bool => str_contains(
            $notification->payload->body,
            '1 Oct 2026, 09:00 (Asia/Dhaka)',
        ));
});

it('says nothing about an event nobody was coming to', function (): void {
    $webinar = Webinar::factory()->published()->withSession()->create();

    Notification::fake();

    callOff($webinar, $this->admin);

    Notification::assertNothingSent();
});

it('says nothing on any other transition', function (): void {
    $webinar = Webinar::factory()->withSession()->create();
    holdPlace($webinar, $this->learner);

    Notification::fake();

    $status = app(ChangeWebinarStatus::class);

    // Publish, unpublish, publish again: none of these is a cancellation.
    $status->handle($webinar, WebinarStatus::Published, $this->admin);
    $status->handle($webinar->refresh(), WebinarStatus::Draft, $this->admin);
    $status->handle($webinar->refresh(), WebinarStatus::Published, $this->admin);

    Notification::assertNothingSent();
});

it('says it once, however many times the academy presses cancel', function (): void {
    $webinar = Webinar::factory()->published()->withSession()->create();
    holdPlace($webinar, $this->learner);

    Notification::fake();

    callOff($webinar, $this->admin);
    // The Action returns early on a no-op, so the event never fires twice.
    callOff($webinar->refresh(), $this->admin);

    Notification::assertSentToTimes($this->learner, DomainNotification::class, 1);
});

/* ------------------------------------ and the event stops looking like it is on */

/*
 * The notice is only half of it. A cancellation nobody's calendar and nobody's
 * reminder knows about sends "called off" on Monday and "starts soon" on
 * Tuesday, which is worse than saying nothing: the learner cannot tell which
 * message is the current one. `SessionAudience` is where that is settled —
 * nobody is expected at an event that is off — and the session row is left
 * `scheduled` on purpose, so reviving the webinar restores all of this.
 */

/** A webinar happening at $moment, with its own standalone session. */
function webinarAt(CarbonInterface $moment): Webinar
{
    $session = LiveSession::factory()->startingAt($moment)->create([
        'course_id' => null,
        'cohort_id' => null,
    ]);

    return Webinar::factory()->published()->create(['live_session_id' => $session->id]);
}

it('does not remind anybody about an event that was called off', function (): void {
    $webinar = webinarAt(now()->addMinutes(20));
    holdPlace($webinar, $this->learner);

    callOff($webinar, $this->admin);

    $this->artisan('live:remind')->assertSuccessful();

    expect(InboxNotification::query()->where('type', 'session.reminder')->count())->toBe(0)
        // And the flag is NOT burned, so a revived event still reminds.
        ->and($webinar->refresh()->session?->reminder_sent_at)->toBeNull();
});

it('reminds again once the event is back on', function (): void {
    $webinar = webinarAt(now()->addMinutes(20));
    holdPlace($webinar, $this->learner);

    callOff($webinar, $this->admin);
    $this->artisan('live:remind')->assertSuccessful();

    // Revived: a cancelled webinar returns to DRAFT, so somebody looks at the
    // date before registrations reopen — but the room is expected again.
    app(ChangeWebinarStatus::class)->handle($webinar->refresh(), WebinarStatus::Draft, $this->admin);

    $this->artisan('live:remind')->assertSuccessful();

    expect(InboxNotification::query()
        ->where('notifiable_id', $this->learner->id)
        ->where('type', 'session.reminder')
        ->count())->toBe(1);
});

it('takes a called-off event out of the calendar', function (): void {
    $webinar = webinarAt(now()->addDays(2));
    holdPlace($webinar, $this->learner);

    $this->actingAs($this->learner)
        ->getJson('/api/v1/calendar')
        ->assertOk()
        ->assertJsonCount(1, 'data.sessions');

    callOff($webinar, $this->admin);

    /*
     * The SESSION is still `scheduled` — the calendar's own `whereNot` on
     * session status does not catch this, which is why the query reads the
     * webinar's status too.
     */
    expect($webinar->refresh()->session?->status->value)->toBe('scheduled');

    $this->actingAs($this->learner)
        ->getJson('/api/v1/calendar')
        ->assertOk()
        ->assertJsonCount(0, 'data.sessions');
});

it('will not admit somebody to an event that was called off', function (): void {
    $webinar = webinarAt(now()->addMinutes(10));
    holdPlace($webinar, $this->learner);

    callOff($webinar, $this->admin);

    // Nobody is in the audience, so the door is shut — the same answer the
    // roster and the reminder now give.
    $this->actingAs($this->learner)
        ->postJson("/api/v1/live-sessions/{$webinar->session?->uuid}/join")
        ->assertForbidden();
});
