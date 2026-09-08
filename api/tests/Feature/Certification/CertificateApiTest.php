<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Certification\Actions\IssueCertificate;
use App\Domain\Certification\Models\CertificateTemplate;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Models\Subscription;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedRegistry();

    /*
     * BEFORE the issue below. Issuing dispatches CertificateIssued and the
     * queue is synchronous here, so the render happens during setup — faking
     * the disk in a test body is too late and the suite writes real PDFs into
     * storage/.
     */
    Storage::fake('private');

    $this->student = User::factory()->withRole(RoleKey::Student)->create(['name' => 'Rumi Haque']);
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
});

/* ------------------------------------------------------------ the holder's view */

it('lists the holders own certificates with a shareable link', function (): void {
    $this->actingAs($this->student)
        ->getJson('/api/v1/certificates')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $this->certificate->number)
        ->assertJsonPath('data.0.is_valid', true)
        // The thing the holder actually wants: a link to hand somebody.
        ->assertJsonPath('data.0.verification_url', fn (string $url) => str_contains($url, '/verify/')
            && str_contains($url, $this->certificate->verification_token));
});

it('says a certificate is valid before its PDF has rendered', function (): void {
    /*
     * The document is a rendering of the fact, not the fact itself. The queue
     * is synchronous in tests so the render has already happened by now —
     * cleared here to model the real state while it is still queued, or after
     * one has failed.
     */
    $this->certificate->forceFill(['pdf_media_id' => null])->save();

    $this->actingAs($this->student)
        ->getJson("/api/v1/certificates/{$this->certificate->uuid}")
        ->assertOk()
        ->assertJsonPath('data.is_valid', true)
        ->assertJsonPath('data.has_pdf', false);
});

it('hands out a short-lived signed URL rather than the bytes', function (): void {
    $this->actingAs($this->student)
        ->getJson("/api/v1/certificates/{$this->certificate->uuid}/download")
        ->assertOk()
        ->assertJsonStructure(['data' => ['url', 'expires_at']])
        ->assertJsonPath('data.url', fn (string $url) => str_contains($url, 'signature='));
});

it('404s the download while the render is still queued', function (): void {
    $this->certificate->forceFill(['pdf_media_id' => null])->save();

    $this->actingAs($this->student)
        ->getJson("/api/v1/certificates/{$this->certificate->uuid}/download")
        ->assertNotFound();
});

/* -------------------------------------------------------------- authorization */

it('shows a learner only their own certificates', function (): void {
    $other = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($other)
        ->getJson('/api/v1/certificates')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('forbids reading somebody elses certificate', function (): void {
    $other = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($other)
        ->getJson("/api/v1/certificates/{$this->certificate->uuid}")
        ->assertForbidden();

    $this->actingAs($other)
        ->getJson("/api/v1/certificates/{$this->certificate->uuid}/download")
        ->assertForbidden();
});

it('lets staff holding certificate.view.any read every certificate', function (): void {
    $staff = User::factory()->withRole(RoleKey::Staff)->create();

    $this->actingAs($staff)->getJson('/api/v1/certificates')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($staff)
        ->getJson("/api/v1/certificates/{$this->certificate->uuid}")
        ->assertOk();
});

it('does not let a holder revoke their own certificate', function (): void {
    // Otherwise a learner could un-revoke reputationally by withdrawing and
    // reissuing, and more simply: revocation is the academy's act.
    $this->actingAs($this->student)
        ->postJson("/api/v1/certificates/{$this->certificate->uuid}/revoke")
        ->assertForbidden();
});

it('does not let staff who can merely READ certificates revoke one', function (): void {
    // Staff hold certificate.view.any and not certificate.revoke — the split
    // is the point, because revoking makes a public page say a qualification
    // was taken back.
    $staff = User::factory()->withRole(RoleKey::Staff)->create();

    $this->actingAs($staff)
        ->postJson("/api/v1/certificates/{$this->certificate->uuid}/revoke")
        ->assertForbidden();
});

it('lets an admin revoke, with a reason', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $this->actingAs($admin)
        ->postJson("/api/v1/certificates/{$this->certificate->uuid}/revoke", [
            'reason' => 'Issued in error.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked')
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.revoked_reason', 'Issued in error.');
});

it('requires authentication for the holder surface', function (): void {
    $this->getJson('/api/v1/certificates')->assertUnauthorized();
    $this->getJson("/api/v1/certificates/{$this->certificate->uuid}")->assertUnauthorized();
});

/* ------------------------------------------------------- the subscription gate */

it('keeps reading and downloading when the academy has lapsed', function (): void {
    /*
     * 402 gates WRITES. A learner must not lose the qualification they earned
     * because the academy stopped paying — reading and exporting never stop.
     */
    Subscription::query()->update([
        'status' => SubscriptionStatus::Canceled,
        'current_period_ends_at' => now()->subMonths(2),
        'grace_days' => 0,
    ]);

    $this->actingAs($this->student)->getJson('/api/v1/certificates')->assertOk();
    $this->actingAs($this->student)
        ->getJson("/api/v1/certificates/{$this->certificate->uuid}/download")
        ->assertOk();

    // Revoking is a write, so it stops.
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    expect($this->actingAs($admin)
        ->postJson("/api/v1/certificates/{$this->certificate->uuid}/revoke")
        ->assertStatus(402))->toBeApiError('subscription_lapsed');
});
