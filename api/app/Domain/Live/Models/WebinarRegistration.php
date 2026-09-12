<?php

declare(strict_types=1);

namespace App\Domain\Live\Models;

use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place held at a webinar.
 *
 * `order_id` names the order that BOUGHT it, so a refund revokes exactly the
 * place that order granted and never one given away free or held before the
 * webinar was ever priced — the same column, for the same reason, as
 * `download_grants.order_id`.
 *
 * The unique key is (webinar_id, EMAIL), not (webinar_id, user_id). Keyed on
 * the email so that when the public path lands in P16, a stranger registering
 * with the address they later sign up with cannot end up holding two places
 * — and `user_id` stays nullable for exactly that future.
 *
 * @property int $id
 * @property int $webinar_id
 * @property int|null $user_id
 * @property int|null $order_id
 * @property string $email
 * @property string|null $name
 * @property string $status
 * @property CarbonInterface $registered_at
 */
final class WebinarRegistration extends Model
{
    public const STATUS_REGISTERED = 'registered';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'webinar_id', 'user_id', 'order_id', 'email', 'name', 'status', 'registered_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['registered_at' => 'datetime'];
    }

    /** @param  Builder<WebinarRegistration>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', self::STATUS_REGISTERED);
    }

    /** @return BelongsTo<Webinar, $this> */
    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
