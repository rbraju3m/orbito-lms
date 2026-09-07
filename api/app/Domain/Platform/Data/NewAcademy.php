<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

use App\Domain\Platform\Models\Plan;

/**
 * Everything needed to stand up an academy, in one object rather than eight
 * positional arguments that a caller can transpose.
 */
final readonly class NewAcademy
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerPassword,
        public ?string $supportEmail = null,
        public ?Plan $plan = null,
    ) {}
}
