<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\RoleAssignment;
use Illuminate\Foundation\Events\Dispatchable;

final class RoleAssigned
{
    use Dispatchable;

    public function __construct(public readonly RoleAssignment $assignment) {}
}
