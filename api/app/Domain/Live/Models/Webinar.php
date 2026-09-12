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
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

/**
 * A standalone live event, not part of any course.
 *
 * READING about one is now PUBLIC and REGISTERING is still members-only, and
 * the split is the honest one rather than a half-finished migration: a
 * stranger can read a published webinar's page on the academy's public site
 * (`tenant.public`), and holding a place needs an account because a place is
 * a thing somebody has to be TOLD about when the event moves or is called off
 * — and delivery is to a central account, not to an email (§ Multi-tenancy).
 * A guest place is therefore a bigger feature than a form: it needs a
 * mail-only delivery for `NotifyOnWebinarCancelled` first.
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property int|null $live_session_id
 * @property int|null $capacity
 * @property bool $is_paid
 * @property WebinarStatus $status
 */
final class Webinar extends Model
{
    /** @use HasFactory<WebinarFactory> */
    use HasFactory;

    protected $fillable = [
        'slug', 'title', 'description', 'live_session_id',
        'capacity', 'is_paid', 'status',
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

    /**
     * The sellable twin, when there is one.
     *
     * A MORPH from the product's side, like a bundle's and a download's, and
     * NOT a `product_id` on this row: `products.purchasable_type/id` already
     * names the webinar, and a second link between the same two rows is a
     * second thing to keep in step. `SyncWebinarProduct` is the only writer.
     *
     * @return MorphOne<Product, $this>
     */
    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'purchasable');
    }

    /** @return HasMany<WebinarRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }

    /**
     * Null means uncapped, which is not the same as zero places left.
     *
     * Counts LIVE registrations only, because that is what
     * `RegisterForWebinar::assertHasRoom()` counts — cancelling frees a place,
     * and a list that said otherwise would show an event as full that the
     * register endpoint would happily accept (§ Patterns established in Phase
     * 9: a rule with two paths must agree).
     *
     * Reads `registered_count` when the caller eager-loaded it. Without that
     * the webinar list is one COUNT per row.
     */
    public function placesRemaining(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        $attributes = $this->getAttributes();

        $taken = array_key_exists('registered_count', $attributes)
            ? (int) $attributes['registered_count']
            : $this->registrations()->where('status', WebinarRegistration::STATUS_REGISTERED)->count();

        return max(0, $this->capacity - $taken);
    }

    /**
     * Whether a place has to be BOUGHT.
     *
     * `is_paid` is the academy's intent and the price is what makes it
     * chargeable; a webinar marked paid with no price is not sellable and
     * cannot be published either (`WebinarPublishRules`).
     */
    public function isPaid(): bool
    {
        return $this->is_paid;
    }

    /** @param  Builder<Webinar>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', WebinarStatus::Published);
    }
}
