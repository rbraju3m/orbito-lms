<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Domain\Identity\Enums\InvitationRole;
use App\Domain\Identity\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A pending invitation. The token is unknown to the test — use `withToken()`
 * when a test needs to follow the link, the way the mail would.
 *
 * @extends Factory<Invitation>
 */
final class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $email = Str::lower(fake()->unique()->safeEmail());

        return [
            'email' => $email,
            'pending_email' => $email,
            'role' => InvitationRole::Student,
            'token_hash' => Invitation::hashToken(Invitation::newToken()),
            'invited_by' => null,
            'expires_at' => now()->addDays(14),
            'sent_count' => 1,
            'last_sent_at' => now(),
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => Invitation::hashToken($token)]);
    }

    public function instructor(): static
    {
        return $this->state(fn () => ['role' => InvitationRole::Instructor]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->afterCreating(function (Invitation $invitation): void {
            $invitation->forceFill(['revoked_at' => now(), 'pending_email' => null])->save();
        });
    }
}
