<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Media\Events\MediaDeleted;
use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\Storage;

final class DeleteMedia
{
    public function handle(Media $media): void
    {
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
