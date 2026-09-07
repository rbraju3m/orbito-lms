<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Long-form list content, split off `courses` so the catalogue query never
 * reads cold JSON.
 *
 * @property int $course_id
 * @property list<string>|null $objectives
 * @property list<string>|null $requirements
 * @property list<string>|null $target_audience
 * @property list<string>|null $materials
 */
final class CourseDetail extends Model
{
    protected $primaryKey = 'course_id';

    public $incrementing = false;

    protected $fillable = ['course_id', 'objectives', 'requirements', 'target_audience', 'materials', 'faq'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'objectives' => 'array',
            'requirements' => 'array',
            'target_audience' => 'array',
            'materials' => 'array',
            'faq' => 'array',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
