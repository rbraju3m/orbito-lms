<?php

declare(strict_types=1);

namespace App\Domain\Live\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Live\Enums\AttendanceSource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person at one session.
 *
 * The unique key on (live_session_id, user_id) means joining twice EXTENDS the
 * row rather than adding one — somebody whose connection drops and comes back
 * attended once, and a report that counted them twice would be worse than
 * useless in a compliance context.
 *
 * @property int $id
 * @property int $live_session_id
 * @property int $user_id
 * @property CarbonInterface $joined_at
 * @property CarbonInterface|null $left_at
 * @property int $duration_seconds
 * @property AttendanceSource $source
 */
final class SessionAttendance extends Model
{
    protected $table = 'session_attendance';

    protected $fillable = ['live_session_id', 'user_id', 'joined_at', 'left_at', 'duration_seconds', 'source'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'duration_seconds' => 'integer',
            'source' => AttendanceSource::class,
        ];
    }

    /** @return BelongsTo<LiveSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
