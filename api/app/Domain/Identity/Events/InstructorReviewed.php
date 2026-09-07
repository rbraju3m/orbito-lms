<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Models\InstructorProfile;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One event for approve / reject / block. Listeners branch on `$status`
 * rather than us shipping three near-identical events.
 */
final class InstructorReviewed
{
    use Dispatchable;

    public function __construct(
        public readonly InstructorProfile $profile,
        public readonly InstructorStatus $status,
        public readonly ?int $reviewedBy,
    ) {}
}
