<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\LatePolicy;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $instructions
 * @property float $total_points
 * @property float|null $passing_points
 * @property Carbon|null $due_at
 * @property LatePolicy $late_policy
 * @property int $late_penalty_percent
 * @property int|null $max_attempts
 * @property bool $allow_text
 * @property bool $allow_files
 * @property int $max_file_size_kb
 * @property list<string>|null $allowed_extensions
 * @property int $max_files
 */
final class Assignment extends Model
{
    protected $fillable = [
        'instructions', 'total_points', 'passing_points',
        'due_at', 'late_policy', 'late_penalty_percent', 'max_attempts',
        'allow_text', 'allow_files', 'max_file_size_kb', 'allowed_extensions', 'max_files',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'late_policy' => LatePolicy::class,
            'total_points' => 'float',
            'passing_points' => 'float',
            'allow_text' => 'boolean',
            'allow_files' => 'boolean',
            'allowed_extensions' => 'array',
        ];
    }

    /** @return MorphOne<CourseItem, $this> */
    public function item(): MorphOne
    {
        return $this->morphOne(CourseItem::class, 'itemable');
    }

    /** @return BelongsToMany<Media, $this> */
    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'assignment_attachments', 'assignment_id', 'media_id')
            ->withPivot('position')
            ->orderBy('assignment_attachments.position');
    }

    /** @return HasMany<AssignmentSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function isPastDue(?Carbon $at = null): bool
    {
        return $this->due_at !== null && ($at ?? now())->greaterThan($this->due_at);
    }

    /**
     * The extensions a learner may hand in.
     *
     * `null` means "whatever the Submission media collection already allows" —
     * the per-assignment list narrows that set, it never widens it, so an
     * author cannot open a hole by typing `exe` into a box.
     *
     * @return list<string>|null
     */
    public function extensionAllowlist(): ?array
    {
        $configured = array_values(array_filter(
            array_map(
                fn (string $extension) => mb_strtolower(ltrim(trim($extension), '.')),
                $this->allowed_extensions ?? [],
            ),
        ));

        return $configured === [] ? null : $configured;
    }

    public function maxFileBytes(): int
    {
        return $this->max_file_size_kb * 1024;
    }
}
