<?php

namespace Database\Seeders;

use App\Models\AuctionTemplate;
use Illuminate\Database\Seeder;

class UniversalMaterialListTemplateSeeder extends Seeder
{
    public function run(): void
    {
        AuctionTemplate::updateOrCreate(
            ['template_code' => 'SCRAPIFY-ML-UNIVERSAL'],
            [
                'name' => 'Official Scrapify Material List',
                'category_id' => null,
                'subcategory_id' => null,
                'direction' => 'both',
                'version' => '1.0',
                'status' => 'active',
                'template_required' => true,
                'allow_manual_items' => false,
                'max_rows' => 500,
                'max_file_size' => 10240,
                'instructions' => 'Fill in the material details using this official template. All fields marked as required must be completed. Upload the completed file as .xlsx format.',
                'schema_definition' => [
                    ['key' => 'material_name', 'label' => 'Material Name', 'type' => 'string', 'required' => true],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'string', 'required' => false],
                    ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'required' => true, 'min' => 0],
                    ['key' => 'unit', 'label' => 'Unit (MT/KG/Nos.)', 'type' => 'enum', 'required' => true, 'options' => ['MT', 'KG', 'Nos.']],
                    ['key' => 'condition', 'label' => 'Condition', 'type' => 'string', 'required' => false],
                    ['key' => 'location', 'label' => 'Location', 'type' => 'string', 'required' => false],
                    ['key' => 'reference_value', 'label' => 'Reference Value (INR)', 'type' => 'money', 'required' => false, 'min' => 0],
                    ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'string', 'required' => false],
                ],
            ],
        );
    }
}
