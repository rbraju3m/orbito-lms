<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use App\Domain\Catalog\Enums\BundleStatus;
use App\Domain\Catalog\Models\Bundle;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One event for every lifecycle move, with both ends of the transition — the
 * same shape as `CourseStatusChanged`, and for the same reason: a listener
 * that only knows the destination cannot tell "published" from "still
 * published".
 */
final class BundleStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Bundle $bundle,
        public readonly BundleStatus $from,
        public readonly BundleStatus $to,
        public readonly ?int $actorId = null,
    ) {}

    public function became(BundleStatus $status): bool
    {
        return $this->to === $status && $this->from !== $status;
    }

    public function left(BundleStatus $status): bool
    {
        return $this->from === $status && $this->to !== $status;
    }
}
