<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use Database\Factories\Assessment\QuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property QuestionType $type
 * @property string $title
 * @property string|null $explanation
 * @property string $points
 * @property string $negative_points
 * @property array<string, mixed>|null $settings
 */
final class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'bank_id', 'owner_id', 'type', 'title', 'body', 'explanation',
        'points', 'negative_points', 'media_id', 'settings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'settings' => 'array',
            'points' => 'decimal:2',
            'negative_points' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        self::creating(fn (self $q) => $q->uuid ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<QuestionBank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'bank_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<QuestionOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    /** @return BelongsToMany<Quiz, $this> */
    public function quizzes(): BelongsToMany
    {
        return $this->belongsToMany(Quiz::class, 'quiz_questions');
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * Short answer grades itself only when accepted answers are configured;
     * otherwise it queues for a human rather than marking everything wrong.
     */
    public function needsManualGrading(): bool
    {
        if ($this->type->alwaysNeedsReview()) {
            return true;
        }

        return $this->type === QuestionType::ShortAnswer
            && ($this->settings['accepted'] ?? []) === [];
    }
}
