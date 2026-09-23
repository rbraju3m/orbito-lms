<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use App\Domain\Platform\Enums\Locale;
use App\Domain\Platform\Enums\RegistrationMode;
use App\Domain\Platform\Enums\TenantStatus;
use Carbon\CarbonInterface;
use Database\Factories\Platform\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * An academy. One row here, one MySQL schema behind it.
 *
 * Lives on the CENTRAL connection — it is the thing that decides which tenant
 * connection exists, so it can never be read through one. The pin below is
 * the invariant `CentralModelConnectionTest` enforces.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property TenantStatus $status
 * @property bool $is_active
 * @property string|null $support_email
 * @property CarbonInterface|null $approved_at
 * @property int|null $approved_by
 * @property string|null $suspended_reason
 * @property string|null $rejected_reason
 * @property string|null $registration_mode read through registrationMode()
 * @property int|null $logo_media_id read through logoUrl()
 * @property string|null $default_locale read through defaultLocale()
 * @property array<int, string>|null $enabled_locales read through enabledLocales()
 */
final class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $connection = 'mysql';

    /**
     * Columns that are REAL columns rather than keys inside `data`.
     *
     * Anything the platform filters, sorts or joins on has to be listed here;
     * everything else is virtual and lives in the JSON blob, which is what
     * lets an academy carry settings we have not thought of yet.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'slug',
            'name',
            'status',
            'is_active',
            'support_email',
            'approved_at',
            'approved_by',
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Whether this academy may be entered at all.
     *
     * Deliberately narrower than `is_active`: a tenant awaiting approval has
     * a row and a schema but no way in, and one that is suspended keeps both
     * so the operator can reinstate it without a restore.
     */
    public function isOpen(): bool
    {
        return $this->is_active && $this->status === TenantStatus::Active;
    }

    /**
     * Who may self-register here.
     *
     * Virtual, in the `data` blob, because nothing filters or sorts on it —
     * the rule this model already states for its own columns. A missing value
     * means the default rather than a state, so a mode added later reaches
     * every academy that never touched the switch and no backfill is needed
     * (the same shape as notification preferences).
     */
    public function logoMediaId(): ?int
    {
        $stored = $this->logo_media_id;

        return is_numeric($stored) ? (int) $stored : null;
    }

    /**
     * Where the academy's logo is served from, or null for none.
     *
     * The id lives HERE, on the central row, in the `data` blob — the place an
     * academy's own settings already live. The file lives in the academy's own
     * `media` table. So this answers only while THIS academy is the one open:
     * with another academy's connection active, the same id names somebody
     * else's file, and drawing it would put one academy's picture in another's
     * header (§ Multi-tenancy — which connection is this running on?).
     *
     * A deleted file reads as no logo. `Media` soft-deletes, and the query
     * does not see a soft-deleted row, so nothing has to clear the id first.
     */
    public function logoUrl(): ?string
    {
        $id = $this->logoMediaId();

        if ($id === null || tenant()?->getTenantKey() !== $this->getTenantKey()) {
            return null;
        }

        return Media::query()
            ->whereKey($id)
            ->where('collection', MediaCollection::AcademyLogo->value)
            ->first()
            ?->publicUrl();
    }

    public function registrationMode(): RegistrationMode
    {
        $stored = $this->registration_mode;

        return is_string($stored)
            ? RegistrationMode::tryFrom($stored) ?? RegistrationMode::default()
            : RegistrationMode::default();
    }

    /**
     * The languages this academy's interface may speak — what the
     * installation supports, narrowed by what the academy chose, and never
     * without its own default. In the `data` blob: nothing filters on it.
     *
     * Never chosen means everything supported, so a language added to the
     * installation reaches an academy that never touched the setting.
     *
     * @return list<Locale>
     */
    public function enabledLocales(): array
    {
        $supported = Locale::supported();
        $stored = $this->enabled_locales;

        if (! is_array($stored) || $stored === []) {
            return $supported;
        }

        $chosen = array_map(Locale::fromTag(...), $stored);
        $enabled = array_values(array_filter($supported, fn (Locale $locale): bool => in_array($locale, $chosen, true)));

        $default = $this->chosenDefaultLocale();

        if ($default !== null && ! in_array($default, $enabled, true)) {
            $enabled[] = $default;
        }

        return $enabled === [] ? $supported : $enabled;
    }

    /** The language a reader gets when they have not chosen one. */
    public function defaultLocale(): Locale
    {
        return $this->chosenDefaultLocale() ?? Locale::supported()[0];
    }

    /**
     * The default the academy actually PICKED, or null. The resolver asks
     * this one: an academy that never chose should not outrank a reader's
     * browser with a default nobody decided on.
     */
    public function chosenDefaultLocale(): ?Locale
    {
        $stored = Locale::fromTag(is_string($this->default_locale) ? $this->default_locale : null);

        return $stored !== null && in_array($stored, Locale::supported(), true) ? $stored : null;
    }
}
