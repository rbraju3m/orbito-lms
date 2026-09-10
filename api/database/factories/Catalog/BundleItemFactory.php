<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\BundleItem;
use App\Domain\Catalog\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BundleItem>
 */
final class BundleItemFactory extends Factory
{
    protected $model = BundleItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bundle_id' => Bundle::factory(),
            'course_id' => Course::factory(),
            'position' => 0,
        ];
    }
}
