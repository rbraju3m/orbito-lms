<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notification;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Support\NotificationPreferences;
use App\Http\Requests\Notification\UpdatePreferencesRequest;
use App\Http\Resources\Notification\NotificationPreferenceResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The switches.
 *
 * Same shape as the inbox: keyed on the caller, so there is nothing to
 * authorize and nothing in the body naming a user.
 */
final class NotificationPreferenceController
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(
            NotificationPreferenceResource::make($this->preferences->matrix($request->user()->id)),
        );
    }

    /**
     * Returns the whole matrix, not just what changed, so the screen renders
     * from the server's answer rather than from its own optimistic guess
     * about what a switch did.
     */
    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $userId = $request->user()->id;

        /** @var list<array{type: string, channel: string, enabled: bool}> $changes */
        $changes = $request->validated('preferences');

        foreach ($changes as $change) {
            $this->preferences->set(
                $userId,
                NotificationType::from($change['type']),
                NotificationChannel::from($change['channel']),
                (bool) $change['enabled'],
            );
        }

        return ApiResponse::ok(
            NotificationPreferenceResource::make($this->preferences->matrix($userId)),
        );
    }
}
