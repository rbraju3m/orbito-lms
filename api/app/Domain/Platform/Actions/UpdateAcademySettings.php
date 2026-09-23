<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Media\Actions\DeleteMedia;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use App\Domain\Platform\Enums\RegistrationMode;
use App\Domain\Platform\Models\Tenant;

/**
 * The settings an academy changes about itself.
 *
 * Deliberately narrow. `slug` names the academy's database for its whole life
 * and `status` is the platform operator's lever, so neither is reachable from
 * here — an academy cannot reopen itself after being suspended, which is the
 * point of suspension.
 */
final class UpdateAcademySettings
{
    public function __construct(private readonly DeleteMedia $deleteMedia) {}

    /** @param array{registration_mode?: string, support_email?: string|null, logo_media_id?: int|null, default_locale?: string, enabled_locales?: list<string>} $changes */
    public function handle(Tenant $academy, array $changes): Tenant
    {
        $replacedLogo = null;

        if (array_key_exists('registration_mode', $changes)) {
            // Stored in the `data` blob as the string the enum round-trips.
            $academy->registration_mode = RegistrationMode::from(
                $changes['registration_mode']
            )->value;
        }

        if (array_key_exists('support_email', $changes)) {
            $academy->support_email = $changes['support_email'];
        }

        // Validated against each other in the request; stored as codes in
        // the `data` blob and read back through Tenant::enabledLocales().
        if (array_key_exists('enabled_locales', $changes)) {
            $academy->enabled_locales = array_values(array_unique($changes['enabled_locales']));
        }

        if (array_key_exists('default_locale', $changes)) {
            $academy->default_locale = $changes['default_locale'];
        }

        if (array_key_exists('logo_media_id', $changes)) {
            $previous = $academy->logoMediaId();
            $next = $changes['logo_media_id'] === null ? null : (int) $changes['logo_media_id'];

            // In the `data` blob, like `registration_mode`: nothing filters on it.
            $academy->logo_media_id = $next;
            $replacedLogo = $previous !== null && $previous !== $next ? $previous : null;
        }

        $academy->save();

        /*
         * A logo taken down or replaced is DELETED, after the save, so a
         * failed save never leaves the header pointing at nothing. Nothing
         * else can use a file from this collection, and a picture nobody can
         * see again would sit on the academy's storage figure for ever (§
         * Patterns established in Phase 16: a form that removes an uploaded
         * file must delete it).
         */
        if ($replacedLogo !== null) {
            $old = Media::query()
                ->whereKey($replacedLogo)
                ->where('collection', MediaCollection::AcademyLogo->value)
                ->first();

            if ($old !== null) {
                $this->deleteMedia->handle($old);
            }
        }

        return $academy->refresh();
    }
}
