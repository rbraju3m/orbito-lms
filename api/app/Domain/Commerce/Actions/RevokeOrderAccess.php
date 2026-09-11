<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Models\DownloadGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Enrollment\Actions\ChangeEnrollmentStatus;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Models\Enrollment;

/**
 * Takes away exactly what ONE order granted — the enrolments it created
 * (`source_id` is the order, for a purchase and for each course of a bundle)
 * and the downloads it granted. Never access that came from somewhere else:
 * a seat an admin gave by hand, a course bought separately, or a course the
 * learner already had when a bundle's overlap was delivered.
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
    }
}
