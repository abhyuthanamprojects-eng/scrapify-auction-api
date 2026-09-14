<?php

namespace Database\Seeders;

use App\Models\AuctionTemplate;
use Illuminate\Database\Seeder;

class AuctionTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $common = [
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'required' => true, 'description' => 'Total quantity', 'example' => '25'],
            ['key' => 'unit', 'label' => 'Unit', 'type' => 'enum', 'required' => true, 'values' => ['MT', 'KG', 'PCS', 'Nos.', 'Units', 'Tonnes'], 'description' => 'Unit of measurement', 'example' => 'MT'],
            ['key' => 'condition', 'label' => 'Condition', 'type' => 'enum', 'required' => false, 'values' => ['New', 'Used', 'Refurbished', 'Salvage', 'Scrap'], 'example' => 'Scrap'],
            ['key' => 'location', 'label' => 'Location', 'type' => 'string', 'required' => false, 'description' => 'Storage location', 'example' => 'Warehouse A'],
            ['key' => 'reference_value', 'label' => 'Reference Value (₹)', 'type' => 'money', 'required' => true, 'min' => 0, 'description' => 'Expected price per unit', 'example' => '35000'],
            ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'string', 'required' => false, 'example' => ''],
        ];

        $templates = [
            [
                'template_code' => 'FERROUS_V1',
                'name' => 'Ferrous Scrap Template',
                'category_id' => 1,
                'direction' => 'both',
                'schema_definition' => ['columns' => array_merge([
                    ['key' => 'item_name', 'label' => 'Material Name', 'type' => 'string', 'required' => true, 'example' => 'MS Scrap Heavy Melting'],
                    ['key' => 'grade', 'label' => 'Grade', 'type' => 'string', 'required' => false, 'example' => 'HMS 1'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'required' => false, 'example' => 'Industrial grade heavy melting scrap'],
                ], $common)],
                'instructions' => "Fill in all rows for your Ferrous scrap items.\n1. Do NOT change column headers.\n2. Material Name, Quantity, Unit, and Reference Value are required.\n3. Each row becomes one lot in the auction.",
            ],
            [
                'template_code' => 'NON_FERROUS_V1',
                'name' => 'Non-Ferrous Scrap Template',
                'category_id' => 2,
                'direction' => 'both',
                'schema_definition' => ['columns' => array_merge([
                    ['key' => 'item_name', 'label' => 'Material Name', 'type' => 'string', 'required' => true, 'example' => 'Copper Wire Scrap'],
                    ['key' => 'grade', 'label' => 'Grade', 'type' => 'string', 'required' => false, 'example' => 'Grade A'],
                    ['key' => 'purity', 'label' => 'Purity / Specification', 'type' => 'string', 'required' => false, 'example' => '99.5%'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'required' => false, 'example' => 'Clean copper wire without insulation'],
                ], $common)],
                'instructions' => "Fill in all rows for your Non-Ferrous scrap items.\n1. Do NOT change column headers.\n2. Material Name, Quantity, Unit, and Reference Value are required.",
            ],
            [
                'template_code' => 'EWASTE_V1',
                'name' => 'E-Waste Auction Template',
                'category_id' => 4,
                'direction' => 'both',
                'schema_definition' => ['columns' => array_merge([
                    ['key' => 'item_name', 'label' => 'Asset / Product Name', 'type' => 'string', 'required' => true, 'example' => 'Laptop'],
                    ['key' => 'brand', 'label' => 'Brand', 'type' => 'string', 'required' => false, 'example' => 'Dell'],
                    ['key' => 'model', 'label' => 'Model', 'type' => 'string', 'required' => false, 'example' => 'Latitude 5420'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'required' => false, 'example' => 'Used corporate laptop'],
                    ['key' => 'manufacturing_year', 'label' => 'Manufacturing Year', 'type' => 'year', 'required' => false, 'example' => '2022'],
                    ['key' => 'serial_identifier', 'label' => 'Serial / Asset ID', 'type' => 'string', 'required' => false, 'example' => 'AST-001'],
                ], $common)],
                'instructions' => "Fill in all rows for your E-Waste items.\n1. Do NOT change column headers.\n2. Asset Name, Quantity, Unit, and Reference Value are required.\n3. Include brand and model for accurate lot grouping.",
            ],
            [
                'template_code' => 'PAPER_V1',
                'name' => 'Paper Scrap Template',
                'category_id' => 10,
                'direction' => 'both',
                'schema_definition' => ['columns' => array_merge([
                    ['key' => 'item_name', 'label' => 'Paper Type', 'type' => 'string', 'required' => true, 'example' => 'White Office Paper'],
                    ['key' => 'grade', 'label' => 'Grade', 'type' => 'string', 'required' => false, 'example' => 'SOP (Sorted Office Paper)'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'required' => false, 'example' => 'Clean sorted white paper'],
                ], $common)],
                'instructions' => "Fill in all rows for your Paper scrap items.\n1. Do NOT change column headers.\n2. Paper Type, Quantity, Unit, and Reference Value are required.",
            ],
            [
                'template_code' => 'PLASTIC_V1',
                'name' => 'Plastic Scrap Template',
                'category_id' => 11,
                'direction' => 'both',
                'schema_definition' => ['columns' => array_merge([
                    ['key' => 'item_name', 'label' => 'Plastic Type', 'type' => 'string', 'required' => true, 'example' => 'HDPE Drums'],
                    ['key' => 'grade', 'label' => 'Grade / Resin Code', 'type' => 'string', 'required' => false, 'example' => 'HDPE (#2)'],
                    ['key' => 'color', 'label' => 'Color', 'type' => 'string', 'required' => false, 'example' => 'Blue'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'required' => false, 'example' => 'Clean HDPE drums, washed'],
                ], $common)],
                'instructions' => "Fill in all rows for your Plastic scrap items.\n1. Do NOT change column headers.\n2. Plastic Type, Quantity, Unit, and Reference Value are required.",
            ],
            [
                'template_code' => 'RUBBER_V1',
                'name' => 'Rubber Scrap Template',
                'category_id' => 12,
                'direction' => 'both',
                'schema_definition' => ['columns' => array_merge([
                    ['key' => 'item_name', 'label' => 'Rubber Type', 'type' => 'string', 'required' => true, 'example' => 'Used Tyres'],
                    ['key' => 'grade', 'label' => 'Grade', 'type' => 'string', 'required' => false, 'example' => 'Truck Tyres'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'required' => false, 'example' => 'Used truck tyres for retreading'],
                ], $common)],
                'instructions' => "Fill in all rows for your Rubber scrap items.\n1. Do NOT change column headers.\n2. Rubber Type, Quantity, Unit, and Reference Value are required.",
            ],
            [
                'template_code' => 'OTHER_V1',
                'name' => 'General Auction Template',
                'category_id' => 13,
                'direction' => 'both',
                'schema_definition' => ['columns' => array_merge([
                    ['key' => 'item_name', 'label' => 'Item Name', 'type' => 'string', 'required' => true, 'example' => 'Mixed Scrap Lot'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'required' => false, 'example' => 'Assorted scrap materials'],
                    ['key' => 'brand', 'label' => 'Brand', 'type' => 'string', 'required' => false, 'example' => ''],
                ], $common)],
                'instructions' => "Fill in all rows for your auction items.\n1. Do NOT change column headers.\n2. Item Name, Quantity, Unit, and Reference Value are required.",
            ],
        ];

        foreach ($templates as $tpl) {
            AuctionTemplate::updateOrCreate(
                ['template_code' => $tpl['template_code'], 'version' => '1.0'],
                array_merge($tpl, [
                    'version' => '1.0',
                    'status' => 'active',
                    'effective_from' => now(),
                ]),
            );
        }
    }
}
