<?php

namespace Database\Seeders;

use App\Models\AuctionTemplate;
use App\Models\Category;
use Illuminate\Database\Seeder;

class AuctionTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $ewaste = Category::where('slug', 'e-waste')->first();
        $itAssets = Category::where('slug', 'it-assets')->first();
        $mobiles = Category::where('slug', 'mobiles')->first();
        $scrapMetal = Category::where('slug', 'scrap-metal')->first();
        $copper = Category::where('slug', 'copper')->first();
        $machinery = Category::where('slug', 'machinery')->first();
        $vehicles = Category::where('slug', 'vehicles')->first();
        $services = Category::where('slug', 'services')->first();

        $templates = [
            [
                'template_code' => 'EWASTE_LAPTOP_V1',
                'name' => 'E-Waste Laptop / IT Equipment Template',
                'category_id' => $ewaste?->id,
                'subcategory_id' => $itAssets?->id,
                'direction' => 'forward',
                'version' => '1.0',
                'status' => 'active',
                'effective_from' => now(),
                'schema_definition' => [
                    'columns' => [
                        ['key' => 'item_name', 'label' => 'Asset / Product Name', 'type' => 'string', 'required' => true, 'description' => 'Name of the laptop/IT asset', 'example' => 'Laptop'],
                        ['key' => 'brand', 'label' => 'Brand', 'type' => 'string', 'required' => true, 'description' => 'Manufacturer brand', 'example' => 'Dell'],
                        ['key' => 'model', 'label' => 'Model', 'type' => 'string', 'required' => true, 'description' => 'Model number', 'example' => 'Latitude 5420'],
                        ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'required' => false, 'description' => 'Brief description', 'example' => 'Used corporate laptop'],
                        ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'required' => true, 'min' => 1, 'description' => 'Number of units', 'example' => '5'],
                        ['key' => 'unit', 'label' => 'Unit', 'type' => 'enum', 'required' => false, 'values' => ['PCS', 'Nos.', 'Units'], 'description' => 'Unit of measurement', 'example' => 'PCS'],
                        ['key' => 'condition', 'label' => 'Condition', 'type' => 'enum', 'required' => false, 'values' => ['New', 'Used', 'Refurbished', 'Salvage', 'Scrap'], 'description' => 'Overall condition', 'example' => 'Used'],
                        ['key' => 'manufacturing_year', 'label' => 'Manufacturing Year', 'type' => 'year', 'required' => false, 'description' => 'Year of manufacture', 'example' => '2022'],
                        ['key' => 'processor', 'label' => 'Processor', 'type' => 'string', 'required' => false, 'description' => 'CPU specification', 'example' => 'Intel i5'],
                        ['key' => 'ram', 'label' => 'RAM', 'type' => 'string', 'required' => false, 'description' => 'Memory', 'example' => '16 GB'],
                        ['key' => 'storage', 'label' => 'Storage', 'type' => 'string', 'required' => false, 'description' => 'Storage capacity', 'example' => '512 GB SSD'],
                        ['key' => 'screen_size', 'label' => 'Screen Size', 'type' => 'string', 'required' => false, 'description' => 'Display size', 'example' => '14 inch'],
                        ['key' => 'functional_status', 'label' => 'Functional Status', 'type' => 'enum', 'required' => false, 'values' => ['Working', 'Partially Working', 'Not Working'], 'description' => 'Whether the asset is operational', 'example' => 'Working'],
                        ['key' => 'physical_condition', 'label' => 'Physical Condition', 'type' => 'enum', 'required' => false, 'values' => ['Excellent', 'Good', 'Fair', 'Poor'], 'description' => 'Physical state of the asset', 'example' => 'Good'],
                        ['key' => 'accessories', 'label' => 'Accessories Included', 'type' => 'string', 'required' => false, 'description' => 'List of included accessories', 'example' => 'Charger'],
                        ['key' => 'serial_identifier', 'label' => 'Serial / Asset Identifier', 'type' => 'string', 'required' => false, 'description' => 'Optional serial or asset tag', 'example' => 'AST-001'],
                        ['key' => 'location', 'label' => 'Location', 'type' => 'string', 'required' => false, 'description' => 'Where the asset is located', 'example' => 'Gurugram'],
                        ['key' => 'reference_value', 'label' => 'Expected / Reference Value', 'type' => 'money', 'required' => false, 'min' => 0, 'description' => 'Estimated market value per unit', 'example' => '25000'],
                        ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'string', 'required' => false, 'description' => 'Additional notes', 'example' => ''],
                    ],
                ],
                'instructions' => 'Each row can represent a single laptop or a group of identical laptops using the Quantity column. Provide brand and model for accurate lot grouping.',
            ],
            [
                'template_code' => 'EWASTE_MOBILE_V1',
                'name' => 'E-Waste Mobile / Electronics Template',
                'category_id' => $ewaste?->id,
                'subcategory_id' => $mobiles?->id,
                'direction' => 'forward',
                'version' => '1.0',
                'status' => 'active',
                'effective_from' => now(),
                'schema_definition' => [
                    'columns' => [
                        ['key' => 'item_name', 'label' => 'Asset / Product Name', 'type' => 'string', 'required' => true, 'example' => 'Mobile Phone'],
                        ['key' => 'brand', 'label' => 'Brand', 'type' => 'string', 'required' => true, 'example' => 'Samsung'],
                        ['key' => 'model', 'label' => 'Model', 'type' => 'string', 'required' => true, 'example' => 'Galaxy S21'],
                        ['key' => 'storage', 'label' => 'Storage', 'type' => 'string', 'required' => false, 'example' => '128 GB'],
                        ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'required' => true, 'min' => 1, 'example' => '10'],
                        ['key' => 'unit', 'label' => 'Unit', 'type' => 'enum', 'required' => false, 'values' => ['PCS', 'Nos.', 'Units'], 'example' => 'PCS'],
                        ['key' => 'condition', 'label' => 'Condition', 'type' => 'enum', 'required' => false, 'values' => ['New', 'Used', 'Refurbished', 'Salvage', 'Scrap'], 'example' => 'Used'],
                        ['key' => 'functional_status', 'label' => 'Functional Status', 'type' => 'enum', 'required' => false, 'values' => ['Working', 'Partially Working', 'Not Working'], 'example' => 'Working'],
                        ['key' => 'location', 'label' => 'Location', 'type' => 'string', 'required' => false, 'example' => 'Mumbai'],
                        ['key' => 'reference_value', 'label' => 'Expected / Reference Value', 'type' => 'money', 'required' => false, 'min' => 0, 'example' => '8000'],
                        ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'string', 'required' => false, 'example' => ''],
                    ],
                ],
            ],
            [
                'template_code' => 'SCRAP_METAL_V1',
                'name' => 'Scrap Metal Template',
                'category_id' => $scrapMetal?->id,
                'subcategory_id' => $copper?->id,
                'direction' => 'forward',
                'version' => '1.0',
                'status' => 'active',
                'effective_from' => now(),
                'schema_definition' => [
                    'columns' => [
                        ['key' => 'item_name', 'label' => 'Material', 'type' => 'string', 'required' => true, 'example' => 'Copper Wire Scrap'],
                        ['key' => 'grade', 'label' => 'Grade', 'type' => 'string', 'required' => false, 'example' => 'Grade A'],
                        ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'required' => true, 'min' => 0.01, 'example' => '5000'],
                        ['key' => 'unit', 'label' => 'Unit', 'type' => 'enum', 'required' => true, 'values' => ['MT', 'KG', 'Tonnes'], 'example' => 'KG'],
                        ['key' => 'estimated_weight', 'label' => 'Estimated Weight (KG)', 'type' => 'decimal', 'required' => false, 'example' => '5000'],
                        ['key' => 'purity', 'label' => 'Purity / Specification', 'type' => 'string', 'required' => false, 'example' => '99.5%'],
                        ['key' => 'location', 'label' => 'Location', 'type' => 'string', 'required' => false, 'example' => 'Pune'],
                        ['key' => 'reference_value', 'label' => 'Reference Price', 'type' => 'money', 'required' => false, 'min' => 0, 'example' => '450'],
                        ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'string', 'required' => false, 'example' => ''],
                    ],
                ],
            ],
            [
                'template_code' => 'MACHINERY_V1',
                'name' => 'Machinery Template',
                'category_id' => $machinery?->id,
                'direction' => 'forward',
                'version' => '1.0',
                'status' => 'active',
                'effective_from' => now(),
                'schema_definition' => [
                    'columns' => [
                        ['key' => 'item_name', 'label' => 'Machine Name', 'type' => 'string', 'required' => true, 'example' => 'CNC Lathe'],
                        ['key' => 'brand', 'label' => 'Manufacturer', 'type' => 'string', 'required' => false, 'example' => 'Mazak'],
                        ['key' => 'model', 'label' => 'Model', 'type' => 'string', 'required' => false, 'example' => 'QT-250'],
                        ['key' => 'manufacturing_year', 'label' => 'Year', 'type' => 'year', 'required' => false, 'example' => '2018'],
                        ['key' => 'capacity', 'label' => 'Capacity', 'type' => 'string', 'required' => false, 'example' => '250mm'],
                        ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'required' => true, 'min' => 1, 'example' => '1'],
                        ['key' => 'unit', 'label' => 'Unit', 'type' => 'enum', 'required' => false, 'values' => ['PCS', 'Nos.', 'Units', 'Set'], 'example' => 'PCS'],
                        ['key' => 'condition', 'label' => 'Condition', 'type' => 'enum', 'required' => false, 'values' => ['New', 'Used', 'Refurbished', 'Salvage', 'Scrap'], 'example' => 'Used'],
                        ['key' => 'functional_status', 'label' => 'Working Status', 'type' => 'enum', 'required' => false, 'values' => ['Working', 'Partially Working', 'Not Working'], 'example' => 'Working'],
                        ['key' => 'location', 'label' => 'Location', 'type' => 'string', 'required' => false, 'example' => 'Chennai'],
                        ['key' => 'reference_value', 'label' => 'Reference Value', 'type' => 'money', 'required' => false, 'min' => 0, 'example' => '500000'],
                        ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'string', 'required' => false, 'example' => ''],
                    ],
                ],
            ],
            [
                'template_code' => 'VEHICLE_V1',
                'name' => 'Vehicles Template',
                'category_id' => $vehicles?->id,
                'direction' => 'forward',
                'version' => '1.0',
                'status' => 'active',
                'effective_from' => now(),
                'schema_definition' => [
                    'columns' => [
                        ['key' => 'item_name', 'label' => 'Vehicle Type', 'type' => 'string', 'required' => true, 'example' => 'Truck'],
                        ['key' => 'brand', 'label' => 'Make', 'type' => 'string', 'required' => true, 'example' => 'Tata'],
                        ['key' => 'model', 'label' => 'Model', 'type' => 'string', 'required' => true, 'example' => 'Prima 4028.S'],
                        ['key' => 'manufacturing_year', 'label' => 'Year', 'type' => 'year', 'required' => false, 'example' => '2019'],
                        ['key' => 'fuel', 'label' => 'Fuel', 'type' => 'enum', 'required' => false, 'values' => ['Diesel', 'Petrol', 'CNG', 'Electric', 'Hybrid'], 'example' => 'Diesel'],
                        ['key' => 'mileage', 'label' => 'Mileage (KM)', 'type' => 'decimal', 'required' => false, 'example' => '120000'],
                        ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'required' => true, 'min' => 1, 'example' => '1'],
                        ['key' => 'unit', 'label' => 'Unit', 'type' => 'enum', 'required' => false, 'values' => ['PCS', 'Nos.', 'Units'], 'example' => 'Nos.'],
                        ['key' => 'condition', 'label' => 'Condition', 'type' => 'enum', 'required' => false, 'values' => ['New', 'Used', 'Salvage', 'Scrap'], 'example' => 'Used'],
                        ['key' => 'location', 'label' => 'Location', 'type' => 'string', 'required' => false, 'example' => 'Delhi'],
                        ['key' => 'reference_value', 'label' => 'Reference Value', 'type' => 'money', 'required' => false, 'min' => 0, 'example' => '1200000'],
                        ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'string', 'required' => false, 'example' => ''],
                    ],
                ],
            ],
            [
                'template_code' => 'SERVICE_REVERSE_V1',
                'name' => 'Services / Reverse Auction Template',
                'category_id' => $services?->id,
                'direction' => 'reverse',
                'version' => '1.0',
                'status' => 'active',
                'effective_from' => now(),
                'schema_definition' => [
                    'columns' => [
                        ['key' => 'item_name', 'label' => 'Service Name', 'type' => 'string', 'required' => true, 'example' => 'Annual Maintenance Contract'],
                        ['key' => 'description', 'label' => 'Scope', 'type' => 'string', 'required' => true, 'example' => 'IT infrastructure maintenance for 3 offices'],
                        ['key' => 'quantity', 'label' => 'Quantity / Effort', 'type' => 'decimal', 'required' => true, 'min' => 1, 'example' => '1'],
                        ['key' => 'unit', 'label' => 'Unit', 'type' => 'enum', 'required' => false, 'values' => ['PCS', 'Months', 'Hours', 'Days', 'Project', 'Contract'], 'example' => 'Contract'],
                        ['key' => 'location', 'label' => 'Location', 'type' => 'string', 'required' => false, 'example' => 'Pan India'],
                        ['key' => 'start_date', 'label' => 'Start Date', 'type' => 'string', 'required' => false, 'example' => '2026-10-01'],
                        ['key' => 'duration', 'label' => 'Contract Duration', 'type' => 'string', 'required' => false, 'example' => '12 months'],
                        ['key' => 'sla_requirement', 'label' => 'Service-Level Requirement', 'type' => 'string', 'required' => false, 'example' => '99.9% uptime'],
                        ['key' => 'reference_value', 'label' => 'Reference Budget', 'type' => 'money', 'required' => false, 'min' => 0, 'example' => '500000'],
                        ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'string', 'required' => false, 'example' => ''],
                    ],
                ],
            ],
        ];

        foreach ($templates as $tpl) {
            if (! $tpl['category_id']) {
                continue;
            }
            AuctionTemplate::updateOrCreate(
                ['template_code' => $tpl['template_code']],
                $tpl,
            );
        }
    }
}
