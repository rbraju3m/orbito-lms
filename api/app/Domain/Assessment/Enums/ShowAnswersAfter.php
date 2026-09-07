<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * When correct answers may be revealed. Enforced server-side: a client cannot
 * ask for them earlier (ADR-06).
 */
enum ShowAnswersAfter: string
{
    case Never = 'never';
    case Submission = 'submission';
    case Pass = 'pass';
}
