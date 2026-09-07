<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\InstructorStatus;
use App\Support\Database\LivesInTenantSchema;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property InstructorStatus $status
 * @property CarbonInterface|null $applied_at
 * @property CarbonInterface|null $reviewed_at
 * @property int|null $reviewed_by
 * @property string|null $review_note
 * @property string|null $application_source
 * @property string|null $application_message
 * @property int|null $commission_rate_bp
 * @property string|null $payout_currency
 */
final class InstructorProfile extends Model
{
    use LivesInTenantSchema;

    protected $fillable = [
        'user_id', 'status', 'applied_at', 'reviewed_at', 'reviewed_by', 'review_note',
        'application_source', 'application_message', 'commission_rate_bp', 'payout_currency',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InstructorStatus::class,
            'applied_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'rating_avg' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Effective commission in basis points, falling back to the platform rate. */
    public function commissionRateBp(): int
    {
        return $this->commission_rate_bp ?? (int) config('orbito.commission.default_rate_bp', 3000);
    }
}
