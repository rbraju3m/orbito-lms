<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * Free or paid — the same split a course makes, with the same consequence: a
 * free download has NO product and is claimed, a paid one is bought.
 *
 * Its own enum rather than `PricingModel` because a course's model grows to
 * cover subscriptions and memberships, and none of those describe a file.
 */
enum DownloadPricing: string
{
    case Free = 'free';
    case OneTime = 'one_time';

    public function isFree(): bool
    {
        return $this === self::Free;
    }
}
