<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\RoleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property RoleScope $scope_kind
 * @property bool $is_system
 */
final class Role extends Model
{
    protected $fillable = ['key', 'name', 'description', 'scope_kind', 'is_system'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope_kind' => RoleScope::class,
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** @return HasMany<RoleAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function isCourseScoped(): bool
    {
        return $this->scope_kind === RoleScope::Course;
    }
}
