<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AcademicPeriod;
use App\Models\BorrowingRequest;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportDateRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 1, 10));
        $this->actingAs(User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
        ]))->withSession(['active_workspace' => 'SPMU']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function predefinedRanges(): array
    {
        return [
            ['2026-10-01', 'month', '2026-10-01', '2026-10-31'],
            ['2026-10-01', 'last_month', '2026-09-01', '2026-09-30'],
            ['2026-10-01', 'quarter', '2026-10-01', '2026-12-31'],
            ['2026-10-01', 'year', '2026-01-01', '2026-12-31'],
            ['2026-01-31', 'last_month', '2025-12-01', '2025-12-31'],
            ['2028-03-31', 'last_month', '2028-02-01', '2028-02-29'],
            ['2026-05-15', 'quarter', '2026-04-01', '2026-06-30'],
            ['2026-10-01', 'week', '2026-09-28', '2026-10-04'],
        ];
    }

    #[DataProvider('predefinedRanges')]
    public function test_predefined_ranges_replace_submitted_custom_dates(string $today, string $period, string $start, string $end): void
    {
        Carbon::setTestNow(Carbon::parse($today));
        $response = $this->get(route('reports.index', [
            'report' => 'borrowing',
            'academic_period' => $period,
            'start_date' => '2025-02-05',
            'end_date' => '2025-02-20',
        ]))->assertOk();

        $this->assertSame($start, $response->viewData('from')->toDateString());
        $this->assertSame($end, $response->viewData('to')->toDateString());
        $this->assertSame('00:00:00.000000', $response->viewData('from')->format('H:i:s.u'));
        $this->assertSame('23:59:59.999999', $response->viewData('to')->format('H:i:s.u'));
        $this->assertMatchesRegularExpression('/<button[^>]+data-report-date-trigger="start"[^>]+tabindex="-1"[^>]+aria-disabled="true"/s', $response->getContent());
        $this->assertMatchesRegularExpression('/<button[^>]+data-report-date-trigger="end"[^>]+tabindex="-1"[^>]+aria-disabled="true"/s', $response->getContent());
    }

    public function test_builder_keeps_existing_options_and_exposes_server_resolved_presets(): void
    {
        $response = $this->get(route('reports.index', ['report' => 'borrowing', 'academic_period' => 'month']))->assertOk();
        $presets = $response->viewData('reportingPeriods');
        $this->assertSame([
            'This Week', 'This Month', 'Current Semester', 'Previous Semester', 'Current Academic Year',
            'Last Month', 'This Quarter', 'This Year', 'Custom Range',
        ], array_column($presets, 'label'));
        $this->assertSame('2026-09-01', $presets['last_month']['start_date']);
        $this->assertSame('2026-12-31', $presets['quarter']['end_date']);
        $this->assertSame('2026-10-01', $presets['semester']['start_date']);
        $response->assertSee('<strong data-applied-start-label>01 Oct 2026</strong>', false)
            ->assertSee('<strong data-applied-end-label>31 Oct 2026</strong>', false);

        // Optional rendered fixture for real-browser interaction and visual QA.
        if ($fixture = getenv('REPORT_BUILDER_HTML')) {
            file_put_contents($fixture, $response->getContent());
        }
    }

    public function test_existing_academic_periods_and_legacy_period_ids_still_resolve(): void
    {
        $active = AcademicPeriod::query()->create([
            'academic_year' => '2026-2027', 'term_code' => 'FIRST', 'term_name' => 'First Semester',
            'start_date' => '2026-08-01', 'end_date' => '2026-12-20', 'status' => 'ACTIVE',
        ]);
        AcademicPeriod::query()->create([
            'academic_year' => '2026-2027', 'term_code' => 'SECOND', 'term_name' => 'Second Semester',
            'start_date' => '2027-01-05', 'end_date' => '2027-05-30', 'status' => 'PLANNED',
        ]);
        foreach (['semester', (string) $active->id, 'academic_year'] as $period) {
            $response = $this->get(route('reports.index', ['report' => 'borrowing', 'academic_period' => $period]))->assertOk();
            $this->assertSame('2026-08-01', $response->viewData('from')->toDateString());
            $this->assertSame($period === 'academic_year' ? '2027-05-30' : '2026-12-20', $response->viewData('to')->toDateString());
        }
    }

    public static function invalidRanges(): array
    {
        return [
            [[], ['start_date', 'end_date']],
            [['start_date' => '2026-10-05'], ['end_date']],
            [['end_date' => '2026-10-20'], ['start_date']],
            [['start_date' => '2026-02-30', 'end_date' => '2026-10-20'], ['start_date']],
            [['start_date' => 'tomorrow', 'end_date' => '2026-10-20'], ['start_date']],
            [['start_date' => ['2026-10-05'], 'end_date' => '2026-10-20'], ['start_date']],
            [['start_date' => '2026-10-05', 'end_date' => 'not-a-date'], ['end_date']],
            [['start_date' => '2026-10-20', 'end_date' => '2026-10-05'], ['end_date']],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_custom_dates_are_validated_on_the_server(array $dates, array $errors): void
    {
        $this->getJson(route('reports.index', ['report' => 'borrowing', 'academic_period' => 'custom', 'generated' => 1, ...$dates]))
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
    }

    public function test_print_export_and_borrower_options_share_date_validation(): void
    {
        $range = ['academic_period' => 'custom', 'start_date' => '2026-10-20', 'end_date' => '2026-10-05'];
        foreach ([
            route('reports.print', ['type' => 'borrowing', ...$range]),
            route('reports.export', ['type' => 'borrowing', 'format' => 'csv', ...$range]),
            route('reports.index', ['report' => 'borrowing', 'borrower_options' => 1, ...$range]),
        ] as $url) {
            $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('end_date');
        }
    }

    public function test_custom_preview_print_export_and_borrower_scope_use_inclusive_applied_dates(): void
    {
        $inside = $this->record('2026-10-05 00:00:00', 'Inside start');
        $this->record('2026-10-20 23:59:59', 'Inside end');
        $this->record('2026-10-04 23:59:59', 'Outside before');
        $this->record('2026-10-21 00:00:00', 'Outside after');
        $range = ['academic_period' => 'custom', 'start_date' => '2026-10-05', 'end_date' => '2026-10-20'];

        $response = $this->get(route('reports.index', ['report' => 'borrowing', 'generated' => 1, ...$range]))->assertOk();
        $this->assertSame(2, $response->viewData('dataset')->count());
        $response->assertSee('<strong data-applied-start-label>05 Oct 2026</strong>', false)
            ->assertSee('<strong data-applied-end-label>20 Oct 2026</strong>', false)
            ->assertSee('Inside start')->assertSee('Inside end')
            ->assertDontSee('Outside before')->assertDontSee('Outside after');
        $this->assertSame(['report' => 'borrowing', ...$range], $response->viewData('reportFilters')->toQuery());

        $this->get(route('reports.print', ['type' => 'borrowing', ...$range]))->assertOk()
            ->assertSee('Inside start')->assertSee('Inside end')
            ->assertDontSee('Outside before')->assertDontSee('Outside after');
        $csv = $this->get(route('reports.export', ['type' => 'borrowing', 'format' => 'csv', ...$range]))->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('Inside start', $content);
        $this->assertStringContainsString('Inside end', $content);
        $this->assertStringNotContainsString('Outside before', $content);
        $this->assertStringNotContainsString('Outside after', $content);

        $this->getJson(route('reports.index', ['report' => 'borrowing', 'borrower_options' => 1, ...$range]))
            ->assertOk()->assertJsonPath('count', 2);
        $sameDay = $this->get(route('reports.index', [
            'report' => 'borrowing', 'generated' => 1, ...$range, 'end_date' => '2026-10-05',
        ]))->assertOk();
        $this->assertSame(1, $sameDay->viewData('dataset')->count());
        $this->assertSame((string) $inside->borrower_user_id, (string) $sameDay->viewData('dataset')->rows->first()['_borrower_user_id']);
    }

    public function test_pagination_export_and_clear_filters_preserve_custom_dates(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->record('2026-10-10 10:00:00', 'Range record '.$i);
        }
        $range = ['academic_period' => 'custom', 'start_date' => '2026-10-05', 'end_date' => '2026-10-20'];
        $response = $this->get(route('reports.index', ['report' => 'borrowing', 'generated' => 1, 'division' => 'ACADEMIC', ...$range]))->assertOk();
        parse_str(parse_url($response->viewData('records')->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('2026-10-05', $query['start_date']);
        $this->assertSame('2026-10-20', $query['end_date']);
        $this->get(route('reports.index', $query))->assertOk()->assertViewHas('records', fn ($records) => $records->count() === 2);
        $response->assertSee('href="'.e(route('reports.index', ['report' => 'borrowing', ...$range, 'generated' => 1])).'"', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        foreach (['start_date' => '2026-10-05', 'end_date' => '2026-10-20'] as $field => $date) {
            $inputs = $xpath->query('//input[@name="'.$field.'"]');
            $this->assertGreaterThan(0, $inputs->length);
            foreach ($inputs as $input) {
                $this->assertSame($date, $input->getAttribute('value'), 'Every builder/export form retains the resolved scope.');
            }
        }
    }

    private function record(string $submittedAt, string $purpose): BorrowingRequest
    {
        $unit = OrganizationalUnit::query()->firstOrCreate(['unit_code' => 'RANGE'], [
            'unit_name' => 'Report range unit', 'unit_type' => 'OFFICE', 'active' => true,
        ]);
        $borrower = User::factory()->create(['organizational_unit_id' => $unit->id, 'full_name' => $purpose]);
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-RANGE-'.fake()->unique()->numberBetween(100000, 999999),
            'borrower_user_id' => $borrower->id, 'accountable_unit_id' => $unit->id,
            'current_version_no' => 1, 'status' => RequestStatus::UnderSpmu,
        ]);
        RequestVersion::query()->create([
            'request_id' => $request->id, 'version_no' => 1, 'purpose_event' => $purpose,
            'location' => 'Campus', 'division_code' => 'ACADEMIC', 'office_unit' => 'College of Computer Studies',
            'submitted_at' => $submittedAt, 'needed_from' => '2026-10-22 08:00:00', 'return_due_at' => '2026-10-23 17:00:00',
        ]);

        return $request;
    }

    public function test_previous_semester_resolves_to_the_period_before_the_active_one(): void
    {
        $range = fn (string $year, string $code, string $from, string $to, string $status) => AcademicPeriod::query()->create([
            'academic_year' => $year, 'term_code' => $code, 'term_name' => ucfirst(strtolower($code)).' Semester',
            'start_date' => $from, 'end_date' => $to, 'status' => $status,
        ]);

        // No active period: the range is offered without dates and never shows another period's records.
        $unconfigured = $this->get(route('reports.index', ['report' => 'borrowing', 'academic_period' => 'previous_semester', 'generated' => 1]))->assertOk();
        $this->assertNull($unconfigured->viewData('reportingPeriods')['previous_semester']['start_date']);
        $unconfigured->assertSee('Previous semester is not configured yet.', false);

        $range('2024-2025', 'SECOND', '2025-01-13', '2025-05-30', 'CLOSED');
        $range('2025-2026', 'SECOND', '2026-01-12', '2026-05-29', 'CLOSED');
        $range('2026-2027', 'FIRST', '2026-08-03', '2026-12-18', 'ACTIVE');
        $range('2026-2027', 'SECOND', '2027-01-11', '2027-05-28', 'PLANNED');
        $this->record('2026-03-10 09:00:00', 'Previous semester record');
        $this->record('2026-09-10 09:00:00', 'Current semester record');

        $response = $this->get(route('reports.index', [
            'report' => 'borrowing', 'academic_period' => 'previous_semester', 'generated' => 1,
            'start_date' => '2025-02-05', 'end_date' => '2025-02-20',
        ]))->assertOk();

        $this->assertSame('2026-01-12', $response->viewData('from')->toDateString());
        $this->assertSame('2026-05-29', $response->viewData('to')->toDateString());
        $this->assertSame('23:59:59.999999', $response->viewData('to')->format('H:i:s.u'));
        $this->assertSame('previous_semester', $response->viewData('periodSelection'));
        $this->assertSame('2026-01-12', $response->viewData('reportingPeriods')['previous_semester']['start_date']);
        $response->assertSee('Previous semester record')->assertDontSee('Current semester record');
        $response->assertDontSee('Previous semester is not configured yet.', false);

        // Pagination and export keep the selection rather than fixed dates.
        $this->assertSame('previous_semester', $response->viewData('reportFilters')->toQuery()['academic_period']);
    }
}
