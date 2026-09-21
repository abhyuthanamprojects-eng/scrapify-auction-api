<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\AuctionTemplate;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AuctionTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function adminUser(): User
    {
        return User::where('role', 'super_admin')->first() ?? User::first();
    }

    private function sellerUser(): User
    {
        return User::where('role', 'seller')->first() ?? User::first();
    }

    private function activeTemplate(): AuctionTemplate
    {
        return AuctionTemplate::where('status', 'active')->firstOrFail();
    }

    public function test_categories_include_template_fields(): void
    {
        $response = $this->getJson('/api/v1/categories');
        $response->assertStatus(200);
        $first = $response->json('data.0');
        $this->assertArrayHasKey('template_required', $first);
        $this->assertArrayHasKey('allow_excel', $first);
        $this->assertArrayHasKey('direction', $first);
        $this->assertArrayHasKey('is_active', $first);
        $this->assertArrayHasKey('max_rows', $first);
    }

    public function test_admin_category_crud(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->postJson('/api/v1/categories', [
            'name' => 'Test Category',
            'direction' => 'forward',
            'template_required' => false,
        ]);
        $response->assertStatus(201);
        $categoryId = $response->json('data.id');
        $this->assertEquals('test-category', $response->json('data.slug'));
        $this->assertFalse($response->json('data.template_required'));

        $this->actingAs($admin)->patchJson("/api/v1/categories/{$categoryId}", [
            'template_required' => true,
            'direction' => 'reverse',
        ])->assertStatus(200)
          ->assertJsonPath('data.template_required', true)
          ->assertJsonPath('data.direction', 'reverse');

        $subResponse = $this->actingAs($admin)->postJson('/api/v1/categories', [
            'name' => 'Test Subcategory',
            'parent_id' => $categoryId,
            'direction' => 'reverse',
        ]);
        $subResponse->assertStatus(201);
        $subId = $subResponse->json('data.id');

        $this->actingAs($admin)->deleteJson("/api/v1/categories/{$categoryId}")
            ->assertStatus(422);

        $this->actingAs($admin)->deleteJson("/api/v1/categories/{$subId}")
            ->assertStatus(200);

        $this->actingAs($admin)->deleteJson("/api/v1/categories/{$categoryId}")
            ->assertStatus(200);
    }

    public function test_auction_accepts_subcategory_id(): void
    {
        $seller = $this->sellerUser();
        $parent = Category::whereNull('parent_id')->has('children')->first();
        if (! $parent) {
            $this->markTestSkipped('No parent category with children.');
        }
        $child = $parent->children->first();

        $response = $this->actingAs($seller)->postJson('/api/v1/auctions', [
            'title' => 'Subcategory Test Auction',
            'company' => 'Test Corp',
            'category' => $parent->name,
            'subcategory_id' => $child->id,
            'direction' => 'forward',
            'quantity' => '10',
            'uom' => 'MT',
            'status' => 'draft',
        ]);

        $response->assertStatus(201);
        $this->assertEquals($child->id, $response->json('data.subcategory_id'));
    }

    public function test_template_for_category_endpoint(): void
    {
        $template = $this->activeTemplate();
        $response = $this->getJson("/api/v1/categories/{$template->category_id}/auction-template?direction={$template->direction}");
        $response->assertStatus(200);
        $this->assertEquals($template->template_code, $response->json('data.template_code'));
    }

    public function test_template_download(): void
    {
        $template = $this->activeTemplate();
        $response = $this->get("/api/v1/auction-templates/{$template->id}/download");
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_admin_template_crud(): void
    {
        $admin = $this->adminUser();
        $category = Category::whereNull('parent_id')->first();

        $response = $this->actingAs($admin)->postJson('/api/v1/auction-templates', [
            'template_code' => 'TEST_TPL_V1',
            'name' => 'Test Template',
            'category_id' => $category->id,
            'direction' => 'forward',
            'version' => '1.0',
            'schema_definition' => [
                'columns' => [
                    ['key' => 'item_name', 'label' => 'Item Name', 'type' => 'string', 'required' => true],
                    ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'required' => true, 'min' => 1],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $templateId = $response->json('data.id');

        $this->actingAs($admin)->postJson("/api/v1/auction-templates/{$templateId}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $this->actingAs($admin)->getJson('/api/v1/auction-templates')
            ->assertStatus(200);
    }

    public function test_template_versioning(): void
    {
        $admin = $this->adminUser();
        $template = $this->activeTemplate();

        $response = $this->actingAs($admin)->postJson("/api/v1/auction-templates/{$template->id}/new-version", [
            'version' => '2.0',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('2.0', $response->json('data.version'));
        $this->assertEquals('draft', $response->json('data.status'));
        $this->assertEquals($template->template_code, $response->json('data.template_code'));
    }

    public function test_upload_valid_template(): void
    {
        Storage::fake('local');
        $seller = $this->sellerUser();
        $template = $this->activeTemplate();

        $auction = Auction::create([
            'title' => 'Test Laptop Auction',
            'company' => 'Test Corp',
            'category_id' => $template->category_id,
            'direction' => 'forward',
            'status' => 'draft',
            'submitted_by' => $seller->id,
            'submitted_by_name' => $seller->name,
        ]);

        $file = $this->buildTemplateFile($template);

        $response = $this->actingAs($seller)->post("/api/v1/auctions/{$auction->code}/template-upload", [
            'template_id' => $template->id,
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.valid'));
        $this->assertGreaterThan(0, $response->json('data.row_count'));
    }

    public function test_upload_wrong_template_rejected(): void
    {
        Storage::fake('local');
        $seller = $this->sellerUser();
        $template = $this->activeTemplate();

        $auction = Auction::create([
            'title' => 'Test Auction',
            'company' => 'Test Corp',
            'category_id' => $template->category_id,
            'direction' => 'forward',
            'status' => 'draft',
            'submitted_by' => $seller->id,
            'submitted_by_name' => $seller->name,
        ]);

        $wrongFile = $this->buildWrongTemplateFile();

        $response = $this->actingAs($seller)->post("/api/v1/auctions/{$auction->code}/template-upload", [
            'template_id' => $template->id,
            'file' => $wrongFile,
        ]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('data.valid'));
        $errors = $response->json('data.errors');
        $this->assertNotEmpty($errors);
        $hasWrongTemplate = collect($errors)->contains(fn ($e) => ($e['type'] ?? '') === 'WRONG_TEMPLATE');
        $this->assertTrue($hasWrongTemplate);
    }

    public function test_confirm_import_creates_lots(): void
    {
        Storage::fake('local');
        $seller = $this->sellerUser();
        $template = $this->activeTemplate();

        $auction = Auction::create([
            'title' => 'Test Import Auction',
            'company' => 'Test Corp',
            'category_id' => $template->category_id,
            'direction' => 'forward',
            'status' => 'draft',
            'submitted_by' => $seller->id,
            'submitted_by_name' => $seller->name,
        ]);

        $file = $this->buildTemplateFile($template);
        $uploadResponse = $this->actingAs($seller)->post("/api/v1/auctions/{$auction->code}/template-upload", [
            'template_id' => $template->id,
            'file' => $file,
        ]);

        $uploadId = $uploadResponse->json('data.upload.id');

        $confirmResponse = $this->actingAs($seller)->postJson("/api/v1/auctions/{$auction->code}/template-upload/{$uploadId}/confirm");
        $confirmResponse->assertStatus(200);

        $auction->refresh();
        $this->assertGreaterThan(0, $auction->lots()->count());
        $this->assertGreaterThan(0, $auction->items()->count());
        $this->assertEquals($template->id, $auction->template_id);
        $this->assertEquals($template->version, $auction->template_version);
    }

    public function test_admin_can_review_template_upload(): void
    {
        Storage::fake('local');
        $admin = $this->adminUser();
        $seller = $this->sellerUser();
        $template = $this->activeTemplate();

        $auction = Auction::create([
            'title' => 'Review Test Auction',
            'company' => 'Test Corp',
            'category_id' => $template->category_id,
            'direction' => 'forward',
            'status' => 'draft',
            'submitted_by' => $seller->id,
            'submitted_by_name' => $seller->name,
        ]);

        $file = $this->buildTemplateFile($template);
        $this->actingAs($seller)->post("/api/v1/auctions/{$auction->code}/template-upload", [
            'template_id' => $template->id,
            'file' => $file,
        ]);

        $reviewResponse = $this->actingAs($admin)->getJson("/api/v1/auctions/{$auction->code}/template-review");
        $reviewResponse->assertStatus(200);
        $this->assertNotNull($reviewResponse->json('data.latest_upload'));
    }

    public function test_row_validation_errors_returned(): void
    {
        Storage::fake('local');
        $seller = $this->sellerUser();
        $template = $this->activeTemplate();

        $auction = Auction::create([
            'title' => 'Validation Test',
            'company' => 'Test Corp',
            'category_id' => $template->category_id,
            'direction' => 'forward',
            'status' => 'draft',
            'submitted_by' => $seller->id,
            'submitted_by_name' => $seller->name,
        ]);

        $file = $this->buildTemplateFileWithErrors($template);
        $response = $this->actingAs($seller)->post("/api/v1/auctions/{$auction->code}/template-upload", [
            'template_id' => $template->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $errors = $response->json('data.errors');
        $this->assertNotEmpty($errors);
        $hasRowError = collect($errors)->contains(fn ($e) => ($e['row'] ?? 0) > 0);
        $this->assertTrue($hasRowError);
    }

    public function test_file_hash_stored(): void
    {
        Storage::fake('local');
        $seller = $this->sellerUser();
        $template = $this->activeTemplate();

        $auction = Auction::create([
            'title' => 'Hash Test',
            'company' => 'Test Corp',
            'category_id' => $template->category_id,
            'direction' => 'forward',
            'status' => 'draft',
            'submitted_by' => $seller->id,
            'submitted_by_name' => $seller->name,
        ]);

        $file = $this->buildTemplateFile($template);
        $response = $this->actingAs($seller)->post("/api/v1/auctions/{$auction->code}/template-upload", [
            'template_id' => $template->id,
            'file' => $file,
        ]);

        $upload = $auction->templateUploads()->first();
        $this->assertNotNull($upload);
        $this->assertEquals(64, strlen($upload->file_hash));
    }

    private function buildTemplateFile(AuctionTemplate $template): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCustomProperty('template_code', $template->template_code)
            ->setCustomProperty('template_version', $template->version)
            ->setCustomProperty('category_id', (string) $template->category_id)
            ->setCustomProperty('generated_by', 'Scrapify Auctions');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        $columns = $template->schema_definition['columns'] ?? [];
        $sheet->setCellValue('A1', 'Item No.');
        $col = 'B';
        foreach ($columns as $colDef) {
            $sheet->setCellValue($col.'1', $colDef['label']);
            $col++;
        }

        $sampleRows = [
            ['item_name' => 'Dell Latitude 5420', 'brand' => 'Dell', 'model' => 'Latitude 5420', 'description' => 'Used corporate laptop', 'manufacturing_year' => '2022', 'serial_identifier' => 'AST-001', 'quantity' => '5', 'unit' => 'PCS', 'condition' => 'Used', 'location' => 'Gurugram', 'reference_value' => '25000', 'remarks' => 'Charger'],
            ['item_name' => 'HP EliteBook 840', 'brand' => 'HP', 'model' => 'EliteBook 840', 'description' => 'Refurbished laptop', 'manufacturing_year' => '2021', 'serial_identifier' => 'AST-002', 'quantity' => '10', 'unit' => 'PCS', 'condition' => 'Refurbished', 'location' => 'Mumbai', 'reference_value' => '22000', 'remarks' => ''],
            ['item_name' => 'Lenovo ThinkPad T14', 'brand' => 'Lenovo', 'model' => 'ThinkPad T14', 'description' => 'Used laptop', 'manufacturing_year' => '2023', 'serial_identifier' => 'AST-003', 'quantity' => '5', 'unit' => 'PCS', 'condition' => 'Used', 'location' => 'Delhi', 'reference_value' => '27000', 'remarks' => 'Charger, Bag'],
        ];

        foreach ($sampleRows as $rowIdx => $rowData) {
            $r = $rowIdx + 2;
            $sheet->setCellValue('A'.$r, $rowIdx + 1);
            $col = 'B';
            foreach ($columns as $colDef) {
                $sheet->setCellValue($col.$r, $rowData[$colDef['key']] ?? $colDef['example'] ?? 'Sample');
                $col++;
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'tpl_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'test_template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function buildWrongTemplateFile(): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCustomProperty('template_code', 'WRONG_TEMPLATE_CODE')
            ->setCustomProperty('template_version', '1.0')
            ->setCustomProperty('generated_by', 'Scrapify Auctions');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');
        $sheet->setCellValue('A1', 'Material');
        $sheet->setCellValue('B1', 'Grade');

        $path = tempnam(sys_get_temp_dir(), 'wrong_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'wrong_template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function buildTemplateFileWithErrors(AuctionTemplate $template): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCustomProperty('template_code', $template->template_code)
            ->setCustomProperty('template_version', $template->version)
            ->setCustomProperty('category_id', (string) $template->category_id)
            ->setCustomProperty('generated_by', 'Scrapify Auctions');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        $columns = $template->schema_definition['columns'] ?? [];
        $sheet->setCellValue('A1', 'Item No.');
        $col = 'B';
        foreach ($columns as $colDef) {
            $sheet->setCellValue($col.'1', $colDef['label']);
            $col++;
        }

        $sheet->setCellValue('A2', 1);
        $sheet->setCellValue('B2', '');
        $sheet->setCellValue('C2', '');
        $sheet->setCellValue('D2', '');
        $sheet->setCellValue('E2', '');
        $sheet->setCellValue('F2', '-5');

        $path = tempnam(sys_get_temp_dir(), 'err_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'error_template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
