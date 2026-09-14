<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicSite;

use App\Domain\Content\Actions\CaptureLead;
use App\Domain\Content\Support\LeadConsent;
use App\Domain\Content\Support\LeadFormToken;
use App\Domain\Platform\Models\Tenant;
use App\Http\Requests\Content\SubmitLeadRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The lead form — the ONE anonymous write in the product (docs/LEADS.md).
 *
 * THE ANSWER IS THE SAME WHATEVER HAPPENED. A new lead, an address already on
 * the list, a filled honeypot and a form posted too fast all get one 202 with
 * one body. Anything more specific is an oracle: "already subscribed" tells a
 * stranger whose email is on an academy's list, and "rejected" tells a script
 * which check to work around. The same instinct as the public site's single
 * 404 for an unknown academy and a closed one.
 */
final class LeadController
{
    /**
     * The form's token and the consent wording. `no-store`, because a token
     * served from a cache is a token somebody else was issued.
     */
    public function form(Request $request, LeadFormToken $tokens): JsonResponse
    {
        $academy = $this->academy($request);

        return ApiResponse::ok([
            'token' => $tokens->mint($academy->slug, now()),
            'consent_text' => LeadConsent::for($academy->name),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(SubmitLeadRequest $request, CaptureLead $capture): JsonResponse
    {
        $academy = $this->academy($request);

        if (! $request->isTrap()) {
            $capture->handle($request->submission(LeadConsent::for($academy->name)));
        }

        return ApiResponse::accepted(['received' => true]);
    }

    /** Resolved by `tenant.public` on the central connection; see AcademyController. */
    private function academy(Request $request): Tenant
    {
        $academy = $request->attributes->get('academy');

        abort_unless($academy instanceof Tenant, 404);

        return $academy;
    }
}
