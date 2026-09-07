<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Enums;

/**
 * How this enrollment came to exist. Declared in full now so the column never
 * needs widening; only `free` and `manual` are reachable before Phase 10.
 */
enum EnrollmentSource: string
{
    case Free = 'free';
    case Purchase = 'purchase';
    case Manual = 'manual';
    case Subscription = 'subscription';
    case Bundle = 'bundle';
    case Membership = 'membership';
    case Import = 'import';
}
