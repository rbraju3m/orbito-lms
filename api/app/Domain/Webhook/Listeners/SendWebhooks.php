<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Listeners;

use App\Domain\Assessment\Events\AssignmentGraded;
use App\Domain\Assessment\Events\AssignmentSubmitted;
use App\Domain\Assessment\Events\QuizAttemptGraded;
use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Catalog\Events\CourseStatusChanged;
use App\Domain\Catalog\Events\DownloadGranted;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\Download;
use App\Domain\Certification\Events\CertificateIssued;
use App\Domain\Commerce\Events\PaymentCaptured;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Engagement\Events\ReviewPublished;
use App\Domain\Enrollment\Events\CourseEnrolled;
use App\Domain\Enrollment\Events\EnrollmentExpired;
use App\Domain\Enrollment\Events\EnrollmentReinstated;
use App\Domain\Enrollment\Events\EnrollmentRevoked;
use App\Domain\Enrollment\Events\EnrollmentSuspended;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Events\CourseCompleted;
use App\Domain\Progress\Events\ItemCompleted;
use App\Domain\Webhook\Actions\QueueWebhookEvent;
use App\Domain\Webhook\Enums\WebhookTopic;

/**
 * Domain events, translated into the payloads integrators read.
 *
 * One method per event, each naming its topic and building its `data`. The
 * data is a closure, so none of these lookups run unless an endpoint has
 * subscribed (`QueueWebhookEvent`). Built from the EVENT's own models, in the
 * request that fired it — the message describes the moment it happened.
 *
 * Every shape here is documented in docs/WEBHOOKS.md and is a contract: add a
 * field freely, never rename or remove one. A person appears as
 * `{id, name, email}` wherever one is involved; that was a decision about
 * what an academy may send to its own integrations, not a default.
 */
final class SendWebhooks
{
    public function __construct(private readonly QueueWebhookEvent $queue) {}

    public function enrolled(CourseEnrolled $event): void
    {
        $this->enrolment(WebhookTopic::EnrollmentCreated, $event->enrollment);
    }

    public function suspended(EnrollmentSuspended $event): void
    {
        $this->enrolment(WebhookTopic::EnrollmentSuspended, $event->enrollment, ['reason' => $event->reason]);
    }

    public function reinstated(EnrollmentReinstated $event): void
    {
        $this->enrolment(WebhookTopic::EnrollmentReinstated, $event->enrollment);
    }

    public function revoked(EnrollmentRevoked $event): void
    {
        $this->enrolment(WebhookTopic::EnrollmentRevoked, $event->enrollment);
    }

    public function expired(EnrollmentExpired $event): void
    {
        $this->enrolment(WebhookTopic::EnrollmentExpired, $event->enrollment);
    }

    public function itemCompleted(ItemCompleted $event): void
    {
        $enrollment = $event->enrollment;
        $item = $event->item;

        $this->queue->handle(WebhookTopic::ItemCompleted, fn (): array => [
            'enrollment_id' => $enrollment->uuid,
            'learner' => $this->learner($enrollment->user_id),
            'course' => $this->course($enrollment->course_id),
            'item' => $this->item($item),
        ]);
    }

    public function courseCompleted(CourseCompleted $event): void
    {
        $this->enrolment(WebhookTopic::CourseCompleted, $event->enrollment);
    }

    public function courseStatusChanged(CourseStatusChanged $event): void
    {
        $this->queue->handle(WebhookTopic::CourseStatusChanged, fn (): array => [
            'course' => $this->course($event->course),
            'from' => $event->from->value,
            'to' => $event->to->value,
        ]);
    }

    public function quizGraded(QuizAttemptGraded $event): void
    {
        $attempt = $event->attempt;

        $this->queue->handle(WebhookTopic::QuizGraded, function () use ($attempt, $event): array {
            $attempt->loadMissing('item');

            return [
                'attempt_id' => $attempt->uuid,
                'attempt_number' => $attempt->attempt_number,
                'learner' => $this->learner($attempt->user_id),
                'course' => $attempt->item !== null ? $this->course($attempt->item->course_id) : null,
                'item' => $attempt->item !== null ? $this->item($attempt->item) : null,
                'passed' => $event->passed,
                // Strings on the model (DECIMAL), numbers on the wire.
                'score' => [
                    'earned' => (float) $attempt->earned_points,
                    'total' => (float) $attempt->total_points,
                    'percent' => (float) $attempt->percent,
                ],
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            ];
        });
    }

    public function assignmentSubmitted(AssignmentSubmitted $event): void
    {
        $submission = $event->submission;

        $this->queue->handle(WebhookTopic::AssignmentSubmitted, fn (): array => $this->submission($submission));
    }

    public function assignmentGraded(AssignmentGraded $event): void
    {
        $submission = $event->submission;

        $this->queue->handle(WebhookTopic::AssignmentGraded, fn (): array => [
            ...$this->submission($submission),
            'passed' => $event->passed,
            'points_earned' => $submission->points_earned,
            'late_penalty_points' => $submission->late_penalty_points,
            'graded_at' => $submission->graded_at?->toIso8601String(),
        ]);
    }

    public function paymentCaptured(PaymentCaptured $event): void
    {
        $order = $event->order;
        $payment = $event->payment;

        $this->queue->handle(WebhookTopic::PaymentCaptured, function () use ($order, $payment): array {
            $order->loadMissing('items.purchasable');

            return [
                'order' => [
                    'id' => $order->uuid,
                    'number' => $order->number,
                    // Minor units and an ISO code, as everywhere else in the API.
                    'total_minor' => $order->total_minor,
                    'currency' => $order->currency,
                    'items' => $order->items->map(fn (OrderItem $item): array => [
                        'type' => $item->purchasable_type,
                        'id' => $item->purchasable?->getAttribute('uuid'),
                        'title' => $item->getAttribute('title_snapshot'),
                        'total_minor' => $item->total_minor,
                    ])->values()->all(),
                ],
                'payment' => [
                    'id' => $payment->uuid,
                    'gateway' => $payment->gateway->value,
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency,
                    'captured_at' => $payment->captured_at?->toIso8601String(),
                ],
                'learner' => $this->learner($order->user_id),
            ];
        });
    }

    public function certificateIssued(CertificateIssued $event): void
    {
        $certificate = $event->certificate;

        $this->queue->handle(WebhookTopic::CertificateIssued, fn (): array => [
            'certificate' => [
                'id' => $certificate->uuid,
                'number' => $certificate->number,
                'issued_at' => $certificate->issued_at->toIso8601String(),
                'expires_at' => $certificate->expires_at?->toIso8601String(),
            ],
            'learner' => $this->learner($certificate->user_id),
            'course' => $this->course($certificate->course_id),
        ]);
    }

    public function downloadGranted(DownloadGranted $event): void
    {
        $grant = $event->grant;

        $this->queue->handle(WebhookTopic::DownloadGranted, function () use ($grant): array {
            $download = Download::query()->find($grant->download_id);

            return [
                'download' => $download === null ? null : [
                    'id' => $download->uuid,
                    'slug' => $download->slug,
                    'title' => $download->title,
                ],
                'learner' => $this->learner($grant->user_id),
                'source' => $grant->source->value,
                'granted_at' => $grant->granted_at->toIso8601String(),
            ];
        });
    }

    public function reviewPublished(ReviewPublished $event): void
    {
        $review = $event->review;

        $this->queue->handle(WebhookTopic::ReviewPublished, fn (): array => [
            'review' => [
                'id' => $review->uuid,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'published_at' => $review->published_at?->toIso8601String(),
            ],
            'learner' => $this->learner($review->user_id),
            'course' => $this->course($review->course_id),
        ]);
    }

    /* ------------------------------------------------------------ shapes */

    /** @param  array<string, mixed>  $extra */
    private function enrolment(WebhookTopic $topic, Enrollment $enrollment, array $extra = []): void
    {
        $this->queue->handle($topic, fn (): array => [
            'enrollment' => [
                'id' => $enrollment->uuid,
                'status' => $enrollment->status->value,
                'source' => $enrollment->source->value,
                'enrolled_at' => $enrollment->enrolled_at->toIso8601String(),
                'expires_at' => $enrollment->expires_at?->toIso8601String(),
                'completed_at' => $enrollment->completed_at?->toIso8601String(),
            ],
            'learner' => $this->learner($enrollment->user_id),
            'course' => $this->course($enrollment->course_id),
            ...$extra,
        ]);
    }

    /** @return array<string, mixed> */
    private function submission(AssignmentSubmission $submission): array
    {
        $item = CourseItem::query()->find($submission->course_item_id);

        return [
            'submission_id' => $submission->uuid,
            'attempt_number' => $submission->attempt_number,
            'is_late' => $submission->is_late,
            'submitted_at' => $submission->submitted_at->toIso8601String(),
            'learner' => $this->learner($submission->user_id),
            'course' => $this->course($submission->course_id),
            'item' => $item !== null ? $this->item($item) : null,
        ];
    }

    /**
     * `users` is CENTRAL and the rows pointing at it are not, so this is a
     * lookup by id rather than a relation (§ Multi-tenancy).
     *
     * @return array{id: string, name: string, email: string}|null
     */
    private function learner(int $userId): ?array
    {
        $user = User::query()->find($userId);

        return $user === null ? null : ['id' => $user->uuid, 'name' => $user->name, 'email' => $user->email];
    }

    /** @return array{id: string, slug: string, title: string}|null */
    private function course(Course|int $course): ?array
    {
        $course = $course instanceof Course ? $course : Course::query()->withTrashed()->find($course);

        return $course === null ? null : ['id' => $course->uuid, 'slug' => $course->slug, 'title' => $course->title];
    }

    /** @return array{id: string, type: string, title: string} */
    private function item(CourseItem $item): array
    {
        return ['id' => $item->uuid, 'type' => $item->type->value, 'title' => $item->title];
    }
}
