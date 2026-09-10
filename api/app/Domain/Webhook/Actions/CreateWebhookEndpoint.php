<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Domain\Webhook\Support\WebhookSigner;
use App\Domain\Webhook\Support\WebhookTarget;

/**
 * Registers an endpoint and mints its signing secret.
 *
 * The address is vetted NOW, so an academy typing an internal URL learns at
 * the moment it saves rather than from a delivery log a day later. The secret
 * is returned to the caller exactly once; nothing reads it back afterwards.
 */
final class CreateWebhookEndpoint
{
    public function __construct(private readonly WebhookTarget $target) {}

    /**
     * @param  list<string>  $events
     * @return array{endpoint: WebhookEndpoint, secret: string}
     */
    public function handle(User $creator, string $url, array $events, ?string $description = null): array
    {
        $this->target->vet($url);

        $secret = WebhookSigner::newSecret();

        $endpoint = WebhookEndpoint::create([
            'url' => $url,
            'description' => $description,
            'secret' => $secret,
            'events' => array_values(array_unique($events)),
            'is_active' => true,
            'created_by' => $creator->id,
        ]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }
}
