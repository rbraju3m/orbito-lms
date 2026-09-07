<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\CourseInstructorRole;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $course_id
 * @property int $user_id
 * @property CourseInstructorRole $role
 */
final class CourseInstructor extends Model
{
    protected $fillable = ['course_id', 'user_id', 'role', 'revenue_share_bp', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => CourseInstructorRole::class];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
