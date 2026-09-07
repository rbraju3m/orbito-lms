<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Support\Exceptions\DomainException;

final class TenantTransitionRejected extends DomainException
{
    public static function notPending(): self
    {
        return new self('Only an academy awaiting approval can be approved or rejected.');
    }

    public static function notSuspendable(): self
    {
        return new self('A rejected academy cannot be suspended.');
    }

    public static function notSuspended(): self
    {
        return new self('Only a suspended academy can be reactivated.');
    }

    public function errorCode(): string
    {
        return 'tenant_transition_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
