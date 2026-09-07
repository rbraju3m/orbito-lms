<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

enum TimeExpiryPolicy: string
{
    /** Grade whatever was answered. */
    case AutoSubmit = 'auto_submit';
    /** Discard the attempt entirely. */
    case AutoAbandon = 'auto_abandon';
}
