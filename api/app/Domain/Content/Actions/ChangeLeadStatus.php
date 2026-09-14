<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\LeadStatus;
use App\Domain\Content\Models\Lead;

/** An academy's own bookkeeping about a lead. Any status may follow any other. */
final class ChangeLeadStatus
{
    public function handle(Lead $lead, LeadStatus $status): Lead
    {
        if ($lead->status !== $status) {
            $lead->status = $status;
            $lead->save();
        }

        return $lead;
    }
}
