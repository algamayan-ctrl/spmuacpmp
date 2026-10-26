<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportExportOptions;
use App\Reports\ReportFilters;
use App\Reports\ReportRecordTable;
use App\Reports\RequestReceiptTiming;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\ReadsReportCsv;
use Tests\TestCase;

class ReportStandardFormatTest extends TestCase
{
    use ReadsReportCsv;
    use RefreshDatabase;

    public function test_borrowing_uses_requested_equipment_and_actual_receipt_to_release(): void
    {
        $unit = OrganizationalUnit::query()->create(['unit_code' => 'FMT', 'unit_name' => 'Format Fixture Unit', 'unit_type' => 'OFFICE', 'active' => true]);
        $borrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly, 'organizational_unit_id' => $unit->id]);
        $request = BorrowingRequest::query()->create([
            'request_no' => 'DEMO-FORMAT-001', 'borrower_user_id' => $borrower->id, 'accountable_unit_id' => $unit->id,
            'current_version_no' => 1, 'status' => RequestStatus::ApprovedReadyForRelease, 'final_approved_at' => '2026-10-21 10:00:00',
        ]);
        $version = $request->versions()->create([
            'version_no' => 1, 'purpose_event' => 'Synthetic review only', 'location' => 'Campus',
            'division_code' => 'ACADEMIC', 'office_unit' => 'Graduate School',
            'schedule_date' => '2026-10-22', 'return_date' => '2026-10-26',
            'needed_from' => '2026-10-22 08:00:00', 'return_due_at' => '2026-10-26 17:00:00',
            'submitted_at' => '2026-10-21 08:30:00',
        ]);
        $category = InventoryCategory::query()->create(['category_code' => 'FMT', 'category_name' => 'Format Fixture', 'active' => true]);
        $measure = UnitOfMeasure::query()->create(['unit_code' => 'FMT', 'unit_name' => 'Piece', 'active' => true]);
        $item = InventoryItem::query()->create(['category_id' => $category->id, 'unit_id' => $measure->id, 'unique_description' => 'Current catalogue description', 'total_quantity' => 10, 'active' => true]);
        $version->items()->create([
            'inventory_item_id' => $item->id, 'description_snapshot' => 'Wireless microphone & stand', 'unit_snapshot' => 'pcs',
            'requested_quantity' => 3, 'approved_quantity' => 2,
        ]);
        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'DEMO-CUSTODY-001', 'request_id' => $request->id, 'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id, 'status' => 'ON_CUSTODY', 'released_at' => '2026-10-22 11:00:00',
            'due_at' => '2026-10-26 17:00:00',
        ]);

        $dataset = app(ReportService::class)->generate($this->filters('borrowing'));
        $row = $dataset->rows->sole();
        $this->assertCount(12, $dataset->columns);
        $this->assertSame('Control No.', $dataset->columnLabels()[0]);
        $this->assertSame("Graduate School\nAcademic", $row['affiliation']);
        $this->assertSame('3 pcs - Wireless microphone & stand', $row['requested_equipment']);
        $this->assertSame('22/10/2026', $row['schedule_date']);
        $this->assertSame('26/10/2026', $row['return_date']);
        $this->assertSame('21/10/2026', $row['received_date']);
        $this->assertSame('8:30 AM', $row['received_time']);
        $this->assertSame('22/10/2026', $row['released_date']);
        $this->assertSame('11:00 AM', $row['released_time']);
        $this->assertSame('1 d 2 h 30 min', $row['total_time_allocated']);

        // Draft creation and approval cannot stand in for missing operational timestamps.
        $version->forceFill(['submitted_at' => null])->save();
        $request->forceFill(['created_at' => '2026-10-20 09:00:00'])->save();
        $legacy = app(ReportService::class)->generate($this->filters('borrowing'))->rows->sole();
        $this->assertSame('-', $legacy['received_date']);
        $this->assertSame('-', $legacy['total_time_allocated']);
        $custody->forceFill(['released_at' => null])->save();
        $unreleased = app(ReportService::class)->generate($this->filters('borrowing'))->rows->sole();
        $this->assertSame('-', $unreleased['released_date']);
        $this->assertSame('-', $unreleased['released_time']);
    }

    public function test_elapsed_time_handles_missing_reversed_and_multi_day_timestamps_without_mutation(): void
    {
        $received = Carbon::parse('2026-10-23 23:45:00', 'Asia/Manila');
        $released = Carbon::parse('2026-10-26 00:15:00', 'Asia/Manila');
        $this->assertSame('2 d 30 min', RequestReceiptTiming::fields($received, $released)['total_time_allocated']);
        $this->assertSame('23:45', $received->format('H:i'));
        $this->assertSame('0 sec', RequestReceiptTiming::fields($received, $received)['total_time_allocated']);
        $this->assertSame('30 sec', RequestReceiptTiming::fields($received, $received->copy()->addSeconds(30))['total_time_allocated']);
        foreach ([[null, $released], [$received, null], [$released, $received]] as [$start, $end]) {
            $this->assertSame('-', RequestReceiptTiming::fields($start, $end)['total_time_allocated']);
        }
        $utc = Carbon::parse('2026-10-21 00:30:00', 'UTC');
        $this->assertSame('8:30 AM', RequestReceiptTiming::fields($utc, null)['received_time']);
        $this->assertSame('UTC', $utc->timezoneName);
    }

    public function test_dates_times_and_elapsed_values_sort_chronologically(): void
    {
        $columns = [['key' => 'received_date', 'label' => 'Received Date'], ['key' => 'total_time_allocated', 'label' => 'Total Time Allocated'], ['key' => 'received_time', 'label' => 'Received Time']];
        $rows = collect([
            ['received_date' => '02/11/2026'] + RequestReceiptTiming::fields(Carbon::parse('2026-11-02 13:00'), Carbon::parse('2026-11-04 13:00')),
            ['received_date' => '30/10/2026'] + RequestReceiptTiming::fields(Carbon::parse('2026-10-30 09:00'), Carbon::parse('2026-10-30 09:30')),
        ]);
        foreach (['received_date', 'received_time', 'total_time_allocated'] as $sort) {
            $table = ReportRecordTable::fromRequest(Request::create('/reports', 'GET', ['sort' => $sort]), $columns, $rows, '/reports', []);
            $this->assertSame('30/10/2026', $table->records->items()[0]['received_date']);
        }
    }

    public function test_all_ten_report_schemas_share_grouped_headers_and_round_trip_every_export(): void
    {
        $service = app(ReportService::class);
        $this->assertCount(10, ReportCatalogue::keys());

        foreach (ReportCatalogue::keys() as $key) {
            $schema = $service->generate($this->filters($key));
            $record = [];
            foreach ($schema->columns as $index => $column) {
                $record[$column['key']] = match (true) {
                    ($column['align'] ?? '') === 'numeric' => '12.50',
                    str_ends_with($column['key'], '_date') => '21/10/2026',
                    str_ends_with($column['key'], '_time') => '8:30 AM',
                    str_ends_with($column['key'], '_at') => '21/10/2026, 8:30 AM',
                    default => 'Demo '.$column['label'],
                };
            }
            $markerColumn = collect($schema->columns)->first(fn (array $column): bool => ($column['align'] ?? '') !== 'numeric');
            $record[$markerColumn['key']] = 'DEMO-'.$key;
            if (isset($record['borrower'])) {
                $record['borrower'] = 'Demo Borrower A & B';
            }
            if (isset($record['requested_equipment'])) {
                $record['requested_equipment'] = "=2+3\nWireless microphone & <stand>";
            }
            $dataset = new ReportDataset($key, $schema->label, $schema->columns, collect([$record]), ['Sample records' => 1], array_merge($schema->meta, ['generated_by' => 'Synthetic test records only']));
            $options = ReportExportOptions::fromRequest(Request::create('/reports/export'), $key);
            $this->assertSame('LEGAL', $options->pageSize);
            $this->assertSame('narrow', $options->marginPreset);

            $html = view('reports.print', ['dataset' => $dataset, 'options' => $options->contentToggles(), 'exportOptions' => $options])->render();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame(2, $xpath->query('//table[@class="doc-table"]/thead/tr')->length, $key);
            $this->assertSame(count($schema->columns), $xpath->query('//table[@class="doc-table"]/tbody/tr/td')->length, $key);

            $csv = $this->reportCsvRows($service->renderExport($dataset, ReportExportOptions::fromRequest(Request::create('/', 'GET', ['format' => 'csv']), $key)));
            $this->assertSame([$schema->columnLabels(), $dataset->values($record)], $csv);

            $outputs = ['print.html' => $html];
            foreach (['pdf', 'docx', 'xlsx'] as $format) {
                $outputs[$format] = $service->renderExport($dataset, ReportExportOptions::fromRequest(Request::create('/', 'GET', ['format' => $format]), $key));
            }
            $this->assertStringStartsWith('%PDF', $outputs['pdf'], $key);
            $file = tempnam(sys_get_temp_dir(), 'report-standard-');
            try {
                file_put_contents($file, $outputs['docx']);
                $zip = new \ZipArchive;
                $this->assertTrue($zip->open($file) === true);
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                $xmlDom = new \DOMDocument;
                $this->assertTrue($xmlDom->loadXML($xml), $key.' Word XML');
                $this->assertStringContainsString('w:gridSpan', $xml);
                $this->assertStringContainsString('w:vMerge', $xml);
                $this->assertStringContainsString('DEMO-'.$key, $xml);

                file_put_contents($file, $outputs['xlsx']);
                $book = IOFactory::load($file);
                $sheet = $book->getActiveSheet();
                $this->assertNotEmpty($sheet->getMergeCells(), $key);
                $data = $book->getSheetByName('Data');
                $this->assertSame($dataset->columnLabels(), $data->rangeToArray('A1:'.Coordinate::stringFromColumnIndex(count($schema->columns)).'1')[0]);
                foreach ($schema->columns as $index => $column) {
                    $cell = $data->getCell([$index + 1, 2]);
                    if (($column['align'] ?? '') === 'numeric') {
                        $this->assertIsNumeric($cell->getValue(), $key.' '.$column['key']);
                        if (in_array($column['key'], ['amount_assessed', 'amount_paid', 'remaining_balance'], true)) {
                            $this->assertSame('12.50', $cell->getFormattedValue());
                        }
                    } else {
                        $this->assertSame($record[$column['key']], $cell->getFormattedValue(), $key.' '.$column['key']);
                        if (str_starts_with($record[$column['key']], '=')) {
                            $this->assertSame('s', $cell->getDataType());
                        }
                    }
                }
                $book->disconnectWorksheets();
            } finally {
                @unlink($file);
            }
            if ($directory = getenv('SPMU_REPORT_ARTIFACTS')) {
                if (! is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                foreach ($outputs as $extension => $content) {
                    file_put_contents($directory.'/'.$key.'.'.$extension, $content);
                }
            }
        }
    }

    private function filters(string $key): ReportFilters
    {
        return ReportFilters::fromRequest(Request::create('/reports'), $key, Carbon::parse('2026-10-01')->startOfDay(), Carbon::parse('2026-10-31')->endOfDay(), 'custom');
    }
}
