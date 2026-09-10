<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Database\LivesInTenantSchema;
use Database\Factories\Catalog\BundleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One course's membership of one bundle.
 *
 * @property int $id
 * @property int $bundle_id
 * @property int $course_id
 * @property int $position
 */
final class BundleItem extends Model
{
    /** @use HasFactory<BundleItemFactory> */
    use HasFactory, LivesInTenantSchema;

    protected $fillable = ['bundle_id', 'course_id', 'position'];

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
}
