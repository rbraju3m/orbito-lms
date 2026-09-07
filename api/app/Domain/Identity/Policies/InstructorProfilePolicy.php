<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\InstructorProfile;
use App\Domain\Identity\Models\User;

final class InstructorProfilePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('instructor.view');
    }

    public function view(User $actor, InstructorProfile $profile): bool
    {
        return $profile->user_id === $actor->id || $actor->hasPermission('instructor.view');
    }

    public function review(User $actor): bool
    {
        return $actor->hasPermission('instructor.approve');
    }

    public function block(User $actor): bool
    {
        return $actor->hasPermission('instructor.block');
    }

    public function manageCommission(User $actor): bool
    {
        return $actor->hasPermission('instructor.commission.manage');
    }
}
