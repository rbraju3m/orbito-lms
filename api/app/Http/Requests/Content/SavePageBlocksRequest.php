<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use App\Domain\Content\Enums\BlockType;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Support\PageBlocks;
use App\Domain\Media\Enums\MediaCollection;
use App\Http\Requests\Concerns\ValidatesOwnedMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A page's WHOLE block list (`PageBlocks` owns the shape).
 *
 * An image's file must be the saver's own — but only when the image is
 * ADDED. The list is sent whole on every save, so checking every image every
 * time would refuse an admin re-saving a page because a colleague put a
 * picture on it last week: the file is the colleague's, and it was checked
 * when the colleague added it. So a reference already on the stored page is
 * trusted, and a NEW one is checked against its owner (§ Patterns established
 * in Phase 4: an id that merely exists is not authorized).
 */
final class SavePageBlocksRequest extends FormRequest
{
    use ValidatesOwnedMedia;

    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return PageBlocks::rules($this->input('blocks'));
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $page = $this->route('page');
            $alreadyOnPage = $page instanceof Page
                ? collect($page->blockList())
                    ->where('type', BlockType::Image->value)
                    ->map(fn (array $block): int => is_numeric($block['props']['media_ref'] ?? null) ? (int) $block['props']['media_ref'] : 0)
                    ->all()
                : [];

            $blocks = $this->input('blocks');

            foreach (is_array($blocks) ? $blocks : [] as $index => $block) {
                if (! is_array($block) || ($block['type'] ?? null) !== BlockType::Image->value) {
                    continue;
                }

                $ref = is_array($block['props'] ?? null) && is_numeric($block['props']['media_ref'] ?? null)
                    ? (int) $block['props']['media_ref']
                    : 0;

                if (in_array($ref, $alreadyOnPage, true)) {
                    continue;
                }

                $this->assertOwnedMedia($validator, "blocks.{$index}.props.media_ref", MediaCollection::CourseThumbnail);
            }
        }];
    }

    /** @return array<int, mixed> */
    public function blocks(): array
    {
        $blocks = $this->validated('blocks');

        return is_array($blocks) ? array_values($blocks) : [];
    }
}
