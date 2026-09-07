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
