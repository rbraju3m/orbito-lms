<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Post;
use App\Support\Html\RichTextSanitizer;

/**
 * The words, the address, the cover, the search snippet. Never the status —
 * that is `ChangePostStatus`, because saving a draft and putting it in front of
 * the internet are different acts (the announcement rule).
 *
 * The body is sanitised on WRITE, so the stored value is already safe for the
 * public page, an export or a feed (§ Patterns established in Phase 6).
 */
final class UpdatePost
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /** @param  array<string, mixed>  $attributes  validated by `UpdatePostRequest` */
    public function handle(Post $post, array $attributes): Post
    {
        if (array_key_exists('body', $attributes)) {
            $attributes['body'] = $this->sanitizer->clean(is_string($attributes['body']) ? $attributes['body'] : null);
        }

        unset($attributes['status'], $attributes['published_at'], $attributes['author_id']);

        $post->fill($attributes)->save();

        return $post;
    }
}
