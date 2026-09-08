<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Data;

use App\Domain\Analytics\Enums\EventName;
use App\Domain\Analytics\Enums\EventSource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing that happened.
 *
 * Scalars only. A listener builds this from a model and the ids are copied
 * out, so nothing is re-queried in a worker and no relation can be lazily
 * loaded there — the same reasoning as NotificationPayload (Phase 12 patterns).
 */
final class EventData
{
    /** @param  array<string, mixed>  $properties */
    public function __construct(
        public readonly EventName $name,
        public readonly ?CarbonInterface $occurredAt = null,
        public readonly ?int $actorId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly ?int $courseId = null,
        public readonly ?int $courseItemId = null,
        public readonly array $properties = [],
        public readonly ?string $ipHash = null,
        public readonly EventSource $source = EventSource::Api,
    ) {}

    /**
     * Names the subject through the MORPH MAP, not the class name.
     *
     * `subject_type` is stored for a decade and read by reports written long
     * after somebody moves a class between namespaces; the map is enforced for
     * exactly this reason (§9).
     *
     * @param  array<string, mixed>  $properties
     */
    public function withSubject(Model $subject, array $properties = []): self
    {
        return new self(
            name: $this->name,
            occurredAt: $this->occurredAt,
            actorId: $this->actorId,
            sessionId: $this->sessionId,
            subjectType: $subject->getMorphClass(),
            subjectId: (int) $subject->getKey(),
            courseId: $this->courseId,
            courseItemId: $this->courseItemId,
            properties: $properties === [] ? $this->properties : $properties,
            ipHash: $this->ipHash,
            source: $this->source,
        );
    }
}
