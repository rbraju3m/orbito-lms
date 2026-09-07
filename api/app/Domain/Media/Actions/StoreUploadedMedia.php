<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Events\MediaUploaded;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Validates and stores an uploaded file.
 *
 * The client's declared MIME type is never trusted: the type is re-derived
 * from the file's own bytes, and the stored name is generated rather than taken
 * from the upload.
 */
final class StoreUploadedMedia
{
    public function handle(
        User $owner,
        UploadedFile $file,
        MediaCollection $collection,
    ): Media {
        // getMimeType() sniffs content; getClientMimeType() is attacker-controlled.
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $size = $file->getSize() ?: 0;

        if (! in_array($mime, $collection->allowedMimes(), true)) {
            throw MediaRejected::mimeNotAllowed($mime, $collection->value);
        }

        if ($size > $collection->maxBytes()) {
            throw MediaRejected::tooLarge($size, $collection->maxBytes());
        }

        $disk = $collection->disk();
        $extension = $this->safeExtension($file, $mime);

        // Generated name, never the client's: the original could be
        // "../../evil.php" or collide with someone else's file.
        $filename = Str::uuid7()->toString().($extension !== '' ? '.'.$extension : '');
        $directory = $collection->value.'/'.now()->format('Y/m');

        $path = $file->storeAs($directory, $filename, ['disk' => $disk->value]);

        $dimensions = $this->imageDimensions($file, $mime);

        $media = Media::create([
            'owner_id' => $owner->id,
            'disk' => $disk,
            'path' => $path,
            'collection' => $collection->value,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $mime,
            'extension' => $extension,
            'size_bytes' => $size,
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'status' => MediaStatus::Ready,
        ]);

        MediaUploaded::dispatch($media);

        return $media;
    }

    /**
     * Derive the extension from the sniffed MIME type, not from the filename —
     * an uploaded "photo.php" must not keep its extension on disk.
     */
    private function safeExtension(UploadedFile $file, string $mime): string
    {
        $fromMime = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
        ][$mime] ?? null;

        if ($fromMime !== null) {
            return $fromMime;
        }

        $guessed = $file->guessExtension();

        return is_string($guessed) && preg_match('/^[a-z0-9]{1,8}$/i', $guessed) === 1
            ? strtolower($guessed)
            : 'bin';
    }

    /** @return array{width: int|null, height: int|null} */
    private function imageDimensions(UploadedFile $file, string $mime): array
    {
        if (! str_starts_with($mime, 'image/')) {
            return ['width' => null, 'height' => null];
        }

        $info = @getimagesize($file->getRealPath());

        return $info === false
            ? ['width' => null, 'height' => null]
            : ['width' => $info[0], 'height' => $info[1]];
    }
}
