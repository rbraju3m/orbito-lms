<?php

declare(strict_types=1);

namespace App\Http\Resources\Certification;

use App\Domain\Certification\Models\Certificate;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A certificate as its HOLDER or academy staff sees it.
 *
 * Carries `verification_url` — the thing the holder actually wants to share —
 * and therefore the token, because the URL contains it. That is correct here
 * and NOT correct on the public page: this response only ever reaches someone
 * the policy has already allowed, and the whole point of a certificate is
 * being able to hand somebody the link.
 *
 * @mixin Certificate
 */
final class CertificateResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Three separate facts, because they answer different questions:
            // is it currently good, was it withdrawn, has it simply lapsed.
            'is_valid' => $this->isValid(),
            'has_expired' => $this->hasExpired(),

            'issued_at' => $this->issued_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_reason' => $this->revoked_reason,

            'learner_name' => $this->snapshot['learner_name'] ?? null,
            'course_title' => $this->snapshot['course_title'] ?? null,
            'academy_name' => $this->snapshot['academy_name'] ?? null,
            'completed_at' => $this->snapshot['completed_at'] ?? null,

            'course' => $this->whenLoaded('course', fn () => [
                'id' => $this->course->uuid,
                'slug' => $this->course->slug,
                'title' => $this->course->title,
            ]),

            /*
             * Null while the render is still queued. A certificate is VALID
             * before its PDF exists, so the UI shows it with the download
             * pending rather than hiding the certificate.
             */
            'has_pdf' => $this->pdf_media_id !== null,

            'verification_url' => $this->verificationUrl(),
        ];
    }

    /**
     * The academy id is in the path because this page has no authenticated
     * user to resolve one from (§16), and it is the immutable uuid rather than
     * the slug because this URL gets PRINTED — an academy renaming itself must
     * not invalidate paper already in the world.
     */
    private function verificationUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/verify/'.tenant()?->getTenantKey()
            .'/'.$this->verification_token;
    }
}
