<?php

declare(strict_types=1);

namespace App\Domain\Content\Policies;

use App\Domain\Content\Models\Lead;
use App\Domain\Identity\Models\User;

/**
 * `lead.view`, `lead.manage`, `lead.export` — Admin and Super Admin
 * (docs/ROLES_PERMISSIONS.md). A lead is academy-wide: it asked to hear from
 * the ACADEMY, and an instructor whose course page it was captured on does
 * not thereby hold somebody's email address.
 *
 * Export is its own key because a list read on screen and every address in
 * one file are different amounts of personal data to hand somebody.
 */
final class LeadPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('lead.view');
    }

    public function export(User $actor): bool
    {
        return $actor->hasPermission('lead.export');
    }

    /** For the list's `meta.can_manage` — the answer `update` and `delete` give every row. */
    public function manage(User $actor): bool
    {
        return $actor->hasPermission('lead.manage');
    }

    public function update(User $actor, Lead $lead): bool
    {
        return $this->manage($actor);
    }

    public function delete(User $actor, Lead $lead): bool
    {
        return $this->manage($actor);
    }
}
