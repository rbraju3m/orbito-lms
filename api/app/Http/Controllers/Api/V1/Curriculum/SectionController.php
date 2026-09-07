<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Curriculum;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Actions\ManageSections;
use App\Domain\Curriculum\Models\CourseSection;
use App\Http\Requests\Curriculum\StoreSectionRequest;
use App\Http\Resources\Curriculum\CourseSectionResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class SectionController
{
    public function __construct(private readonly ManageSections $sections) {}

    public function store(StoreSectionRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('manage-curriculum', $course);

        $section = $this->sections->create(
            $course,
            $request->string('title')->value(),
            $request->string('description')->value() ?: null,
        );

        return ApiResponse::created(CourseSectionResource::make($section->load('items')));
    }

    public function update(StoreSectionRequest $request, CourseSection $section): JsonResponse
    {
        Gate::authorize('manage-curriculum', $section->loadMissing('course')->course);

        $updated = $this->sections->update($section, $request->validated());

        return ApiResponse::ok(CourseSectionResource::make($updated->load('items')));
    }

    public function destroy(CourseSection $section): JsonResponse
    {
        Gate::authorize('manage-curriculum', $section->loadMissing('course')->course);

        $this->sections->delete($section);

        return ApiResponse::noContent();
    }

    public function duplicate(CourseSection $section): JsonResponse
    {
        Gate::authorize('manage-curriculum', $section->loadMissing('course')->course);

        $copy = $this->sections->duplicate($section);

        return ApiResponse::created(CourseSectionResource::make($copy->load('items')));
    }
}
