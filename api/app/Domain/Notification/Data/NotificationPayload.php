<?php

declare(strict_types=1);

namespace App\Domain\Notification\Data;

use App\Domain\Notification\Enums\NotificationType;

/**
 * What a notification SAYS, frozen at the moment it was raised.
 *
 * A notification is a message, not a live view of a row. If an instructor
 * edits an announcement after sending it, the email already in somebody's
 * inbox does not change, and neither should the in-app entry beside it —
 * so the text is captured here rather than re-read from the model at render
 * time. It also means nothing has to be re-queried on the queue, and no
 * relation can be lazily loaded in a worker.
 *
 * `actionPath` is a RELATIVE SPA path, never an absolute URL. An academy's
 * frontend address can change; a thousand stored absolute links would rot
 * silently, and only be noticed by somebody clicking a year-old email. The
 * absolute URL is built at render time by `url()`.
 */
final class NotificationPayload
{
    /**
     * @param  array<string, mixed>  $meta  Ids the SPA needs to route or badge.
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionPath = null,
        public readonly array $meta = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'body' => $this->body,
            'action_label' => $this->actionLabel,
            'action_path' => $this->actionPath,
            'meta' => $this->meta,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: NotificationType::from((string) $data['type']),
            title: (string) $data['title'],
            body: (string) $data['body'],
            actionLabel: isset($data['action_label']) ? (string) $data['action_label'] : null,
            actionPath: isset($data['action_path']) ? (string) $data['action_path'] : null,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    /** Absolute, built now — see the note about stored links above. */
    public function url(): ?string
    {
        if ($this->actionPath === null) {
            return null;
        }

        return rtrim(frontend_url(), '/').'/'.ltrim($this->actionPath, '/');
    }

    /**
     * A one-line excerpt of author HTML, safe to put in a subject or a card.
     *
     * The stored body was sanitised on write (§12), so this is about LENGTH
     * and markup, not about safety.
     */
    public static function excerpt(?string $html, int $limit = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)) ?? '');

        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit - 1).'…'
            : $text;
    }
}
