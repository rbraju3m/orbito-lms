<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use App\Domain\Content\Enums\PageStatus;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Content\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A page on the academy's public site, built from blocks (docs/PAGES.md).
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property int $author_id
 * @property string $title
 * @property array<int, mixed>|null $blocks
 * @property PageStatus $status
 * @property CarbonInterface|null $published_at
 * @property bool $show_in_nav
 * @property string|null $home_key
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 *
 * `author_id` crosses the schema boundary to a table with no FK (§ Multi-tenancy).
 * @property-read User|null $author
 */
final class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    /** The value `home_key` holds on the one front page. */
    public const HOME = 'home';

    protected $fillable = [
        'slug', 'author_id', 'title', 'blocks', 'status', 'published_at',
        'show_in_nav', 'home_key', 'seo_title', 'seo_description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'blocks' => 'array',
            'status' => PageStatus::class,
            'published_at' => 'datetime',
            'show_in_nav' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $page): void {
            $page->uuid ??= (string) Str::uuid7();
            $page->slug ??= self::generateSlug($page->title);
            $page->blocks ??= [];
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function generateSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'page', 180, '');
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

    /** @param  Builder<Page>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PageStatus::Published);
    }

    public function isHome(): bool
    {
        return $this->home_key !== null;
    }

    /**
     * The blocks as the list `PageBlocks` stored — always a list, whatever the
     * column holds.
     *
     * @return list<array{id: string, type: string, props: array<string, mixed>}>
     */
    public function blockList(): array
    {
        $blocks = [];

        foreach ($this->blocks ?? [] as $block) {
            if (is_array($block)
                && is_string($block['id'] ?? null)
                && is_string($block['type'] ?? null)
                && is_array($block['props'] ?? null)) {
                /** @var array<string, mixed> $props */
                $props = $block['props'];
                $blocks[] = ['id' => $block['id'], 'type' => $block['type'], 'props' => $props];
            }
        }

        return $blocks;
    }
}
