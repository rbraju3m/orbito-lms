<?php

declare(strict_types=1);

namespace App\Http\Resources\Content;

use App\Domain\Content\Models\Lead;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A lead, for the academy staff who hold `lead.view`. Never rendered on the
 * public surface: the stranger who submitted one is answered with nothing
 * about it (`PublicSite\LeadController`).
 *
 * @mixin Lead
 */
final class LeadResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            // The course or event title as it was when they asked; null for
            // the front page.
            'source_title' => $this->source_title,
            'consent_text' => $this->consent_text,
            'consented_at' => $this->consented_at->toIso8601String(),
            'submissions_count' => $this->submissions_count,
            'first_submitted_at' => $this->created_at->toIso8601String(),
            'last_submitted_at' => $this->last_submitted_at->toIso8601String(),
        ];
    }
}
