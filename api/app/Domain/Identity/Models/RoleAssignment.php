<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Support\Database\LivesInTenantSchema;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user holding a role, optionally scoped to one resource (ADR-07).
 *
 * `scope_type`/`scope_id` NULL = global. ('course', 42) = only on course 42.
 *
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property string|null $scope_type
 * @property int|null $scope_id
 * @property int|null $granted_by
 * @property CarbonInterface|null $expires_at
 */
final class RoleAssignment extends Model
{
    use LivesInTenantSchema;

    protected $fillable = ['user_id', 'role_id', 'scope_type', 'scope_id', 'granted_by', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isGlobal(): bool
    {
        return $this->scope_type === null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @param  Builder<RoleAssignment>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
