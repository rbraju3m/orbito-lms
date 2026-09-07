<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property int $usage_count
 */
final class CourseTag extends Model
{
    protected $fillable = ['slug', 'name', 'usage_count'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Tags are created on demand from free text, so normalise on the slug. */
    public static function findOrCreateByName(string $name): self
    {
        $slug = Str::slug($name);

        return self::firstOrCreate(['slug' => $slug], ['name' => trim($name)]);
    }

    /** @return BelongsToMany<Course, $this> */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_tag', 'course_tag_id', 'course_id');
    }
}
