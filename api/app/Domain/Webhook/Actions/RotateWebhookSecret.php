<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Actions;

use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Domain\Webhook\Support\WebhookSigner;

/**
 * A new signing secret, effective immediately, shown once.
 *
 * No overlap window: the old secret stops the moment this returns, so a
 * receiver must be updated at once. Deliveries already queued are signed at
 * SEND time and will use the new one. A grace period with two valid secrets
 * is the obvious next step if an academy ever needs zero-downtime rotation.
 */
final class RotateWebhookSecret
{
    public function handle(WebhookEndpoint $endpoint): string
    {
        $secret = WebhookSigner::newSecret();

        $endpoint->forceFill(['secret' => $secret])->save();

        return $secret;
    }
}
