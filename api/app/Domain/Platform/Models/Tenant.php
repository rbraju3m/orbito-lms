<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Identity\Models\User;
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
 * @property string|null $logo_path
 * @property string|null $support_email
 * @property CarbonInterface|null $approved_at
 * @property int|null $approved_by
 * @property string|null $suspended_reason
 * @property string|null $rejected_reason
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
            'logo_path',
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
}
