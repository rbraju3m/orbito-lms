<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Engagement\Enums\DiscussionStatus;
use App\Domain\Engagement\Enums\DiscussionType;
use App\Domain\Engagement\Exceptions\DiscussionRejected;
use App\Domain\Engagement\Models\Discussion;
use App\Domain\Identity\Models\User;
use App\Support\Html\RichTextSanitizer;

/**
 * Opens a thread on a course, optionally against one lesson.
 */
final class PostDiscussion
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    public function handle(
        User $user,
        Course $course,
        string $title,
        string $body,
        DiscussionType $type = DiscussionType::Question,
        ?CourseItem $item = null,
    ): Discussion {
        $course->loadMissing('setting');

        if (! $course->setting?->enable_qa) {
            throw DiscussionRejected::notEnabled();
        }

        /*
         * An item from another course would attach a question to a lesson
         * nobody in this thread can open. The binding resolves globally, so
         * membership of THIS course is checked here — the same trap as the
         * quiz-question binding in Phase 7.
         */
        if ($item !== null && $item->course_id !== $course->id) {
            throw DiscussionRejected::replyNotInThread();
        }

        return Discussion::create([
            'course_id' => $course->id,
            'course_item_id' => $item?->id,
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            // Sanitised on WRITE, never on render (§12).
            'body' => (string) $this->sanitizer->clean($body),
            'status' => DiscussionStatus::Open,
            'reply_count' => 0,
        ]);
    }
}
