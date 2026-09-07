<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Data\CourseData;
use App\Domain\Catalog\Enums\CompletionMode;
use App\Domain\Catalog\Enums\CourseLevel;
use App\Domain\Catalog\Enums\CourseVisibility;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:3', 'max:180'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:course_categories,id'],
            'level' => ['sometimes', Rule::enum(CourseLevel::class)],
            'locale' => ['sometimes', 'string', Rule::in(config('orbito.locales.supported'))],
            'visibility' => ['sometimes', Rule::enum(CourseVisibility::class)],
            'completion_mode' => ['sometimes', Rule::enum(CompletionMode::class)],
            'pricing_model' => ['sometimes', Rule::enum(PricingModel::class)],

            'thumbnail_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'intro_video_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'intro_video_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],

            'tags' => ['sometimes', 'array', 'max:15'],
            'tags.*' => ['string', 'min:2', 'max:40'],

            'detail' => ['sometimes', 'array'],
            'detail.objectives' => ['sometimes', 'array', 'max:20'],
            'detail.objectives.*' => ['string', 'max:300'],
            'detail.requirements' => ['sometimes', 'array', 'max:20'],
            'detail.requirements.*' => ['string', 'max:300'],
            'detail.target_audience' => ['sometimes', 'array', 'max:20'],
            'detail.target_audience.*' => ['string', 'max:300'],
            'detail.materials' => ['sometimes', 'array', 'max:20'],
            'detail.materials.*' => ['string', 'max:300'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // An id that exists is not enough: it must be YOUR media, and of the
            // right kind. Otherwise anyone could point their course thumbnail at
            // someone else's private file.
            $this->assertOwnedMedia($validator, 'thumbnail_media_id', MediaCollection::CourseThumbnail);
            $this->assertOwnedMedia($validator, 'intro_video_media_id', MediaCollection::CourseIntroVideo);
        });
    }

    public function toData(): CourseData
    {
        return CourseData::fromArray($this->validated());
    }

    /** @return array<string, mixed> */
    public function suppliedKeys(): array
    {
        return $this->validated();
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
