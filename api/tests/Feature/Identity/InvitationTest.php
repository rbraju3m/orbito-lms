<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\InvitationRole;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\InvitationMail;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use App\Domain\Platform\Enums\RegistrationMode;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\UsageCounters;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
 * Invitations (docs/INVITATIONS.md): staff mail a link that makes an account
 * — a student or an instructor — in every signup mode.
 */

uses()->beforeEach(fn () => $this->withHeaders(spaHeaders()));

beforeEach(function (): void {
    seedRegistry();
    Notification::fake();

    $this->academy = Tenant::findOrFail(tenancy()->tenant->getTenantKey());
    $this->admin = userWithRole(RoleKey::Admin);
});

/** The token from the mail, the way the invitee's browser would get it. */
function mailedInvitationToken(string $email): string
{
    $token = null;

    Notification::assertSentOnDemand(
        InvitationMail::class,
        function (InvitationMail $mail, array $channels, AnonymousNotifiable $to) use ($email, &$token): bool {
            if ($to->routes['mail'] !== $email) {
                return false;
            }

            parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return true;
        },
    );

    return (string) $token;
}

/** @param  array<string, mixed>  $overrides */
function acceptInvitation(string $token, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/auth/invitations/accept', $overrides + [
        'academy' => 'test-academy',
        'token' => $token,
        'name' => 'Ada Lovelace',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ]);
}

/* -------------------------------------------------- sending */

it('invites an address and mails it a link to the academy', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', ['email' => ' Ada@Example.test ', 'role' => 'student'])
        ->assertCreated();

    expect($response->json('data.email'))->toBe('ada@example.test')
        ->and($response->json('data.status'))->toBe('pending')
        ->and($response->json('data.role'))->toBe('student')
        ->and($response->json('data.sent_count'))->toBe(1)
        ->and($response->json('data.accepted_at'))->toBeNull()
        // Nothing in the response can be followed.
        ->and(json_encode($response->json()))->not->toContain('token');

    $token = mailedInvitationToken('ada@example.test');

    // Only the hash is stored: the row cannot be turned back into a link.
    $invitation = Invitation::query()->sole();
    expect($invitation->token_hash)->toBe(Invitation::hashToken($token))
        ->and($invitation->token_hash)->not->toBe($token);
});

it('re-issues an open invitation instead of stacking a second link', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', ['email' => 'ada@example.test', 'role' => 'student'])
        ->assertCreated();
    $first = mailedInvitationToken('ada@example.test');

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', ['email' => 'ada@example.test', 'role' => 'instructor'])
        ->assertCreated()
        ->assertJsonPath('data.role', 'instructor')
        ->assertJsonPath('data.sent_count', 2);

    expect(Invitation::query()->count())->toBe(1);

    // The first link stopped working when the second was issued.
    acceptInvitation($first)->assertNotFound();
});

it('refuses to invite an address that already has an account', function (): void {
    User::factory()->create(['email' => 'taken@example.test']);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', ['email' => 'taken@example.test', 'role' => 'student'])
        ->assertUnprocessable();

    expect($response)->toBeApiError('validation_failed')
        ->and($response->json('error.details.0.field'))->toBe('email');

    Notification::assertNothingSent();
});

it('refuses a role an invitation cannot grant', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', ['email' => 'ada@example.test', 'role' => 'admin'])
        ->assertUnprocessable();

    expect(Invitation::query()->count())->toBe(0);
});

it('does not let somebody without invitation.manage invite anybody', function (): void {
    $instructor = User::factory()->instructor()->create();

    $this->actingAs($instructor)
        ->postJson('/api/v1/admin/invitations', ['email' => 'ada@example.test', 'role' => 'student'])
        ->assertForbidden();
    $this->actingAs($instructor)->getJson('/api/v1/admin/invitations')->assertForbidden();

    Notification::assertNothingSent();
});

it('counts open instructor invitations against the plan\'s seats', function (): void {
    $plan = Plan::query()
        ->whereKey(Subscription::where('tenant_id', $this->academy->getTenantKey())->value('plan_id'))
        ->firstOrFail();
    $plan->forceFill(['limits' => [
        UsageMetric::Instructors->planKey() => app(UsageCounters::class)->get(UsageMetric::Instructors) + 1,
    ]])->save();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', ['email' => 'one@example.test', 'role' => 'instructor'])
        ->assertCreated();

    // The last seat is held by the open invitation above.
    expect($this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', ['email' => 'two@example.test', 'role' => 'instructor'])
        ->assertStatus(402))->toBeApiError('plan_limit_reached');

    // Re-sending the one that holds the seat does not count it twice, and a
    // student is not an instructor seat at all.
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', ['email' => 'one@example.test', 'role' => 'instructor'])
        ->assertCreated();
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', ['email' => 'two@example.test', 'role' => 'student'])
        ->assertCreated();
});

/* -------------------------------------------------- the list, resend, revoke */

it('lists invitations with a status derived from the clock', function (): void {
    Invitation::factory()->create(['email' => 'pending@example.test']);
    Invitation::factory()->expired()->create(['email' => 'late@example.test']);
    Invitation::factory()->revoked()->create(['email' => 'withdrawn@example.test']);

    $all = $this->actingAs($this->admin)->getJson('/api/v1/admin/invitations')->assertOk();

    expect(collect($all->json('data'))->pluck('status', 'email')->all())->toEqual([
        'withdrawn@example.test' => 'revoked',
        'late@example.test' => 'expired',
        'pending@example.test' => 'pending',
    ]);

    $expired = $this->actingAs($this->admin)->getJson('/api/v1/admin/invitations?status=expired')->assertOk();
    expect($expired->json('data.*.email'))->toBe(['late@example.test'])
        ->and($expired->json('data.0.can_resend'))->toBeTrue();

    expect($this->actingAs($this->admin)->getJson('/api/v1/admin/invitations?q=withdrawn')
        ->json('data.*.can_resend'))->toBe([false]);
});

it('re-sends an expired invitation with a fresh link', function (): void {
    $invitation = Invitation::factory()->expired()->withToken('old-token')->create(['email' => 'late@example.test']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/invitations/{$invitation->uuid}/resend")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    acceptInvitation('old-token')->assertNotFound();
    acceptInvitation(mailedInvitationToken('late@example.test'))->assertCreated();
});

it('revokes an invitation, keeps the record, and frees the address', function (): void {
    $invitation = Invitation::factory()->withToken('the-token')->create(['email' => 'ada@example.test']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/invitations/{$invitation->uuid}/revoke")
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked');

    expect(acceptInvitation('the-token')->assertStatus(410))->toBeApiError('invitation_revoked');

    // A settled invitation cannot be revoked or re-sent again.
    $this->actingAs($this->admin)->postJson("/api/v1/admin/invitations/{$invitation->uuid}/revoke")->assertConflict();
    $this->actingAs($this->admin)->postJson("/api/v1/admin/invitations/{$invitation->uuid}/resend")->assertConflict();

    // The address can be asked again.
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', ['email' => 'ada@example.test', 'role' => 'student'])
        ->assertCreated();
    expect(Invitation::query()->count())->toBe(2);
});

/* -------------------------------------------------- following the link */

it('makes a verified student account from the link and signs them in', function (): void {
    $invitation = Invitation::factory()->withToken('the-token')->create(['email' => 'ada@example.test']);

    acceptInvitation('the-token', ['email' => 'somebody-else@example.test'])
        ->assertCreated()
        // The invited address, whatever the body said: the link proved that mailbox.
        ->assertJsonPath('data.user.email', 'ada@example.test')
        ->assertJsonPath('data.roles', ['student'])
        ->assertJsonPath('data.must_verify_email', false);

    $user = User::query()->where('email', 'ada@example.test')->sole();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and(User::query()->where('email', 'somebody-else@example.test')->exists())->toBeFalse()
        ->and($invitation->refresh()->accepted_user_id)->toBe($user->id)
        ->and($invitation->pending_email)->toBeNull();

    Notification::assertNotSentTo($user, VerifyEmailNotification::class);
    $this->assertAuthenticatedAs($user, 'web');

    // One link, one account.
    expect(acceptInvitation('the-token', ['email' => 'x@example.test'])->assertStatus(410))
        ->toBeApiError('invitation_accepted');
});

it('makes an approved instructor, even with the plan\'s seats now full', function (): void {
    $inviter = $this->admin;
    Invitation::factory()->instructor()->withToken('the-token')
        ->create(['email' => 'grace@example.test', 'invited_by' => $inviter->id]);

    // The seat was checked when the invitation was sent; the invitee cannot
    // change the academy's plan, so acceptance does not ask again.
    Plan::query()
        ->whereKey(Subscription::where('tenant_id', $this->academy->getTenantKey())->value('plan_id'))
        ->firstOrFail()
        ->forceFill(['limits' => [UsageMetric::Instructors->planKey() => 0]])->save();

    $before = app(UsageCounters::class)->get(UsageMetric::Instructors);

    acceptInvitation('the-token', ['name' => 'Grace Hopper'])->assertCreated();

    $user = User::query()->where('email', 'grace@example.test')->sole();
    $profile = $user->instructorProfile()->sole();

    expect($user->hasRole(RoleKey::Instructor))->toBeTrue()
        ->and($user->hasRole(RoleKey::Student))->toBeTrue()
        ->and($profile->status)->toBe(InstructorStatus::Approved)
        ->and($profile->application_source)->toBe('invitation')
        ->and($profile->reviewed_by)->toBe($inviter->id)
        // Approved through the one path that grants the role, so the seat
        // counter moved like any other approval.
        ->and(app(UsageCounters::class)->get(UsageMetric::Instructors))->toBe($before + 1);
});

it('works in an academy that takes no sign-ups at all', function (): void {
    $this->academy->registration_mode = RegistrationMode::Closed->value;
    $this->academy->save();

    Invitation::factory()->withToken('the-token')->create(['email' => 'ada@example.test']);

    acceptInvitation('the-token')->assertCreated();
});

it('tells the holder of an expired link to ask for a new one', function (): void {
    Invitation::factory()->expired()->withToken('the-token')->create(['email' => 'ada@example.test']);

    expect(acceptInvitation('the-token')->assertStatus(410))->toBeApiError('invitation_expired');
    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

it('sends somebody who signed up in the meantime to sign in', function (): void {
    Invitation::factory()->withToken('the-token')->create(['email' => 'ada@example.test']);
    User::factory()->create(['email' => 'ada@example.test']);

    expect(acceptInvitation('the-token')->assertConflict())->toBeApiError('account_exists');
});

it('validates the account form before touching the invitation', function (): void {
    $invitation = Invitation::factory()->withToken('the-token')->create(['email' => 'ada@example.test']);

    acceptInvitation('the-token', ['password_confirmation' => 'something else'])->assertUnprocessable();

    expect($invitation->refresh()->accepted_at)->toBeNull();
});

it('refuses a link on an academy that is not open', function (): void {
    Invitation::factory()->withToken('the-token')->create(['email' => 'ada@example.test']);

    expect(acceptInvitation('the-token', ['academy' => 'no-such-academy'])->assertForbidden())
        ->toBeApiError('registration_not_open');
});

it('never sends the role an invitation did not name', function (): void {
    Invitation::factory()->withToken('the-token')->create([
        'email' => 'ada@example.test', 'role' => InvitationRole::Student,
    ]);

    acceptInvitation('the-token', ['role' => 'admin', 'wants_to_teach' => true])
        ->assertCreated()
        ->assertJsonPath('data.roles', ['student']);
});
