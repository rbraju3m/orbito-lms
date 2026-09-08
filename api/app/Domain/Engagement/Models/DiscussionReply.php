<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Models;

use App\Domain\Identity\Models\User;
use Database\Factories\Engagement\DiscussionReplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One reply. At most one level deep — see the migration.
 *
 * @property int $id
 * @property string $uuid
 * @property int $discussion_id
 * @property int|null $parent_id
 * @property int $user_id
 * @property string $body
 * @property bool $is_instructor_reply
 * @property string $status
 * @property-read User|null $user
 * @property-read DiscussionReply|null $parent
 */
final class DiscussionReply extends Model
{
    /** @use HasFactory<DiscussionReplyFactory> */
    use HasFactory;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    protected $fillable = [
        'discussion_id', 'parent_id', 'user_id', 'body', 'is_instructor_reply', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_instructor_reply' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $reply): void {
            $reply->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /** @return BelongsTo<DiscussionReply, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<DiscussionReply, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The replies that COUNT — toward `reply_count`, and toward whether a
     * thread reads as answered. Hiding a reply must roll both back.
     *
     * @param  Builder<DiscussionReply>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
