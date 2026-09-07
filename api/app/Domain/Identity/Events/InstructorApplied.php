<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\InstructorProfile;
use Illuminate\Foundation\Events\Dispatchable;

final class InstructorApplied
{
    use Dispatchable;

    public function __construct(public readonly InstructorProfile $profile) {}
}
