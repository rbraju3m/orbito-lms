<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessment;

use App\Domain\Assessment\Models\Question;
use App\Domain\Media\Support\MediaUrlGenerator;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A question as served to a learner DURING an attempt.
 *
 * ADR-06: this class must never expose `is_correct`, `match_key`,
 * `explanation`, or `settings.accepted`. That is enforced structurally — it is
 * a separate class from the authoring resource, so leaking the answers would
 * take a deliberate edit here rather than a forgotten flag somewhere else.
 * `QuizSecurityTest` asserts it.
 *
 * @mixin Question
 */
final class AttemptQuestionResource extends BaseResource
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
            'points' => (float) $this->points,
            'media_url' => $this->media !== null ? app(MediaUrlGenerator::class)->for($this->media) : null,

            // Blank count only — never the accepted answers.
            'blank_count' => $this->when(
                $this->type->value === 'fill_blank',
                fn () => count($this->settings['blanks'] ?? []),
            ),

            // The right-hand values a learner drags onto, shuffled and detached
            // from which option they belong to.
            'match_targets' => $this->when(
                in_array($this->type->value, ['matching', 'image_matching'], true),
                fn () => $this->options
                    ->pluck('match_key')
                    ->filter()
                    ->unique()
                    ->shuffle()
                    ->values(),
            ),

            'options' => $this->when(
                $this->type->hasOptions(),
                fn () => $this->options->map(fn ($option): array => [
                    'id' => $option->id,
                    'label' => $option->label,
                    'media_url' => $option->media !== null
                        ? app(MediaUrlGenerator::class)->for($option->media)
                        : null,
                ])->values()->all(),
            ),
        ];
    }
}
