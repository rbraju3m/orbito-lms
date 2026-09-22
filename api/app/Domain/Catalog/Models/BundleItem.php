<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Database\LivesInTenantSchema;
use Database\Factories\Catalog\BundleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One course's, or one download's, membership of one bundle.
 *
 * Exactly one of `course_id` / `download_id` is set — a CHECK enforces it, so
 * a row naming both or neither cannot exist (docs/BUNDLES.md §9).
 *
 * @property int $id
 * @property int $bundle_id
 * @property int|null $course_id
 * @property int|null $download_id
 * @property int $position
 */
final class BundleItem extends Model
{
    /** @use HasFactory<BundleItemFactory> */
    use HasFactory, LivesInTenantSchema;

    protected $fillable = ['bundle_id', 'course_id', 'download_id', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Bundle, $this> */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Download, $this> */
    public function download(): BelongsTo
    {
        return $this->belongsTo(Download::class);
    }
}
