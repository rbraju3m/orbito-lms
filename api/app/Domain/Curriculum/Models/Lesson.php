<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Models;

use App\Domain\Curriculum\Enums\ContentFormat;
use App\Domain\Curriculum\Enums\VideoProvider;
use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property int $id
 * @property string|null $content
 * @property ContentFormat $content_format
 * @property VideoProvider $video_provider
 * @property int|null $video_media_id
 * @property string|null $video_url
 * @property int $video_duration_seconds
 */
final class Lesson extends Model
{
    protected $fillable = [
        'content', 'content_format', 'video_provider', 'video_media_id', 'video_url',
        'video_duration_seconds', 'video_poster_media_id', 'audio_media_id', 'document_media_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content_format' => ContentFormat::class,
            'video_provider' => VideoProvider::class,
        ];
    }

    /** @return MorphOne<CourseItem, $this> */
    public function item(): MorphOne
    {
        return $this->morphOne(CourseItem::class, 'itemable');
    }

    /** @return BelongsTo<Media, $this> */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'video_media_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'document_media_id');
    }
}
