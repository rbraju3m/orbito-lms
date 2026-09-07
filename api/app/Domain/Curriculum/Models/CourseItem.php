<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Enums\ItemType;
use App\Domain\Media\Models\Media;
use Carbon\CarbonInterface;
use Database\Factories\Curriculum\CourseItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One rung on the course spine (ADR-01).
 *
 * Progress, drip and ordering all key on this row, so a new content type in a
 * later phase costs one `itemable` implementation and nothing else.
 *
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property int $section_id
 * @property int $position
 * @property ItemType $type
 * @property string $itemable_type
 * @property int $itemable_id
 * @property string $title
 * @property bool $is_preview
 * @property bool $is_published
 * @property int $duration_seconds
 * @property CarbonInterface|null $drip_available_at
 */
final class CourseItem extends Model
{
    /** @use HasFactory<CourseItemFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id', 'section_id', 'position', 'type', 'itemable_type', 'itemable_id',
        'title', 'is_preview', 'is_published', 'duration_seconds',
        'drip_available_at', 'drip_after_days', 'drip_after_item_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'is_preview' => 'boolean',
            'is_published' => 'boolean',
            'drip_available_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->uuid ??= (string) Str::uuid7();
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

    /** @return BelongsTo<CourseSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    /** @return MorphTo<Model, $this> */
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsToMany<Media, $this> */
    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'course_item_attachments')
            ->withPivot('position')
            ->orderBy('course_item_attachments.position');
    }

    /**
     * The next item a learner should see. One indexed query, because position
     * is course-global — this is the whole point of ADR-01.
     */
    public function next(): ?self
    {
        return self::query()
            ->where('course_id', $this->course_id)
            ->where('is_published', true)
            ->where('position', '>', $this->position)
            ->orderBy('position')
            ->first();
    }

    public function previous(): ?self
    {
        return self::query()
            ->where('course_id', $this->course_id)
            ->where('is_published', true)
            ->where('position', '<', $this->position)
            ->orderByDesc('position')
            ->first();
    }

    /** @param  Builder<CourseItem>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }
}
