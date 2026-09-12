<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Models\DownloadGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Enrollment\Actions\ChangeEnrollmentStatus;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Live\Models\WebinarRegistration;

/**
 * Takes away exactly what ONE order granted — the enrolments it created
 * (`source_id` is the order, for a purchase and for each course of a bundle),
 * the downloads it granted, and the webinar places it bought. Never access
 * that came from somewhere else: a seat an admin gave by hand, a course bought
 * separately, or a course the learner already had when a bundle's overlap was
 * delivered.
 *
 * Revoked, not deleted — progress and grades stay (`ChangeEnrollmentStatus`),
 * and a download grant keeps its row with `revoked_at` set, so buying it
 * again later is a new grant rather than a resurrected one.
 */
final class RevokeOrderAccess
{
    public function __construct(private readonly ChangeEnrollmentStatus $enrollments) {}

    public function handle(Order $order): void
    {
        Enrollment::query()
            ->where('source_id', $order->id)
            ->whereIn('source', [EnrollmentSource::Purchase, EnrollmentSource::Bundle])
            ->get()
            ->each(function (Enrollment $enrollment): void {
                if ($enrollment->status->grantsAccess()) {
                    $this->enrollments->revoke($enrollment);
                }
            });

        DownloadGrant::query()
            ->where('order_id', $order->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        /*
         * A refunded place goes back into the room, which is why this is a
         * cancel rather than a delete: the registration stays as the record
         * that somebody was coming, and `placesRemaining()` counts only live
         * ones. Keyed on the ORDER, so a place the academy gave away — or one
         * held before the webinar was ever priced — is never touched.
         */
        WebinarRegistration::query()
            ->where('order_id', $order->id)
            ->live()
            ->update(['status' => WebinarRegistration::STATUS_CANCELLED]);
    }
}
