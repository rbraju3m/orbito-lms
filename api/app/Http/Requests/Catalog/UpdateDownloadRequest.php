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

final class UpdateDownloadRequest extends FormRequest
{
    use ValidatesOwnedMedia;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:5', 'max:180'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            // Replacing the file on a published download is allowed: it is a
            // LIVE file, and buyers get the new one on their next fetch.
            'media_id' => ['sometimes', 'nullable', 'integer'],
            'thumbnail_media_id' => ['sometimes', 'nullable', 'integer'],
            'pricing_model' => ['sometimes', Rule::enum(DownloadPricing::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
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

    /** @return array<string, mixed> */
    public function suppliedKeys(): array
    {
        return $this->validated();
    }
}
