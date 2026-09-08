<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One row of the stall heatmap.
 *
 * `drop_off_rate` is read from the stored column rather than recomputed here,
 * so the number the UI colours a cell by is the same number the index sorts
 * on. Two definitions of one figure is how a "worst item" list ends up
 * disagreeing with the shading beside it.
 */
final class FunnelItemResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var object{item_uuid: string, title: string, type: string, position: int, section_title: ?string, started: int, completed: int, avg_seconds: ?int, drop_off_rate: string, computed_at: string} $row */
        $row = $this->resource;

        return [
            'item_id' => $row->item_uuid,
            'title' => $row->title,
            'type' => $row->type,
            'position' => (int) $row->position,
            'section_title' => $row->section_title,

            'started' => (int) $row->started,
            'completed' => (int) $row->completed,
            'drop_off_rate' => (float) $row->drop_off_rate,

            /*
             * Null, not zero. "Nobody has opened this" and "everybody left
             * immediately" are different facts, and a text lesson has no
             * seconds to average at all.
             */
            'avg_seconds' => $row->avg_seconds === null ? null : (int) $row->avg_seconds,

            // When it was last answered. A funnel is a snapshot, not a series,
            // so a reader needs to know how stale it is.
            'computed_at' => $row->computed_at,
        ];
    }
}
