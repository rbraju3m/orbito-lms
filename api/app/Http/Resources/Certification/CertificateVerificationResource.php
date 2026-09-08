<?php

declare(strict_types=1);

namespace App\Http\Resources\Certification;

use App\Domain\Certification\Models\Certificate;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * What a STRANGER is told. A separate resource from CertificateResource, not a
 * `when()` on it, for the reason ADR-06 gives: one resource with conditional
 * fields is one mistaken condition away from leaking.
 *
 * The audience here is somebody checking a claim on a CV. They are given
 * exactly enough to confirm or refute it and nothing more:
 *
 *  - NOT the verification token. They already hold it; echoing it back only
 *    puts a credential in one more log and one more browser history.
 *  - NOT the course id, slug or any link into the academy. Verifying a
 *    certificate is not an introduction to the catalogue, and a slug is an
 *    identifier this stranger has no business resolving.
 *  - NOT a PDF download. The authoritative answer is this page; handing out
 *    the document would let anyone with a token pull a file naming a person.
 *  - NOT the learner's email, id, or anything not already printed on the
 *    certificate the holder chose to show them.
 *
 * @mixin Certificate
 */
final class CertificateVerificationResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status->value,

            /*
             * The three states a checker must be able to tell apart. An
             * expired certificate was genuinely earned; collapsing it into
             * "invalid" would make an honest holder look like a forger, and a
             * revoked one must not read as merely lapsed.
             */
            'is_valid' => $this->isValid(),
            'has_expired' => $this->hasExpired(),
            'is_revoked' => ! $this->status->isValid(),

            'issued_at' => $this->issued_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),

            // The reason is deliberately omitted. "Revoked" is the fact a
            // checker needs; why is between the academy and the holder.

            'learner_name' => $this->snapshot['learner_name'] ?? null,
            'course_title' => $this->snapshot['course_title'] ?? null,
            'academy_name' => $this->snapshot['academy_name'] ?? null,
            'completed_at' => $this->snapshot['completed_at'] ?? null,
        ];
    }
}
