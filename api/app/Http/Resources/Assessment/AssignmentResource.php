<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessment;

use App\Domain\Assessment\Models\Assignment;
use App\Domain\Media\Support\MediaUrlGenerator;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One resource, not two: unlike a quiz question, nothing about an assignment
 * is secret from the learner. The instructions, the deadline, the marks and
 * the file rules are exactly what they need in order to hand work in.
 *
 * @mixin Assignment
 */
final class AssignmentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'instructions' => $this->instructions,
            'total_points' => (float) $this->total_points,
            'passing_points' => $this->passing_points === null ? null : (float) $this->passing_points,

            'due_at' => $this->due_at?->toIso8601String(),
            'late_policy' => $this->late_policy->value,
            'late_policy_label' => $this->late_policy->label(),
            'late_penalty_percent' => $this->late_penalty_percent,

            'max_attempts' => $this->max_attempts,
            'allow_text' => $this->allow_text,
            'allow_files' => $this->allow_files,
            'max_file_size_kb' => $this->max_file_size_kb,
            'max_files' => $this->max_files,
            'allowed_extensions' => $this->extensionAllowlist(),

            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments
                ->map(fn ($media) => [
                    'id' => $media->id,
                    'name' => $media->original_name,
                    'size_bytes' => $media->size_bytes,
                    // Short-lived and minted only after access was granted (ADR-09).
                    'url' => app(MediaUrlGenerator::class)->for($media),
                ])->values()->all()),
        ];
    }
}
