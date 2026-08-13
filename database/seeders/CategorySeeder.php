<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Two-level taxonomy reconciling the two frontends:
 *  - top level  = the admin panel's MATERIAL_CATEGORIES (vendors-store.ts)
 *  - children   = the mobile demo's lot categories (bidplay-data.ts)
 */
class CategorySeeder extends Seeder
{
    private const TREE = [
        'Ferrous' => [],
        'Non-Ferrous' => ['Cables'],
        'E-Waste' => ['IT Assets', 'Mobiles', 'PCBs', 'Batteries', 'Appliances'],
        'Paper' => [],
        'Plastic' => [],
        'Rubber' => [],
        'Other' => ['Mixed Lots'],
    ];

    public function run(): void
    {
        $sort = 0;

        foreach (self::TREE as $parentName => $children) {
            $parent = Category::updateOrCreate(
                ['slug' => Str::slug($parentName)],
                ['name' => $parentName, 'sort_order' => $sort++],
            );

            foreach ($children as $childName) {
                Category::updateOrCreate(
                    ['slug' => Str::slug($childName)],
                    ['name' => $childName, 'parent_id' => $parent->id, 'sort_order' => $sort++],
                );
            }
        }
    }
}
