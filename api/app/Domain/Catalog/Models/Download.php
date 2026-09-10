<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\DownloadPricing;
use App\Domain\Catalog\Enums\DownloadStatus;
use App\Domain\Commerce\Models\Product;
use App\Domain\Media\Models\Media;
use App\Support\Database\LivesInTenantSchema;
use Carbon\CarbonInterface;
use Database\Factories\Catalog\DownloadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

/**
 * A file an academy sells.
 *
 * The file is LIVE: replacing it — v2 of an eBook — reaches every buyer on
 * their next fetch. The order line is the snapshot, not this. That is the
 * opposite of `order_items.title_snapshot`, and deliberate.
 *
 * Owned by the academy, not by an instructor (docs/DOWNLOADS.md §1), so there
 * is no owner column and no `.own` permission.
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $description
 * @property int|null $media_id
 * @property int|null $thumbnail_media_id
 * @property DownloadPricing $pricing_model
 * @property DownloadStatus $status
 * @property CarbonInterface|null $published_at
 */
final class Download extends Model
{
    /** @use HasFactory<DownloadFactory> */
    use HasFactory, LivesInTenantSchema;

    protected $fillable = [
        'title', 'subtitle', 'description', 'media_id', 'thumbnail_media_id', 'pricing_model',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pricing_model' => DownloadPricing::class,
            'status' => DownloadStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $download): void {
            $download->uuid ??= (string) Str::uuid7();
            $download->slug ??= self::generateSlug($download->title);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Stable once assigned: a download page's URL is shared and bookmarked. */
    public static function generateSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'download';
        $slug = $base;
        $suffix = 1;

        while (self::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return Str::limit($slug, 190, '');
    }

    /** @return BelongsTo<Media, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function thumbnail(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'thumbnail_media_id');
    }

    /** @return HasMany<DownloadGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(DownloadGrant::class);
    }

    /** @return MorphOne<Product, $this> */
    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'purchasable');
    }

    /** @param  Builder<Download>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', DownloadStatus::Published);
    }
}
