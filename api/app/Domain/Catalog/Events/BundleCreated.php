<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use App\Domain\Catalog\Models\Bundle;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bundle exists. Commerce listens, because a bundle needs its product from
 * the moment it is a draft: the publish checklist wants a price, a price hangs
 * off a product, and a product that only appeared at publication would make
 * that check permanently unsatisfiable.
 */
final class BundleCreated
{
    use Dispatchable;

    public function __construct(public readonly Bundle $bundle) {}
}
