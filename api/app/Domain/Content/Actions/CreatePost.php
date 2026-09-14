<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Models\Post;
use App\Domain\Identity\Models\User;
use App\Support\Html\RichTextSanitizer;

/**
 * A new post, always a DRAFT. Publishing is its own decision
 * (`ChangePostStatus`), so nothing reaches the public site by somebody saving
 * a form.
 */
final class CreatePost
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /** @param  array<string, mixed>  $attributes  validated by `StorePostRequest` */
    public function handle(User $author, array $attributes): Post
    {
        if (array_key_exists('body', $attributes)) {
            $attributes['body'] = $this->sanitizer->clean(is_string($attributes['body']) ? $attributes['body'] : null);
        }

        return Post::create([
            ...$attributes,
            'author_id' => $author->id,
            'status' => PostStatus::Draft,
            'published_at' => null,
        ]);
    }
}
