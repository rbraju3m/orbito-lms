<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use Carbon\CarbonInterface;
use Database\Factories\Content\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A blog post on the academy's public site (docs/BLOG.md).
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property int $author_id
 * @property string $title
 * @property string|null $excerpt
 * @property string|null $body
 * @property int|null $cover_media_id
 * @property PostStatus $status
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $announced_at when `post.published` fired — once, ever (`AnnouncePost`)
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 *
 * `author_id` crosses the schema boundary to a table with no FK (§ Multi-tenancy).
 * @property-read User|null $author
 * @property-read Media|null $cover
 */
final class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected $fillable = [
        'slug', 'author_id', 'title', 'excerpt', 'body', 'cover_media_id',
        'status', 'published_at', 'seo_title', 'seo_description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'cover_media_id' => 'integer',
            'status' => PostStatus::class,
            'published_at' => 'datetime',
            'announced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $post): void {
            $post->uuid ??= (string) Str::uuid7();
            $post->slug ??= self::generateSlug($post->title);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** A readable, unique address from the title — `-2`, `-3` on a collision. */
    public static function generateSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'post', 180, '');
        $slug = $base;
        $suffix = 1;

        while (self::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /**
     * What a stranger may read: published, and its time has come. Compared
     * against NOW rather than a flag, so a scheduled post appears on its own.
     *
     * @param  Builder<Post>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PostStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isLive(): bool
    {
        return $this->status === PostStatus::Published
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }

    public function isScheduled(): bool
    {
        return $this->status === PostStatus::Published
            && $this->published_at !== null
            && $this->published_at->isFuture();
    }

    /**
     * Minutes to read at 200 words a minute, never less than one.
     *
     * Words are split on whitespace with the Unicode flag rather than counted by
     * `str_word_count`, which knows only ASCII letters and would read a Bengali
     * post — this product's first academy writes in Bengali — as nearly empty.
     */
    public function readingMinutes(): int
    {
        $text = trim(html_entity_decode(strip_tags((string) $this->body)));

        if ($text === '') {
            return 1;
        }

        $words = preg_split('/\s+/u', $text) ?: [];

        return max(1, (int) ceil(count($words) / 200));
    }
}
