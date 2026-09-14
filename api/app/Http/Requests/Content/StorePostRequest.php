<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use App\Domain\Media\Enums\MediaCollection;
use App\Http\Requests\Concerns\ValidatesOwnedMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A new draft. Every field but the title may come later. */
final class StorePostRequest extends FormRequest
{
    use ValidatesOwnedMedia;

    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            // Lower-case words joined by single hyphens — the shape
            // `Str::slug()` produces, so a hand-typed one reads the same.
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('posts', 'slug')],
            'excerpt' => ['nullable', 'string', 'max:500'],
            // Sanitised on write; the sanitizer's own cap is 500 KB.
            'body' => ['nullable', 'string', 'max:200000'],
            'cover_media_id' => ['nullable', 'integer'],
            'seo_title' => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // A cover shares the course-cover collection, like bundles and downloads.
            $this->assertOwnedMedia($validator, 'cover_media_id', MediaCollection::CourseThumbnail);
        }];
    }
}
