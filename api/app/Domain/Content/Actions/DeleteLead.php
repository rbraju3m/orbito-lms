<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Lead;

/**
 * Erases a lead — the answer to "delete my details", which the consent wording
 * promises is available. A HARD delete: a soft-deleted row is a copy of the
 * personal data somebody asked to be rid of, and nothing refers to a lead that
 * would need it kept.
 *
 * What already left through a `lead.captured` webhook is the receiving
 * system's to erase; the admin screen says so.
 */
final class DeleteLead
{
    public function handle(Lead $lead): void
    {
        $lead->delete();
    }
}
