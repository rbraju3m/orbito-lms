<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Models;

use App\Domain\Catalog\Models\Course;
use Database\Factories\Curriculum\CourseSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $course_id
 * @property string $title
 * @property string|null $description
 * @property int $position
 */
final class CourseSection extends Model
{
    /** @use HasFactory<CourseSectionFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['course_id', 'title', 'description', 'position'];

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<CourseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CourseItem::class, 'section_id')->orderBy('position');
    }
}
