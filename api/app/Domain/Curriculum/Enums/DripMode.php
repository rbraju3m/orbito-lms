<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Enums;

/**
 * How a course releases its curriculum over time.
 *
 * The mode is a course setting; the per-item parameters it reads live on
 * `course_items` (`drip_available_at`, `drip_after_days`, `drip_after_item_id`).
 * Switching mode therefore reinterprets the same rows rather than migrating
 * them — an author can try sequential, go back to by_date, and lose nothing.
 */
enum DripMode: string
{
    /** Everything is open the moment the learner enrols. */
    case None = 'none';
    /** An absolute calendar: the same date for every learner. */
    case ByDate = 'by_date';
    /** Relative to when this learner started. */
    case ByDays = 'by_days';
    /** The previous item must be completed first. */
    case Sequential = 'sequential';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No drip — everything available immediately',
            self::ByDate => 'On a fixed date',
            self::ByDays => 'A number of days after enrolment',
            self::Sequential => 'After the previous item is completed',
        };
    }

    public function isActive(): bool
    {
        return $this !== self::None;
    }

    /** Which per-item column this mode reads. Drives the builder UI. */
    public function itemField(): ?string
    {
        return match ($this) {
            self::None => null,
            self::ByDate => 'drip_available_at',
            self::ByDays => 'drip_after_days',
            self::Sequential => 'drip_after_item_id',
        };
    }
}
