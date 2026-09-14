<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use App\Domain\Content\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A NEW address asked an academy to keep in touch. Fired once per lead — never
 * for a repeat submission of an address already on the list — so a script
 * resubmitting one address cannot flood an integration listening for this.
 */
final class LeadCaptured
{
    use Dispatchable;

    public function __construct(public readonly Lead $lead) {}
}
