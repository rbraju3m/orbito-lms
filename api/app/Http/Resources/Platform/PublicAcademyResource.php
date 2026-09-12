<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Platform\Models\Tenant;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * An academy as a STRANGER sees it: the header of its public site.
 *
 * Deliberately small, and the reason is the boundary rather than the screen.
 * The `tenants` row carries its status, its rejection reason, who approved it
 * and when, its support address and its subscription — an operator's view of a
 * customer. None of that is the public's business, so this resource lists what
 * IS rather than hiding what is not: a new column on `tenants` must be added
 * here on purpose before it reaches the internet (ADR-06, two resources per
 * model when the audience differs).
 *
 * `registration_open` is the only computed field, and it is here so the page
 * can offer a Sign up button or not. A closed academy still has a public site
 * — its courses are still worth reading about — it just has no way in.
 *
 * @mixin Tenant
 */
final class PublicAcademyResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            /*
             * ALWAYS null today: `tenants.logo_path` was declared with the
             * table in Phase 1 and nothing has ever written it — there is no
             * screen that uploads an academy logo (§ Known debt). The field is
             * here because the site's header reads it, so wiring the upload is
             * one Action and not also an API change; `url()` treats the value
             * as a path relative to the app, which is the convention to
             * revisit when the logo becomes a `Media` reference like every
             * other image in the product.
             */
            'logo_url' => $this->logo_path === null ? null : url($this->logo_path),
            // A support address IS public — it is on the page for a reason.
            'support_email' => $this->support_email,
            'registration_open' => $this->registrationMode()->allowsSelfSignup(),
        ];
    }
}
