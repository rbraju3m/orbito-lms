<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $owner_id
 * @property int|null $course_id
 * @property string $title
 * @property bool $is_shared
 */
final class QuestionBank extends Model
{
    protected $fillable = ['owner_id', 'course_id', 'title', 'description', 'is_shared'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_shared' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Question, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'bank_id');
    }
}
