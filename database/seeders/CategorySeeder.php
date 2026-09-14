<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    private const TREE = [
        'Ferrous' => ['children' => [], 'direction' => 'forward', 'template_required' => false],
        'Non-Ferrous' => ['children' => ['Cables'], 'direction' => 'forward', 'template_required' => false],
        'E-Waste' => ['children' => ['IT Assets', 'Mobiles', 'PCBs', 'Batteries', 'Appliances'], 'direction' => 'forward', 'template_required' => true],
        'Paper' => ['children' => [], 'direction' => 'forward', 'template_required' => false],
        'Plastic' => ['children' => [], 'direction' => 'forward', 'template_required' => false],
        'Rubber' => ['children' => [], 'direction' => 'forward', 'template_required' => false],
        'Scrap Metal' => ['children' => ['Copper', 'Aluminium', 'Mixed Scrap'], 'direction' => 'forward', 'template_required' => true],
        'Machinery' => ['children' => ['Industrial Machinery', 'Construction Equipment'], 'direction' => 'forward', 'template_required' => true],
        'Vehicles' => ['children' => ['Commercial Vehicles', 'Passenger Vehicles'], 'direction' => 'forward', 'template_required' => true],
        'Electronics' => ['children' => ['Consumer Electronics', 'Telecom Equipment'], 'direction' => 'forward', 'template_required' => true],
        'Furniture' => ['children' => ['Office Furniture', 'Industrial Furniture'], 'direction' => 'forward', 'template_required' => false],
        'Services' => ['children' => ['Software Development', 'Computer Repair', 'Logistics', 'Maintenance', 'Facility Management'], 'direction' => 'reverse', 'template_required' => true],
        'Other' => ['children' => ['Mixed Lots'], 'direction' => 'both', 'template_required' => false],
    ];

    public function run(): void
    {
        $sort = 0;

        foreach (self::TREE as $parentName => $config) {
            $parent = Category::updateOrCreate(
                ['slug' => Str::slug($parentName)],
                [
                    'name' => $parentName,
                    'sort_order' => $sort++,
                    'direction' => $config['direction'],
                    'template_required' => $config['template_required'],
                    'allow_manual_items' => true,
                    'allow_excel' => true,
                ],
            );

            foreach ($config['children'] as $childName) {
                Category::updateOrCreate(
                    ['slug' => Str::slug($childName)],
                    [
                        'name' => $childName,
                        'parent_id' => $parent->id,
                        'sort_order' => $sort++,
                        'direction' => $config['direction'],
                        'template_required' => $config['template_required'],
                        'allow_manual_items' => true,
                        'allow_excel' => true,
                    ],
                );
            }
        }
    }
}
