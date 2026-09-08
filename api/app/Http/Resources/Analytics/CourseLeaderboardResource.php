<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

final class CourseLeaderboardResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var object{uuid: string, title: string, slug: string, views: int, enrollments: int, completions: int, revenue_minor: int} $row */
        $row = $this->resource;

        return [
            'course' => [
                'id' => $row->uuid,
                'title' => $row->title,
                'slug' => $row->slug,
            ],
            'views' => (int) $row->views,
            'enrollments' => (int) $row->enrollments,
            'completions' => (int) $row->completions,
            'revenue_minor' => (int) $row->revenue_minor,
        ];
    }
}
