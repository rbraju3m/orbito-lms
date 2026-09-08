<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A course's rating may have moved.
 *
 * Carries the COURSE ID rather than the Review, deliberately: it fires for
 * deletions too, where there is no longer a review to hand anybody, and the
 * only thing every listener needs is which course to recount.
 */
final class ReviewChanged
{
    use Dispatchable;

    public function __construct(public readonly int $courseId) {}
}
