<?php

declare(strict_types=1);

namespace App\Domain\Live\Enums;

use App\Domain\Live\Data\CredentialField;

/**
 * Where a live session actually happens.
 *
 * `Manual` is not a placeholder — it is the one that works today and the one
 * most academies will use. Somebody schedules the meeting in whatever tool
 * they already pay for and pastes the link; Orbito owns the schedule, the
 * roster and the attendance, which is the part a video provider does badly.
 *
 * Zoom and Google Meet are written and have NEVER been contacted, for the
 * same reason StripeGateway had not in Phase 10: neither can be proven
 * without credentials. `isAvailable()` is what stops one being selected by an
 * academy that has not connected an account.
 */
enum LiveProvider: string
{
    case Manual = 'manual';
    case Zoom = 'zoom';
    case GoogleMeet = 'google_meet';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Paste a link',
            self::Zoom => 'Zoom',
            self::GoogleMeet => 'Google Meet',
        };
    }

    /** Whether an implementation exists at all. */
    public function isImplemented(): bool
    {
        return true;
    }

    /**
     * Whether this provider needs the academy to connect an account.
     *
     * Manual does not — that is the whole point of it, and it is why a fresh
     * academy can schedule a live session on day one without an integration.
     */
    public function needsAccount(): bool
    {
        return $this !== self::Manual;
    }

    /**
     * Whether the host supplies the link themselves.
     *
     * The API validates on this rather than on the enum case, so adding a
     * fourth provider does not mean revisiting every form request.
     */
    public function isHostSupplied(): bool
    {
        return $this === self::Manual;
    }

    /**
     * What an academy has to type in to connect this provider.
     *
     * The single definition of a provider's credentials: the connect screen
     * renders these boxes, the form request refuses any key not listed here,
     * and the implementation reads the same keys back out of
     * `ProviderAccount`. Adding a provider is a case here and a class there,
     * with nothing to remember in between.
     *
     * @return list<CredentialField>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            // Nothing to connect — the host pastes a link per session, which
            // is why a fresh academy can schedule on day one.
            self::Manual => [],

            self::Zoom => [
                new CredentialField(
                    'account_id',
                    'Account ID',
                    'From the Server-to-Server OAuth app you create in the Zoom App Marketplace.',
                ),
                new CredentialField('client_id', 'Client ID', 'From the same app.'),
                new CredentialField(
                    'client_secret',
                    'Client secret',
                    'Zoom shows this once. Scopes needed: meeting:write:admin, meeting:read:admin.',
                    isSecret: true,
                ),
                new CredentialField(
                    'host_email',
                    'Default host email',
                    'The Zoom user meetings are created for when the person scheduling has no Zoom account of their own.',
                    isRequired: false,
                ),
            ],

            /*
             * A SERVICE ACCOUNT, not an access token. Google's tokens live an
             * hour, so a box asking for one would connect an academy until
             * lunchtime and then fail silently — the provider mints its own
             * from these, the way Zoom does.
             */
            self::GoogleMeet => [
                new CredentialField(
                    'client_email',
                    'Service account email',
                    'The `client_email` from the service account JSON key file.',
                ),
                new CredentialField(
                    'private_key',
                    'Private key',
                    'The `private_key` from the same file, including the BEGIN and END lines.',
                    isSecret: true,
                ),
                new CredentialField(
                    'subject',
                    'Calendar owner email',
                    'The account the service account acts as, through domain-wide delegation. Without it the events have no owner in your organisation.',
                    isRequired: false,
                ),
                new CredentialField(
                    'calendar_id',
                    'Calendar ID',
                    'Which calendar the events land on. Defaults to the owner\'s primary calendar.',
                    isRequired: false,
                ),
            ],
        };
    }

    /** Where the academy goes to create the credentials above. */
    public function setupUrl(): ?string
    {
        return match ($this) {
            self::Manual => null,
            self::Zoom => 'https://marketplace.zoom.us/develop/create',
            self::GoogleMeet => 'https://console.cloud.google.com/iam-admin/serviceaccounts',
        };
    }
}
