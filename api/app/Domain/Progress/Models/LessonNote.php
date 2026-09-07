<?php

declare(strict_types=1);

namespace App\Domain\Progress\Models;

use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $course_item_id
 * @property string $body
 * @property int|null $video_timestamp_seconds
 */
final class LessonNote extends Model
{
    protected $fillable = ['user_id', 'course_item_id', 'course_id', 'body', 'video_timestamp_seconds'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CourseItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CourseItem::class, 'course_item_id');
    }
}
