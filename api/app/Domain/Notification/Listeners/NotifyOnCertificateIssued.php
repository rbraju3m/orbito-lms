<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Certification\Events\CertificateIssued;
use App\Domain\Notification\Actions\NotifyUsers;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The reward at the end.
 *
 * Sent on ISSUE, not on render. The certificate is valid before its PDF
 * exists (Phase 11) — waiting for the file would mean a failed render is also
 * a missing congratulation, and the page this links to shows the certificate
 * whether or not a document has been produced yet.
 *
 * This is the one case that reports back something the learner did do — but
 * completing the last lesson and being awarded a credential are different
 * facts, and the second one arrives later, from the system, without them
 * watching.
 */
final class NotifyOnCertificateIssued implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly NotifyUsers $notify) {}

    public function handle(CertificateIssued $event): void
    {
        $certificate = $event->certificate;
        $certificate->loadMissing('course');

        $course = $certificate->course;

        if ($course === null) {
            return;
        }

        $payload = new NotificationPayload(
            type: NotificationType::CertificateIssued,
            title: 'Your certificate for '.$course->title.' is ready',
            body: 'Certificate '.$certificate->number.' has been issued. You can download it or share the verification link.',
            actionLabel: 'View certificate',
            actionPath: '/certificates',
            meta: [
                'course_id' => $course->uuid,
                'course_title' => $course->title,
                'certificate_id' => $certificate->uuid,
                'certificate_number' => $certificate->number,
            ],
        );

        $this->notify->handle([$certificate->user_id], $payload);
    }
}
