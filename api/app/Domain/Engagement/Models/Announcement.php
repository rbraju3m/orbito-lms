<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Engagement\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property int $author_id
 * @property string $title
 * @property string $body
 * @property CarbonInterface|null $published_at
 * @property bool $notify
 *
 * `author_id` crosses the schema boundary to a table with no FK (§ Multi-tenancy).
 * @property-read User|null $author
 */
final class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    protected $fillable = ['course_id', 'author_id', 'title', 'body', 'published_at', 'notify'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'notify' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $announcement): void {
            $announcement->uuid ??= (string) Str::uuid7();
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

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * What a learner may see.
     *
     * Evaluated against NOW rather than a stored flag, so an announcement
     * scheduled for later becomes visible on its own — the same reasoning as
     * drip and sale prices, which are also live rather than swept.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && ! $this->published_at->isFuture();
    }
}
