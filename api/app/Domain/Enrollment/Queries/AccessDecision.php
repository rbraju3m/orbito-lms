<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Queries;

use App\Domain\Enrollment\Models\Enrollment;
use Carbon\CarbonInterface;

/**
 * Why access was granted or refused. The `reason` is a stable machine code the
 * UI switches on to explain itself — "not enrolled" and "your access expired"
 * need different screens.
 *
 * A denial that the caller can do something about carries `unlocksAt` and/or
 * `meta` so the 423 screen can say HOW to get in rather than only that they
 * cannot: a date to wait for, an item to finish, a course to complete first.
 */
final readonly class AccessDecision
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public bool $granted,
        public string $reason,
        public string $source,
        public ?Enrollment $enrollment = null,
        public ?CarbonInterface $expiresAt = null,
        public ?CarbonInterface $unlocksAt = null,
        public array $meta = [],
    ) {}

    public static function grant(
        string $source,
        ?Enrollment $enrollment = null,
        ?CarbonInterface $expiresAt = null,
    ): self {
        return new self(true, 'granted', $source, $enrollment, $expiresAt);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function deny(
        string $reason,
        ?CarbonInterface $unlocksAt = null,
        array $meta = [],
        ?Enrollment $enrollment = null,
    ): self {
        return new self(false, $reason, 'none', $enrollment, null, $unlocksAt, $meta);
    }

    /** True when the caller can author, not merely consume. */
    public function isStaff(): bool
    {
        return $this->granted && in_array($this->source, ['owner', 'course_staff', 'platform_staff'], true);
    }
}
