<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Data;

use App\Domain\Gamification\Enums\TriggerEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing that happened, as the rule engine sees it.
 *
 * Scalars only, built by the listener that owns the domain event. Rules are
 * evaluated against THIS and never against a model they re-read: by the time a
 * queued listener runs, the row may have changed, and a rule that re-queries
 * awards on the state it finds rather than on the state that earned it.
 */
final class TriggerContext
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly TriggerEvent $trigger,
        public readonly int $userId,
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly array $payload = [],
        public readonly ?int $courseId = null,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public static function for(
        TriggerEvent $trigger,
        int $userId,
        Model $source,
        array $payload = [],
        ?int $courseId = null,
    ): self {
        return new self(
            trigger: $trigger,
            userId: $userId,
            // The morph-map alias, not the class name: the ledger outlives
            // any class moving namespace.
            sourceType: $source->getMorphClass(),
            sourceId: (int) $source->getKey(),
            payload: $payload,
            courseId: $courseId,
        );
    }

    /**
     * The key that makes a once-per-source rule idempotent.
     *
     * It names the RULE and the SOURCE, so two different rules can both pay
     * out for one lesson while neither pays twice.
     */
    public function dedupeKey(string $ruleKey): string
    {
        return "{$ruleKey}:{$this->sourceType}:{$this->sourceId}";
    }
}
