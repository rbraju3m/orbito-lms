<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Catalog\Models\Download;
use App\Domain\Catalog\Queries\DownloadDecision;
use App\Support\Exceptions\DomainException;

/**
 * 423 Locked, not 403 (§ Phase 6): the caller is signed in, the download
 * exists, and they could legitimately own it. `meta` says how — the product
 * to buy, or that it is free to claim — because a locked screen that cannot
 * say how to get in is a dead end.
 */
final class DownloadLocked extends DomainException
{
    public static function from(DownloadDecision $decision, Download $download): self
    {
        $free = $download->pricing_model->isFree();

        $exception = new self(match ($decision->reason) {
            'not_owned' => $free ? 'Get this download to open it.' : 'Buy this download to open it.',
            'grant_revoked' => 'Your access to this download was withdrawn.',
            'unauthenticated' => 'Sign in to get this download.',
            default => 'You do not have access to this download.',
        });

        $exception->details = [['code' => $decision->reason, 'message' => $exception->getMessage()]];

        // Never lazy-loads: strict mode would throw on the error path, the
        // worst place to throw twice. The caller eager-loads the product.
        $product = $download->relationLoaded('product') ? $download->product : null;

        $exception->meta = array_filter([
            'pricing_model' => $download->pricing_model->value,
            'product_id' => $product !== null && $product->status->isSellable() ? $product->uuid : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $exception;
    }

    public function errorCode(): string
    {
        return 'download_locked';
    }

    public function status(): int
    {
        return 423;
    }
}
