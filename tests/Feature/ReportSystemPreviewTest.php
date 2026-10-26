<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Models\ApprovalStep;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use App\Reports\ReportCatalogue;
use App\Services\ReportService;
use App\Services\UserRoleAssignmentService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ReadsReportCsv;
use Tests\TestCase;

/**
 * Reports preview is a system view of the actual records; the formal
 * CSPC-SPMU layout belongs only to the generated (printed/exported) report.
 */
class ReportSystemPreviewTest extends TestCase
{
    use ReadsReportCsv;
    use RefreshDatabase;

    private User $head;

    private OrganizationalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        Carbon::setTestNow(Carbon::create(2026, 9, 20, 9));

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'SYSPRV',
            'unit_name' => 'System Preview Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->head = User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
            'full_name' => 'Preview Head',
        ]);
        app(UserRoleAssignmentService::class)->synchronize($this->head, AccessClassification::SpmuHead);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Operational reports */
    /* ------------------------------------------------------------------ */

    public function test_every_report_type_previews_as_system_ui_without_paper_layout(): void
    {
        $this->request('PRV-ALL-1', '2026-09-05 10:00:00');

        foreach (ReportCatalogue::keys() as $report) {
            $response = $this->asHead()->get(route('reports.index', [
                'report' => $report, 'academic_period' => 'month', 'generated' => 1,
            ]));

            $response->assertOk();
            $response->assertSee('Report preview', false);
            $response->assertDontSee('doc-sheet', false);
            $response->assertDontSee('Republic of the Philippines', false);
            $response->assertDontSee('No signature is required', false);
            $response->assertDontSee('data-report-print-view', false);
            $this->assertStringStartsWith('<!doctype html>', ltrim($response->getContent()), "{$report} must render a valid page.");
        }
    }

    public function test_preview_records_are_exactly_the_records_every_export_carries(): void
    {
        foreach (range(1, 13) as $index) {
            $this->request(sprintf('PRV-%03d', $index), sprintf('2026-09-%02d 08:30:00', $index));
        }
        $scope = ['report' => 'borrowing', 'academic_period' => 'month'];

        $preview = $this->asHead()->get(route('reports.index', $scope + ['generated' => 1, 'per_page' => 100]))->assertOk();
        $dataset = $preview->viewData('dataset');
        $table = $preview->viewData('table');

        $this->assertSame(13, $table->scopeTotal);
        $this->assertSame(13, $table->records->total());
        $previewRecords = collect($table->records->items())->map(fn (array $row): array => $dataset->values($row))->all();

        $csv = $this->asHead()->get(route('reports.export', ['type' => 'borrowing'] + $scope + ['format' => 'csv']))->assertOk();
        $csvRows = $this->reportCsvRows($csv->streamedContent());
        $this->assertSame($dataset->columnLabels(), array_shift($csvRows));
        $this->assertSame($previewRecords, $csvRows);

        $print = $this->asHead()->get(route('reports.print', ['type' => 'borrowing'] + $scope))->assertOk();
        foreach (range(1, 13) as $index) {
            $print->assertSee(sprintf('PRV-%03d', $index), false);
        }

        foreach (['pdf' => '%PDF', 'docx' => 'PK', 'xlsx' => 'PK'] as $format => $magic) {
            $file = $this->asHead()->get(route('reports.export', ['type' => 'borrowing'] + $scope + ['format' => $format]))->assertOk();
            $this->assertStringStartsWith($magic, $file->getContent(), "{$format} must be a real file.");
        }

        $this->assertSame(4, AuditEvent::query()->where('action_code', 'report.exported')->count());
    }

    public function test_search_sort_and_page_size_only_arrange_the_preview_never_the_export_scope(): void
    {
        foreach (range(1, 13) as $index) {
            $this->request(sprintf('PRV-%03d', $index), sprintf('2026-09-%02d 08:30:00', $index));
        }
        $scope = ['report' => 'borrowing', 'academic_period' => 'month', 'generated' => 1];

        $searched = $this->asHead()->get(route('reports.index', $scope + ['search' => 'prv-007']))->assertOk();
        $table = $searched->viewData('table');
        $this->assertSame(1, $table->records->total());
        $this->assertSame(13, $table->scopeTotal);
        $searched->assertSee('print and export include all 13 records', false);
        $searched->assertSee('Clear search', false);
        // The export dialog and the Print action carry the report scope only.
        $this->assertDoesNotMatchRegularExpression('#data-export-form>.*?name="search".*?</form>#s', $searched->getContent());
        $searched->assertSee('href="'.e(route('reports.print', ['type' => 'borrowing', 'report' => 'borrowing', 'academic_period' => 'month'])).'"', false);

        $sorted = $this->asHead()->get(route('reports.index', $scope + ['sort' => 'request_no', 'direction' => 'asc', 'per_page' => 10]))->assertOk();
        $records = $sorted->viewData('records');
        $this->assertSame('PRV-001', $records->items()[0]['request_no']);
        $this->assertSame(10, $records->perPage());
        $sorted->assertSee('aria-sort="ascending"', false);

        parse_str((string) parse_url($records->nextPageUrl(), PHP_URL_QUERY), $next);
        $this->assertSame(['report' => 'borrowing', 'academic_period' => 'month', 'generated' => '1', 'sort' => 'request_no', 'direction' => 'asc', 'page' => '2'], $next);

        $descending = $this->asHead()->get(route('reports.index', $scope + ['sort' => 'request_no', 'direction' => 'desc', 'per_page' => 25]))->assertOk();
        $this->assertSame('PRV-013', $descending->viewData('records')->items()[0]['request_no']);
        $this->assertSame(25, $descending->viewData('records')->perPage());

        // Unknown sort keys and page sizes fall back instead of failing.
        $fallback = $this->asHead()->get(route('reports.index', $scope + ['sort' => 'password', 'per_page' => 9999]))->assertOk();
        $this->assertNull($fallback->viewData('table')->sort);
        $this->assertSame(10, $fallback->viewData('records')->perPage());
    }

    public function test_filters_apply_identically_to_the_preview_and_every_export(): void
    {
        foreach (['FLT-A1', 'FLT-A2', 'FLT-A3'] as $i => $number) {
            $this->request($number, sprintf('2026-09-%02d 09:00:00', $i + 2), 'ACADEMIC');
        }
        foreach (['FLT-X1', 'FLT-X2'] as $i => $number) {
            $this->request($number, sprintf('2026-09-%02d 09:00:00', $i + 10), 'ADMINISTRATION');
        }
        $scope = ['report' => 'borrowing', 'academic_period' => 'month', 'division' => 'ACADEMIC'];

        $preview = $this->asHead()->get(route('reports.index', $scope + ['generated' => 1, 'per_page' => 100]))->assertOk();
        $dataset = $preview->viewData('dataset');
        $previewRecords = collect($preview->viewData('records')->items())->map(fn (array $row): array => $dataset->values($row))->all();
        $this->assertEqualsCanonicalizing(['FLT-A1', 'FLT-A2', 'FLT-A3'], array_column($previewRecords, 0));
        $preview->assertSee('<strong>Division:</strong>', false);
        $preview->assertDontSee('FLT-X1', false);

        // The export dialog submits exactly the previewed scope.
        $x = $this->xpath($preview);
        $form = $x->query('//form[@data-export-form]')->item(0);
        $this->assertNotNull($form);
        $hidden = [];
        foreach ($x->query('.//input[@type="hidden"]', $form) as $input) {
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertSame('ACADEMIC', $hidden['division']);
        $this->assertSame('month', $hidden['academic_period']);
        $this->assertArrayNotHasKey('per_page', $hidden);

        $csv = $this->asHead()->get(route('reports.export', ['type' => 'borrowing'] + $scope + ['format' => 'csv']))->assertOk();
        $csvRows = $this->reportCsvRows($csv->streamedContent());
        array_shift($csvRows);
        $this->assertSame($previewRecords, $csvRows);

        $strings = $this->sharedStrings(
            $this->asHead()->get(route('reports.export', ['type' => 'borrowing'] + $scope + ['format' => 'xlsx']))->assertOk()->getContent()
        );
        foreach (['FLT-A1', 'FLT-A2', 'FLT-A3'] as $number) {
            $this->assertStringContainsString($number, $strings);
        }
        $this->assertStringNotContainsString('FLT-X', $strings);

        $this->asHead()->get(route('reports.print', ['type' => 'borrowing'] + $scope))
            ->assertOk()->assertSee('FLT-A2', false)->assertDontSee('FLT-X1', false);
    }

    public function test_table_navigation_keeps_report_scope_filters_and_view_state(): void
    {
        foreach (range(1, 13) as $index) {
            $this->request(sprintf('NAV-%03d', $index), sprintf('2026-09-%02d 08:30:00', $index));
        }
        $state = [
            'report' => 'borrowing', 'academic_period' => 'month', 'division' => 'ACADEMIC', 'generated' => 1,
            'search' => 'NAV', 'sort' => 'request_no', 'direction' => 'desc', 'per_page' => 10,
        ];

        $response = $this->asHead()->get(route('reports.index', $state))->assertOk();
        $x = $this->xpath($response);

        // Search form: keeps scope, sort and page size; replaces only the search term.
        $search = $x->query('//form[@role="search"]')->item(0);
        $names = [];
        foreach ($x->query('.//input[@type="hidden"]', $search) as $input) {
            $names[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertSame(['report' => 'borrowing', 'academic_period' => 'month', 'division' => 'ACADEMIC', 'generated' => '1', 'sort' => 'request_no', 'direction' => 'desc'], $names);

        // Sorting another column keeps the search and filters and returns to page one.
        $sortHref = $x->query('//th//a[contains(@href, "sort=borrower")]')->item(0)->getAttribute('href');
        parse_str((string) parse_url($sortHref, PHP_URL_QUERY), $sortQuery);
        $this->assertSame('NAV', $sortQuery['search']);
        $this->assertSame('ACADEMIC', $sortQuery['division']);
        $this->assertArrayNotHasKey('page', $sortQuery);

        // Page links keep everything.
        parse_str((string) parse_url($response->viewData('records')->nextPageUrl(), PHP_URL_QUERY), $next);
        $this->assertSame(['report' => 'borrowing', 'academic_period' => 'month', 'division' => 'ACADEMIC', 'generated' => '1', 'search' => 'NAV', 'sort' => 'request_no', 'direction' => 'desc', 'page' => '2'], $next);

        $page2 = $this->asHead()->get($response->viewData('records')->nextPageUrl())->assertOk();
        $this->assertSame(['NAV-003', 'NAV-002', 'NAV-001'], array_column($page2->viewData('records')->items(), 'request_no'));

        // The builder still shows the chosen report and filter after navigating.
        $page2->assertSee('name="generated"', false);
        $this->assertSame('ACADEMIC', $page2->viewData('reportFilters')->get('division'));
    }

    public function test_summary_cards_are_visible_and_status_uses_the_application_labels(): void
    {
        $this->request('LBL-001', '2026-09-04 09:00:00');
        $scope = ['report' => 'borrowing', 'academic_period' => 'month'];

        $preview = $this->asHead()->get(route('reports.index', $scope + ['generated' => 1]))->assertOk();

        // The cards are rendered and no stylesheet on the page hides them.
        $preview->assertDontSee('class="report-system-summary-card"', false);
        $this->assertDoesNotMatchRegularExpression('/\.report-system-summary-grid\s*\{\s*display:\s*none/', $preview->getContent());
        // Rows never scroll inside a nested box.
        $this->assertDoesNotMatchRegularExpression('/\.report-system-table-scroll\s*\{[^}]*max-height:(?!\s*none)/', $preview->getContent());

        // A request status reads as the application labels it, on screen and in the export.
        $this->assertSame('Under SPMU Review', $preview->viewData('records')->items()[0]['status']);
        $preview->assertDontSee('Under Spmu', false);

        $csv = $this->asHead()->get(route('reports.export', ['type' => 'borrowing'] + $scope + ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Under SPMU Review', $csv);
        $this->assertStringNotContainsString('Under Spmu', $csv);
    }

    public function test_record_drilldown_is_offered_in_the_preview(): void
    {
        $request = $this->request('PRV-LINK', '2026-09-04 09:00:00');
        /* Waiting for the Head's decision, so the Head may open it. */
        $this->step($request, 2, 'RECEIVED');

        $this->asHead()->get(route('reports.index', ['report' => 'borrowing', 'academic_period' => 'month', 'generated' => 1]))
            ->assertOk()
            ->assertSee('View Record', false)
            ->assertSee('href="'.e(route('requests.show', $request)).'"', false);
    }

    public function test_generation_failure_shows_an_error_state_and_no_export_actions(): void
    {
        $this->mock(ReportService::class, function ($mock): void {
            $mock->shouldReceive('generate')->andThrow(new \RuntimeException('database unavailable'));
        });

        $this->asHead()->get(route('reports.index', ['report' => 'borrowing', 'academic_period' => 'month', 'generated' => 1]))
            ->assertOk()
            ->assertSee('The report could not be generated.', false)
            ->assertSee('Try Again', false)
            ->assertDontSee('Generate Report', false)
            ->assertDontSee('database unavailable', false);
    }

    public function test_non_head_users_cannot_preview_print_or_export_reports(): void
    {
        $officer = User::factory()->create(['access_classification' => AccessClassification::SpmuOfficer]);
        $session = $this->actingAs($officer)->withSession(['active_workspace' => 'SPMU']);

        $session->get(route('reports.index', ['report' => 'borrowing', 'generated' => 1]))->assertForbidden();
        $session->get(route('reports.print', ['type' => 'borrowing']))->assertForbidden();
        $session->get(route('reports.export', ['type' => 'borrowing', 'format' => 'csv']))->assertForbidden();
        $this->assertSame(0, AuditEvent::query()->where('action_code', 'report.exported')->count());
    }

    public function test_reports_opens_on_all_transactions_across_all_dates_by_default(): void
    {
        $this->request('DEF-001', '2026-09-04 09:00:00');
        $this->request('DEF-002', '2026-09-06 09:00:00');
        $this->request('DEF-OLD', '2026-07-06 09:00:00');

        $default = $this->asHead()->get(route('reports.index'))->assertOk();
        $default->assertSee('All Transactions', false);
        $default->assertSee('DEF-001', false);
        $default->assertSee('DEF-OLD', false);
        $default->assertDontSee('class="report-system-summary-card"', false);
        $this->assertSame(3, $default->viewData('records')->total());

        // The existing scoped Borrowing Activity report remains independently accessible.
        $scope = ['report' => 'borrowing', 'academic_period' => 'month'];
        $explicit = $this->asHead()->get(route('reports.index', $scope))->assertOk();
        $this->assertEqualsCanonicalizing(['DEF-001', 'DEF-002'], $explicit->viewData('dataset')->rows->pluck('request_no')->all());

        $empty = $this->asHead()->get(route('reports.index', ['report' => 'borrowing', 'academic_period' => 'last_month']))->assertOk();
        $this->assertSame(0, $empty->viewData('dataset')->count());
        $empty->assertSee('report-no-results', false);
    }

    public function test_reports_page_has_no_institutional_form_card_and_refreshes_in_place(): void
    {
        $this->request('LIVE-001', '2026-09-04 09:00:00');

        $page = $this->asHead()->get(route('reports.index', ['report' => 'borrowing']))->assertOk();

        $page->assertDontSee('Institutional form', false);
        $page->assertDontSee('Open Official Form', false);
        $page->assertDontSee('Official SPMU form', false);
        $page->assertDontSee('/reports/official/equipment', false);
        $page->assertDontSee('Republic of the Philippines', false);
        $page->assertDontSee('doc-sheet', false);

        // The regions the builder swaps, and the export dialog that travels with the records.
        $page->assertSee('data-report-live="builder"', false);
        $page->assertSee('data-report-live="results"', false);
        $page->assertSee('data-report-live="filters-heading"', false);
        $page->assertSee('id="report-options"', false);
        $page->assertSee('data-report-records', false);

        // The retired institutional form is no longer served at all.
        $this->asHead()->get('/reports/official/equipment?from=2026-09-01&to=2026-09-30')->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* Fixture */
    /* ------------------------------------------------------------------ */

    /* ------------------------------------------------------------------ */
    /* View Record follows the request page's own authorization */
    /* ------------------------------------------------------------------ */

    public function test_view_record_is_only_offered_for_requests_the_viewer_may_open(): void
    {
        /* Awaiting Action Officer verification: the Head has no step yet. */
        $awaitingOfficer = $this->request('ACL-AO', '2026-09-03 09:00:00');
        $this->step($awaitingOfficer, 1, 'PENDING');

        /* Verified and waiting for the Head's decision. */
        $awaitingHead = $this->request('ACL-HEAD', '2026-09-04 09:00:00');
        $this->step($awaitingHead, 1, 'VERIFIED');
        $this->step($awaitingHead, 2, 'RECEIVED');

        /* Decided requests stay open to the Head as history. */
        $approved = $this->request('ACL-APPROVED', '2026-09-05 09:00:00');
        $this->step($approved, 2, 'APPROVED');
        $approved->forceFill(['status' => RequestStatus::ApprovedReadyForRelease, 'final_approved_at' => '2026-09-06 09:00:00'])->save();

        $rejected = $this->request('ACL-REJECTED', '2026-09-06 09:00:00');
        $this->step($rejected, 2, 'REJECTED');
        $rejected->forceFill(['status' => RequestStatus::Rejected])->save();

        /* Under review with no approval step recorded at all. */
        $this->request('ACL-NOSTEP', '2026-09-07 09:00:00');

        $requests = BorrowingRequest::query()->get()->keyBy('request_no');
        $scope = ['academic_period' => 'month', 'per_page' => 100];

        $pages = [
            'borrowing' => route('reports.index', ['report' => 'borrowing'] + $scope),
            'approval' => route('reports.index', ['report' => 'approval'] + $scope),
            'gate-pass' => route('reports.index', ['report' => 'gate-pass'] + $scope),
        ];

        foreach ($pages as $name => $url) {
            $page = $this->asHead()->get($url)->assertOk();
            $rows = collect($page->viewData('table')->records->items());
            $numberKey = 'request_no';

            $this->assertCount(5, $rows, $name);

            $offered = [];
            foreach ($rows as $row) {
                $request = $requests[$row[$numberKey]];
                $link = route('requests.show', $request);
                $status = $this->asHead()->get($link)->getStatusCode();

                /* The link is on the page exactly when the request page would open. */
                $this->assertSame(
                    $status !== 403,
                    filled($row['_link'] ?? null),
                    "{$name}: {$request->request_no} answered {$status}"
                );

                if (filled($row['_link'] ?? null)) {
                    $this->assertSame($link, $row['_link']);
                    $page->assertSee('href="'.$link.'"', false);
                    $offered[] = $request->request_no;
                } else {
                    $this->assertTrue($row['_link_restricted'] ?? false);
                    $page->assertDontSee('href="'.$link.'"', false);
                }
            }

            $this->assertEqualsCanonicalizing(['ACL-HEAD', 'ACL-APPROVED', 'ACL-REJECTED'], $offered, $name);
            $this->assertSame(2, substr_count($page->getContent(), 'class="report-record-restricted"'), $name);
            $this->assertSame(3, substr_count($page->getContent(), '>View Record</a>'), $name);
        }

        /* Withholding a link never removes the record: the export still carries all five. */
        $csv = $this->asHead()->get(route('reports.export', ['type' => 'borrowing', 'format' => 'csv', 'academic_period' => 'month']))->streamedContent();
        foreach ($requests->keys() as $number) {
            $this->assertStringContainsString($number, $csv);
        }

        /* And it grants nothing: the request page keeps its own check. */
        $this->asHead()->get(route('requests.show', $awaitingOfficer))->assertForbidden();
    }

    private function step(BorrowingRequest $request, int $sequence, string $decision): void
    {
        ApprovalStep::query()->create([
            'request_version_id' => $request->currentVersion()->firstOrFail()->id,
            'stage_code' => ApprovalStage::Spmu,
            'sequence_no' => $sequence,
            'received_at' => '2026-09-03 10:00:00',
            'decision' => $decision,
            'decided_at' => in_array($decision, ['PENDING', 'RECEIVED'], true) ? null : '2026-09-05 10:00:00',
        ]);
    }

    private function asHead(): self
    {
        return $this->actingAs($this->head)->withSession(['active_workspace' => 'SPMU']);
    }

    private function xpath(TestResponse $response): \DOMXPath
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();

        return new \DOMXPath($document);
    }

    private function sharedStrings(string $xlsx): string
    {
        $file = tempnam(sys_get_temp_dir(), 'preview_xlsx_');
        file_put_contents($file, $xlsx);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $strings = (string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        @unlink($file);

        return $strings;
    }

    public function test_all_transactions_filters_search_and_dates_without_changing_formal_reports(): void
    {
        $this->request('TX-SEP', '2026-09-04 09:00:00');
        $this->request('TX-JUL', '2026-07-06 09:00:00');

        $base = $this->asHead()->get(route('reports.index'))->assertOk();
        $this->assertSame(2, $base->viewData('records')->total());
        $base->assertSee('All dates', false);
        $base->assertDontSee('class="report-system-summary-card"', false);

        $filtered = $this->asHead()->get(route('reports.index', [
            'report' => 'all-transactions',
            'type' => 'Borrowing',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'search' => 'TX-SEP',
        ]))->assertOk();
        $this->assertSame(1, $filtered->viewData('records')->total());
        $filtered->assertSee('TX-SEP', false)->assertDontSee('TX-JUL', false);

        // The ten existing formal report routes continue accepting their own scopes.
        $this->asHead()->get(route('reports.index', ['report' => 'borrowing', 'academic_period' => 'month']))->assertOk();
    }

    private function request(string $number, string $submittedAt, string $division = 'ACADEMIC'): BorrowingRequest
    {
        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $request = BorrowingRequest::query()->create([
            'request_no' => $number,
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::UnderSpmu,
        ]);
        $request->forceFill(['created_at' => $submittedAt, 'updated_at' => $submittedAt])->save();

        $day = Carbon::parse($submittedAt)->addDay();
        RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'submitted_at' => $submittedAt,
            'purpose_event' => 'System preview fixture '.$number,
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $division === 'ACADEMIC' ? 'College of Computer Studies' : 'Human Resource Management Office',
            'schedule_date' => $day->toDateString(),
            'return_date' => $day->toDateString(),
            'needed_from' => $day->copy()->startOfDay(),
            'return_due_at' => $day->copy()->endOfDay(),
        ]);

        return $request;
    }
}
