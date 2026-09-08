<?php

declare(strict_types=1);

namespace App\Domain\Live\Events;

use App\Domain\Live\Models\SessionAttendance;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody was at a session, for the first time.
 *
 * Fires once per (session, learner) — the unique key makes a second
 * impossible — so a listener may complete a curriculum item without checking
 * whether it already did. Rejoining after a dropped connection extends the
 * row and fires nothing.
 */
final class AttendanceRecorded
{
    use Dispatchable;

    public function __construct(public readonly SessionAttendance $attendance) {}
}
