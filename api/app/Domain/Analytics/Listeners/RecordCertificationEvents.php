<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Listeners;

use App\Domain\Analytics\Actions\RecordEvent;
use App\Domain\Analytics\Data\EventData;
use App\Domain\Analytics\Enums\EventName;
use App\Domain\Certification\Events\CertificateIssued;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Certificates, as the log sees it.
 *
 * On ISSUE, not on render: the certificate is valid before its PDF exists
 * (Phase 11), so counting renders would under-report the thing that actually
 * happened.
 */
final class RecordCertificationEvents implements ShouldQueue
{
    public function __construct(private readonly RecordEvent $record) {}

    public function handle(CertificateIssued $event): void
    {
        $certificate = $event->certificate;

        $this->record->handle(new EventData(
            name: EventName::CertificateIssued,
            occurredAt: $certificate->issued_at,
            actorId: $certificate->user_id,
            subjectType: $certificate->getMorphClass(),
            subjectId: $certificate->id,
            courseId: $certificate->course_id,
            properties: ['number' => $certificate->number],
        ));
    }
}
