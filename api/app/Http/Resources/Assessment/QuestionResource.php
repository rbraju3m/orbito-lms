<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessment;

use App\Domain\Assessment\Models\Question;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The AUTHORING shape — includes correct answers and explanations.
 *
 * Only ever returned from /studio routes, which authorize against the course.
 * The learner-facing shape is AttemptQuestionResource.
 *
 * @mixin Question
 */
final class QuestionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'ref' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'title' => $this->title,
            'body' => $this->body,
            'explanation' => $this->explanation,
            'points' => (float) $this->points,
            'negative_points' => (float) $this->negative_points,
            'settings' => $this->settings,
            'needs_manual_grading' => $this->needsManualGrading(),
            'options' => $this->whenLoaded(
                'options',
                fn () => $this->options->map(fn ($option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                    'media_id' => $option->media_id,
                    'is_correct' => $option->is_correct,
                    'match_key' => $option->match_key,
                    'position' => $option->position,
                ])->values(),
            ),
        ];
    }
}
