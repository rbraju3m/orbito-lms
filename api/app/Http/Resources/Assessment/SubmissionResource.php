<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessment;

use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Media\Support\MediaUrlGenerator;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin AssignmentSubmission
 */
final class SubmissionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $graded = $this->status->value === 'graded';

        return [
            'id' => $this->uuid,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'body' => $this->body,
            'submitted_at' => $this->submitted_at->toIso8601String(),
            'is_late' => $this->is_late,

            // Absent until a grader has been through it, rather than present
            // and zero — "0/100" and "not marked yet" are different things.
            'points_raw' => $this->when($graded, fn () => (float) $this->points_raw),
            'late_penalty_points' => $this->when($graded, fn () => (float) $this->late_penalty_points),
            'points_earned' => $this->when($graded, fn () => (float) $this->points_earned),
            'passed' => $this->when($graded && $this->passed !== null, fn () => $this->passed),

            'feedback' => $this->feedback,
            'graded_at' => $this->graded_at?->toIso8601String(),
            'graded_by' => $this->whenLoaded('grader', fn () => $this->grader?->name),

            'files' => $this->whenLoaded('files', fn () => $this->files
                ->map(fn ($file) => [
                    'id' => $file->media_id,
                    'name' => $file->original_name,
                    'size_bytes' => $file->size_bytes,
                    'url' => $file->media === null
                        ? null
                        : app(MediaUrlGenerator::class)->for($file->media),
                ])->values()->all()),
        ];
    }
}
