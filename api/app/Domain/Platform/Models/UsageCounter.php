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
    /*
     * CENTRAL. Pinned so this model can never be read through a tenant
     * connection: when tenancy is initialised the default connection is
     * swapped, and an unpinned central model would silently query a table of
     * the same name inside the academy's schema — or fail because there is
     * none. CentralModelConnectionTest enforces this.
     */
    protected $connection = 'mysql';

    protected $fillable = ['tenant_id', 'owner_type', 'owner_id', 'metric', 'value', 'reconciled_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reconciled_at' => 'datetime', 'value' => 'integer'];
    }
}
