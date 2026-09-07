<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;

/**
 * A policy never asks "what role is this?" — only "what may they do?".
 * Ownership checks live here; permission checks live in the registry.
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('user.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->is($target) || $actor->hasPermission('user.view');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->is($target) || $actor->hasPermission('user.update');
    }

    public function delete(User $actor, User $target): bool
    {
        // Deleting yourself through the admin endpoint is almost always a
        // mistake; account closure is a separate, deliberate flow.
        return ! $actor->is($target) && $actor->hasPermission('user.delete');
    }

    public function suspend(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        // Nobody suspends a Super Admin through the API.
        if ($target->isSuperAdmin()) {
            return false;
        }

        return $actor->hasPermission('user.suspend');
    }

    public function export(User $actor, User $target): bool
    {
        return $actor->is($target) || $actor->hasPermission('user.export');
    }
}
