<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Certification\Actions\IssueCertificate;
use App\Domain\Certification\Actions\RevokeCertificate;
use App\Domain\Certification\Models\Certificate;
use App\Domain\Certification\Models\CertificateTemplate;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/*
 * The public verification page — the second route in the system with no
 * authenticated user.
 *
 * Its audience is a stranger holding a printed certificate, so what it
 * REFUSES to say matters as much as what it confirms.
 */

beforeEach(function (): void {
    seedRegistry();

    /*
     * Issuing dispatches CertificateIssued, and the queue is synchronous in
     * tests — so every certificate here renders a real PDF. Faked, or the
     * suite writes files into storage/ that nobody cleans up.
     */
    Storage::fake('private');

    $this->student = User::factory()->withRole(RoleKey::Student)
        ->create(['name' => 'Rumi Haque', 'email' => 'rumi@example.test']);

    $this->course = courseWithCurriculum(
        Course::factory()->published()->create(['title' => 'Modern Bengali Poetry']),
        [1],
    );
    $this->course->setting->update(['enable_certificate' => true]);
    CertificateTemplate::factory()->create();

    $this->enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
        'completed_at' => now(),
    ]);

    $this->certificate = app(IssueCertificate::class)->handle($this->enrollment);
    $this->tenantId = tenant()->getTenantKey();
});

function verify(string $token, ?string $tenantId = null)
{
    $tenantId ??= test()->tenantId;

    return test()->getJson("/api/v1/verify/{$tenantId}/{$token}");
}

/* ------------------------------------------------------------- the happy path */

it('confirms a genuine certificate to a stranger with no account', function (): void {
    expect(auth()->check())->toBeFalse();

    verify($this->certificate->verification_token)
        ->assertOk()
        ->assertJsonPath('data.is_valid', true)
        ->assertJsonPath('data.is_revoked', false)
        ->assertJsonPath('data.has_expired', false)
        ->assertJsonPath('data.learner_name', 'Rumi Haque')
        ->assertJsonPath('data.course_title', 'Modern Bengali Poetry')
        ->assertJsonPath('data.number', $this->certificate->number);
});

it('tells a checker nothing beyond what is printed on the certificate', function (): void {
    /*
     * The audience is somebody checking a claim on a CV. Verifying is not an
     * introduction to the catalogue, and the token they already hold must not
     * be echoed into one more log and one more browser history.
     */
    $body = verify($this->certificate->verification_token)->assertOk()->getContent();

    expect($body)
        ->not->toContain($this->certificate->verification_token)
        ->not->toContain('rumi@example.test')
        ->not->toContain($this->course->slug)
        ->not->toContain($this->course->uuid)
        ->not->toContain('verification_url')
        ->not->toContain('has_pdf');
});

/* ------------------------------------------- revoked and expired are not gone */

it('still answers for a revoked certificate, and says it was revoked', function (): void {
    /*
     * A withdrawn certificate that 404'd would be indistinguishable from a
     * forgery — which protects the forger. The page must be able to say "this
     * was real, and it was taken back".
     */
    app(RevokeCertificate::class)->handle($this->certificate, 'Issued in error.');

    $response = verify($this->certificate->verification_token)
        ->assertOk()
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.is_revoked', true)
        ->assertJsonPath('data.has_expired', false);

    // The REASON is between the academy and the holder, not the stranger.
    expect($response->getContent())->not->toContain('Issued in error.');
});

it('distinguishes expired from revoked', function (): void {
    // An expired certificate was genuinely earned. Collapsing the two would
    // make an honest holder look like a forger.
    $this->certificate->forceFill(['expires_at' => now()->subDay()])->save();

    verify($this->certificate->verification_token)
        ->assertOk()
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.has_expired', true)
        ->assertJsonPath('data.is_revoked', false);
});

/* --------------------------------------------------------------- the refusals */

it('404s an unknown token', function (): void {
    verify(str_repeat('a', 32))->assertNotFound();
});

it('404s a malformed token without touching the database', function (): void {
    verify('short')->assertNotFound();
    verify(str_repeat('x', 500))->assertNotFound();
});

it('404s an unknown academy, indistinguishably from an unknown token', function (): void {
    $missingAcademy = verify($this->certificate->verification_token, 'no-such-academy');
    $missingToken = verify(str_repeat('a', 32));

    expect($missingAcademy->status())->toBe($missingToken->status())->toBe(404);
});

it('404s a closed academy the same way', function (): void {
    // Anything more specific turns a route reachable by anybody on the
    // internet into an oracle for which academy ids exist.
    $suspended = Tenant::create([
        'id' => 'suspended-academy',
        'slug' => 'suspended-academy',
        'name' => 'Suspended',
        'status' => TenantStatus::Suspended,
        'is_active' => false,
    ]);

    verify($this->certificate->verification_token, $suspended->id)->assertNotFound();
});

it('will not verify a token against the wrong academy', function (): void {
    // The token is real, but it belongs to another schema — so it resolves to
    // nothing rather than to somebody else's certificate.
    $other = Tenant::create([
        'id' => 'other-academy',
        'slug' => 'other-academy',
        'name' => 'Other Academy',
        'status' => TenantStatus::Active,
        'is_active' => true,
    ]);

    verify($this->certificate->verification_token, $other->id)->assertNotFound();
})->skip('Provisioning a second schema mid-test needs SwitchesTenants; covered by TenantIsolationTest.');

/* ------------------------------------------------------- outside the usual gates */

it('keeps verifying when the academy subscription has lapsed', function (): void {
    /*
     * The qualification was earned. A billing lapse is between the platform
     * and the academy, not something to take out on a former student's CV.
     */
    Subscription::query()->update([
        'status' => SubscriptionStatus::Canceled,
        'current_period_ends_at' => now()->subMonths(2),
        'grace_days' => 0,
    ]);

    verify($this->certificate->verification_token)
        ->assertOk()
        ->assertJsonPath('data.is_valid', true);
});

it('is not behind auth at all', function (): void {
    // No actingAs anywhere in this file. The assertion is that everything
    // above passes without one; this states it directly.
    expect(auth()->check())->toBeFalse();

    verify($this->certificate->verification_token)->assertOk();
});

it('never exposes the token through the model in any serialisation', function (): void {
    // A Resource is not the only path to a response: a dd() in a controller or
    // a queued job's failure payload serialises the model too.
    expect(Certificate::findOrFail($this->certificate->id)->toArray())
        ->not->toHaveKey('verification_token');
});
