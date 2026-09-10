<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Data\BundleData;
use App\Domain\Media\Enums\MediaCollection;
use App\Http\Requests\Concerns\ValidatesOwnedMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreBundleRequest extends FormRequest
{
    use ValidatesOwnedMedia;

    /** Authorised by the policy in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:180'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'thumbnail_media_id' => ['sometimes', 'nullable', 'integer'],

            // The WHOLE collection, never a delta (§ Phase 5). Two people
            // editing one bundle must not interleave into a set neither asked
            // for — and here that set is what somebody gets charged for.
            'course_ids' => ['sometimes', 'array', 'max:50'],
            'course_ids.*' => ['integer', 'distinct', 'exists:courses,id'],
        ];
    }

    /**
     * Shipped in the bundles slice checking `exists:media,id` alone — the
     * exact thing §10 forbids. Fixed here: the cover must be YOUR image.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertOwnedMedia($validator, 'thumbnail_media_id', MediaCollection::CourseThumbnail);
        });
    }

    public function toData(): BundleData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return BundleData::fromArray($validated);
    }
}
