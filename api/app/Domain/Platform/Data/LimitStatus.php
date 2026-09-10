<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

use App\Domain\Platform\Enums\UsageMetric;

/**
 * One metric's allowance, usage, and what that combination means.
 *
 * Rendered to the academy AND consulted by the write path, so the panel and
 * the server cannot disagree about whether the next course is allowed — the
 * same rule as `PublishChecklist` (§10) and `SubmissionRules` (§14).
 */
final readonly class LimitStatus
{
    public function __construct(
        public UsageMetric $metric,
        public int $used,
        /** The cap, or null for uncapped. */
        public ?int $limit,
        /** Whether exceeding this cap blocks the write or is only reported. */
        public bool $enforced,
    ) {}

    /** How much headroom is left, or null when uncapped. */
    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }

    /** At the cap: the next one will not fit. */
    public function atLimit(): bool
    {
        return $this->limit !== null && $this->used >= $this->limit;
    }

    /**
     * PAST the cap — which is a normal state, not a corrupt one. An academy
     * downgraded onto a smaller plan is instantly over on everything it
     * already built, and nothing is deleted to make it fit.
     */
    public function overLimit(): bool
    {
        return $this->limit !== null && $this->used > $this->limit;
    }

    /** Whether adding `$by` more would exceed the cap. */
    public function permits(int $by = 1): bool
    {
        return $this->limit === null || $this->used + $by <= $this->limit;
    }

    /** 0.0–1.0 for a progress bar, or null when there is nothing to fill. */
    public function fraction(): ?float
    {
        if ($this->limit === null || $this->limit <= 0) {
            return null;
        }

        return min(1.0, $this->used / $this->limit);
    }
}
