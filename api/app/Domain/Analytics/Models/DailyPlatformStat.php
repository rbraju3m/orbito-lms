<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The whole academy, one UTC day.
 *
 * @property CarbonInterface $date
 * @property int $new_users
 * @property int $new_enrollments
 * @property int $completions
 * @property int $revenue_minor
 * @property int $download_revenue_minor
 * @property string $currency
 * @property int $active_learners
 */
final class DailyPlatformStat extends Model
{
    protected $table = 'analytics_daily_platform';

    public $incrementing = false;

    protected $primaryKey = 'date';

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'new_users' => 'integer',
            'new_enrollments' => 'integer',
            'completions' => 'integer',
            'download_revenue_minor' => 'integer',
            'revenue_minor' => 'integer',
            'active_learners' => 'integer',
        ];
    }
}
