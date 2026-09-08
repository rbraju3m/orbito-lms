<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores a file the SERVER produced — a rendered PDF, an export.
 *
 * Separate from `StoreUploadedMedia` because the threat model is inverted.
 * That action's whole job is distrusting a client: sniff the real MIME,
 * discard the supplied filename, cap the size. Here we made the bytes, so
 * there is nothing to distrust — and pretending otherwise by routing generated
 * content through an UploadedFile would mean writing a temp file just to
 * re-read it.
 *
 * What is NOT relaxed is the collection's rules. The MIME must still be one
 * the collection accepts and the size cap still applies, because a renderer
 * that produces a 400MB PDF is a bug that should stop here rather than fill a
 * disk.
 */
final class StoreGeneratedMedia
{
    public function handle(
        /* Not nullable: `media.owner_id` is NOT NULL, and MediaPolicy reads
         * it. A generated file still belongs to somebody — a certificate PDF
         * belongs to the learner it names. */
        int $ownerId,
        string $contents,
        MediaCollection $collection,
        string $mime,
        string $extension,
        string $name,
    ): Media {
        if (! in_array($mime, $collection->allowedMimes(), true)) {
            throw MediaRejected::mimeNotAllowed($mime, $collection->value);
        }

        $size = strlen($contents);

        if ($size > $collection->maxBytes()) {
            throw MediaRejected::tooLarge($size, $collection->maxBytes());
        }

        $disk = $collection->disk();
        $path = $collection->value.'/'.now()->format('Y/m').'/'.Str::uuid7()->toString().'.'.$extension;

        Storage::disk($disk->value)->put($path, $contents);

        return Media::create([
            'owner_id' => $ownerId,
            'disk' => $disk,
            'path' => $path,
            'collection' => $collection->value,
            'original_name' => Str::limit($name, 250, ''),
            'mime' => $mime,
            'extension' => $extension,
            'size_bytes' => $size,
            'width' => null,
            'height' => null,
            'status' => MediaStatus::Ready,
        ]);
    }
}
