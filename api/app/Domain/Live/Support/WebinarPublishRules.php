<?php

declare(strict_types=1);

namespace App\Domain\Live\Support;

use App\Domain\Live\Models\Webinar;

/**
 * The single definition of "this webinar is ready to be offered".
 *
 * Rendered by the studio (`is_publishable`, `publish_blockers`) and enforced
 * by `ChangeWebinarStatus`, so the disabled button and the 422 cannot
 * disagree — the same shape as `PublishChecklist` (§10) and `SubmissionRules`
 * (§14), kept as a flat list rather than a checklist because there are two
 * rules and both are blocking.
 *
 * ORDER MATTERS. The first blocker is the one the rejection is reported as,
 * and a webinar with no session is the more fundamental complaint: an event
 * with no time cannot be priced into existence either.
 */
final class WebinarPublishRules
{
    /** @return list<array{code: string, field: string, message: string}> */
    public function blockers(Webinar $webinar): array
    {
        $blockers = [];

        if ($webinar->live_session_id === null) {
            $blockers[] = [
                'code' => 'webinar_needs_session',
                'field' => 'starts_at',
                'message' => 'Schedule the session before publishing this webinar.',
            ];
        }

        if ($webinar->is_paid && ! $this->isPriced($webinar)) {
            $blockers[] = [
                'code' => 'webinar_needs_price',
                'field' => 'price',
                'message' => 'A paid webinar needs a price in '.$this->baseCurrency()
                    .' before it can be published.',
            ];
        }

        return $blockers;
    }

    public function isPublishable(Webinar $webinar): bool
    {
        return $this->blockers($webinar) === [];
    }

    /**
     * A price in the ACCOUNTING currency, which is the one the checklist for
     * a course and a download both ask for. Selling only in another currency
     * is a decision an academy can make, but not one it can make by accident.
     */
    private function isPriced(Webinar $webinar): bool
    {
        $webinar->loadMissing('product.prices');

        return $webinar->product?->priceIn($this->baseCurrency()) !== null;
    }

    private function baseCurrency(): string
    {
        return strtoupper((string) config('orbito.currency.base', 'USD'));
    }
}
