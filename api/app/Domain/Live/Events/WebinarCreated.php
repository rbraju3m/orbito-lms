<?php

declare(strict_types=1);

namespace App\Domain\Live\Events;

use App\Domain\Live\Models\Webinar;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A webinar exists. Commerce gives a paid one its (dormant) product, because
 * a price hangs off a product and the publish rule wants a price — the same
 * reason a bundle's product is created while it is still a draft.
 */
final class WebinarCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Webinar $webinar) {}
}
