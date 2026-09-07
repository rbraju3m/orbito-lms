<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The name and size are copied at submission time. The media row can be
 * renamed or soft-deleted later, and a graded submission must still describe
 * what was actually handed in.
 *
 * @property int $id
 * @property int $submission_id
 * @property int $media_id
 * @property string $original_name
 * @property int $size_bytes
 */
final class AssignmentSubmissionFile extends Model
{
    protected $fillable = ['submission_id', 'media_id', 'original_name', 'size_bytes'];

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** @return BelongsTo<AssignmentSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'submission_id');
    }
}
