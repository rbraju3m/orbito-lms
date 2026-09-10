<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Events\PaymentCaptured;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Enrollment\Data\EnrollmentIntent;
use App\Domain\Enrollment\Exceptions\EnrollmentRejected;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Marks a payment captured, marks its order paid, and grants what was bought.
 *
 * Only ever reached from HandleWebhook, after a verified signature. It is
 * separate from that action because the checks and the consequences are
 * different jobs — and because a reconciliation sweep (polling a provider for
 * orders stuck awaiting payment) will need to arrive at exactly this point by
 * a different road.
 */
final class CapturePayment
{
    public function __construct(private readonly EnrollInCourse $enroll) {}

    public function handle(Payment $payment, WebhookEvent $event): Payment
    {
        $order = $payment->order;

        /*
         * The amount check.
         *
         * A provider reporting a capture smaller than the order — a partial
         * authorisation, a tampered test call, a currency mix-up — must not
         * grant access. Nothing here trusts the payload for the FIGURE; it
         * compares it against what we priced.
         */
        if ($event->amountMinor !== null && $event->amountMinor < $order->total_minor) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'Captured amount is less than the order total.',
            ])->save();

            Log::warning('Payment captured for less than the order total.', [
                'order' => $order->uuid,
                'expected_minor' => $order->total_minor,
                'reported_minor' => $event->amountMinor,
            ]);

            return $payment->refresh();
        }

        if ($event->currency !== null && $event->currency !== strtoupper($order->currency)) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'Captured currency does not match the order.',
            ])->save();

            return $payment->refresh();
        }

        $payment->forceFill([
            'status' => PaymentStatus::Captured,
            'captured_at' => now(),
        ])->save();

        $order->forceFill([
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ])->save();

        $this->grantAccess($order);

        PaymentCaptured::dispatch($payment->refresh(), $order->refresh());

        return $payment;
    }

    /**
     * Grants every line on the order.
     *
     * Failure here must NOT roll the payment back — the money moved, and an
     * order that reads unpaid because a grant failed is the worst of both.
     * It is logged loudly instead, for an operator to finish by hand.
     */
    private function grantAccess(Order $order): void
    {
        $order->loadMissing('items');
        $user = User::find($order->user_id);

        if ($user === null) {
            Log::error('Paid order has no user to grant access to.', ['order' => $order->uuid]);

            return;
        }

        foreach ($order->items as $item) {
            match ($item->purchasable_type) {
                'course' => $this->grantCourse($order, $user, (int) $item->purchasable_id),
                'bundle' => $this->grantBundle($order, $user, (int) $item->purchasable_id),
                // A product type with no grant path yet — a download, a
                // coaching slot. Silence here is deliberate: the order is paid
                // and its other lines must still be delivered.
                default => null,
            };
        }
    }

    private function grantCourse(Order $order, User $user, int $courseId): void
    {
        $course = Course::find($courseId);

        if ($course === null) {
            Log::error('Paid order references a course that no longer exists.', [
                'order' => $order->uuid,
                'course_id' => $courseId,
            ]);

            return;
        }

        // The intent Phase 9 declared for exactly this moment: it bypasses the
        // price gate, because the price was just paid, and records the order
        // it came from.
        $this->enrolOne($order, $user, $course, EnrollmentIntent::purchase($order->id));
    }

    /**
     * Fans a bundle out into one enrolment per course.
     *
     * Every course is attempted even if an earlier one fails, for the same
     * reason the whole method never rolls the payment back: somebody paid for
     * five courses and getting four is strictly better than getting none.
     *
     * `EnrollmentIntent::bundle()` bypasses prerequisites — see the comment on
     * it. Without that, a bundle that sells a sequence could not deliver the
     * later half of the sequence.
     */
    private function grantBundle(Order $order, User $user, int $bundleId): void
    {
        $bundle = Bundle::with('courses')->find($bundleId);

        if ($bundle === null) {
            Log::error('Paid order references a bundle that no longer exists.', [
                'order' => $order->uuid,
                'bundle_id' => $bundleId,
            ]);

            return;
        }

        foreach ($bundle->courses as $course) {
            $this->enrolOne($order, $user, $course, EnrollmentIntent::bundle($order->id));
        }
    }

    private function enrolOne(Order $order, User $user, Course $course, EnrollmentIntent $intent): void
    {
        try {
            $this->enroll->handle($user, $course, $intent);
        } catch (EnrollmentRejected $e) {
            // Already enrolled is fine and means the grant already happened —
            // and for a bundle it is ORDINARY, because partial overlap is a
            // sale we deliberately allow. Anything else, a full course say, is
            // a genuine problem somebody has paid for.
            Log::warning('Could not grant access for a paid order.', [
                'order' => $order->uuid,
                'course_id' => $course->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
