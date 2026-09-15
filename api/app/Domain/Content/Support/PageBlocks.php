<?php

declare(strict_types=1);

namespace App\Domain\Content\Support;

use App\Domain\Content\Enums\BlockType;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Validation\Rule;

/**
 * The shape of a page's `blocks` list, and the one place it is enforced.
 *
 * A block is `{id, type, props}`:
 *  - `id` is the builder's own key for the block — stable across saves, so the
 *    drag-and-drop list and a React key do not jump — and means nothing to
 *    the server beyond "distinct".
 *  - `type` is a `BlockType`. An unknown one is refused.
 *  - `props` are validated by the type's own rules and then NORMALISED: keys
 *    the type does not declare are dropped, numbers become numbers, and text
 *    HTML is sanitised. What is stored is exactly what the renderer expects.
 *
 * The whole list is sent and stored on every save (§ Patterns established in
 * Phase 5) — there is no "move block 3" endpoint for two editors to interleave.
 */
final class PageBlocks
{
    /** Enough for a real page; a page of a thousand blocks is somebody's script. */
    public const MAX_BLOCKS = 50;

    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /**
     * Rules for a request's `blocks`, with each block's props checked against
     * ITS type's rules — built from the input, because which rules apply to
     * `blocks.3.props` depends on what `blocks.3.type` says.
     *
     * @return array<string, mixed>
     */
    public static function rules(mixed $blocks): array
    {
        $rules = [
            'blocks' => ['present', 'array', 'list', 'max:'.self::MAX_BLOCKS],
            'blocks.*' => ['array:id,type,props'],
            'blocks.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'blocks.*.type' => ['required', Rule::enum(BlockType::class)],
            'blocks.*.props' => ['present', 'array'],
        ];

        if (! is_array($blocks)) {
            return $rules;
        }

        foreach ($blocks as $index => $block) {
            $type = is_array($block) && is_string($block['type'] ?? null)
                ? BlockType::tryFrom($block['type'])
                : null;

            // The enum rule above refuses it; there is nothing to check it against.
            if ($type === null) {
                continue;
            }

            foreach ($type->rules() as $field => $fieldRules) {
                $rules["blocks.{$index}.props.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * @param  array<int, mixed>  $blocks  already validated by `rules()`
     * @return list<array{id: string, type: string, props: array<string, mixed>}>
     */
    public function normalise(array $blocks): array
    {
        $normalised = [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_array($block['props'] ?? null)) {
                continue;
            }

            $type = BlockType::from((string) $block['type']);
            $props = $block['props'];

            $normalised[] = [
                'id' => (string) $block['id'],
                'type' => $type->value,
                'props' => match ($type) {
                    BlockType::Heading => [
                        'text' => $this->text($props['text'] ?? null) ?? '',
                        'level' => $this->int($props['level'] ?? 2),
                    ],
                    BlockType::Text => [
                        'html' => $this->sanitizer->clean($this->text($props['html'] ?? null)) ?? '',
                    ],
                    BlockType::Image => [
                        'media_ref' => $this->int($props['media_ref'] ?? 0),
                        'alt' => $this->text($props['alt'] ?? null),
                        'caption' => $this->text($props['caption'] ?? null),
                    ],
                    BlockType::Button => [
                        'label' => $this->text($props['label'] ?? null) ?? '',
                        'url' => $this->text($props['url'] ?? null) ?? '',
                    ],
                    BlockType::Courses => [
                        'title' => $this->text($props['title'] ?? null),
                        'course_ids' => array_values(array_filter(
                            is_array($props['course_ids'] ?? null) ? $props['course_ids'] : [],
                            'is_string',
                        )),
                    ],
                    BlockType::Webinars, BlockType::Posts => [
                        'title' => $this->text($props['title'] ?? null),
                        'limit' => $this->int($props['limit'] ?? 3),
                    ],
                    BlockType::LeadForm => [
                        'title' => $this->text($props['title'] ?? null),
                        'description' => $this->text($props['description'] ?? null),
                    ],
                },
            ];
        }

        return $normalised;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
