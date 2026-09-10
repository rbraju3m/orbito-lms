<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\BundleStatus;
use App\Domain\Commerce\Models\Product;
use App\Domain\Media\Models\Media;
use App\Support\Database\LivesInTenantSchema;
use Carbon\CarbonInterface;
use Database\Factories\Catalog\BundleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

/**
 * Several courses, sold as one thing.
 *
 * A bundle owns NO content and appears in no access check. Buying one fans
 * out into an enrolment per course, so `CourseAccess` (ADR-03) still answers
 * "may they consume this?" from an enrolment and needs to know nothing about
 * bundles. What you bought, you keep — removing a course from a bundle later
 * takes nothing away from anyone. See docs/BUNDLES.md §1.
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $description
 * @property int|null $thumbnail_media_id
 * @property BundleStatus $status
 * @property CarbonInterface|null $published_at
 * @property-read Collection<int, Course> $courses
 */
final class Bundle extends Model
{
    /** @use HasFactory<BundleFactory> */
    use HasFactory, LivesInTenantSchema;

    protected $fillable = ['title', 'subtitle', 'description', 'thumbnail_media_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BundleStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $bundle): void {
            $bundle->uuid ??= (string) Str::uuid7();
            $bundle->slug ??= self::generateSlug($bundle->title);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Stable once assigned, for the same reason a course's is. */
    public static function generateSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'bundle';
        $slug = $base;
        $suffix = 1;

        while (self::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return Str::limit($slug, 190, '');
    }

    /** @return HasMany<BundleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class)->orderBy('position');
    }

    /**
     * The courses in it, in the order the author arranged them.
     *
     * @return BelongsToMany<Course, $this>
     */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'bundle_items')
            ->withPivot('position')
            ->orderBy('bundle_items.position');
    }

    /** @return BelongsTo<Media, $this> */
    public function thumbnail(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'thumbnail_media_id');
    }

    /** @return MorphOne<Product, $this> */
    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'purchasable');
    }

    /** @param  Builder<Bundle>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', BundleStatus::Published);
    }
}
