<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Data;

use Carbon\CarbonInterface;

/**
 * Whether one item is released to one learner, and — when it is not — enough
 * for the UI to say why without a second request.
 *
 * A locked item is still LISTED. Hiding it would make the course look shorter
 * than it is and turn "10 lessons" on the sales page into a lie.
 */
final readonly class DripState
{
    private function __construct(
        public bool $locked,
        public ?CarbonInterface $unlocksAt = null,
        public ?int $blockingItemId = null,
        public ?string $blockingItemTitle = null,
    ) {}

    public static function open(): self
    {
        return new self(false);
    }

    public static function until(CarbonInterface $unlocksAt): self
    {
        return new self(true, $unlocksAt);
    }

    public static function behind(int $itemId, string $title): self
    {
        return new self(true, null, $itemId, $title);
    }

    /**
     * The machine-readable half, folded into a 423 body and the curriculum
     * outline alike so both screens explain the lock the same way.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return array_filter([
            'blocked_by_id' => $this->blockingItemId,
            'blocked_by_title' => $this->blockingItemTitle,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
