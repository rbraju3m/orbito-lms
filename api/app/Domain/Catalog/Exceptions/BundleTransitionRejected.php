<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Catalog\Enums\BundleStatus;
use App\Support\Exceptions\DomainException;

final class BundleTransitionRejected extends DomainException
{
    public static function illegal(BundleStatus $from, BundleStatus $to): self
    {
        return new self("A {$from->label()} bundle cannot become {$to->label()}.");
    }

    public function errorCode(): string
    {
        return 'bundle_transition_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
