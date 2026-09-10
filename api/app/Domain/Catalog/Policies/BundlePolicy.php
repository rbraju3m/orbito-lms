<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Identity\Models\User;

/**
 * One permission for the whole bundle surface: `bundle.manage`.
 *
 * No `.own` variant, because a bundle has no owner. It can contain another
 * instructor's courses, and pricing it decides what that instructor earns —
 * so it is an academy-level merchandising decision, not something an
 * individual author does to somebody else's work. See config/permissions.php.
 */
final class BundlePolicy
{
    public function viewAny(?User $actor): bool
    {
        // The catalogue query filters by status; drafts need `view` below.
        return true;
    }

    public function view(?User $actor, Bundle $bundle): bool
    {
        if ($bundle->status->isLive()) {
            return true;
        }

        return $actor !== null && $actor->hasPermission('bundle.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('bundle.manage');
    }

    public function update(User $actor, Bundle $bundle): bool
    {
        return $actor->hasPermission('bundle.manage');
    }

    public function delete(User $actor, Bundle $bundle): bool
    {
        return $actor->hasPermission('bundle.manage');
    }

    public function publish(User $actor, Bundle $bundle): bool
    {
        return $actor->hasPermission('bundle.manage');
    }

    /** Pricing rides with managing it: a bundle nobody priced cannot ship. */
    public function price(User $actor, Bundle $bundle): bool
    {
        return $actor->hasPermission('bundle.manage');
    }
}
