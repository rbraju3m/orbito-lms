<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\Locale;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Http\Request;

/**
 * The ONLY answer to "which language does this reader get?" (docs/I18N.md §2).
 *
 * In order, the first one the academy has enabled:
 *
 *   1. `?locale=` — an explicit ask for this one request
 *   2. the person's own choice (`users.locale`)
 *   3. the academy's default, if it picked one
 *   4. the browser's `Accept-Language`, in its order of preference
 *   5. the installation's fallback
 *
 * A person's choice beats the academy's, which beats a browser guess. An
 * unknown or disabled code is SKIPPED, never an error: a preference that
 * cannot be honoured falls through to the next one, so an academy switching
 * a language off does not break anybody who had chosen it.
 *
 * The SPA never decides this for itself — it reads the answer off `/auth/me`
 * and the public academy payload — so the client and the mail a person is
 * sent cannot disagree about their language.
 */
final class LocaleResolver
{
    public function resolve(Request $request, ?User $user, ?Tenant $academy): Locale
    {
        $enabled = $academy?->enabledLocales() ?? Locale::supported();

        $query = $request->query('locale');

        $candidates = [
            Locale::fromTag(is_string($query) ? $query : null),
            Locale::fromTag($user?->locale),
            $academy?->chosenDefaultLocale(),
            ...array_map(Locale::fromTag(...), $request->getLanguages()),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && in_array($candidate, $enabled, true)) {
                return $candidate;
            }
        }

        return $academy?->defaultLocale() ?? Locale::fallback();
    }

    /**
     * The block `/auth/me` and the public academy carry: what the reader gets,
     * and what they could switch to — the list's-`meta` rule (§ Patterns
     * established in Phase 12), so a switcher never offers a language the
     * profile endpoint would refuse.
     *
     * @return array{code: string, native_name: string, direction: string, available: list<array{code: string, native_name: string, direction: string}>}
     */
    public function describe(Request $request, ?User $user, ?Tenant $academy): array
    {
        return [
            ...$this->resolve($request, $user, $academy)->toArray(),
            'available' => array_map(
                fn (Locale $locale): array => $locale->toArray(),
                $academy?->enabledLocales() ?? Locale::supported(),
            ),
        ];
    }
}
