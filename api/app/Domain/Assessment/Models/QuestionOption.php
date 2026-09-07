<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $question_id
 * @property string $label
 * @property bool $is_correct
 * @property string|null $match_key
 * @property int $position
 */
final class QuestionOption extends Model
{
    protected $fillable = ['question_id', 'label', 'media_id', 'is_correct', 'match_key', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
