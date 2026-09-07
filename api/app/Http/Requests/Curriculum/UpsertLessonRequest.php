<?php

declare(strict_types=1);

namespace App\Http\Requests\Curriculum;

use App\Domain\Curriculum\Enums\ContentFormat;
use App\Domain\Curriculum\Enums\VideoProvider;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpsertLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'content_format' => ['sometimes', Rule::enum(ContentFormat::class)],

            'video_provider' => ['sometimes', Rule::enum(VideoProvider::class)],
            'video_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'video_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'video_duration_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'document_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],

            'title' => ['sometimes', 'string', 'min:1', 'max:180'],
            'is_preview' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'duration_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Existing is not the same as yours. Without this a lesson could
            // point at another instructor's private video.
            $this->assertOwnedMedia($validator, 'video_media_id', MediaCollection::LessonVideo);
            $this->assertOwnedMedia($validator, 'document_media_id', MediaCollection::LessonAttachment);

            $provider = $this->input('video_provider');

            if ($provider === VideoProvider::Upload->value && ! $this->filled('video_media_id')) {
                $validator->errors()->add('video_media_id', 'Choose an uploaded video file.');
            }

            if (in_array($provider, ['youtube', 'vimeo', 'external'], true) && ! $this->filled('video_url')) {
                $validator->errors()->add('video_url', 'Enter the video URL.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function lessonAttributes(): array
    {
        return $this->safe()->only([
            'content', 'content_format', 'video_provider', 'video_media_id',
            'video_url', 'video_duration_seconds', 'document_media_id',
        ]);
    }

    /** @return array<string, mixed> */
    public function itemAttributes(): array
    {
        return $this->safe()->only(['title', 'is_preview', 'is_published', 'duration_seconds']);
    }

    private function assertOwnedMedia(Validator $validator, string $field, MediaCollection $collection): void
    {
        $id = $this->input($field);

        if (! is_numeric($id)) {
            return;
        }

        $media = Media::find((int) $id);

        if ($media === null
            || $media->owner_id !== $this->user()?->id
            || $media->collection !== $collection->value) {
            $validator->errors()->add($field, 'That file is not available for this field.');
        }
    }
}
