<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

final class RoleAssignmentRejected extends DomainException
{
    public static function scopeRequired(string $role): self
    {
        return new self("The role [{$role}] is course-scoped and requires a scope.");
    }

    public static function scopeNotAllowed(string $role): self
    {
        return new self("The role [{$role}] is global and cannot be scoped to a resource.");
    }

    public static function scopeNotFound(string $type, int $id): self
    {
        return new self("No {$type} found with id {$id} to scope this role to.");
    }

    public static function lastSuperAdmin(): self
    {
        return new self('At least one Super Admin must remain.');
    }

    public function errorCode(): string
    {
        return 'role_assignment_rejected';
    }

    public function status(): int
    {
        return 422;
    }
}
