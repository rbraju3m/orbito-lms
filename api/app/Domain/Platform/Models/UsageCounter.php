<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $owner_type
 * @property int|null $owner_id
 * @property string $metric
 * @property int $value
 */
final class UsageCounter extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'metric', 'value', 'reconciled_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reconciled_at' => 'datetime', 'value' => 'integer'];
    }
}
