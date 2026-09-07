<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Catalog\Models\CourseCategory;
use App\Domain\Catalog\Models\CourseTag;
use App\Http\Resources\Catalog\CourseCategoryResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryController
{
    public function index(): JsonResponse
    {
        $categories = CourseCategory::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn ($q) => $q->where('is_active', true)])
            ->withCount(['courses' => fn ($q) => $q->listed()])
            ->orderBy('position')
            ->get();

        return ApiResponse::ok(CourseCategoryResource::collection($categories));
    }

    public function show(CourseCategory $category): JsonResponse
    {
        return ApiResponse::ok(
            CourseCategoryResource::make($category->load('children')->loadCount('courses'))
        );
    }

    public function tags(Request $request): JsonResponse
    {
        $tags = CourseTag::query()
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where('name', 'like', $request->string('q')->value().'%'),
            )
            ->orderByDesc('usage_count')
            ->limit(50)
            ->get(['slug', 'name', 'usage_count']);

        return ApiResponse::ok($tags);
    }
}
