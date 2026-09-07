<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\AssignmentSubmission;
use Illuminate\Foundation\Events\Dispatchable;

/** Handed back for another attempt, without a mark. */
final class AssignmentReturned
{
    use Dispatchable;

    public function __construct(public readonly AssignmentSubmission $submission) {}
}
