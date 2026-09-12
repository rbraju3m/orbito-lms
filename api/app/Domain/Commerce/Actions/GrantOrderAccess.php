<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Actions\GrantDownload;
use App\Domain\Catalog\Enums\DownloadSource;
use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\Download;
use App\Domain\Commerce\Models\Order;
use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Enrollment\Data\EnrollmentIntent;
use App\Domain\Enrollment\Exceptions\EnrollmentRejected;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Actions\RegisterForWebinar;
use App\Domain\Live\Models\Webinar;
use Illuminate\Support\Facades\Log;

/**
 * Delivers every line of a PAID order: an enrolment per course, one per course
 * in a bundle, a grant per download, a place at a webinar.
 *
 * One place, reached two ways — a captured payment (`CapturePayment`) and an
 * order the server priced at nothing (`CompleteFreeOrder`) — so a free order
 * cannot be delivered differently from a paid one.
 *
 * Failure here never undoes the order. The money moved (or there was none to
 * move), and an order that reads unpaid because a grant failed is the worst of
 * both; it is logged loudly instead, for an operator to finish by hand.
 */
final class GrantOrderAccess
{
    public function __construct(
        private readonly EnrollInCourse $enroll,
        private readonly GrantDownload $grants,
        private readonly RegisterForWebinar $webinars,
    ) {}

    public function handle(Order $order): void
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
                'download' => $this->grantDownload($order, $user, (int) $item->purchasable_id),
                'webinar' => $this->grantWebinar($order, $user, (int) $item->purchasable_id),
                // A product type with no grant path yet — a coaching slot.
                // Silence is deliberate: the order's other lines must still
                // be delivered.
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
     * Every course is attempted even if an earlier one fails: somebody paid
     * for five courses and getting four is strictly better than getting none.
     * `EnrollmentIntent::bundle()` bypasses prerequisites — without that, a
     * bundle that sells a sequence could not deliver its later half.
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

    /** Idempotent: a second delivery finds the grant the first one made. */
    private function grantDownload(Order $order, User $user, int $downloadId): void
    {
        $download = Download::find($downloadId);

        if ($download === null) {
            Log::error('Paid order references a download that no longer exists.', [
                'order' => $order->uuid,
                'download_id' => $downloadId,
            ]);

            return;
        }

        $this->grants->handle($user, $download, DownloadSource::Purchase, $order->id);
    }

    /**
     * Holds the place that was bought — and holds it unconditionally.
     *
     * The capacity check and the "is it still open?" check both happened at
     * the basket and again at checkout (`WebinarPurchase`). They are NOT
     * repeated here: the money has moved, and refusing a paid registrant
     * because the room filled during the redirect leaves them paid and
     * holding nothing. A room with one extra person in it is the smaller
     * failure, and the order is the record that says who to talk to.
     *
     * Idempotent: a second delivery finds the registration the first made.
     */
    private function grantWebinar(Order $order, User $user, int $webinarId): void
    {
        $webinar = Webinar::find($webinarId);

        if ($webinar === null) {
            Log::error('Paid order references a webinar that no longer exists.', [
                'order' => $order->uuid,
                'webinar_id' => $webinarId,
            ]);

            return;
        }

        $this->webinars->handle($user, $webinar, $order->id);
    }

    private function enrolOne(Order $order, User $user, Course $course, EnrollmentIntent $intent): void
    {
        try {
            $this->enroll->handle($user, $course, $intent);
        } catch (EnrollmentRejected $e) {
            // Already enrolled means the grant already happened — and for a
            // bundle it is ORDINARY, because partial overlap is a sale we
            // allow. Anything else, a full course say, is a genuine problem
            // somebody has paid for.
            Log::warning('Could not grant access for a paid order.', [
                'order' => $order->uuid,
                'course_id' => $course->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
