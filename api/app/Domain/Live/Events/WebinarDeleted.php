<?php

declare(strict_types=1);

namespace App\Domain\Live\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A webinar is gone, so its product must stop being sellable at once — a
 * basket still holding it would otherwise check out, take the money and find
 * nothing to deliver. The bundles slice shipped without this and it was a bug.
 *
 * Carries the ID, not the model: the row no longer exists, and a listener that
 * tried to re-read it would find nothing.
 */
final class WebinarDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $webinarId) {}
}
