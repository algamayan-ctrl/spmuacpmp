<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Reports\ReportExportOptions;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

/** Stock Card presentation only: the controller supplies the complete filtered ledger. */
class InventoryStockCardExportService
{
    public const HEADERS = ['Date', 'Reference', 'In', 'Out', 'Issued Office', 'Balance Qty.'];

    public function options(Request $request, ?string $forceFormat = null): ReportExportOptions
    {
        $copy = $request->duplicate();
        // Keep the existing Reports validation/defaults; Stock Card automatic is landscape.
        $choice = $request->input('orientation');
        $copy->merge([
            'orientation' => in_array($choice, ['portrait', 'landscape'], true) ? $choice : 'landscape',
            'format' => $forceFormat ?? $request->input('format', 'pdf'),
            'options_submitted' => 1,
            'include_summary' => 0,
        ]);
        $copy->validate([
            'format' => ['required', 'string'], 'page_size' => ['nullable', 'string'],
            'margins' => ['nullable', 'string'], 'font_size' => ['nullable', 'numeric'],
            'margin_top' => ['nullable', 'numeric'], 'margin_right' => ['nullable', 'numeric'],
            'margin_bottom' => ['nullable', 'numeric'], 'margin_left' => ['nullable', 'numeric'],
        ]);
        return ReportExportOptions::fromRequest($copy, 'stock-card');
    }

    public function records(array $card): array
    {
        return $card['rows']->map(fn (array $row) => [
            $row['date'] ? Carbon::parse($row['date'])->format('m-d-Y') : '',
            (string) $row['reference'],
            $row['receipt'], $row['issue'],
            $row['issue'] !== null ? (string) $row['office'] : '',
            $row['balance'],
        ])->values()->all();
    }

    public function scope(array $card): string
    {
        return match ($card['movement']) { 'RECEIPT' => 'Received Items', 'ISSUE' => 'Issued Items', default => 'All Entries' }
            .($card['search'] !== '' ? ' · Search: '.$card['search'] : '');
    }

    public function mimeType(string $format): string
    {
        return app(ReportService::class)->mimeType($format);
    }

    public function filename(InventoryItem $item, string $format): string
    {
        return 'Stock_Card_INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT).'_'.now()->format('Y-m-d').'.'.$format;
    }

    public function render(InventoryItem $item, array $card, ReportExportOptions $options): string
    {
        return match ($options->format) {
            'pdf' => $this->pdf($item, $card, $options),
            'docx' => $this->word($item, $card, $options),
            'xlsx' => $this->excel($item, $card),
            'csv' => $this->csv($card),
            default => throw new \InvalidArgumentException('Unsupported Stock Card export format.'),
        };
    }

    private function pdf(InventoryItem $item, array $card, ReportExportOptions $options): string
    {
        $pdfOptions = new Options;
        $pdfOptions->set('isRemoteEnabled', false);
        $pdfOptions->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($pdfOptions);
        $pdf->setPaper(strtolower($options->pageSize), $options->orientation);
        $pdf->loadHtml(view('inventory.stock-card-print', ['item' => $item, 'card' => $card, 'options' => $options, 'pdfMode' => true])->render(), 'UTF-8');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text(12, $canvas->get_height() - 11, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('Helvetica'), 7);
        return (string) $pdf->output();
    }

    private function csv(array $card): string
    {
        $handle = fopen('php://temp', 'w+');
        try {
            fputcsv($handle, self::HEADERS, ',', '"', '');
            foreach ($this->records($card) as $row) {
                // Protect spreadsheet readers from formula injection in textual references/offices.
                foreach ([0, 1, 4] as $index) {
                    if (preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $row[$index])) {
                        $row[$index] = "'".$row[$index];
                    }
                }
                fputcsv($handle, $row, ',', '"', '');
            }
            rewind($handle);
            return (string) stream_get_contents($handle);
        } finally { fclose($handle); }
    }

    private function identity(InventoryItem $item, array $card): array
    {
        return [
            'Item: '.$item->unique_description,
            'Inventory Reference: INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT),
            'Unit: '.$item->unit?->unit_name,
            'Filter: '.$this->scope($card),
            'Generated: '.now()->format('d M Y, g:i A'),
        ];
    }

    private function excel(InventoryItem $item, array $card): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Stock Card');
        $row = 1;
        foreach (array_merge(['STOCK CARD'], $this->identity($item, $card), array_filter([$card['warning'], $card['balance_note']])) as $line) {
            $sheet->mergeCells('A'.$row.':F'.$row);
            $sheet->setCellValueExplicit('A'.$row, $line, DataType::TYPE_STRING);
            $sheet->getStyle('A'.$row)->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(max(18, 16 * (int) ceil(mb_strlen($line) / 100)));
            $row++;
        }
        $header = $row++;
        $sheet->fromArray(self::HEADERS, null, 'A'.$header);
        $sheet->getStyle('A'.$header.':F'.$header)->getFont()->setBold(true);
        foreach ($this->records($card) as $record) {
            foreach ($record as $i => $value) {
                if ($value === null) continue;
                $sheet->setCellValueExplicit([$i + 1, $row], in_array($i, [2, 3, 5], true) ? (float) $value : (string) $value, in_array($i, [2, 3, 5], true) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            $row++;
        }
        foreach ([14, 38, 12, 12, 30, 16] as $i => $width) $sheet->getColumnDimensionByColumn($i + 1)->setWidth($width);
        $sheet->freezePane('A'.($header + 1));
        if ($row > $header + 1) {
            $sheet->setAutoFilter('A'.$header.':F'.($row - 1));
            $sheet->getStyle('C'.($header + 1).':D'.($row - 1))->getNumberFormat()->setFormatCode('#,##0.###');
            $sheet->getStyle('F'.($header + 1).':F'.($row - 1))->getNumberFormat()->setFormatCode('#,##0.###');
        }
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd($header, $header);
        return $this->temporaryFile(fn ($path) => (new Xlsx($book))->save($path));
    }

    private function word(InventoryItem $item, array $card, ReportExportOptions $options): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize($options->fontSize ?? 10);
        [$width, $height] = match ($options->pageSize) { 'LETTER' => [12240, 15840], 'LEGAL' => [12240, 20160], default => [11906, 16838] };
        if ($options->orientation === 'landscape') [$width, $height] = [$height, $width];
        $m = array_map(fn ($mm) => (int) round($mm * 56.6929), $options->marginsMm);
        $section = $word->addSection(['orientation' => $options->orientation, 'pageSizeW' => $width, 'pageSizeH' => $height, 'marginTop' => $m['top'], 'marginRight' => $m['right'], 'marginBottom' => $m['bottom'], 'marginLeft' => $m['left']]);
        $section->addText('CAMARINES SUR POLYTECHNIC COLLEGES', ['bold' => true], ['alignment' => 'center']);
        $section->addText('STOCK CARD', ['bold' => true, 'size' => 14], ['alignment' => 'center']);
        foreach ($this->identity($item, $card) as $line) $section->addText($this->literal($line));
        if ($card['warning']) $section->addText($this->literal($card['warning']));
        $table = $section->addTable(['borderSize' => 4, 'borderColor' => '555555', 'cellMargin' => 70, 'width' => 5000, 'unit' => 'pct']);
        $widths = array_map(fn ($fraction) => (int) (($width - $m['left'] - $m['right']) * $fraction), [.12, .23, .12, .12, .28, .13]);
        $table->addRow(null, ['tblHeader' => true]);
        foreach (self::HEADERS as $i => $label) $table->addCell($widths[$i], ['bgColor' => 'EEEEEE'])->addText($label, ['bold' => true]);
        foreach ($this->records($card) as $record) {
            $table->addRow(null, ['cantSplit' => true]);
            foreach ($record as $i => $value) $table->addCell($widths[$i])->addText($this->literal((string) $value), ['bold' => $i === 5], ['alignment' => in_array($i, [2, 3, 5], true) ? 'right' : 'left']);
        }
        if ($card['rows']->isEmpty()) $section->addText('No matching stock entries.');
        $section->addText($this->literal($card['balance_note']));
        $section->addFooter()->addPreserveText('Page {PAGE} of {NUMPAGES}', ['size' => 8], ['alignment' => 'right']);
        return $this->temporaryFile(fn ($path) => IOFactory::createWriter($word, 'Word2007')->save($path));
    }

    private function literal(string $value): string
    {
        return Settings::isOutputEscapingEnabled() ? $value : htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function temporaryFile(callable $write): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-stock-');
        if ($path === false) throw new \RuntimeException('Cannot create Stock Card export.');
        try { $write($path); return (string) file_get_contents($path); }
        finally { @unlink($path); }
    }
}
