<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\CompletionMode;
use App\Domain\Catalog\Enums\CourseLevel;
use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Enums\CourseVisibility;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Commerce\Models\Product;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Support\Database\LivesInTenantSchema;
use Carbon\CarbonInterface;
use Database\Factories\Catalog\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $description
 * @property int $owner_id
 * @property int|null $category_id
 * @property CourseLevel $level
 * @property string $locale
 * @property CourseStatus $status
 * @property CourseVisibility $visibility
 * @property CompletionMode $completion_mode
 * @property PricingModel $pricing_model
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $archived_at
 *
 * A course created before Phase 4 — or by a factory — can be missing its
 * settings row, so every read of it is nullsafe.
 * @property CourseSetting|null $setting
 * @property-read Collection<int, Course> $prerequisites
 */
final class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory, LivesInTenantSchema, SoftDeletes;

    protected $fillable = [
        'title', 'subtitle', 'description', 'category_id', 'level', 'locale',
        'visibility', 'completion_mode', 'pricing_model',
        'thumbnail_media_id', 'intro_video_media_id', 'intro_video_url', 'coming_soon_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => CourseLevel::class,
            'status' => CourseStatus::class,
            'visibility' => CourseVisibility::class,
            'completion_mode' => CompletionMode::class,
            'pricing_model' => PricingModel::class,
            'published_at' => 'datetime',
            'submitted_at' => 'datetime',
            'archived_at' => 'datetime',
            'coming_soon_at' => 'datetime',
            'rating_avg' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $course): void {
            $course->uuid ??= (string) Str::uuid7();
            $course->slug ??= self::generateSlug($course->title);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Slugs are stable once assigned: a published course's URL is shared,
     * bookmarked and indexed, so renaming the title must not break it.
     */
    public static function generateSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'course';
        $slug = $base;
        $suffix = 1;

        while (self::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return Str::limit($slug, 180, '');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<CourseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'category_id');
    }

    /** @return BelongsToMany<CourseTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CourseTag::class, 'course_tag', 'course_id', 'course_tag_id');
    }

    /** @return HasMany<CourseInstructor, $this> */
    public function instructors(): HasMany
    {
        return $this->hasMany(CourseInstructor::class)->orderBy('position');
    }

    /** @return HasMany<CourseSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('position');
    }

    /**
     * The whole spine, in display order (ADR-01).
     *
     * @return HasMany<CourseItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CourseItem::class)->orderBy('position');
    }

    /**
     * Courses that must be completed before this one may be entered.
     *
     * @return BelongsToMany<Course, $this>
     */
    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'course_prerequisites',
            'course_id',
            'prerequisite_course_id',
        )->withPivot('position')->orderBy('course_prerequisites.position');
    }

    /**
     * The reverse edge: courses this one opens the door to. Read by the
     * "what next?" panel, and by the cycle check when prerequisites are set.
     *
     * @return BelongsToMany<Course, $this>
     */
    public function unlocks(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'course_prerequisites',
            'prerequisite_course_id',
            'course_id',
        );
    }

    /**
     * Seats left, or null when the course is uncapped. Derived from the same
     * `active()` scope EnrollInCourse counts with, so the number shown and the
     * number enforced cannot disagree.
     */
    public function seatsRemaining(): ?int
    {
        $this->loadMissing('setting');
        $max = $this->setting?->max_students;

        if ($max === null) {
            return null;
        }

        return max(0, $max - $this->enrollments()->active()->count());
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * The sellable twin, if this course has one (ADR-13).
     *
     * A course is not a product — it is a thing a product can point at, which
     * is what lets a bundle sell three of them through one checkout later. The
     * relation exists so the catalogue can render a PRICE without the page
     * making a second request, and it is `morphOne` because `products` is
     * polymorphic from the start.
     *
     * Reading it is a display concern. Nothing in Catalog may act on it —
     * pricing and selling stay in Commerce.
     *
     * @return MorphOne<Product, $this>
     */
    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'purchasable');
    }

    /** @return HasOne<CourseDetail, $this> */
    public function detail(): HasOne
    {
        return $this->hasOne(CourseDetail::class);
    }

    /** @return HasOne<CourseSetting, $this> */
    public function setting(): HasOne
    {
        return $this->hasOne(CourseSetting::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function thumbnail(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'thumbnail_media_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function introVideo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'intro_video_media_id');
    }

    /**
     * Anyone with a course-staff seat: the owner, co-instructors and assistants.
     * Course-scoped roles (Manager, Reviewer, TA) are separate — they come from
     * role_assignments and are answered by hasPermission($key, $course).
     */
    public function isStaffedBy(User $user): bool
    {
        if ($this->owner_id === $user->id) {
            return true;
        }

        $this->loadMissing('instructors');

        return $this->instructors->contains(fn (CourseInstructor $i) => $i->user_id === $user->id);
    }

    /**
     * Visible in the public catalogue.
     *
     * @param  Builder<Course>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->where('status', CourseStatus::Published)
            ->where('visibility', CourseVisibility::Public);
    }

    /**
     * Reachable by direct link — published, but possibly unlisted.
     *
     * @param  Builder<Course>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', CourseStatus::Published)
            ->whereIn('visibility', [CourseVisibility::Public, CourseVisibility::Unlisted]);
    }
}
