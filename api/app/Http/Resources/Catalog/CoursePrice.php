<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\Course;

/**
 * What a course costs, in the shape the catalogue renders.
 *
 * A separate class because BOTH course resources need it and a copy in each is
 * a copy that will drift. It reads Commerce for display only — the figure that
 * CHARGES is the one `PlaceOrder` re-reads at checkout (ADR-05), and this is
 * explicitly not it. The UI must treat it as a label, never as an input.
 *
 * Null means "not for sale right now", which is a different fact from free:
 * a free course has no product at all, and a paid course whose product was
 * deactivated cannot be bought even though its `pricing_model` still says it
 * is paid. Both render as an unbuyable button, for different reasons.
 */
final class CoursePrice
{
    /**
     * @return array<string, mixed>|null
     */
    public static function for(Course $course, string $currency): ?array
    {
        // Never lazy-loads: the caller eager-loads `product.prices` or gets
        // nothing. Strict mode would throw, and a price silently absent from a
        // list is worse than a list that never had one.
        if (! $course->relationLoaded('product')) {
            return null;
        }

        // The shape itself is shared with bundles — see PriceView. This class
        // stays because the eager-load guard above is a COURSE rule.
        return PriceView::for($course->product, $currency);
    }
}
