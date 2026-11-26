<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for the calculation defects confirmed in the Analytics
 * audit. Each test states the reading the page must give and would fail on
 * the calculation it replaced.
 */
class AnalyticsAuditCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsService $analytics;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = app(AnalyticsService::class);

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'AUDIT',
            'unit_name' => 'Audit Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();

        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Trend buckets                                                       */
    /* ------------------------------------------------------------------ */

    public function test_a_monthly_trend_keeps_the_short_month_after_a_period_that_starts_on_the_31st(): void
    {
        /*
         * A semester that starts on 31 January. Stepping a month at a time
         * from the 31st lands in March, so February had no bucket and its
         * requests vanished from the chart while still counting in the KPI.
         */
        $from = Carbon::create(2026, 1, 31)->startOfDay();
        $to = Carbon::create(2026, 4, 30)->endOfDay();

        $this->request('ACADEMIC', 'College of Computer Studies', createdAt: Carbon::create(2026, 2, 10, 10));
        $this->request('ACADEMIC', 'College of Computer Studies', createdAt: Carbon::create(2026, 3, 12, 10));

        $trend = $this->analytics->trend($from, $to, null, null, 'semester');
        $counts = array_column($trend['points'], 'count', 'label');

        $this->assertSame(1, $counts['February 2026'] ?? null);
        $this->assertSame(1, $counts['March 2026'] ?? null);
        $this->assertSame(
            $this->analytics->demandTotals($from, $to, null, null)['requests'],
            array_sum($counts),
            'Every filed request the KPI counts is plotted.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Operational performance                                             */
    /* ------------------------------------------------------------------ */

    public function test_pending_approvals_are_the_current_queue_not_only_requests_filed_in_the_period(): void
    {
        /* Filed in March, still in review on 20 April: 41 days in the queue. */
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::UnderSpmu,
            createdAt: Carbon::create(2026, 3, 10, 9)
        );

        $april = $this->analytics->processingPerformance($this->from, $this->to, null, null);

        $this->assertSame(1, $april['pending_count']);
        $this->assertEquals(41 * 1440, $april['oldest_pending_minutes']);
        $this->assertTrue($april['available']);
    }

    public function test_only_requests_awaiting_an_approver_count_as_pending(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::UnderGsu);
        /* Waiting on the borrower, not on an approver. */
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::ReturnedForRevision);
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::Rejected);
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::Cancelled);
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::Draft);

        $approved = $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::ApprovedReadyForRelease);
        $approved->forceFill(['final_approved_at' => $this->from->copy()->addDays(3)])->save();

        $this->assertSame(1, $this->analytics->processingPerformance($this->from, $this->to, null, null)['pending_count']);
    }

    public function test_pending_approvals_follow_the_division_and_borrower_filters(): void
    {
        $borrower = $this->borrower('Filtered Borrower');

        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::UnderSpmu, borrower: $borrower);
        $this->request('ADMINISTRATIVE', 'Supply Office', RequestStatus::UnderSpmu);

        $this->assertSame(2, $this->analytics->processingPerformance($this->from, $this->to, null, null)['pending_count']);
        $this->assertSame(1, $this->analytics->processingPerformance($this->from, $this->to, 'ACADEMIC', null)['pending_count']);
        $this->assertSame(1, $this->analytics->processingPerformance($this->from, $this->to, null, null, $borrower->id)['pending_count']);
    }

    public function test_approval_and_release_averages_run_between_their_recorded_timestamps(): void
    {
        $item = $this->item('Projector', 5);
        $filed = Carbon::create(2026, 4, 3, 9);

        $request = $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            createdAt: $filed,
            lines: [['item' => $item, 'released' => 1]],
            custody: ['status' => 'ACTIVE', 'released_at' => $filed->copy()->addHours(26)]
        );
        $request->forceFill(['final_approved_at' => $filed->copy()->addHours(2)])->save();

        /* Filed and never decided: no endpoint, so no sample. */
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::UnderSpmu, createdAt: $filed);

        $processing = $this->analytics->processingPerformance($this->from, $this->to, null, null);

        $this->assertSame(1, $processing['approval_samples']);
        $this->assertEquals(120, $processing['approval_minutes']);
        $this->assertSame(1, $processing['release_samples']);
        $this->assertEquals(24 * 60, $processing['release_minutes']);
    }

    /* ------------------------------------------------------------------ */
    /* Peak borrowing                                                      */
    /* ------------------------------------------------------------------ */

    public function test_peak_borrowing_follows_the_borrower_filter(): void
    {
        $selected = $this->borrower('Selected Borrower');

        for ($i = 0; $i < 10; $i++) {
            $this->request('ACADEMIC', 'College of Computer Studies', createdAt: Carbon::create(2026, 4, 6, 10), borrower: $selected);
            $this->request('ACADEMIC', 'College of Computer Studies', createdAt: Carbon::create(2026, 4, 8, 14));
        }

        $this->assertSame(20, $this->analytics->peakBorrowing($this->from, $this->to, null, null)['total']);

        $peak = $this->analytics->peakBorrowing($this->from, $this->to, null, null, $selected->id);

        $this->assertSame(10, $peak['total']);
        $this->assertSame('Monday', $peak['peak_day']);
    }

    /* ------------------------------------------------------------------ */
    /* Inventory                                                           */
    /* ------------------------------------------------------------------ */

    public function test_attention_needed_counts_a_maintenance_hold_as_well_as_an_incident(): void
    {
        $item = $this->item('Folding Table', 10);

        $this->incidentHeld($item, 3);

        app(InventoryService::class)->recordAdjustment($item, $this->spmuHead(), [
            'action' => 'PLACE_UNDER_MAINTENANCE',
            'quantity' => 2,
            'reason' => 'Two units found damaged during preparation.',
        ]);

        $totals = $this->analytics->inventory(app(InventoryService::class))['totals'];

        /* Three held by the incident and two on hold are five different units. */
        $this->assertEquals(3, $totals['incident']);
        $this->assertEquals(2, $totals['condition_hold']);
        $this->assertEquals(5, $totals['attention']);
        $this->assertEquals(5, $totals['available']);
    }

    public function test_the_distribution_states_add_up_to_serviceable_stock(): void
    {
        $item = $this->item('Folding Table', 10);

        $this->incidentHeld($item, 3);

        app(InventoryService::class)->recordAdjustment($item, $this->spmuHead(), [
            'action' => 'PLACE_UNDER_MAINTENANCE',
            'quantity' => 2,
            'reason' => 'Two units found damaged during preparation.',
        ]);

        $totals = $this->analytics->inventory(app(InventoryService::class))['totals'];

        $this->assertEquals(
            $totals['serviceable'],
            $totals['available'] + $totals['allocated'] + $totals['on_custody'] + $totals['laundry']
                + $totals['incident'] + $totals['condition_hold']
        );

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'inventory',
            'academic_period' => 'month',
        ]));

        $page->assertOk();
        /* Available 5 of 10, and the held slice carries the other 5. */
        $page->assertSee('aria-label="Available: 5 units, 50 percent of serviceable stock"', false);
        $page->assertSee('aria-label="Incident / Unavailable: 5 units, 50 percent of serviceable stock"', false);
    }

    public function test_inventory_health_renders_when_a_low_stock_item_has_release_history(): void
    {
        /*
         * Nine of ten out across three releases: the item is on the risk
         * watch and has release history. The watch row used to read a key
         * the service never returned, which failed the whole page.
         */
        $item = $this->item('Sound System', 10);

        foreach ([2, 10, 18] as $offset) {
            $this->releaseOnce($item, 3, $this->from->copy()->addDays($offset));
        }

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'inventory',
            'academic_period' => 'month',
        ]));

        $page->assertOk();
        $page->assertSee('Coverage Detail');
        $page->assertSee('1 of 10 available now', false);
        $page->assertViewHas('coverage', function (array $data): bool {
            $row = collect($data['items'])->firstWhere('name', 'Sound System');

            return $row !== null && (float) $row['available'] === 1.0
                && (float) $row['stock'] === 10.0 && (float) $row['released'] === 9.0
                && $row['releases'] === 3 && ! array_key_exists('days_coverage', $row)
                && ! array_key_exists('daily_rate', $row) && ! array_key_exists('risk', $row);
        });
        // Incoming separates current availability from descriptive period releases.
        $page->assertSee('Sound System: 9 released', false);
        $page->assertViewHas('released', fn (array $data) => collect($data['items'])->contains(
            fn (array $row) => $row['name'] === 'Sound System' && (float) $row['released'] === 9.0
        ));

        foreach (['days coverage', 'Insufficient history', 'per day', 'An estimate, not a probability'] as $projected) {
            $page->assertDontSee($projected, false);
        }

        $scope = ['section' => 'inventory', 'academic_period' => 'month'];
        $this->get(route('analytics.index', $scope + ['detail' => 'card', 'for' => 'inventory.coverage']))
            ->assertOk()->assertViewHas('detail', fn (array $detail) => $detail['title'] === 'Coverage Detail'
                && $detail['value'] === 1
                && $detail['breakdown_tables'][0]['rows'][0][0] === 'Sound System'
                && (float) $detail['breakdown_tables'][0]['rows'][0][1] === 1.0
                && (float) $detail['breakdown_tables'][0]['rows'][0][3] === 9.0
                && count($detail['table']['rows']) === 3);
        $this->get(route('analytics.index', $scope + ['detail' => 'coverage', 'item' => $item->id]))
            ->assertOk()->assertViewHas('detail', fn (array $detail) => $detail['title'] === 'Sound System'
                && (float) $detail['value'] === 1.0 && $detail['value_label'] === 'units available now');
    }

    public function test_stock_history_filters_borrowers_and_never_assigns_external_releases_to_a_cspc_unit(): void
    {
        $item = $this->item('Scoped stock history', 20);
        $external = $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 2]],
            custody: ['status' => 'ACTIVE', 'released_at' => $this->from->copy()->addDays(2)]);
        $external->versions()->first()->update(['affiliation_type' => 'EXTERNAL', 'external_company_name' => 'Reviewed Agency']);
        $this->releaseOnce($item, 3, $this->from->copy()->addDays(3));
        $inventory = app(InventoryService::class);

        $all = $this->analytics->stockCoverage($inventory, $this->from, $this->to);
        $employee = $this->analytics->stockCoverage($inventory, $this->from, $this->to, 10, 'ACADEMIC', 'College of Computer Studies');
        $selected = $this->analytics->stockCoverage($inventory, $this->from, $this->to, 10, null, null, $external->borrower_user_id);
        $this->assertSame(5.0, (float) $all['items'][0]['released']);
        $this->assertSame(3.0, (float) $employee['items'][0]['released']);
        $this->assertSame(2.0, (float) $selected['items'][0]['released']);
        $this->assertSame(15.0, (float) $selected['items'][0]['available'], 'Current stock remains institution-wide.');
        $this->assertSame([], $this->analytics->stockCoverage($inventory, $this->from, $this->to, 10, 'ACADEMIC', null, $external->borrower_user_id)['items']);
    }

    public function test_the_low_availability_rule_is_the_same_on_overview_and_inventory_health(): void
    {
        /* Exactly a quarter usable is Limited; one unit more is not. */
        $atThreshold = $this->item('At Threshold', 8);
        $aboveThreshold = $this->item('Above Threshold', 8);

        $this->releaseOnce($atThreshold, 6, $this->from->copy()->addDays(2));
        $this->releaseOnce($aboveThreshold, 5, $this->from->copy()->addDays(2));

        $inventory = app(InventoryService::class);
        $low = $this->analytics->lowAvailability($inventory);
        $rows = collect($this->analytics->inventory($inventory)['availability'])->keyBy('name');

        $this->assertSame(0.25, AnalyticsService::LOW_AVAILABILITY_RATIO);
        $this->assertSame(1, $low['count']);
        $this->assertSame('At Threshold', $low['watch']['name']);
        $this->assertSame('Limited', $rows['At Threshold']['status']);
        $this->assertTrue($rows['At Threshold']['low']);
        $this->assertSame('Healthy', $rows['Above Threshold']['status']);
        $this->assertFalse($rows['Above Threshold']['low']);
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    /** A returned borrowing with an open incident holding part of what came back. */
    private function incidentHeld(InventoryItem $item, int $quantity): void
    {
        $borrower = $this->borrower();

        $request = $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            borrower: $borrower,
            lines: [['item' => $item, 'released' => $quantity, 'returned' => $quantity]],
            custody: ['status' => 'INCIDENT_OPEN', 'released_at' => $this->from->copy()->addDays(3)]
        );

        $custody = CustodyTransaction::query()->where('request_id', $request->id)->firstOrFail();

        $incidentId = DB::table('incidents')->insertGetId([
            'incident_no' => 'INC-'.fake()->unique()->numberBetween(100000, 999999),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $borrower->id,
            'reported_by_user_id' => $this->spmuHead()->id,
            'incident_type' => 'LOST',
            'reported_at' => $this->from->copy()->addDays(5),
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_lines')->insert([
            'incident_id' => $incidentId,
            'custody_line_id' => CustodyLine::query()->where('custody_transaction_id', $custody->id)->value('id'),
            'quantity' => $quantity,
            'observed_condition' => 'LOST',
            'disposition_state' => 'LOST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function releaseOnce(InventoryItem $item, int $quantity, Carbon $releasedAt): void
    {
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            createdAt: $releasedAt->copy()->subDay(),
            lines: [['item' => $item, 'released' => $quantity]],
            custody: ['status' => 'ACTIVE', 'released_at' => $releasedAt]
        );
    }

    private function spmuHead(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
        ]);
    }

    private function borrower(?string $name = null): User
    {
        return User::factory()->create(array_filter([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
            'full_name' => $name,
        ]));
    }

    private function item(string $description, int $total): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'AUDIT'],
            ['category_name' => 'Audit Fixture', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => $description,
            'total_quantity' => $total,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);
    }

    /**
     * @param  array<int, array{item: InventoryItem, released: int, returned?: int}>  $lines
     */
    private function request(
        string $division,
        string $unit,
        RequestStatus $status = RequestStatus::UnderSpmu,
        ?Carbon $createdAt = null,
        ?User $borrower = null,
        array $lines = [],
        ?array $custody = null,
    ): BorrowingRequest {
        $createdAt ??= $this->from->copy()->addDays(2);
        $borrower ??= $this->borrower();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => $status,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Audit fixture activity',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);

        if ($custody === null) {
            return $request->refresh();
        }

        $transaction = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.fake()->unique()->numberBetween(100000, 999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $custody['status'],
            'due_at' => $custody['due_at'] ?? $createdAt->copy()->addDays(3)->endOfDay(),
            'released_at' => $custody['released_at'] ?? null,
            'closed_at' => $custody['closed_at'] ?? null,
        ]);

        $transaction->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        foreach ($lines as $line) {
            $requestItem = RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $line['item']->id,
                'description_snapshot' => $line['item']->unique_description,
                'unit_snapshot' => 'Piece',
                'requested_quantity' => $line['released'],
                'approved_quantity' => $line['released'],
            ]);

            $allocation = Allocation::query()->create([
                'request_item_id' => $requestItem->id,
                'period_start' => $createdAt->copy()->addDay()->startOfDay(),
                'period_end' => $createdAt->copy()->addDays(3)->endOfDay(),
                'allocated_quantity' => $line['released'],
                'released_quantity' => $line['released'],
                'restored_quantity' => 0,
                'status' => 'RELEASED',
                'allocated_at' => $createdAt,
            ]);

            CustodyLine::query()->create([
                'custody_transaction_id' => $transaction->id,
                'request_item_id' => $requestItem->id,
                'allocation_id' => $allocation->id,
                'approved_quantity' => $line['released'],
                'quantity_to_receive' => $line['released'],
                'actual_released_quantity' => $line['released'],
                'returned_quantity' => $line['returned'] ?? 0,
            ]);
        }

        return $request->refresh();
    }
}
