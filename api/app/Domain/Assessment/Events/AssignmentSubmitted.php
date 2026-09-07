<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\AssignmentSubmission;
use Illuminate\Foundation\Events\Dispatchable;

/** Handed in and waiting for a person. Notifications hang off this. */
final class AssignmentSubmitted
{
    use Dispatchable;

    public function __construct(public readonly AssignmentSubmission $submission) {}
}
