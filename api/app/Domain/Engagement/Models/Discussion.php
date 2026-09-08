<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Engagement\Enums\DiscussionStatus;
use App\Domain\Engagement\Enums\DiscussionType;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Engagement\DiscussionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One thread.
 *
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property int|null $course_item_id
 * @property int $user_id
 * @property DiscussionType $type
 * @property string $title
 * @property string $body
 * @property DiscussionStatus $status
 * @property bool $is_pinned
 * @property int $reply_count
 * @property CarbonInterface|null $last_reply_at
 * @property int|null $accepted_reply_id
 *
 * `user_id` crosses the schema boundary to a table with no FK (§16).
 * @property-read User|null $user
 * @property-read CourseItem|null $item
 */
final class Discussion extends Model
{
    /** @use HasFactory<DiscussionFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id', 'course_item_id', 'user_id', 'type', 'title', 'body',
        'status', 'is_pinned', 'reply_count', 'last_reply_at', 'accepted_reply_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DiscussionType::class,
            'status' => DiscussionStatus::class,
            'is_pinned' => 'boolean',
            'reply_count' => 'integer',
            'last_reply_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $discussion): void {
            $discussion->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<CourseItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CourseItem::class, 'course_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DiscussionReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(DiscussionReply::class);
    }

    /**
     * What a non-moderator may see.
     *
     * One definition, so the course list, the lesson panel and the counts
     * cannot each decide differently.
     *
     * @param  Builder<Discussion>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', '!=', DiscussionStatus::Hidden);
    }

    public function isResolved(): bool
    {
        return $this->status === DiscussionStatus::Resolved;
    }
}
