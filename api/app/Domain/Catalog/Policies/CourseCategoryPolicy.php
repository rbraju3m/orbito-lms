<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Identity\Models\User;

final class CourseCategoryPolicy
{
    public function viewAny(?User $actor): bool
    {
        return true;
    }

    /** Taxonomy is platform configuration, not per-course content. */
    public function manage(User $actor): bool
    {
        return $actor->hasPermission('settings.update');
    }
}
