<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Models;

use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A downloadable or linked resource. Not completable — a learner does not
 * "finish" a PDF, so it does not count toward course progress.
 *
 * @property int $id
 * @property string|null $description
 * @property int|null $media_id
 * @property string|null $external_url
 * @property bool $download_allowed
 */
final class Resource extends Model
{
    protected $fillable = ['description', 'media_id', 'external_url', 'download_allowed'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['download_allowed' => 'boolean'];
    }

    /** @return MorphOne<CourseItem, $this> */
    public function item(): MorphOne
    {
        return $this->morphOne(CourseItem::class, 'itemable');
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
