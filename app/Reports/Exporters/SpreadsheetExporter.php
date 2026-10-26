<?php

namespace App\Reports\Exporters;

use App\Reports\ReportDataset;
use App\Reports\ReportExportOptions;
use App\Reports\ReportScopeFormatter;
use App\Reports\ReportSummaryFormatter;
use App\Reports\ReportTableLayout;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Approved formal report sheet plus a flat Data sheet for sorting and filtering.
 * Values in both sheets come from the same authorized dataset.
 */
class SpreadsheetExporter
{
    /**
     * The two date shapes the report builders produce. Nothing else is
     * treated as a date, so a purpose or a remark can never be reinterpreted
     * as one.
     */
    private const DATE_FORMATS = [
        'd/m/Y, g:i A' => 'dd/mm/yyyy, h:mm AM/PM',
        'd/m/Y' => 'dd/mm/yyyy',
        'g:i A' => 'h:mm AM/PM',
        'd M Y, g:i A' => 'dd mmm yyyy hh:mm AM/PM',
        'd M Y' => 'dd mmm yyyy',
    ];

    public function render(ReportDataset $dataset, ReportExportOptions $options): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($dataset->label));

        $meta = $dataset->meta;
        $row = 1;

        $sheet->setCellValue([1, $row++], 'Republic of the Philippines');
        $sheet->setCellValue([1, $row++], 'CAMARINES SUR POLYTECHNIC COLLEGES');
        $sheet->setCellValue([1, $row++], 'Nabua, Camarines Sur');
        $row++;
        $titleRow = $row;

        /*
         * A short provenance block so an exported sheet can still be
         * identified once it is separated from the system that produced it.
         */
        $sheet->setCellValue([1, $row++], $dataset->label);
        $sheet->setCellValue([1, $row++], $meta['period_long'] ?? ($meta['period_label'] ?? ''));

        if ($options->includeGeneratedBy && ! empty($meta['generated_by'])) {
            $sheet->setCellValue([1, $row++], 'Prepared by: '.$meta['generated_by']);
        }

        $sheet->setCellValue([1, $row++], 'Date Generated: '.($meta['generated_long'] ?? ''));

        if ($options->includeFilters) {
            $scopeSummary = implode(' · ', array_column(ReportScopeFormatter::rows($meta['applied_filters'] ?? []), 'value'));

            if ($scopeSummary !== '') {
                $sheet->setCellValue([1, $row++], 'Report Scope: '.$scopeSummary);
            }
        }

        $row++;
        $headerRow = $row;

        $headers = ReportTableLayout::headers($dataset->columns);
        $lastColumn = max(1, count($dataset->columns));
        $headerEnd = $headerRow + count($headers) - 1;

        foreach ($headers as $offset => $cells) {
            foreach ($cells as $cell) {
                $column = $cell['index'] + 1;
                $sheet->setCellValueExplicit([$column, $headerRow + $offset], $cell['label'], DataType::TYPE_STRING);
                if ($cell['colspan'] > 1 || $cell['rowspan'] > 1) {
                    $sheet->mergeCells([$column, $headerRow + $offset, $column + $cell['colspan'] - 1, $headerRow + $offset + $cell['rowspan'] - 1]);
                }
            }
            $sheet->getRowDimension($headerRow + $offset)->setRowHeight(32);
        }

        $headerStyle = $sheet->getStyle([1, $headerRow, $lastColumn, $headerEnd]);
        $headerStyle->getFont()->setBold(true)->setSize(9);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0F0');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $row = $headerEnd + 1;

        foreach ($dataset->rows as $record) {
            foreach ($dataset->columns as $index => $column) {
                $this->writeCell($sheet, $index + 1, $row, (string) ($record[$column['key']] ?? ''), ($column['align'] ?? null) === 'numeric', $column['key']);
            }
            $row++;
        }

        $tableEnd = max($headerEnd, $row - 1);
        $sheet->getStyle([1, $headerRow, $lastColumn, $tableEnd])->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('8C8C8C');
        $sheet->getStyle([1, $headerEnd + 1, $lastColumn, max($headerEnd + 1, $tableEnd)])->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->freezePane([1, $headerEnd + 1]);
        foreach (ReportTableLayout::widths($dataset->columns) as $index => $width) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth(max(12, $width * 2.6));
        }
        foreach ($dataset->meta['table_notes'] ?? [] as $note) {
            $row++;
            $sheet->setCellValue([1, $row], $note);
            $sheet->mergeCells([1, $row, $lastColumn, $row]);
            $sheet->getStyle([1, $row])->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(30);
        }

        if ($options->includeSummary && ! empty($dataset->summary)) {
            $row++;
            $sheet->setCellValue([1, $row], 'Report Summary');
            $sheet->getStyle([1, $row, 1, $row])->getFont()->setBold(true);
            $row++;

            foreach ($dataset->summary as $label => $value) {
                $sheet->setCellValue([1, $row], $label);
                $summaryValueColumn = min(4, $lastColumn);
                if ($summaryValueColumn > 2) {
                    $sheet->mergeCells([1, $row, $summaryValueColumn - 1, $row]);
                }
                $sheet->getStyle([1, $row])->getAlignment()->setWrapText(true);
                $sheet->getRowDimension($row)->setRowHeight(30);
                $numeric = ReportSummaryFormatter::numeric($value);

                if ($numeric !== null) {
                    $sheet->setCellValueExplicit([$summaryValueColumn, $row], $numeric, DataType::TYPE_NUMERIC);

                    if (ReportSummaryFormatter::isCurrencyLabel((string) $label)) {
                        $sheet->getStyle([$summaryValueColumn, $row])
                            ->getNumberFormat()
                            ->setFormatCode('₱#,##0.00');
                    }
                } else {
                    $sheet->setCellValueExplicit([$summaryValueColumn, $row], (string) $value, DataType::TYPE_STRING);
                }

                $row++;
            }
        }

        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $sheet->getStyle([1, 2])->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle([1, $titleRow])->getFont()->setBold(true)->setSize(13);
        // Provenance uses the full sheet width and never stretches column A.
        foreach (range(1, $headerRow - 2) as $metaRow) {
            $sheet->mergeCells([1, $metaRow, $lastColumn, $metaRow]);
        }
        $sheet->getPageSetup()->setOrientation($options->orientation)
            ->setPaperSize(match ($options->pageSize) {
                'LEGAL' => PageSetup::PAPERSIZE_LEGAL,
                'LETTER' => PageSetup::PAPERSIZE_LETTER,
                default => PageSetup::PAPERSIZE_A4,
            })->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTop([$headerRow, $headerEnd]);
        foreach ($options->marginsMm as $edge => $mm) {
            $sheet->getPageMargins()->{'set'.ucfirst($edge)}($mm / 25.4);
        }
        $sheet->getHeaderFooter()->setOddFooter('&LSPMU-ACPMP&C'.$dataset->label.'&RPage &P of &N');
        $sheet->getPageSetup()->setPrintArea('A1:'.Coordinate::stringFromColumnIndex($lastColumn).max($tableEnd, $row));

        // Flat unique headers keep Excel filtering usable despite merged formal headings.
        $data = $spreadsheet->createSheet()->setTitle('Data');
        foreach ($dataset->columns as $index => $column) {
            $data->setCellValueExplicit([$index + 1, 1], $column['label'], DataType::TYPE_STRING);
            $data->getColumnDimensionByColumn($index + 1)->setWidth(24);
        }
        foreach ($dataset->rows->values() as $index => $record) {
            foreach ($dataset->columns as $columnIndex => $column) {
                $this->writeCell($data, $columnIndex + 1, $index + 2, (string) ($record[$column['key']] ?? ''), ($column['align'] ?? null) === 'numeric', $column['key']);
            }
        }
        $data->getStyle([1, 1, $lastColumn, 1])->getFont()->setBold(true);
        $data->getStyle([1, 1, $lastColumn, max(1, $dataset->count() + 1)])->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $data->freezePane('A2');
        $data->setAutoFilter([1, 1, $lastColumn, max(1, $dataset->count() + 1)]);
        $spreadsheet->setActiveSheetIndex(0);

        return $this->output($spreadsheet);
    }

    /**
     * Write one cell, keeping numbers numeric and dates real dates.
     *
     * Values arrive pre-formatted from the dataset, so a value is converted
     * only when it matches one of the shapes the builders emit exactly.
     * Anything else is written as text rather than guessed at.
     */
    private function writeCell(mixed $sheet, int $column, int $row, string $value, bool $numericColumn, string $key): void
    {
        if ($value === '') {
            return;
        }

        if ($numericColumn && is_numeric(str_replace(',', '', $value))) {
            $sheet->setCellValueExplicit(
                [$column, $row],
                (float) str_replace(',', '', $value),
                DataType::TYPE_NUMERIC
            );
            if (in_array($key, ['amount_assessed', 'amount_paid', 'remaining_balance'], true)) {
                $sheet->getStyle([$column, $row])->getNumberFormat()->setFormatCode('#,##0.00');
            }

            return;
        }

        foreach (self::DATE_FORMATS as $phpFormat => $excelFormat) {
            /*
             * createFromFormat throws on a value that does not match, so the
             * attempt is guarded: a purpose or a remark must never be
             * reinterpreted as a date, and must never abort the export.
             */
            try {
                $parsed = Carbon::createFromFormat('!'.$phpFormat, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format($phpFormat) === $value) {
                $sheet->setCellValue(
                    [$column, $row],
                    ($phpFormat === 'g:i A' ? ($parsed->hour * 3600 + $parsed->minute * 60) / 86400 : Date::PHPToExcel($parsed))
                );

                $sheet->getStyle([$column, $row, $column, $row])
                    ->getNumberFormat()
                    ->setFormatCode($excelFormat);

                return;
            }
        }

        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);
    }

    private function output(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    /** Excel rejects sheet names over 31 characters or carrying []:*?/\ */
    private function sheetTitle(string $label): string
    {
        return mb_substr(
            (string) preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $label),
            0,
            31
        );
    }
}
