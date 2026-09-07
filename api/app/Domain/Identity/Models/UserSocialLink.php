<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $platform
 * @property string $url
 */
final class UserSocialLink extends Model
{
    /*
     * CENTRAL. Pinned so this model can never be read through a tenant
     * connection: when tenancy is initialised the default connection is
     * swapped, and an unpinned central model would silently query a table of
     * the same name inside the academy's schema — or fail because there is
     * none. CentralModelConnectionTest enforces this.
     */
    protected $connection = 'mysql';

    protected $fillable = ['user_id', 'platform', 'url'];

    /** The platforms a profile may link to. Anything else is rejected. */
    public const PLATFORMS = [
        'website', 'twitter', 'linkedin', 'github', 'facebook', 'instagram', 'youtube',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
