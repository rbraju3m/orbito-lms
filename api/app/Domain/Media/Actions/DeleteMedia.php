<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Assessment\Models\AssignmentSubmissionFile;
use App\Domain\Catalog\Models\Download;
use App\Domain\Media\Events\MediaDeleted;
use App\Domain\Media\Exceptions\MediaInUse;
use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\Storage;

final class DeleteMedia
{
    public function handle(Media $media): void
    {
        /*
         * A file somebody PAID for is not deletable. This has to be a check
         * here, before anything is removed, and cannot be a foreign key: the
         * row below is SOFT-deleted, which no foreign key sees, and the bytes
         * go first. Any download, not only published ones — an archived one
         * still has owners.
         *
         * Media asking Catalog directly, on purpose: a guard must answer
         * synchronously, and an event cannot say no (the same argument as
         * `PlanLimits`).
         */
        $usedBy = Download::query()->where('media_id', $media->id)->value('title');

        if ($usedBy !== null) {
            throw MediaInUse::byDownload((string) $usedBy);
        }

        /*
         * Nor is a file somebody has HANDED IN — and its owner holds
         * `media.delete.own`. The submission copies the name and size, not the
         * bytes, so deleting this would leave whoever marks it a filename
         * pointing at nothing. Handed in is handed in.
         */
        if (AssignmentSubmissionFile::query()->where('media_id', $media->id)->exists()) {
            throw MediaInUse::bySubmission();
        }

        $ownerId = $media->owner_id;
        $size = $media->size_bytes;

        // Bytes go first; a soft-deleted row pointing at a file we still pay to
        // store is the worst of both worlds.
        Storage::disk($media->disk->value)->delete($media->path);

        foreach ($media->variants as $variant) {
            Storage::disk($media->disk->value)->delete($variant->path);
        }

        $media->delete();

        MediaDeleted::dispatch($ownerId, $size);
    }
}
