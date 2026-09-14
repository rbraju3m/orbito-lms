<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Live\Notifications\GuestMail;
use App\Domain\Live\Support\GuestToken;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SwitchesTenants;

/*
 * A place at a free webinar for somebody with no account — the second
 * anonymous write (docs/GUEST_REGISTRATION.md).
 *
 * Every request is a stranger's, so tenancy is ended before each one and the
 * file switches tenants (see PublicSiteTest). Fixtures are created first,
 * while the harness still has the academy open.
 */

uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();
    Notification::fake();

    $this->webinar = Webinar::factory()->published()->withSession()->create([
        'title' => 'Open evening',
        'slug' => 'open-evening',
    ]);
});

function guestFormToken(): string
{
    tenancy()->end();

    $token = test()->getJson('/api/v1/public/test-academy/form-token')->assertOk()->json('data.token');

    test()->travel(30)->seconds();

    return (string) $token;
}

/** @param  array<string, mixed>  $overrides */
function askForPlace(array $overrides = [], string $slug = 'open-evening'): TestResponse
{
    $body = $overrides + ['email' => 'Ada@Example.test', 'name' => 'Ada Lovelace', 'form_token' => guestFormToken()];

    tenancy()->end();

    return test()->postJson("/api/v1/public/test-academy/webinars/{$slug}/guest-registrations", $body);
}

function guestCall(string $path, string $token): TestResponse
{
    tenancy()->end();

    return test()->postJson("/api/v1/public/test-academy/{$path}", ['token' => $token]);
}

/** @return list<array{to: string, mail: GuestMail}> */
function guestMails(): array
{
    $sent = [];

    Notification::assertSentOnDemand(GuestMail::class, function (GuestMail $mail, array $channels, AnonymousNotifiable $to) use (&$sent): bool {
        $sent[] = ['to' => (string) $to->routes['mail'], 'mail' => $mail];

        return true;
    });

    return $sent;
}

function tokenIn(GuestMail $mail): string
{
    parse_str((string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

/** Asks, then follows the link in the mail. */
function confirmedGuestToken(): string
{
    askForPlace()->assertAccepted();

    return (string) guestCall('guest-registrations/confirm', tokenIn(guestMails()[0]['mail']))
        ->assertCreated()
        ->json('data.token');
}

/** @return Collection<int, WebinarRegistration> */
function webinarPlaces(): Collection
{
    return Tenant::query()->where('slug', 'test-academy')->firstOrFail()
        ->run(fn () => WebinarRegistration::query()->orderBy('id')->get());
}

it('asks the mailbox before holding anything', function (): void {
    askForPlace()->assertAccepted()->assertExactJson(['data' => ['received' => true]]);

    // The form writes nothing: a stranger cannot book a place in somebody else's name.
    expect(webinarPlaces())->toBeEmpty();

    $mails = guestMails();

    expect($mails)->toHaveCount(1)
        ->and($mails[0]['to'])->toBe('ada@example.test')
        ->and($mails[0]['mail']->subject)->toBe('Confirm your place at Open evening')
        ->and($mails[0]['mail']->actionUrl)->toContain('/a/test-academy/webinars/open-evening/confirm?token=');
});

it('holds the place when the link is followed, and only once', function (): void {
    askForPlace()->assertAccepted();
    $confirmation = tokenIn(guestMails()[0]['mail']);

    $first = guestCall('guest-registrations/confirm', $confirmation)
        ->assertCreated()
        ->assertJsonPath('data.status', 'registered')
        ->assertJsonPath('data.can_cancel', true)
        ->assertJsonPath('data.webinar.slug', 'open-evening');

    guestCall('guest-registrations/confirm', $confirmation)->assertCreated();

    $place = webinarPlaces()->sole();

    expect($place->user_id)->toBeNull()
        ->and($place->email)->toBe('ada@example.test')
        ->and($place->name)->toBe('Ada Lovelace')
        ->and($first->json('data.token'))->toBeString();

    // Clicking twice is clicking twice: one "you are registered", not two.
    $registered = array_filter(guestMails(), fn (array $sent): bool => str_starts_with($sent['mail']->subject, 'You are registered'));
    expect($registered)->toHaveCount(1);
});

it('answers a new address, one already holding a place and a trap identically', function (): void {
    confirmedGuestToken();

    $held = askForPlace();
    $fresh = askForPlace(['email' => 'grace@example.test']);
    $trapped = askForPlace(['email' => 'bot@example.test', 'website' => 'https://spam.example']);

    // Anything more specific tells a stranger whose address holds a place.
    expect($held->json())->toEqual($fresh->json())
        ->and($trapped->json())->toEqual($fresh->json())
        ->and($trapped->status())->toBe(202);

    $to = array_column(guestMails(), 'to');

    expect($to)->not->toContain('bot@example.test')
        // The address that holds a place is sent its manage link, not a second confirmation.
        ->and(collect(guestMails())->last(fn (array $sent): bool => $sent['to'] === 'ada@example.test')['mail']->subject)
        ->toBe('Your place at Open evening');
});

it('stops a stranger filling somebody\'s inbox, without saying so', function (): void {
    config(['orbito.guest_registration.mails_per_address' => 2]);

    askForPlace()->assertAccepted();
    askForPlace()->assertAccepted();
    askForPlace()->assertAccepted();

    expect(guestMails())->toHaveCount(2);
});

it('tells a guest plainly that a paid place needs an account', function (): void {
    $paid = Webinar::factory()->published()->withSession()->paid()->create(['slug' => 'masterclass']);

    $response = askForPlace([], $paid->slug)->assertStatus(409);

    expect($response)->toBeApiError('webinar_guest_not_allowed');
    Notification::assertNothingSent();
});

it('404s a webinar that is not published', function (): void {
    $draft = Webinar::factory()->create(['slug' => 'not-yet']);

    askForPlace([], $draft->slug)->assertNotFound();
});

it('tells whoever follows the link that the room filled up meanwhile', function (): void {
    askForPlace()->assertAccepted();
    $confirmation = tokenIn(guestMails()[0]['mail']);

    Tenant::query()->where('slug', 'test-academy')->firstOrFail()->run(function (): void {
        $this->webinar->forceFill(['capacity' => 1])->save();
        $member = User::factory()->withRole(RoleKey::Student)->create();
        WebinarRegistration::create([
            'webinar_id' => $this->webinar->id,
            'user_id' => $member->id,
            'email' => $member->email,
            'status' => WebinarRegistration::STATUS_REGISTERED,
            'registered_at' => now(),
        ]);
    });

    expect(guestCall('guest-registrations/confirm', $confirmation)->assertStatus(409))->toBeApiError('webinar_full');
    expect(webinarPlaces())->toHaveCount(1);
});

it('refuses a link that lapsed or was minted for another academy', function (): void {
    askForPlace()->assertAccepted();
    $confirmation = tokenIn(guestMails()[0]['mail']);
    $borrowed = app(GuestToken::class)->confirmation('another-academy', $this->webinar, 'ada@example.test', null, now());

    expect(guestCall('guest-registrations/confirm', $borrowed)->assertUnprocessable())->toBeApiError('guest_link_invalid');

    $this->travel(25)->hours();

    expect(guestCall('guest-registrations/confirm', $confirmation)->assertUnprocessable())->toBeApiError('guest_link_invalid');
    expect(webinarPlaces())->toBeEmpty();
});

it('lets a guest see, join and give up the place with the link from the mail', function (): void {
    $session = LiveSession::factory()->startingAt(now()->addMinutes(5))->create([
        'course_id' => null,
        'cohort_id' => null,
        'join_url' => 'https://meet.example.test/join-here',
    ]);
    $this->webinar->forceFill(['live_session_id' => $session->id])->save();

    $token = confirmedGuestToken();

    guestCall('guest-places/show', $token)
        ->assertOk()
        ->assertJsonPath('data.status', 'registered')
        ->assertJsonPath('data.can_join', true);

    guestCall('guest-places/join', $token)
        ->assertOk()
        ->assertJsonPath('data.join_url', 'https://meet.example.test/join-here');

    guestCall('guest-places/cancel', $token)
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.can_cancel', false)
        ->assertJsonPath('data.can_join', false);

    expect(guestCall('guest-places/join', $token)->assertUnprocessable())->toBeApiError('live_session_not_joinable');
});

it('never accepts a confirmation link as a manage link', function (): void {
    askForPlace()->assertAccepted();

    expect(guestCall('guest-places/show', tokenIn(guestMails()[0]['mail']))->assertUnprocessable())
        ->toBeApiError('guest_link_invalid');
});
