<?php

declare(strict_types=1);

namespace App\Domain\Certification\Actions;

use App\Domain\Certification\Enums\CertificateStatus;
use App\Domain\Certification\Events\CertificateIssued;
use App\Domain\Certification\Exceptions\CertificateRejected;
use App\Domain\Certification\Models\Certificate;
use App\Domain\Certification\Models\CertificateTemplate;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mints one certificate for one completed enrolment.
 *
 * IDEMPOTENT, and that is the whole difficulty. `CourseCompleted` is not a
 * once-per-lifetime event: `RecalculateCourseProgress` fires it whenever the
 * last item flips to complete, a queue can retry a job, and a learner can
 * complete, reset and complete again. None of those may mint a second
 * certificate — a person with two numbers for one course cannot prove which
 * is real.
 *
 * The guarantee is the UNIQUE key on `enrollment_id`, not the read below it.
 * The read is an optimisation; the constraint is the truth, and the race it
 * loses is caught rather than prevented.
 */
final class IssueCertificate
{
    public function handle(Enrollment $enrollment): Certificate
    {
        $existing = Certificate::where('enrollment_id', $enrollment->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $enrollment->loadMissing(['course.setting', 'user']);
        $course = $enrollment->course;

        if (! $course->setting?->enable_certificate) {
            throw CertificateRejected::notEnabled();
        }

        if ($enrollment->completed_at === null) {
            throw CertificateRejected::courseNotCompleted();
        }

        $template = $this->template();

        try {
            $certificate = DB::transaction(fn (): Certificate => Certificate::create([
                'number' => $this->nextNumber(),
                'template_id' => $template?->id,
                'user_id' => $enrollment->user_id,
                'course_id' => $course->id,
                'enrollment_id' => $enrollment->id,
                'issued_at' => now(),
                /*
                 * A certificate outlives the enrolment that earned it.
                 * Deliberately NOT copied from `enrollment.expires_at`:
                 * losing access to a course you finished does not un-finish
                 * it. Nothing sets a validity period yet.
                 */
                'expires_at' => null,
                'status' => CertificateStatus::Issued,
                'snapshot' => $this->snapshot($enrollment),
                'verification_token' => $this->token(),
            ]));
        } catch (UniqueConstraintViolationException) {
            /*
             * Two completions raced. The constraint refused the second, which
             * is exactly what it is for — return the one that won rather than
             * failing a job that has nothing left to do.
             */
            return Certificate::where('enrollment_id', $enrollment->id)->firstOrFail();
        }

        CertificateIssued::dispatch($certificate);

        return $certificate;
    }

    /**
     * What was true at issue.
     *
     * Frozen because a certificate is a historical claim: a learner who
     * changes their display name, or an author who renames a course, must not
     * silently rewrite a document somebody has already verified.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Enrollment $enrollment): array
    {
        /*
         * Looked up rather than read off the relation. `user_id` crosses the
         * schema boundary to a table with no foreign key (§16), so the row can
         * genuinely be absent — the relation's type says otherwise and is
         * wrong about it.
         */
        $user = User::find($enrollment->user_id);

        return [
            'learner_name' => $user->name ?? 'Unknown learner',
            'course_title' => $enrollment->course->title,
            'completed_at' => $enrollment->completed_at?->toIso8601String(),
            'academy_name' => tenant()?->name,
        ];
    }

    /**
     * Human-facing, printed, and read aloud.
     *
     * Random rather than sequential for the same reason order numbers are: a
     * strictly incrementing certificate number tells every holder how many the
     * academy has ever issued. Uppercase and digit-free of ambiguous
     * characters would be better still; `Str::upper(Str::random())` is enough
     * here because the number is never the credential — the token is.
     */
    private function nextNumber(): string
    {
        return 'CERT-'.now()->format('Y').'-'.Str::upper(Str::random(10));
    }

    /** The credential the public verification page checks. 32 chars, indexed. */
    private function token(): string
    {
        return Str::lower(Str::random(32));
    }

    private function template(): ?CertificateTemplate
    {
        return CertificateTemplate::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
