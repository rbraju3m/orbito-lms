<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/**
 * The transitions a platform operator may ask for on an academy.
 *
 * An enum rather than a validated string so the controller's match is
 * exhaustive: a fifth transition becomes an error at every point that
 * dispatches on it, instead of a silently unhandled case. Same reasoning as
 * EnrollmentAction.
 */
enum TenantAction: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Suspend = 'suspend';
    case Reactivate = 'reactivate';
}
