<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Events;

use App\Domain\Enrollment\Models\Enrollment;
use Illuminate\Foundation\Events\Dispatchable;

final class EnrollmentSuspended
{
    use Dispatchable;

    public function __construct(
        public readonly Enrollment $enrollment,
        public readonly ?string $reason = null,
    ) {}
}
