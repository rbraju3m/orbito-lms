<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Engagement\Events\AnnouncementPublished;
use App\Domain\Engagement\Models\Announcement;

/**
 * Moves an announcement from draft to published, once.
 *
 * The transition is what matters, not the column. An instructor editing a
 * published announcement saves it repeatedly; each of those must not read as a
 * fresh publication, or a typo fix notifies a thousand people.
 */
final class PublishAnnouncement
{
    public function handle(Announcement $announcement): Announcement
    {
        if ($announcement->isPublished()) {
            // Already out. Nothing to announce again.
            return $announcement;
        }

        $announcement->forceFill(['published_at' => now()])->save();

        AnnouncementPublished::dispatch($announcement->refresh());

        return $announcement;
    }

    /** Back to draft. Does not un-notify anybody — that cannot be undone. */
    public function unpublish(Announcement $announcement): Announcement
    {
        $announcement->forceFill(['published_at' => null])->save();

        return $announcement->refresh();
    }
}
