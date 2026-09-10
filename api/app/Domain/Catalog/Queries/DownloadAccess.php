<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\Models\Download;
use App\Domain\Catalog\Models\DownloadGrant;
use App\Domain\Identity\Models\User;

/**
 * The ONE answer to "may this person fetch this file?".
 *
 * Separate from `CourseAccess` (ADR-03) because it answers a different
 * question about a different thing — but built to the same rule: every gate
 * asks here, and nothing writes a second check.
 *
 * Status is deliberately NOT part of an owner's answer. Archiving a download
 * takes it off sale; it does not take it away from anyone who bought it.
 *
 * No memo, for the reason `CourseAccess` carries one in writing: Laravel's
 * container outlives a request under Octane, and a cached `granted` would
 * outlive a revoked grant (§ Phase 9).
 */
final class DownloadAccess
{
    public function for(?User $user, Download $download): DownloadDecision
    {
        if ($user === null) {
            return DownloadDecision::deny('unauthenticated');
        }

        // The people who stock the shelf can open what is on it.
        if ($user->hasPermission('download.manage')) {
            return DownloadDecision::grant('staff');
        }

        $grant = DownloadGrant::query()
            ->where('download_id', $download->id)
            ->where('user_id', $user->id)
            ->first();

        if ($grant === null) {
            return DownloadDecision::deny('not_owned');
        }

        return $grant->isActive()
            ? DownloadDecision::grant('owner', $grant)
            : DownloadDecision::deny('grant_revoked', $grant);
    }

    /**
     * Whether it may be SEEN at all. A published download is visible to every
     * member; an unpublished one only to those who can open it. Anybody else
     * gets a 404, because a draft nobody may see is indistinguishable from
     * one that does not exist.
     */
    public function isVisibleTo(?User $user, Download $download): bool
    {
        return $download->status->isLive() || $this->for($user, $download)->granted;
    }
}
