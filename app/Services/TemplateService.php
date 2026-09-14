<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\AuctionTemplate;
use App\Models\AuctionTemplateUpload;
use App\Models\Lot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class TemplateService
{
    private const ALLOWED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const ALLOWED_EXTENSIONS = ['xlsx'];

    private const MAX_DEFAULT_FILE_SIZE = 10 * 1024 * 1024;

    public function generateExcel(AuctionTemplate $template): string
    {
        $spreadsheet = new Spreadsheet();

        $spreadsheet->getProperties()
            ->setCreator('Scrapify Auctions')
            ->setTitle($template->name)
            ->setCustomProperty('template_code', $template->template_code)
            ->setCustomProperty('template_version', $template->version)
            ->setCustomProperty('category_id', (string) $template->category_id)
            ->setCustomProperty('generated_by', 'Scrapify Auctions');

        if ($template->subcategory_id) {
            $spreadsheet->getProperties()
                ->setCustomProperty('subcategory_id', (string) $template->subcategory_id);
        }

        $this->buildInstructionsSheet($spreadsheet, $template);
        $this->buildDataSheet($spreadsheet, $template);

        $spreadsheet->setActiveSheetIndex(1);

        $path = storage_path('app/templates/'.$template->template_code.'_v'.$template->version.'.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function buildInstructionsSheet(Spreadsheet $spreadsheet, AuctionTemplate $template): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Instructions');

        $schema = $template->schema_definition;
        $columns = $schema['columns'] ?? [];

        $sheet->setCellValue('A1', 'SCRAPIFY AUCTIONS — OFFICIAL TEMPLATE');
        $sheet->setCellValue('A2', 'Template: '.$template->name);
        $sheet->setCellValue('A3', 'Version: '.$template->version);
        $sheet->setCellValue('A4', 'Template Code: '.$template->template_code);
        $sheet->setCellValue('A5', '');
        $sheet->setCellValue('A6', 'INSTRUCTIONS');
        $sheet->setCellValue('A7', '1. Fill the "Data" sheet with your item details.');
        $sheet->setCellValue('A8', '2. Do not rename or delete any sheets.');
        $sheet->setCellValue('A9', '3. Do not modify column headers in the Data sheet.');
        $sheet->setCellValue('A10', '4. Each row represents one item or grouped variant.');
        $sheet->setCellValue('A11', '5. Use the Quantity column for grouped identical items.');
        $sheet->setCellValue('A12', '');
        $sheet->setCellValue('A13', 'COLUMN REFERENCE');

        $sheet->setCellValue('A14', 'Column');
        $sheet->setCellValue('B14', 'Type');
        $sheet->setCellValue('C14', 'Required');
        $sheet->setCellValue('D14', 'Description');
        $sheet->setCellValue('E14', 'Example');

        $row = 15;
        foreach ($columns as $col) {
            $sheet->setCellValue('A'.$row, $col['label'] ?? $col['key']);
            $sheet->setCellValue('B'.$row, $col['type'] ?? 'string');
            $sheet->setCellValue('C'.$row, ($col['required'] ?? false) ? 'Yes' : 'No');
            $sheet->setCellValue('D'.$row, $col['description'] ?? '');
            $sheet->setCellValue('E'.$row, $col['example'] ?? '');
            $row++;
        }

        if ($template->instructions) {
            $row += 2;
            $sheet->setCellValue('A'.$row, 'ADDITIONAL INSTRUCTIONS');
            $row++;
            $sheet->setCellValue('A'.$row, $template->instructions);
        }

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(40);
        $sheet->getColumnDimension('E')->setWidth(30);

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A6')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A13')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A14:E14')->getFont()->setBold(true);
        $sheet->getStyle('A14:E14')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');
    }

    private function buildDataSheet(Spreadsheet $spreadsheet, AuctionTemplate $template): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Data');

        $schema = $template->schema_definition;
        $columns = $schema['columns'] ?? [];

        $sheet->setCellValue('A1', 'Item No.');
        $col = 'B';
        foreach ($columns as $colDef) {
            $sheet->setCellValue($col.'1', $colDef['label'] ?? $colDef['key']);
            $sheet->getColumnDimension($col)->setWidth(max(strlen($colDef['label'] ?? $colDef['key']) + 4, 15));
            $col++;
        }

        $headerRange = 'A1:'.chr(ord($col) - 1).'1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4472C4');
        $sheet->getStyle($headerRange)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 1);
    }

    public function validateAndParseUpload(
        UploadedFile $file,
        AuctionTemplate $template,
        Auction $auction,
    ): array {
        $errors = [];

        $errors = array_merge($errors, $this->validateFile($file, $template));
        if (! empty($errors)) {
            return ['valid' => false, 'errors' => $errors, 'rows' => []];
        }

        $tempPath = $file->getRealPath();
        $fileHash = hash_file('sha256', $tempPath);

        try {
            $spreadsheet = IOFactory::load($tempPath);
        } catch (\Exception $e) {
            return ['valid' => false, 'errors' => [['row' => 0, 'column' => '', 'error' => 'Unable to read the Excel file. It may be corrupted.']], 'rows' => [], 'file_hash' => $fileHash];
        }

        $errors = array_merge($errors, $this->validateTemplateIdentity($spreadsheet, $template));
        if (! empty($errors)) {
            $spreadsheet->disconnectWorksheets();

            return ['valid' => false, 'errors' => $errors, 'rows' => [], 'file_hash' => $fileHash];
        }

        $dataSheet = $spreadsheet->getSheetByName('Data');
        if (! $dataSheet) {
            $spreadsheet->disconnectWorksheets();

            return ['valid' => false, 'errors' => [['row' => 0, 'column' => '', 'error' => 'The "Data" sheet is missing from this workbook.']], 'rows' => [], 'file_hash' => $fileHash];
        }

        $schemaErrors = $this->validateSchema($dataSheet, $template);
        if (! empty($schemaErrors)) {
            $spreadsheet->disconnectWorksheets();

            return ['valid' => false, 'errors' => $schemaErrors, 'rows' => [], 'file_hash' => $fileHash];
        }

        $result = $this->parseAndValidateRows($dataSheet, $template);
        $spreadsheet->disconnectWorksheets();

        $result['file_hash'] = $fileHash;

        return $result;
    }

    private function validateFile(UploadedFile $file, AuctionTemplate $template): array
    {
        $errors = [];

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::ALLOWED_EXTENSIONS)) {
            $errors[] = ['row' => 0, 'column' => '', 'error' => 'Only .xlsx files are accepted.'];
        }

        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME_TYPES)) {
            $errors[] = ['row' => 0, 'column' => '', 'error' => 'Invalid file type. Please upload a valid .xlsx file.'];
        }

        $maxSize = $template->max_file_size ?: self::MAX_DEFAULT_FILE_SIZE;
        if ($file->getSize() > $maxSize) {
            $errors[] = ['row' => 0, 'column' => '', 'error' => 'File exceeds maximum size of '.round($maxSize / 1024 / 1024, 1).' MB.'];
        }

        return $errors;
    }

    private function validateTemplateIdentity(Spreadsheet $spreadsheet, AuctionTemplate $template): array
    {
        $errors = [];
        $props = $spreadsheet->getProperties();

        $uploadedCode = $props->getCustomPropertyValue('template_code');
        if (! $uploadedCode || $uploadedCode !== $template->template_code) {
            $errors[] = [
                'row' => 0,
                'column' => '',
                'error' => 'Wrong template uploaded. Expected: '.$template->template_code
                    .($uploadedCode ? '. Found: '.$uploadedCode : '. No template identifier found in this file.'),
                'type' => 'WRONG_TEMPLATE',
                'expected' => $template->template_code,
                'found' => $uploadedCode,
            ];

            return $errors;
        }

        $uploadedVersion = $props->getCustomPropertyValue('template_version');
        if ($uploadedVersion && $uploadedVersion !== $template->version) {
            $errors[] = [
                'row' => 0,
                'column' => '',
                'error' => 'Template version outdated. Uploaded: '.$uploadedVersion.'. Current: '.$template->version.'.',
                'type' => 'TEMPLATE_VERSION_UNSUPPORTED',
                'uploaded_version' => $uploadedVersion,
                'current_version' => $template->version,
            ];
        }

        return $errors;
    }

    private function validateSchema(Worksheet $sheet, AuctionTemplate $template): array
    {
        $errors = [];
        $columns = $template->schema_definition['columns'] ?? [];

        $headerRow = [];
        $lastCol = $sheet->getHighestColumn();
        $lastColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastCol);

        for ($c = 1; $c <= $lastColIndex; $c++) {
            $val = trim((string) $sheet->getCell([$c, 1])->getValue());
            if ($val !== '') {
                $headerRow[strtolower($val)] = $c;
            }
        }

        foreach ($columns as $col) {
            if (($col['required'] ?? false)) {
                $label = strtolower($col['label'] ?? $col['key']);
                if (! isset($headerRow[$label])) {
                    $errors[] = ['row' => 0, 'column' => $col['label'] ?? $col['key'], 'error' => 'Required column "'.$col['label'].'" is missing from the Data sheet.'];
                }
            }
        }

        return $errors;
    }

    private function parseAndValidateRows(Worksheet $sheet, AuctionTemplate $template): array
    {
        $columns = $template->schema_definition['columns'] ?? [];
        $errors = [];
        $rows = [];

        $headerMap = $this->buildHeaderMap($sheet);
        $highestRow = $sheet->getHighestRow();
        $maxRows = $template->max_rows ?: 1000;

        if ($highestRow - 1 > $maxRows) {
            $errors[] = ['row' => 0, 'column' => '', 'error' => 'Too many rows. Maximum allowed: '.$maxRows.'. Found: '.($highestRow - 1).'.'];

            return ['valid' => false, 'errors' => $errors, 'rows' => []];
        }

        $totalQty = 0;
        $totalRefValue = 0;
        $dataRowCount = 0;

        for ($r = 2; $r <= $highestRow; $r++) {
            $rowData = [];
            $rowEmpty = true;

            foreach ($columns as $col) {
                $label = strtolower($col['label'] ?? $col['key']);
                $colIndex = $headerMap[$label] ?? null;
                $value = $colIndex ? trim((string) $sheet->getCell([$colIndex, $r])->getCalculatedValue()) : '';

                if ($value !== '') {
                    $rowEmpty = false;
                }

                $value = $this->sanitizeCellValue($value);
                $rowData[$col['key']] = $value;
            }

            if ($rowEmpty) {
                continue;
            }

            $dataRowCount++;
            $rowErrors = $this->validateRow($r, $rowData, $columns);
            $errors = array_merge($errors, $rowErrors);

            $qty = $this->parseNumeric($rowData['quantity'] ?? '1');
            $refVal = $this->parseNumeric($rowData['reference_value'] ?? '0');
            $totalQty += $qty;
            $totalRefValue += $refVal * $qty;

            $rows[] = ['row_number' => $r - 1, 'data' => $rowData];
        }

        if ($dataRowCount === 0) {
            $errors[] = ['row' => 0, 'column' => '', 'error' => 'No data rows found in the template.'];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'rows' => $rows,
            'row_count' => $dataRowCount,
            'total_quantity' => $totalQty,
            'total_reference_value' => $totalRefValue,
        ];
    }

    private function buildHeaderMap(Worksheet $sheet): array
    {
        $map = [];
        $lastCol = $sheet->getHighestColumn();
        $lastColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastCol);

        for ($c = 1; $c <= $lastColIndex; $c++) {
            $val = strtolower(trim((string) $sheet->getCell([$c, 1])->getValue()));
            if ($val !== '') {
                $map[$val] = $c;
            }
        }

        return $map;
    }

    private function validateRow(int $rowNum, array $data, array $columns): array
    {
        $errors = [];

        foreach ($columns as $col) {
            $key = $col['key'];
            $value = $data[$key] ?? '';
            $label = $col['label'] ?? $key;
            $required = $col['required'] ?? false;
            $type = $col['type'] ?? 'string';

            if ($required && ($value === '' || $value === null)) {
                $errors[] = ['row' => $rowNum, 'column' => $label, 'value' => '', 'error' => $label.' is required.'];

                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            switch ($type) {
                case 'integer':
                    if (! ctype_digit(str_replace([',', ' '], '', (string) $value))) {
                        $errors[] = ['row' => $rowNum, 'column' => $label, 'value' => $value, 'error' => $label.' must be a whole number.'];
                    }
                    break;
                case 'decimal':
                case 'money':
                    $cleaned = str_replace([',', '₹', '$', ' '], '', (string) $value);
                    if (! is_numeric($cleaned)) {
                        $errors[] = ['row' => $rowNum, 'column' => $label, 'value' => $value, 'error' => $label.' must be a valid number.'];
                    } elseif ((float) $cleaned < 0) {
                        $errors[] = ['row' => $rowNum, 'column' => $label, 'value' => $value, 'error' => $label.' cannot be negative.'];
                    }
                    break;
                case 'year':
                    $y = (int) $value;
                    if ($y < 1900 || $y > (int) date('Y') + 2) {
                        $errors[] = ['row' => $rowNum, 'column' => $label, 'value' => $value, 'error' => $label.' must be a reasonable year.'];
                    }
                    break;
                case 'enum':
                    $allowed = $col['values'] ?? [];
                    if (! empty($allowed) && ! in_array(strtolower($value), array_map('strtolower', $allowed))) {
                        $errors[] = ['row' => $rowNum, 'column' => $label, 'value' => $value, 'error' => $label.' must be one of: '.implode(', ', $allowed).'.'];
                    }
                    break;
            }

            if (isset($col['min'])) {
                $numVal = $this->parseNumeric($value);
                if ($numVal < $col['min']) {
                    $errors[] = ['row' => $rowNum, 'column' => $label, 'value' => $value, 'error' => $label.' must be at least '.$col['min'].'.'];
                }
            }
        }

        return $errors;
    }

    private function sanitizeCellValue(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'])) {
            if (! is_numeric($value)) {
                $value = "'".$value;
            }
        }

        return $value;
    }

    private function parseNumeric(string $value): float
    {
        return (float) str_replace([',', '₹', '$', ' '], '', $value);
    }

    public function storeUpload(
        UploadedFile $file,
        Auction $auction,
        AuctionTemplate $template,
        array $parseResult,
        int $userId,
    ): AuctionTemplateUpload {
        $nextVersion = ($auction->templateUploads()->max('submission_version') ?? 0) + 1;

        $storedPath = $file->store('auction-templates/'.$auction->code, 'local');

        return AuctionTemplateUpload::create([
            'auction_id' => $auction->id,
            'template_id' => $template->id,
            'template_version' => $template->version,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'disk' => 'local',
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_hash' => $parseResult['file_hash'],
            'row_count' => $parseResult['row_count'] ?? 0,
            'total_quantity' => $parseResult['total_quantity'] ?? 0,
            'total_reference_value' => $parseResult['total_reference_value'] ?? 0,
            'status' => $parseResult['valid'] ? 'valid' : 'invalid',
            'validation_errors' => $parseResult['valid'] ? null : $parseResult['errors'],
            'parsed_summary' => $parseResult['valid'] ? [
                'rows' => count($parseResult['rows']),
                'total_quantity' => $parseResult['total_quantity'],
                'total_reference_value' => $parseResult['total_reference_value'],
            ] : null,
            'submission_version' => $nextVersion,
            'uploaded_by' => $userId,
        ]);
    }

    public function confirmImport(AuctionTemplateUpload $upload): void
    {
        $auction = $upload->auction;
        $template = $upload->template;
        $schema = $template->schema_definition;
        $columns = $schema['columns'] ?? [];

        $file = Storage::disk($upload->disk)->path($upload->stored_path);
        $spreadsheet = IOFactory::load($file);
        $dataSheet = $spreadsheet->getSheetByName('Data');
        $headerMap = $this->buildHeaderMap($dataSheet);

        DB::transaction(function () use ($auction, $upload, $template, $columns, $dataSheet, $headerMap) {
            $auction->items()->delete();
            $auction->lots()->delete();

            $highestRow = $dataSheet->getHighestRow();
            $lotNumber = 0;

            for ($r = 2; $r <= $highestRow; $r++) {
                $rowData = [];
                $rowEmpty = true;

                foreach ($columns as $col) {
                    $label = strtolower($col['label'] ?? $col['key']);
                    $colIndex = $headerMap[$label] ?? null;
                    $value = $colIndex ? trim((string) $dataSheet->getCell([$colIndex, $r])->getCalculatedValue()) : '';
                    if ($value !== '') {
                        $rowEmpty = false;
                    }
                    $rowData[$col['key']] = $this->sanitizeCellValue($value);
                }

                if ($rowEmpty) {
                    continue;
                }

                $lotNumber++;
                $qty = $this->parseNumeric($rowData['quantity'] ?? '1');
                $refVal = $this->parseNumeric($rowData['reference_value'] ?? '0');

                $lot = Lot::create([
                    'code' => sprintf('%s-L%d', $auction->code, $lotNumber),
                    'auction_id' => $auction->id,
                    'name' => $rowData['item_name'] ?? $rowData['product_name'] ?? 'Lot '.$lotNumber,
                    'description' => $rowData['description'] ?? null,
                    'brand' => $rowData['brand'] ?? null,
                    'model' => $rowData['model'] ?? null,
                    'quantity' => (string) $qty,
                    'uom' => $rowData['unit'] ?? $auction->uom,
                    'condition' => $rowData['condition'] ?? null,
                    'location' => $rowData['location'] ?? null,
                    'reference_value' => $refVal > 0 ? $refVal : null,
                    'reserve_price' => $this->parseNumeric($rowData['reserve_value'] ?? '0') ?: null,
                    'attributes' => $this->extractExtraAttributes($rowData, $columns),
                ]);

                AuctionItem::create([
                    'auction_id' => $auction->id,
                    'lot_id' => $lot->id,
                    'upload_id' => $upload->id,
                    'row_number' => $r - 1,
                    'item_name' => $rowData['item_name'] ?? $rowData['product_name'] ?? 'Item '.$lotNumber,
                    'brand' => $rowData['brand'] ?? null,
                    'model' => $rowData['model'] ?? null,
                    'description' => $rowData['description'] ?? null,
                    'quantity' => $qty,
                    'unit' => $rowData['unit'] ?? 'PCS',
                    'condition' => $rowData['condition'] ?? null,
                    'functional_status' => $rowData['functional_status'] ?? null,
                    'physical_condition' => $rowData['physical_condition'] ?? null,
                    'manufacturing_year' => ! empty($rowData['manufacturing_year']) ? (int) $rowData['manufacturing_year'] : null,
                    'location' => $rowData['location'] ?? null,
                    'reference_value' => $refVal > 0 ? $refVal : null,
                    'reserve_value' => $this->parseNumeric($rowData['reserve_value'] ?? '0') ?: null,
                    'serial_identifier' => $rowData['serial_identifier'] ?? null,
                    'remarks' => $rowData['remarks'] ?? null,
                    'attributes' => $this->extractExtraAttributes($rowData, $columns),
                ]);
            }

            $upload->update([
                'status' => 'parsed',
                'confirmed_at' => now(),
            ]);

            $auction->update([
                'template_id' => $upload->template_id,
                'template_version' => $upload->template_version,
                'submission_version' => $upload->submission_version,
                'lot_type' => $lotNumber > 1 ? 'lot_wise' : 'single',
            ]);
        });

        $spreadsheet->disconnectWorksheets();
    }

    private function extractExtraAttributes(array $rowData, array $columns): ?array
    {
        $coreKeys = ['item_name', 'product_name', 'brand', 'model', 'description', 'quantity', 'unit', 'condition', 'functional_status', 'physical_condition', 'manufacturing_year', 'location', 'reference_value', 'reserve_value', 'serial_identifier', 'remarks'];
        $extra = [];

        foreach ($columns as $col) {
            $key = $col['key'];
            if (in_array($key, $coreKeys)) {
                continue;
            }
            if (! empty($rowData[$key])) {
                $extra[$key] = $rowData[$key];
            }
        }

        return empty($extra) ? null : $extra;
    }
}
