<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Queries;

use App\Domain\Enrollment\Models\Enrollment;
use Carbon\CarbonInterface;

/**
 * Why access was granted or refused. The `reason` is a stable machine code the
 * UI switches on to explain itself — "not enrolled" and "your access expired"
 * need different screens.
 */
final readonly class AccessDecision
{
    private function __construct(
        public bool $granted,
        public string $reason,
        public string $source,
        public ?Enrollment $enrollment = null,
        public ?CarbonInterface $expiresAt = null,
    ) {}

    public static function grant(
        string $source,
        ?Enrollment $enrollment = null,
        ?CarbonInterface $expiresAt = null,
    ): self {
        return new self(true, 'granted', $source, $enrollment, $expiresAt);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason, 'none');
    }

    /** True when the caller can author, not merely consume. */
    public function isStaff(): bool
    {
        return $this->granted && in_array($this->source, ['owner', 'course_staff', 'platform_staff'], true);
    }
}
