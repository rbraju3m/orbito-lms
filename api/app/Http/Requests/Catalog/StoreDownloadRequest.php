<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Data\DownloadData;
use App\Domain\Catalog\Enums\DownloadPricing;
use App\Domain\Media\Enums\MediaCollection;
use App\Http\Requests\Concerns\ValidatesOwnedMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreDownloadRequest extends FormRequest
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
            'media_id' => ['sometimes', 'nullable', 'integer'],
            'thumbnail_media_id' => ['sometimes', 'nullable', 'integer'],
            'pricing_model' => ['sometimes', Rule::enum(DownloadPricing::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // The file must be one YOU uploaded into the download collection —
            // never somebody's submission, never a lesson attachment.
            $this->assertOwnedMedia($validator, 'media_id', MediaCollection::Download);
            $this->assertOwnedMedia($validator, 'thumbnail_media_id', MediaCollection::CourseThumbnail);
        });
    }

    public function toData(): DownloadData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return DownloadData::fromArray($validated);
    }
}
