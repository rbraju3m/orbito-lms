<?php

declare(strict_types=1);

namespace App\Domain\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $media_id
 * @property string $kind
 * @property string $path
 */
final class MediaVariant extends Model
{
    protected $fillable = ['media_id', 'kind', 'path', 'mime', 'size_bytes', 'width', 'height', 'bitrate'];

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
