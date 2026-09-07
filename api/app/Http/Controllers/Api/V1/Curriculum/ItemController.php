<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Curriculum;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Actions\ManageItems;
use App\Domain\Curriculum\Actions\UpsertLesson;
use App\Domain\Curriculum\Exceptions\CurriculumRejected;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Http\Requests\Curriculum\StoreItemRequest;
use App\Http\Requests\Curriculum\UpdateItemRequest;
use App\Http\Requests\Curriculum\UpsertLessonRequest;
use App\Http\Resources\Curriculum\CourseItemResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ItemController
{
    public function __construct(private readonly ManageItems $items) {}

    public function store(StoreItemRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('manage-curriculum', $course);

        $section = CourseSection::findOrFail($request->integer('section_id'));

        // A section id that exists is not necessarily THIS course's section.
        if ($section->course_id !== $course->id) {
            throw CurriculumRejected::sectionNotInCourse();
        }

        $item = $this->items->create($section, $request->type(), $request->string('title')->value());

        return ApiResponse::created(CourseItemResource::make($item->load('itemable')));
    }

    public function show(CourseItem $item): JsonResponse
    {
        Gate::authorize('view-curriculum', $item->loadMissing('course')->course);

        return ApiResponse::ok(CourseItemResource::make($item->load('itemable')));
    }

    public function update(UpdateItemRequest $request, CourseItem $item): JsonResponse
    {
        Gate::authorize('manage-curriculum', $item->loadMissing('course')->course);

        $updated = $this->items->update($item, $request->validated());

        return ApiResponse::ok(CourseItemResource::make($updated->load('itemable')));
    }

    public function destroy(CourseItem $item): JsonResponse
    {
        Gate::authorize('manage-curriculum', $item->loadMissing('course')->course);

        $this->items->delete($item);

        return ApiResponse::noContent();
    }

    public function duplicate(CourseItem $item): JsonResponse
    {
        Gate::authorize('manage-curriculum', $item->loadMissing('course')->course);

        $copy = $this->items->duplicate($item);

        return ApiResponse::created(CourseItemResource::make($copy->load('itemable')));
    }

    public function updateLesson(UpsertLessonRequest $request, CourseItem $item, UpsertLesson $action): JsonResponse
    {
        Gate::authorize('manage-curriculum', $item->loadMissing('course')->course);

        $updated = $action->handle($item, $request->lessonAttributes(), $request->itemAttributes());

        return ApiResponse::ok(CourseItemResource::make($updated));
    }
}
