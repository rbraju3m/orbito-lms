<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\CourseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The starter taxonomy. Real deployments will edit it; a brand-new install
 * should not present an instructor with an empty category dropdown, which is
 * a blocking publish requirement.
 */
final class CatalogSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const TAXONOMY = [
        'Development' => ['Web Development', 'Mobile Development', 'Data Science', 'Programming Languages'],
        'Business' => ['Entrepreneurship', 'Management', 'Sales', 'Finance'],
        'Design' => ['Graphic Design', 'UX & UI', 'Motion Graphics'],
        'Marketing' => ['Digital Marketing', 'Content Marketing', 'SEO'],
        'Languages' => ['English', 'Bengali', 'Arabic'],
        'Personal Development' => ['Productivity', 'Leadership', 'Career Development'],
    ];

    public function run(): void
    {
        $position = 0;

        foreach (self::TAXONOMY as $parentName => $children) {
            $parent = CourseCategory::updateOrCreate(
                ['slug' => Str::slug($parentName)],
                ['name' => $parentName, 'position' => $position++, 'is_active' => true],
            );

            $childPosition = 0;

            foreach ($children as $childName) {
                CourseCategory::updateOrCreate(
                    ['slug' => Str::slug($childName)],
                    [
                        'name' => $childName,
                        'parent_id' => $parent->id,
                        'position' => $childPosition++,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
