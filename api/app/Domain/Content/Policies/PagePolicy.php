<?php

declare(strict_types=1);

namespace App\Domain\Content\Policies;

use App\Domain\Content\Models\Page;
use App\Domain\Identity\Models\User;

/**
 * `page.manage` — Admin and Super Admin. The public site is the ACADEMY's
 * front door, so building it is academy-wide, like the blog. Reading a
 * published page needs no policy: the query is the boundary
 * (ROLES_PERMISSIONS §6a).
 */
final class PagePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('page.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('page.manage');
    }

    public function view(User $actor, Page $page): bool
    {
        return $actor->hasPermission('page.manage');
    }

    public function update(User $actor, Page $page): bool
    {
        return $actor->hasPermission('page.manage');
    }

    public function publish(User $actor, Page $page): bool
    {
        return $actor->hasPermission('page.manage');
    }

    public function delete(User $actor, Page $page): bool
    {
        return $actor->hasPermission('page.manage');
    }
}
