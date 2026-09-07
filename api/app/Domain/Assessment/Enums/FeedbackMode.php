<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * Kept from the audited product — one of its genuinely good ideas.
 */
enum FeedbackMode: string
{
    /** Nothing until the whole attempt is submitted. */
    case Deferred = 'deferred';
    /** The right answer is shown after each question. */
    case Reveal = 'reveal';
    /** A wrong answer can be retried immediately. */
    case Retry = 'retry';
}
