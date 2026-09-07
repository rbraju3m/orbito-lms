<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class CourseDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $courseId,
        public readonly int $ownerId,
        public readonly bool $wasPublished,
    ) {}
}
