<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Webhook\Models\WebhookEndpoint;

/**
 * `webhook.manage` is a SYSTEM permission, held by the academy's Super Admin
 * and nobody else by default (docs/ROLES_PERMISSIONS.md). Deciding where an
 * academy's learner data is sent is not an everyday admin task — an endpoint
 * receives names and email addresses.
 */
final class WebhookEndpointPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('webhook.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('webhook.manage');
    }

    public function view(User $actor, WebhookEndpoint $endpoint): bool
    {
        return $actor->hasPermission('webhook.manage');
    }

    public function update(User $actor, WebhookEndpoint $endpoint): bool
    {
        return $actor->hasPermission('webhook.manage');
    }

    public function delete(User $actor, WebhookEndpoint $endpoint): bool
    {
        return $actor->hasPermission('webhook.manage');
    }
}
