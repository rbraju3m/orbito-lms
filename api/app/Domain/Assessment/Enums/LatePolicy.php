<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * What happens to work handed in after the deadline.
 *
 * The decision is the server's, taken against `assignments.due_at` at the
 * moment of submission and then frozen onto the row — moving the deadline
 * afterwards must not retroactively make somebody late, or un-late.
 */
enum LatePolicy: string
{
    case Reject = 'reject';
    case Accept = 'accept';
    case Penalise = 'penalise';

    public function label(): string
    {
        return match ($this) {
            self::Reject => 'Refuse late work',
            self::Accept => 'Accept late work in full',
            self::Penalise => 'Accept late work with a penalty',
        };
    }

    public function allowsLate(): bool
    {
        return $this !== self::Reject;
    }
}
