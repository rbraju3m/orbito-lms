<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Data\BundleData;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateBundleRequest extends FormRequest
{
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
            'thumbnail_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'course_ids' => ['sometimes', 'array', 'max:50'],
            'course_ids.*' => ['integer', 'distinct', 'exists:courses,id'],
        ];
    }

    public function toData(): BundleData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return BundleData::fromArray($validated);
    }

    /**
     * The keys actually sent, so `UpdateBundle` can tell "omitted" from
     * "sent as null".
     *
     * @return array<string, mixed>
     */
    public function suppliedKeys(): array
    {
        return $this->validated();
    }
}
