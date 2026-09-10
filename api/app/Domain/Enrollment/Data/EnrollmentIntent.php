<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Data;

use App\Domain\Enrollment\Enums\EnrollmentSource;
use Carbon\CarbonInterface;

/**
 * How one enrolment is being granted, and which gates that path may skip.
 *
 * Four booleans on `EnrollInCourse::handle()` would be four call sites that
 * can silently pass them in the wrong order. Naming the paths instead means
 * there is a fixed, readable list of who is allowed past what — and a reviewer
 * can see the whole policy in one file.
 *
 * Note what NOTHING here bypasses: the seat limit. A staff override would make
 * `max_students` mean nothing in particular; the error tells them to raise it.
 */
final readonly class EnrollmentIntent
{
    private function __construct(
        public EnrollmentSource $source,
        public ?int $sourceId = null,
        public ?CarbonInterface $startsAt = null,
        public ?CarbonInterface $expiresAt = null,
        public bool $bypassPayment = false,
        public bool $bypassPrerequisites = false,
        /**
         * Which RUN of the course, when the academy schedules them.
         *
         * It sits here rather than in a second action because the cohort's
         * capacity has to be checked in the same transaction as the course's
         * seat limit — two writes would let two people take one last place
         * (§ Phase 9's lesson, applied again).
         */
        public ?int $cohortId = null,
    ) {}

    /** The same path, aimed at one scheduled run. */
    public function forCohort(int $cohortId): self
    {
        return new self(
            source: $this->source,
            sourceId: $this->sourceId,
            startsAt: $this->startsAt,
            expiresAt: $this->expiresAt,
            bypassPayment: $this->bypassPayment,
            bypassPrerequisites: $this->bypassPrerequisites,
            cohortId: $cohortId,
        );
    }

    /** A learner enrolling themselves in a free course. Bypasses nothing. */
    public static function free(): self
    {
        return new self(EnrollmentSource::Free);
    }

    /**
     * Staff granting a seat. Bypasses payment and prerequisites — that is what
     * "granting" means — and records who did it in `source_id`.
     */
    public static function manual(
        int $grantedByUserId,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $expiresAt = null,
    ): self {
        return new self(
            source: EnrollmentSource::Manual,
            sourceId: $grantedByUserId,
            startsAt: $startsAt,
            expiresAt: $expiresAt,
            bypassPayment: true,
            bypassPrerequisites: true,
        );
    }

    /**
     * Phase 10. Declared now so the payment path is visibly a first-class
     * intent rather than something bolted on with a flag later.
     *
     * Note what it does NOT bypass: prerequisites. Buying one course does not
     * excuse you from the course it is built on.
     */
    public static function purchase(int $orderId, ?CarbonInterface $expiresAt = null): self
    {
        return new self(
            source: EnrollmentSource::Purchase,
            sourceId: $orderId,
            expiresAt: $expiresAt,
            bypassPayment: true,
        );
    }

    /**
     * One course out of a bundle somebody just paid for.
     *
     * Unlike `purchase()` three lines up, this DOES bypass prerequisites, and
     * the difference is deliberate. A curated path — course 3 builds on course
     * 2 — is the most natural bundle there is, and enforcing prerequisites
     * would fail to grant course 3 at the moment of purchase, leaving somebody
     * paid-up and locked out of what they bought. Selling the sequence is the
     * academy asserting the sequence.
     *
     * The seat limit is still not bypassed. Nothing bypasses that.
     */
    public static function bundle(int $orderId, ?CarbonInterface $expiresAt = null): self
    {
        return new self(
            source: EnrollmentSource::Bundle,
            sourceId: $orderId,
            expiresAt: $expiresAt,
            bypassPayment: true,
            bypassPrerequisites: true,
        );
    }
}
