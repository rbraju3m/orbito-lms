<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Models\Post;
use App\Domain\Media\Enums\MediaCollection;
use App\Http\Requests\Concerns\ValidatesOwnedMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A partial update — only the fields sent change.
 *
 * The SLUG is locked once a post has been published: it is the post's public
 * address, and a link somebody shared, bookmarked or a search engine indexed
 * must keep landing. Unpublishing does not unlock it — the link is out there
 * whether or not the page currently is.
 */
final class UpdatePostRequest extends FormRequest
{
    use ValidatesOwnedMedia;

    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $post = $this->route('post');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('posts', 'slug')->ignore($post instanceof Post ? $post->id : null),
            ],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'cover_media_id' => ['sometimes', 'nullable', 'integer'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->assertOwnedMedia($validator, 'cover_media_id', MediaCollection::CourseThumbnail);

            $post = $this->route('post');

            if ($post instanceof Post
                && $this->has('slug')
                && $this->input('slug') !== $post->slug
                && ($post->status === PostStatus::Published || $post->published_at !== null)) {
                $validator->errors()->add('slug', 'A post that has been published keeps its address, so links to it keep working.');
            }
        }];
    }
}
