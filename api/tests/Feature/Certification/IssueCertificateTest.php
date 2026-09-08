<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Certification\Actions\IssueCertificate;
use App\Domain\Certification\Actions\RevokeCertificate;
use App\Domain\Certification\Enums\CertificateStatus;
use App\Domain\Certification\Events\CertificateIssued;
use App\Domain\Certification\Exceptions\CertificateRejected;
use App\Domain\Certification\Models\Certificate;
use App\Domain\Certification\Models\CertificateTemplate;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Events\CourseCompleted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/*
 * Issuing, which has exactly one hard problem: `CourseCompleted` is not a
 * once-per-lifetime event, and a learner with two certificate numbers for one
 * course cannot prove which is real.
 */

beforeEach(function (): void {
    seedRegistry();

    /*
     * Issuing dispatches CertificateIssued, and the queue is synchronous in
     * tests — so every certificate here renders a real PDF. Faked, or the
     * suite writes files into storage/ that nobody cleans up.
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
});

it('issues a certificate with a number, a token and a frozen snapshot', function (): void {
    Event::fake([CertificateIssued::class]);

    $certificate = app(IssueCertificate::class)->handle($this->enrollment);

    expect($certificate->status)->toBe(CertificateStatus::Issued)
        ->and($certificate->number)->toStartWith('CERT-')
        ->and(strlen($certificate->verification_token))->toBe(32)
        ->and($certificate->issued_at)->not->toBeNull()
        // Frozen at issue: a later rename must not rewrite a verified document.
        ->and($certificate->snapshot['learner_name'])->toBe('Rumi Haque')
        ->and($certificate->snapshot['course_title'])->toBe('Modern Bengali Poetry');

    Event::assertDispatched(CertificateIssued::class);
});

it('does not rewrite a certificate when the learner or course is renamed', function (): void {
    $certificate = app(IssueCertificate::class)->handle($this->enrollment);

    $this->student->update(['name' => 'Someone Else']);
    $this->course->update(['title' => 'A Different Course']);

    expect($certificate->fresh()->snapshot['learner_name'])->toBe('Rumi Haque')
        ->and($certificate->fresh()->snapshot['course_title'])->toBe('Modern Bengali Poetry');
});

/* ------------------------------------------------------------ idempotency */

it('issues exactly one certificate however many times completion fires', function (): void {
    $first = app(IssueCertificate::class)->handle($this->enrollment);
    $second = app(IssueCertificate::class)->handle($this->enrollment);
    $third = app(IssueCertificate::class)->handle($this->enrollment->fresh());

    expect($second->id)->toBe($first->id)
        ->and($third->id)->toBe($first->id)
        ->and(Certificate::count())->toBe(1)
        // The number must not move either — it is printed and read aloud.
        ->and($second->number)->toBe($first->number);
});

it('dispatches CertificateIssued only for the mint that actually happened', function (): void {
    // Otherwise a re-completion queues another PDF render for a certificate
    // that already has one.
    Event::fake([CertificateIssued::class]);

    app(IssueCertificate::class)->handle($this->enrollment);
    app(IssueCertificate::class)->handle($this->enrollment);

    Event::assertDispatchedTimes(CertificateIssued::class, 1);
});

it('holds one certificate per enrolment at the database level', function (): void {
    app(IssueCertificate::class)->handle($this->enrollment);

    // The UNIQUE key is the guarantee; the read above it is an optimisation.
    expect(fn () => Certificate::factory()->create([
        'enrollment_id' => $this->enrollment->id,
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

/* --------------------------------------------------------------- refusals */

it('refuses a course that does not award certificates', function (): void {
    $this->course->setting->update(['enable_certificate' => false]);

    expect(fn () => app(IssueCertificate::class)->handle($this->enrollment))
        ->toThrow(CertificateRejected::class);

    expect(Certificate::count())->toBe(0);
});

it('refuses an enrolment that has not been completed', function (): void {
    $this->enrollment->update(['completed_at' => null]);

    expect(fn () => app(IssueCertificate::class)->handle($this->enrollment->fresh()))
        ->toThrow(CertificateRejected::class);
});

it('still issues when the academy has no template at all', function (): void {
    // A missing design is not a reason to withhold a qualification somebody
    // earned. It renders on the default layout.
    CertificateTemplate::query()->delete();

    $certificate = app(IssueCertificate::class)->handle($this->enrollment);

    expect($certificate->template_id)->toBeNull()
        ->and($certificate->status)->toBe(CertificateStatus::Issued);
});

it('prefers the default template over any other active one', function (): void {
    CertificateTemplate::query()->delete();
    $other = CertificateTemplate::factory()->create(['is_default' => false, 'name' => 'Other']);
    $default = CertificateTemplate::factory()->create(['is_default' => true, 'name' => 'Default']);

    expect(app(IssueCertificate::class)->handle($this->enrollment)->template_id)
        ->toBe($default->id)
        ->not->toBe($other->id);
});

/* ------------------------------------------------------------- revocation */

it('revokes without deleting, so the URL still answers', function (): void {
    /*
     * A revoked certificate that vanished would 404, and a 404 is
     * indistinguishable from a forgery — deleting would protect the forger.
     */
    $certificate = app(IssueCertificate::class)->handle($this->enrollment);

    app(RevokeCertificate::class)->handle($certificate, 'Issued in error.');

    $certificate->refresh();

    expect(Certificate::count())->toBe(1)
        ->and($certificate->status)->toBe(CertificateStatus::Revoked)
        ->and($certificate->revoked_at)->not->toBeNull()
        ->and($certificate->revoked_reason)->toBe('Issued in error.')
        ->and($certificate->isValid())->toBeFalse();
});

it('refuses to revoke twice', function (): void {
    $certificate = app(IssueCertificate::class)->handle($this->enrollment);
    app(RevokeCertificate::class)->handle($certificate);

    expect(fn () => app(RevokeCertificate::class)->handle($certificate->fresh()))
        ->toThrow(CertificateRejected::class);
});

it('treats expiry as different from revocation', function (): void {
    // An expired certificate was genuinely earned. Saying "invalid" would make
    // an honest holder look like a forger.
    $certificate = Certificate::factory()->expired()->create([
        'enrollment_id' => $this->enrollment->id,
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    expect($certificate->hasExpired())->toBeTrue()
        ->and($certificate->isValid())->toBeFalse()
        // Still `issued`, not `revoked` — the page must be able to say which.
        ->and($certificate->status)->toBe(CertificateStatus::Issued);
});

/* ------------------------------------------- completion wires to issuance */

it('issues from the CourseCompleted event without the request waiting', function (): void {
    $this->enrollment->update(['completed_at' => null]);

    CourseCompleted::dispatch($this->enrollment->fresh());

    expect(Certificate::count())->toBe(0);

    $this->enrollment->update(['completed_at' => now()]);
    CourseCompleted::dispatch($this->enrollment->fresh());

    expect(Certificate::where('enrollment_id', $this->enrollment->id)->exists())->toBeTrue();
});

it('does not fail the completion when the course awards no certificate', function (): void {
    // Most courses. A rejection here must not become a failing queue job that
    // retries forever.
    $this->course->setting->update(['enable_certificate' => false]);

    CourseCompleted::dispatch($this->enrollment->fresh());

    expect(Certificate::count())->toBe(0);
});
