<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved course.
 *
 * @property int $id
 * @property int $user_id
 * @property int $course_id
 */
final class WishlistItem extends Model
{
    protected $table = 'wishlists';

    protected $fillable = ['user_id', 'course_id'];

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
