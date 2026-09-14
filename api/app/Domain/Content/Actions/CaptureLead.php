<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\LeadSubmission;
use App\Domain\Content\Enums\LeadStatus;
use App\Domain\Content\Events\LeadCaptured;
use App\Domain\Content\Models\Lead;

/**
 * Records that somebody asked an academy to keep in touch.
 *
 * ONE row per address, enforced by the unique index and not by a lookup:
 * `createOrFirst` inserts and catches the violation, so two submissions racing
 * each other still make one row (§ Phase 14: make idempotency a constraint).
 *
 * A REPEAT is somebody typing an address that is already on the list — which
 * may not be the person it belongs to. So a repeat may move the count and the
 * date and nothing a stranger could use to rewrite somebody else's record: it
 * fills a name only where there was none, and never touches the consent, the
 * source or the status an academy set. And it fires nothing, so a script
 * resubmitting one address cannot flood an academy's integrations.
 *
 * The caller answers a new lead and a repeat IDENTICALLY — see
 * `PublicSite\LeadController` — so this return value must never reach a
 * response.
 */
final class CaptureLead
{
    public function handle(LeadSubmission $submission): Lead
    {
        $now = now();

        $lead = Lead::query()->createOrFirst(
            ['email' => Lead::normaliseEmail($submission->email)],
            [
                'name' => $submission->name,
                'status' => LeadStatus::New,
                'source' => $submission->source,
                'source_id' => $submission->sourceId,
                'source_title' => $submission->sourceTitle,
                'consent_text' => $submission->consentText,
                'consented_at' => $now,
                'submissions_count' => 1,
                'last_submitted_at' => $now,
            ],
        );

        if ($lead->wasRecentlyCreated) {
            LeadCaptured::dispatch($lead);

            return $lead;
        }

        $lead->submissions_count++;
        $lead->last_submitted_at = $now;
        $lead->name ??= $submission->name;
        $lead->save();

        return $lead;
    }
}
