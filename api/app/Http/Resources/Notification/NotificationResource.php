<?php

declare(strict_types=1);

namespace App\Http\Resources\Notification;

use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Models\Notification;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin Notification
 */
final class NotificationResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->data;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'action_label' => $data['action_label'] ?? null,
            /*
             * The RELATIVE path, as stored. The SPA routes on it internally —
             * handing it an absolute URL would make an in-app link a full
             * page load, and would rot the moment the academy's address
             * changes (see NotificationPayload).
             */
            'action_path' => $data['action_path'] ?? null,
            'meta' => $data['meta'] ?? [],
            'read_at' => $this->read_at?->toIso8601String(),
            'is_read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * A row written before a type was retired still renders: the payload is
     * read as data, and only callers that need the enum resolve it.
     */
    public function payload(): NotificationPayload
    {
        return NotificationPayload::fromArray($this->data);
    }
}
