<?php

declare(strict_types=1);

namespace App\Http\Resources\Progress;

use App\Domain\Curriculum\Models\CourseItem;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A curriculum item as a learner sees it: no authoring fields, plus their own
 * progress.
 *
 * @mixin CourseItem
 */
final class LearnerItemResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'title' => $this->title,
            'position' => $this->position,
            'duration_seconds' => $this->duration_seconds,
            'is_preview' => $this->is_preview,
            'is_completable' => $this->type->isCompletable(),
            // Counts toward completion, but is earned rather than declared —
            // the player hides "Mark complete" for these.
            'is_self_markable' => $this->type->isSelfMarkable(),

            'status' => $this->getAttribute('progress_status')->value ?? 'not_started',
            'watch_position_seconds' => (int) ($this->getAttribute('watch_position_seconds') ?? 0),
        ];
    }
}
