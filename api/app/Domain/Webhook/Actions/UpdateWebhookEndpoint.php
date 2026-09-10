<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Actions;

use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Domain\Webhook\Support\WebhookTarget;

/**
 * Changes an endpoint's address, topics, description or on/off switch.
 *
 * Switching one back ON clears its failure count: somebody has looked at it
 * and says it works now, so the next failure starts a fresh count rather than
 * switching it straight back off on the first blip.
 */
final class UpdateWebhookEndpoint
{
    public function __construct(private readonly WebhookTarget $target) {}

    /**
     * @param  array{url?: string, events?: list<string>, description?: string|null, is_active?: bool}  $changes
     */
    public function handle(WebhookEndpoint $endpoint, array $changes): WebhookEndpoint
    {
        if (array_key_exists('url', $changes)) {
            $this->target->vet($changes['url']);
            $endpoint->url = $changes['url'];
        }

        if (array_key_exists('events', $changes)) {
            $endpoint->events = array_values(array_unique($changes['events']));
        }

        if (array_key_exists('description', $changes)) {
            $endpoint->description = $changes['description'];
        }

        if (array_key_exists('is_active', $changes) && $changes['is_active'] !== $endpoint->is_active) {
            $endpoint->forceFill($changes['is_active']
                ? ['is_active' => true, 'consecutive_failures' => 0, 'disabled_at' => null, 'disabled_reason' => null]
                : ['is_active' => false, 'disabled_at' => now(), 'disabled_reason' => 'Switched off by an administrator.']);
        }

        $endpoint->save();

        return $endpoint->refresh();
    }
}
