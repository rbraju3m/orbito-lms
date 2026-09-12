<?php

declare(strict_types=1);

namespace App\Http\Resources\Live;

use App\Domain\Live\Data\CredentialField;
use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Models\LiveProviderAccount;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Whether a meeting provider is connected — never with what.
 *
 * `credentials` is absent by construction rather than filtered: there is no
 * branch here that could emit it, so no later edit can re-enable one. An
 * admin who has lost a client secret re-enters it; the API will not read it
 * back, because anything the API reads back is something an attacker holding
 * a session reads back too. Same rule, same words, as
 * `PaymentGatewayAccountResource`.
 *
 * @mixin LiveProviderAccount
 */
final class LiveProviderAccountResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::shape(
            provider: $this->provider,
            isConnected: $this->credentials !== null && $this->credentials !== [],
            isActive: $this->is_active,
            upcomingSessions: (int) ($this->getAttributes()['upcoming_sessions_count'] ?? 0),
            updatedAt: $this->updated_at?->toIso8601String(),
        );
    }

    /**
     * A provider the academy has NOT connected, in the same shape.
     *
     * The list has to carry these — nobody can connect Zoom from a list of
     * what is already connected — and `Manual` appears among them with no
     * fields at all, because a screen that hid it would suggest live sessions
     * need an integration when they do not.
     *
     * @return array<string, mixed>
     */
    public static function unconnected(LiveProvider $provider, int $upcomingSessions = 0): array
    {
        return self::shape($provider, false, false, $upcomingSessions, null);
    }

    /**
     * One row, connected or not.
     *
     * `fields` is the server's declaration of what this provider needs
     * (`LiveProvider::credentialFields()`) — the same list the form request
     * validates against, so the screen cannot offer a box the API refuses or
     * miss one it requires.
     *
     * `upcoming_sessions` is what would be left stranded by a disconnect:
     * their links keep working and they can still be cancelled, but nothing
     * can reschedule them through a provider with no account. A confirmation
     * that cannot say that is a confirmation of nothing.
     *
     * @return array<string, mixed>
     */
    private static function shape(
        LiveProvider $provider,
        bool $isConnected,
        bool $isActive,
        int $upcomingSessions,
        ?string $updatedAt,
    ): array {
        return [
            'provider' => $provider->value,
            'label' => $provider->label(),
            'needs_account' => $provider->needsAccount(),
            'is_connected' => $isConnected,
            'is_active' => $isActive,
            'fields' => array_map(
                static fn (CredentialField $field): array => $field->toArray(),
                $provider->credentialFields(),
            ),
            'setup_url' => $provider->setupUrl(),
            'upcoming_sessions' => $upcomingSessions,
            'updated_at' => $updatedAt,
        ];
    }
}
