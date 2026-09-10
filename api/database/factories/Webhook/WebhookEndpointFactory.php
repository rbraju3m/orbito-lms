<?php

declare(strict_types=1);

namespace Database\Factories\Webhook;

use App\Domain\Webhook\Enums\WebhookTopic;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Domain\Webhook\Support\WebhookSigner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An ACTIVE endpoint listening for enrolments, at a host tests resolve to a
 * public address (see `fakeWebhookDns()`).
 *
 * @extends Factory<WebhookEndpoint>
 */
final class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'url' => 'https://hooks.example.com/orbito',
            'description' => null,
            'secret' => WebhookSigner::newSecret(),
            'events' => [WebhookTopic::EnrollmentCreated->value],
            'is_active' => true,
            'consecutive_failures' => 0,
        ];
    }

    /** @param  list<WebhookTopic>  $topics */
    public function listeningTo(array $topics): static
    {
        return $this->state(fn (): array => [
            'events' => array_map(static fn (WebhookTopic $topic): string => $topic->value, $topics),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_active' => false, 'disabled_at' => now()]);
    }
}
