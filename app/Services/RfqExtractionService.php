<?php

namespace App\Services;

use Symfony\Component\Process\Process;

final class RfqExtractionService
{
    public function extract(string $path, float $threshold = 0.85): array
    {
        $text = $this->commandText(['pdftotext', '-layout', $path, '-']);
        $method = 'PDF_TEXT';
        if (trim($text) === '') {
            $method = 'OCR';
            $text = $this->commandText(['sh', '-lc', 'command -v pdftoppm >/dev/null && command -v tesseract >/dev/null && pdftoppm -f 1 -l 3 -png '.escapeshellarg($path).' /tmp/rfq-ocr >/dev/null 2>&1 && tesseract /tmp/rfq-ocr-1.png stdout 2>/dev/null || true']);
        }
        $quantity = $this->number($text, 'QUANTITY');
        $unit = $this->money($text, 'UNIT_PRICE');
        $total = $this->money($text, 'GRAND_TOTAL|TOTAL_QUOTATION_VALUE|FINAL_RFQ_VALUE');
        $calculated = $quantity !== null && $unit !== null ? $quantity * $unit : null;
        $warnings = [];
        if ($calculated !== null && $total !== null && abs($calculated - $total) > 0.01) $warnings[] = 'VALUE_MISMATCH';
        $found = array_filter([$quantity, $unit, $total], fn ($v) => $v !== null);
        $confidence = $method === 'PDF_TEXT' ? (count($found) === 3 ? 0.99 : 0.72) : (count($found) === 3 ? 0.68 : 0.35);
        if ($total === null) $warnings[] = 'EXTRACTION_FAILED';
        if ($confidence < $threshold) $warnings[] = 'MANUAL_REVIEW_REQUIRED';
        return ['method' => $method, 'quantity' => $quantity, 'unit_amount' => $unit, 'amount' => $total, 'calculated_total' => $calculated, 'confidence' => $confidence, 'warnings' => array_values(array_unique($warnings)), 'status' => $total !== null && $confidence >= $threshold && ! in_array('VALUE_MISMATCH', $warnings, true) ? 'extracted' : 'review_required'];
    }

    private function commandText(array $command): string
    {
        try { $p = new Process($command); $p->setTimeout(30); $p->run(); return $p->isSuccessful() ? $p->getOutput() : ''; } catch (\Throwable) { return ''; }
    }
    private function number(string $text, string $label): ?float
    {
        if (! preg_match('/'.preg_quote($label, '/').'\s*[:=]?\s*([0-9]+(?:\.[0-9]+)?)/i', $text, $m)) return null;
        return (float) $m[1];
    }
    private function money(string $text, string $labels): ?float
    {
        if (! preg_match('/(?:'.$labels.')\s*[:=]?\s*(?:₹|INR|Rs\.?\s*)?([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $text, $m)) return null;
        return (float) str_replace(',', '', $m[1]);
    }
}
