<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/**
 * A language the INTERFACE can speak (docs/I18N.md).
 *
 * A case here is what the product knows how to speak; `orbito.locales.supported`
 * is which of those an installation offers, and an academy's
 * `enabled_locales` narrows that again. A code in the config with no case
 * here is ignored rather than trusted — there is no direction to give it.
 *
 * Adding a right-to-left language is a case with `TextDirection::Rtl`, a font
 * and a browser pass. If it needs more than that, the foundation is wrong.
 */
enum Locale: string
{
    case English = 'en';
    case Bengali = 'bn';

    /** The name a reader of that language recognises — the switcher's label. */
    public function nativeName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Bengali => 'বাংলা',
        };
    }

    public function direction(): TextDirection
    {
        return match ($this) {
            self::English, self::Bengali => TextDirection::Ltr,
        };
    }

    /**
     * What this installation offers, in the config's order.
     *
     * @return list<self>
     */
    public static function supported(): array
    {
        /** @var array<int, string> $codes */
        $codes = (array) config('orbito.locales.supported', []);

        $supported = [];

        foreach ($codes as $code) {
            $locale = self::tryFrom(strtolower(trim($code)));

            if ($locale !== null && ! in_array($locale, $supported, true)) {
                $supported[] = $locale;
            }
        }

        return $supported === [] ? [self::fallback()] : $supported;
    }

    /** The last resort, whatever anybody asked for. */
    public static function fallback(): self
    {
        return self::tryFrom((string) config('orbito.locales.default')) ?? self::English;
    }

    /**
     * A tag as a browser or a person writes it — `bn-BD`, `EN_us`, `bn` — reduced
     * to a case, or null. Only the primary subtag decides: there is one
     * Bengali here, not one per country.
     */
    public static function fromTag(?string $tag): ?self
    {
        if ($tag === null || $tag === '') {
            return null;
        }

        $primary = strtolower((string) preg_split('/[-_]/', trim($tag))[0]);

        return self::tryFrom($primary);
    }

    /** @return array{code: string, native_name: string, direction: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->value,
            'native_name' => $this->nativeName(),
            'direction' => $this->direction()->value,
        ];
    }
}
