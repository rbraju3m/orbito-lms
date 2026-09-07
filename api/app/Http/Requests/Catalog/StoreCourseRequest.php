<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Data\CourseData;
use App\Domain\Catalog\Enums\CompletionMode;
use App\Domain\Catalog\Enums\CourseLevel;
use App\Domain\Catalog\Enums\CourseVisibility;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Course::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:course_categories,id'],
            'level' => ['sometimes', Rule::enum(CourseLevel::class)],
            'locale' => ['sometimes', 'string', Rule::in(config('orbito.locales.supported'))],
            'visibility' => ['sometimes', Rule::enum(CourseVisibility::class)],
            'completion_mode' => ['sometimes', Rule::enum(CompletionMode::class)],
            'pricing_model' => ['sometimes', Rule::enum(PricingModel::class)],
            'tags' => ['sometimes', 'array', 'max:15'],
            'tags.*' => ['string', 'min:2', 'max:40'],
        ];
    }

    public function toData(): CourseData
    {
        return CourseData::fromArray($this->validated());
    }
}
