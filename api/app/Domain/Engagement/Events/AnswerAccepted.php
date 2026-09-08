<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Events;

use App\Domain\Engagement\Models\DiscussionReply;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody's reply was marked as the answer.
 *
 * Fired on ACCEPTING only, never on un-accepting: taking a mark back is not an
 * event anybody downstream wants, and a listener that had to check which
 * direction it went would be reading a boolean to decide whether to do
 * nothing.
 */
final class AnswerAccepted
{
    use Dispatchable;

    public function __construct(public readonly DiscussionReply $reply) {}
}
