<?php

declare(strict_types=1);

namespace App\Domain\Media\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;

final class MediaPolicy
{
    public function upload(User $actor): bool
    {
        return $actor->hasPermission('media.upload');
    }

    /**
     * Public files are readable by anyone. Private files are readable by their
     * owner and by staff — and, from Phase 9, by anyone CourseAccess grants.
     */
    public function view(User $actor, Media $media): bool
    {
        if ($media->isPublic()) {
            return true;
        }

        return $media->owner_id === $actor->id
            || $actor->hasPermission('media.library.view.any');
    }

    public function delete(User $actor, Media $media): bool
    {
        return ($media->owner_id === $actor->id && $actor->hasPermission('media.delete.own'))
            || $actor->hasPermission('media.delete.any');
    }

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('media.library.view.any');
    }
}
