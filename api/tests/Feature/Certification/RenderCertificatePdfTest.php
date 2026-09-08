<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Certification\Actions\IssueCertificate;
use App\Domain\Certification\Actions\RenderCertificatePdf;
use App\Domain\Certification\Models\Certificate;
use App\Domain\Certification\Models\CertificateTemplate;
use App\Domain\Certification\Support\CertificateHtml;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\Storage;

/*
 * The render. dompdf was chosen because this host cannot run headless Chrome,
 * so these tests exercise the real renderer rather than mocking it — an engine
 * that has never produced a byte is the Stripe adapter all over again.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->student = User::factory()->withRole(RoleKey::Student)->create(['name' => 'Rumi Haque']);
    $this->course = courseWithCurriculum(
        Course::factory()->published()->create(['title' => 'Modern Bengali Poetry']),
        [1],
    );
    $this->course->setting->update(['enable_certificate' => true]);
    $this->template = CertificateTemplate::factory()->create();

    $this->enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
        'completed_at' => now(),
    ]);

    $this->certificate = app(IssueCertificate::class)->handle($this->enrollment);
});

it('produces a real PDF and attaches it to the certificate', function (): void {
    Storage::fake('private');

    $certificate = app(RenderCertificatePdf::class)->handle($this->certificate);

    expect($certificate->pdf_media_id)->not->toBeNull();

    $media = Media::findOrFail($certificate->pdf_media_id);

    expect($media->mime)->toBe('application/pdf')
        ->and($media->collection)->toBe('certificate')
        // Owned by the learner it names, so MediaPolicy answers correctly.
        ->and($media->owner_id)->toBe($this->student->id);

    // The bytes, not just the row: %PDF- is the file signature.
    $bytes = Storage::disk('private')->get($media->path);

    expect($bytes)->toStartWith('%PDF-')
        ->and(strlen((string) $bytes))->toBeGreaterThan(1000);
});

it('stores the PDF privately, never on the public disk', function (): void {
    // A certificate names a person. A guessable public path would list them.
    Storage::fake('private');
    Storage::fake('public');

    $certificate = app(RenderCertificatePdf::class)->handle($this->certificate);
    $media = Media::findOrFail($certificate->pdf_media_id);

    expect($media->disk->value)->toBe('private');
    Storage::disk('private')->assertExists($media->path);
});

it('renders the learner and course from the SNAPSHOT, not from live rows', function (): void {
    $this->student->update(['name' => 'Someone Else']);
    $this->course->update(['title' => 'A Different Course']);

    $html = app(CertificateHtml::class)->for($this->certificate->fresh());

    expect($html)->toContain('Rumi Haque')
        ->toContain('Modern Bengali Poetry')
        ->not->toContain('Someone Else')
        ->not->toContain('A Different Course');
});

it('escapes author input rather than letting it become markup', function (): void {
    // A course title is author input and reaches the document unfiltered
    // otherwise. This is the same class of hole as unsanitised lesson HTML.
    $certificate = Certificate::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
        'enrollment_id' => Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => User::factory()->withRole(RoleKey::Student)->create()->id,
        ])->id,
        'snapshot' => [
            'learner_name' => '<script>alert(1)</script>',
            'course_title' => '"><img src=x onerror=alert(1)>',
            'academy_name' => 'Test',
        ],
    ]);

    $html = app(CertificateHtml::class)->for($certificate);

    /*
     * Escaping neutralises the TAG, it does not delete the words inside it —
     * `onerror=alert(1)` survives as inert prose once `<img` became `&lt;img`,
     * and asserting on that substring would be asserting the wrong thing.
     * What must be absent is markup that can execute.
     */
    expect($html)->not->toContain('<script>')
        ->not->toContain('<img src=x')
        ->toContain('&lt;script&gt;')
        ->toContain('&lt;img src=x');
});

it('refuses a template colour that is not a colour', function (): void {
    // Otherwise a template field is a CSS injection into every certificate.
    $this->template->update([
        'layout' => ['accent_colour' => 'red; } body { display:none } .x {'] + CertificateTemplate::defaultLayout(),
    ]);

    $html = app(CertificateHtml::class)->for($this->certificate->fresh(['template']));

    expect($html)->not->toContain('display:none')
        // Fell back to the default rather than emitting the attack.
        ->toContain('#1c7ed6');
});

it('still renders when the template was deleted', function (): void {
    // A tidied-up template list must not stop somebody downloading a
    // qualification they earned.
    Storage::fake('private');
    CertificateTemplate::query()->delete();

    $certificate = app(RenderCertificatePdf::class)->handle($this->certificate->fresh());

    expect($certificate->pdf_media_id)->not->toBeNull();
    expect(Storage::disk('private')->get(Media::findOrFail($certificate->pdf_media_id)->path))
        ->toStartWith('%PDF-');
});

it('re-renders rather than refusing, and repoints the certificate', function (): void {
    Storage::fake('private');

    $first = app(RenderCertificatePdf::class)->handle($this->certificate);
    $firstMediaId = $first->pdf_media_id;

    $second = app(RenderCertificatePdf::class)->handle($first);

    expect($second->pdf_media_id)->not->toBe($firstMediaId)
        // The old row stays: a signed URL somebody is holding must not 404
        // mid-download because a re-render happened.
        ->and(Media::find($firstMediaId))->not->toBeNull();
});
