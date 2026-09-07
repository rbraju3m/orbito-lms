<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Enums;

/**
 * The transitions staff may ask for on somebody else's enrollment.
 *
 * An enum rather than a validated string so the controller's match is
 * exhaustive: adding a fifth transition becomes a compile-time-ish error at
 * every point that dispatches on it, instead of a silently unhandled case.
 */
enum EnrollmentAction: string
{
    case Suspend = 'suspend';
    case Reinstate = 'reinstate';
    case Extend = 'extend';
    case Revoke = 'revoke';
}
