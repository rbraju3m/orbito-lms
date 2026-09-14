<?php

declare(strict_types=1);

namespace App\Domain\Content\Policies;

use App\Domain\Content\Models\Post;
use App\Domain\Identity\Models\User;

/**
 * `post.manage` — Admin and Super Admin (docs/ROLES_PERMISSIONS.md). The blog
 * speaks for the ACADEMY on its public site, so it is academy-wide: holding a
 * course does not let an instructor publish in the academy's name.
 *
 * Reading a published post needs no policy at all — it is on the public site,
 * where the query is the boundary (`Post::published()`, ROLES_PERMISSIONS §6a).
 */
final class PostPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('post.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('post.manage');
    }

    public function view(User $actor, Post $post): bool
    {
        return $actor->hasPermission('post.manage');
    }

    public function update(User $actor, Post $post): bool
    {
        return $actor->hasPermission('post.manage');
    }

    public function publish(User $actor, Post $post): bool
    {
        return $actor->hasPermission('post.manage');
    }

    public function delete(User $actor, Post $post): bool
    {
        return $actor->hasPermission('post.manage');
    }
}
