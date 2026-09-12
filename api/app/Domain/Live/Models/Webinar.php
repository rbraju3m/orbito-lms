<?php

declare(strict_types=1);

namespace App\Domain\Live\Models;

use App\Domain\Commerce\Models\Product;
use App\Domain\Live\Enums\WebinarStatus;
use Database\Factories\Live\WebinarFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A standalone live event, not part of any course.
 *
 * REGISTRATION IS MEMBERS-ONLY, and that is a consequence of the tenancy
 * design rather than a product decision: tenancy resolves from the
 * authenticated user, so there is no anonymous surface to register from
 * (§ Multi-tenancy). A webinar open to the whole academy is still worth
 * having — it is the one live format that is not gated on buying a course —
 * and the public path arrives with the marketing site in P16, which is when
 * there will be somewhere to register FROM.
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property int|null $live_session_id
 * @property int|null $capacity
 * @property bool $is_paid
 * @property int|null $product_id
 * @property WebinarStatus $status
 */
final class Webinar extends Model
{
    /** @use HasFactory<WebinarFactory> */
    use HasFactory;

    protected $fillable = [
        'slug', 'title', 'description', 'live_session_id',
        'capacity', 'is_paid', 'product_id', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'capacity' => 'integer',
            'status' => WebinarStatus::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $webinar): void {
            $webinar->uuid ??= (string) Str::uuid7();
            $webinar->slug ??= self::generateSlug($webinar->title);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Stable once assigned, for the same reason a course's is: a link handed
     * out for an event is a link somebody keeps until the event happens.
     */
    public static function generateSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'webinar';
        $slug = $base;
        $suffix = 1;

        while (self::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return Str::limit($slug, 190, '');
    }

    /**
     * Whether anybody's record depends on this.
     *
     * A webinar with registrations is CANCELLED, never deleted — the place
     * somebody holds is a fact about them, and the academy calling the event
     * off still needs to know who to tell. Same shape, and the same reasoning,
     * as `Cohort::isInUse()`; checked on the raw attributes because strict
     * mode throws on a count that was never loaded.
     */
    public function isInUse(): bool
    {
        $attributes = $this->getAttributes();

        if (array_key_exists('registrations_count', $attributes)) {
            return (int) $attributes['registrations_count'] > 0;
        }

        return $this->registrations()->exists();
    }

    /** @return BelongsTo<LiveSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<WebinarRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }

    /** Null means uncapped, which is not the same as zero places left. */
    public function placesRemaining(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return max(0, $this->capacity - $this->registrations()->count());
    }

    /** @param  Builder<Webinar>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', WebinarStatus::Published);
    }
}
