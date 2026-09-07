<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One piece of work waiting to be marked, whichever table it came from.
 *
 * A DTO rather than an array shape because the queue unions two sources: the
 * type is the promise that both branches produce the same row, checked once
 * here instead of at every call site.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class GradingQueueRow implements Arrayable
{
    public function __construct(
        /** 'quiz' or 'assignment' — what the grader is about to open. */
        public string $kind,
        public string $id,
        public string $status,
        public bool $awaitingReview,
        public ?string $submittedAt,
        public ?string $learnerId,
        public ?string $learnerName,
        public ?string $itemId,
        public ?string $itemTitle,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'status' => $this->status,
            'awaiting_review' => $this->awaitingReview,
            'submitted_at' => $this->submittedAt,
            'learner' => ['id' => $this->learnerId, 'name' => $this->learnerName],
            'item' => ['id' => $this->itemId, 'title' => $this->itemTitle],
        ];
    }
}
