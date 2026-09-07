<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use App\Domain\Catalog\Models\Course;
use Illuminate\Foundation\Events\Dispatchable;

final class CourseCreated
{
    use Dispatchable;

    public function __construct(public readonly Course $course) {}
}
