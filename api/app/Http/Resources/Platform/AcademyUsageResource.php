<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Platform\Data\LimitStatus;
use App\Domain\Platform\Models\Plan;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * What an academy has used, against what its plan allows.
 *
 * Built from the SAME `PlanLimits` the write path consults, so the panel
 * cannot promise room the server will refuse — the §16 `meta` pattern, given
 * a screen of its own because it is the whole subject rather than a footnote
 * on a list.
 *
 * `enforced` is on every row and is not decoration: it is the difference
 * between "this will stop you" and "this is what we are counting", and a
 * panel that showed a student cap as a hard wall would be lying about what
 * happens next.
 *
 * The PLAN here is what the academy is on, not the price list. A plan a
 * customer can be upsold to is a different screen and a different audience
 * (ADR-06).
 *
 * @property list<LimitStatus> $resource
 */
final class AcademyUsageResource extends BaseResource
{
    /** @param  list<LimitStatus>  $limits */
    public function __construct(private readonly array $limits, private readonly ?Plan $plan)
    {
        parent::__construct($limits);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'plan' => $this->plan === null ? null : [
                'slug' => $this->plan->slug,
                'name' => $this->plan->name,
            ],

            /*
             * True when ANY enforced dimension is full — the one fact a header
             * badge branches on, computed here so three components do not each
             * derive it and drift.
             */
            'any_at_limit' => array_filter(
                $this->limits,
                static fn (LimitStatus $status): bool => $status->enforced && $status->atLimit(),
            ) !== [],

            'limits' => array_map(
                static fn (LimitStatus $status): array => [
                    'metric' => $status->metric->value,
                    'label' => $status->metric->label(),
                    'is_bytes' => $status->metric->isBytes(),

                    'used' => $status->used,
                    // null is UNCAPPED, and the client must render it as such
                    // rather than as zero. See docs/API.md §2 on null vs absent.
                    'limit' => $status->limit,
                    'remaining' => $status->remaining(),
                    'fraction' => $status->fraction(),

                    'at_limit' => $status->atLimit(),
                    'over_limit' => $status->overLimit(),
                    'enforced' => $status->enforced,
                ],
                $this->limits,
            ),
        ];
    }
}
