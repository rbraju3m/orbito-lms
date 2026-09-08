<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * Where a thread is.
 *
 * `open` and `answered` are DERIVED from the reply count and maintained by
 * the same listener that maintains it, so the two cannot disagree.
 * `resolved` and `hidden` are set explicitly — one by the asker accepting an
 * answer, one by a moderator — and neither is something a reply arriving
 * should undo.
 */
enum DiscussionStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Resolved = 'resolved';
    case Hidden = 'hidden';

    /** Whether anybody but a moderator may see it. */
    public function isVisible(): bool
    {
        return $this !== self::Hidden;
    }

    /**
     * Whether a reply arriving may move this status.
     *
     * A resolved thread stays resolved when somebody adds a footnote, and a
     * hidden one does not resurface because somebody replied to it.
     */
    public function followsReplies(): bool
    {
        return $this === self::Open || $this === self::Answered;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Answered => 'Answered',
            self::Resolved => 'Resolved',
            self::Hidden => 'Hidden',
        };
    }
}
