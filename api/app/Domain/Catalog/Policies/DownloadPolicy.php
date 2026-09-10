<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Download;
use App\Domain\Identity\Models\User;

/**
 * One permission for the authoring surface: `download.manage`. No `.own`
 * variant, because a download belongs to the academy (docs/DOWNLOADS.md §1).
 *
 * Who may FETCH a file is not a policy question — that is `DownloadAccess`,
 * the same split `CourseAccess` makes from `CoursePolicy` (ADR-03).
 */
final class DownloadPolicy
{
    public function viewAny(?User $actor): bool
    {
        return true;
    }

    public function view(?User $actor, Download $download): bool
    {
        return $download->status->isLive()
            || ($actor !== null && $actor->hasPermission('download.manage'));
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('download.manage');
    }

    public function update(User $actor, Download $download): bool
    {
        return $actor->hasPermission('download.manage');
    }

    public function delete(User $actor, Download $download): bool
    {
        return $actor->hasPermission('download.manage');
    }

    public function publish(User $actor, Download $download): bool
    {
        return $actor->hasPermission('download.manage');
    }

    public function price(User $actor, Download $download): bool
    {
        return $actor->hasPermission('download.manage');
    }
}
