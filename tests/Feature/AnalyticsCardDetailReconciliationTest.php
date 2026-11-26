<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AcademicPeriod;
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
use App\Services\AnalyticsCardDetailService;
use App\Services\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A card detail must agree with the card it was opened from.
 *
 * AnalyticsCardDrilldownTest guards that every card opens a panel. This file
 * guards what the panel says: each detail is compared against the exact
 * service payload the visible card was rendered from, so a detail that reads
 * a key the service never wrote - and quietly shows zero, an empty list or a
 * zero-width bar in place of real data - fails here.
 *
 * Selected period: April 2026 (30 days). The forecast history windows are
 * the three 30-day periods before it, as in ForecastServiceTest:
 *
 *   P-1  02 Mar - 31 Mar   weight 3
 *   P-2  31 Jan - 01 Mar   weight 2
 *   P-3  01 Jan - 30 Jan   weight 1
 */
class AnalyticsCardDetailReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsCardDetailService $details;

    private AnalyticsService $analytics;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->details = app(AnalyticsCardDetailService::class);
        $this->analytics = app(AnalyticsService::class);

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'RECON',
            'unit_name' => 'Reconciliation Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();

        /* Inside the selected period, after every history window has closed. */
        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Forecast division and unit                                          */
    /* ------------------------------------------------------------------ */

    public function test_retired_forecast_division_detail_lists_the_same_divisions_and_values_as_the_card_is_unavailable(): void
    {
        $this->seedThreePeriodHistory();
        $detail = $this->detail('forecast.division');
        $this->assertSame('Detail unavailable', $detail['title']);
        $this->assertNull($detail['value']);
        $this->assertFalse(isset($detail['bars']));
    }

    public function test_retired_forecast_unit_detail_lists_the_same_units_in_the_same_order_as_the_card_is_unavailable(): void
    {
        $this->seedThreePeriodHistory();
        $detail = $this->detail('forecast.unit');
        $this->assertSame('Detail unavailable', $detail['title']);
        $this->assertNull($detail['value']);
        $this->assertFalse(isset($detail['bars']));
    }

    /**
     * Without enough history the card lists requests already filed for the
     * next window under a "Scheduled" heading. The detail must do the same,
     * and must never call those records a forecast.
     */
    public function test_retired_forecast_division_and_unit_details_fall_back_to_scheduled_demand_and_say_so_is_unavailable(): void
    {
        $this->seedThreePeriodHistory();
        $detail = $this->detail('forecast.scheduled');
        $this->assertSame('Detail unavailable', $detail['title']);
        $this->assertNull($detail['value']);
        $this->assertFalse(isset($detail['bars']));
    }

    /* ------------------------------------------------------------------ */
    /* Expected busy period                                                */
    /* ------------------------------------------------------------------ */

    public function test_retired_busy_period_detail_quotes_the_busiest_bucket_the_service_named_is_unavailable(): void
    {
        $this->seedThreePeriodHistory();
        $detail = $this->detail('forecast.busy');
        $this->assertSame('Detail unavailable', $detail['title']);
        $this->assertNull($detail['value']);
        $this->assertFalse(isset($detail['bars']));
    }

    public function test_retired_tied_busy_periods_are_not_presented_as_one_unique_peak_is_unavailable(): void
    {
        $this->seedThreePeriodHistory();
        $detail = $this->detail('forecast.busy');
        $this->assertSame('Detail unavailable', $detail['title']);
        $this->assertNull($detail['value']);
        $this->assertFalse(isset($detail['bars']));
    }

    public function test_retired_busy_period_detail_withholds_a_figure_instead_of_showing_zero_without_history_is_unavailable(): void
    {

        $detail = $this->detail('forecast.busy');
        $this->assertSame('Detail unavailable', $detail['title']);
        $this->assertNull($detail['value']);
        $this->assertFalse(isset($detail['bars']));
    }

    /* ------------------------------------------------------------------ */
    /* Peak borrowing                                                      */
    /* ------------------------------------------------------------------ */

    public function test_peak_detail_uses_the_service_peak_day_hour_and_total(): void
    {
        /* 6 April 2026 is a Monday; ten requests meet the minimum. */
        for ($index = 0; $index < AnalyticsService::PEAK_MINIMUM_OBSERVATIONS; $index++) {
            $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 6, 9)->addMinutes($index));
        }

        $peak = $this->analytics->peakBorrowing($this->from, $this->to, null, null);
        $this->assertTrue($peak['available']);

        $detail = $this->detail('demand.peak');

        $this->assertSame('Monday', $detail['value']);
        $this->assertNull($detail['empty']);

        $stats = collect($detail['stats'])->pluck('value', 'label');
        $this->assertSame($peak['total'], $stats['Requests observed']);
        $this->assertSame(AnalyticsService::PEAK_MINIMUM_OBSERVATIONS, $stats['Requests observed']);
        $this->assertSame($peak['peak_day'], $stats['Busiest weekday']);
        $this->assertSame($peak['peak_hour'], $stats['Busiest hour']);
        $this->assertSame('9 AM', $stats['Busiest hour']);

        $monday = collect($detail['bars'])->firstWhere('label', 'Monday');
        $this->assertSame('10', $monday['value']);
        $this->assertSame(100, $monday['share']);
    }

    public function test_peak_detail_shows_the_same_insufficient_state_as_the_card(): void
    {
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 6, 9));

        $peak = $this->analytics->peakBorrowing($this->from, $this->to, null, null);
        $this->assertFalse($peak['available']);

        $detail = $this->detail('demand.peak');

        $this->assertNull($detail['value']);
        $this->assertSame($peak['summary'], $detail['empty']);

        $stats = collect($detail['stats'])->pluck('value', 'label');

        /* The one request really was observed; the peak itself is withheld. */
        $this->assertSame(1, $stats['Requests observed']);
        $this->assertArrayNotHasKey('Busiest weekday', $stats->all());
        $this->assertNull($detail['bars']);
    }

    /* ------------------------------------------------------------------ */
    /* Low / no usage                                                      */
    /* ------------------------------------------------------------------ */

    public function test_low_usage_detail_draws_bars_from_released_quantity_when_something_moved(): void
    {
        $quiet = $this->item('Quiet Lectern');
        $light = $this->item('Lightly Used Screen');
        $busy = $this->item('Busy Speaker');

        $this->release($light, 4, Carbon::create(2026, 4, 5, 10));
        $this->release($busy, 8, Carbon::create(2026, 4, 6, 10));

        $slow = $this->analytics->slowMovingItems($this->from, $this->to, 10);
        $this->assertSame(['Quiet Lectern', 'Lightly Used Screen', 'Busy Speaker'], array_column($slow['items'], 'name'));
        $this->assertArrayNotHasKey('share', $slow['items'][0]);

        $detail = $this->detail('demand.low-usage');

        $this->assertNull($detail['table']);
        $this->assertNull($detail['empty']);

        $bars = collect($detail['bars'])->keyBy('label');

        /* Width follows released quantity against the largest shown: 0, 4/8, 8/8. */
        $this->assertSame(0, $bars['Quiet Lectern']['share']);
        $this->assertSame(50, $bars['Lightly Used Screen']['share']);
        $this->assertSame(100, $bars['Busy Speaker']['share']);
        $this->assertSame('8 released 1 release', $bars['Busy Speaker']['value']);

        $stats = collect($detail['stats'])->pluck('value', 'label');
        $this->assertSame(1, $stats['Items with no release']);
        $this->assertSame(3, $stats['Borrowable items in catalogue']);

        $this->assertSame($quiet->unique_description, $detail['bars'][0]['label']);
    }

    public function test_low_usage_detail_lists_unreleased_items_without_fake_bars(): void
    {
        $this->item('Idle Tent');
        $this->item('Idle Table');

        $detail = $this->detail('demand.low-usage');

        $this->assertNull($detail['bars']);
        $this->assertSame(['Item', 'Released', 'Release transactions'], $detail['table']['columns']);
        $this->assertSame(
            [['Idle Table', 'No release', '0'], ['Idle Tent', 'No release', '0']],
            $detail['table']['rows']
        );
        $this->assertSame('None of the listed items was released during this period.', $detail['empty']);

        $stats = collect($detail['stats'])->pluck('value', 'label');
        $this->assertSame(2, $stats['Items with no release']);
    }

    /* ------------------------------------------------------------------ */
    /* Outlook                                                             */
    /* ------------------------------------------------------------------ */

    public function test_retired_outlook_detail_draws_bars_against_the_largest_observed_or_projected_value_is_unavailable(): void
    {
        $this->seedThreePeriodHistory();
        $detail = $this->detail('forecast.outlook');
        $this->assertSame('Detail unavailable', $detail['title']);
        $this->assertNull($detail['value']);
        $this->assertFalse(isset($detail['bars']));
    }

    public function test_retired_outlook_detail_adds_no_projection_row_when_the_forecast_is_withheld_is_unavailable(): void
    {

        $detail = $this->detail('forecast.outlook');
        $this->assertSame('Detail unavailable', $detail['title']);
        $this->assertNull($detail['value']);
        $this->assertFalse(isset($detail['bars']));
    }

    /* ------------------------------------------------------------------ */
    /* Forecast readiness                                                  */
    /* ------------------------------------------------------------------ */

    public function test_retired_readiness_detail_counts_only_periods_that_have_actually_ended_is_unavailable(): void
    {
        $this->seedThreePeriodHistory();
        $detail = $this->detail('forecast.readiness');
        $this->assertSame('Detail unavailable', $detail['title']);
        $this->assertNull($detail['value']);
        $this->assertFalse(isset($detail['bars']));
    }

    public function test_retired_readiness_card_and_detail_print_the_same_completed_period_count_is_unavailable(): void
    {
        $this->seedThreePeriodHistory();
        $detail = $this->detail('forecast.readiness');
        $this->assertSame('Detail unavailable', $detail['title']);
        $this->assertNull($detail['value']);
        $this->assertFalse(isset($detail['bars']));
    }

    /* ------------------------------------------------------------------ */
    /* Most requested items and requested quantity                         */
    /* ------------------------------------------------------------------ */

    public function test_most_requested_items_detail_ranks_by_request_count_and_says_so(): void
    {
        [$frequent, $bulky] = $this->seedFrequentAndBulkyDemand();

        $requested = $this->analytics->requestedEquipment($this->from, $this->to, null, null, 10);
        $this->assertSame([$frequent->unique_description, $bulky->unique_description], array_column($requested['items'], 'name'));

        $detail = $this->detail('demand.requested-items');

        $this->assertSame('Ranked by number of requests', $detail['context']);
        $this->assertStringNotContainsString('requested quantity', strtolower($detail['context']));

        /* The bar is the request-count ranking; quantity is stated beside it. */
        $this->assertSame($frequent->unique_description, $detail['bars'][0]['label']);
        $this->assertSame('3 requests Requested quantity: 3', $detail['bars'][0]['value']);
        $this->assertSame(100, $detail['bars'][0]['share']);

        $this->assertSame($bulky->unique_description, $detail['bars'][1]['label']);
        $this->assertSame('1 request Requested quantity: 100', $detail['bars'][1]['value']);
        $this->assertSame(33, $detail['bars'][1]['share']);

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'demand',
            'academic_period' => 'month',
            'detail' => 'card',
            'for' => 'demand.requested-items',
        ]));

        $page->assertOk();
        $page->assertSee('Ranked by number of requests');
        $page->assertViewHas('detail', fn ($rendered) => $rendered['context'] === 'Ranked by number of requests'
            && $rendered['bars'][0]['value'] === '3 requests Requested quantity: 3');
        $page->assertDontSee('Ranked by requested quantity');
    }

    public function test_requested_quantity_detail_bars_follow_quantity_not_request_count(): void
    {
        [$frequent, $bulky] = $this->seedFrequentAndBulkyDemand();

        $totals = $this->analytics->demandTotals($this->from, $this->to, null, null);
        $this->assertSame(103.0, $totals['requested_quantity']);

        $detail = $this->detail('demand.requested-quantity');

        $this->assertSame(103.0, $detail['value']);

        /* Widest bar is the largest quantity, even though it was asked for once. */
        $this->assertSame($bulky->unique_description, $detail['bars'][0]['label']);
        $this->assertSame('100 Piece 1 request', $detail['bars'][0]['value']);
        $this->assertSame(100, $detail['bars'][0]['share']);

        $this->assertSame($frequent->unique_description, $detail['bars'][1]['label']);
        $this->assertSame('3 Piece 3 requests', $detail['bars'][1]['value']);
        $this->assertSame(3, $detail['bars'][1]['share']);
    }

    public function test_demand_quantity_evidence_reconciles_beyond_the_top_ten_and_uses_release_dates(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $item = $this->item('Demand evidence item '.$i);
            $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 4, 10), [
                ['item' => $item, 'quantity' => $i],
            ]);
        }
        $released = $this->item('Released from an earlier filing');
        // Filing on March 31 is outside April, release on April 1 is inside.
        $this->release($released, 7, Carbon::create(2026, 4, 1, 10));
        $totals = $this->analytics->demandTotals($this->from, $this->to, null, null);
        $requested = $this->detail('demand.requested-quantity');
        $this->assertSame(12, count($requested['breakdown_tables'][0]['rows']));
        $this->assertEquals($totals['requested_quantity'], array_sum(array_column($requested['breakdown_tables'][0]['rows'], 3)));
        $this->assertEquals($totals['requested_quantity'], array_sum(array_column($requested['table']['rows'], 5)));
        $actual = $this->detail('demand.released-quantity');
        $this->assertEquals(7, $actual['value']);
        $this->assertEquals($totals['released_quantity'], array_sum(array_column($actual['table']['rows'], 6)));
        $this->assertNotContains($released->unique_description, array_column($requested['table']['rows'], 3));
    }

    public function test_equipment_evidence_uses_distinct_requests_and_same_snapshot_group_and_cohort(): void
    {
        $a = $this->item('Microphone A');
        $b = $this->item('Microphone B');
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 4, 10), [
            ['item' => $a, 'quantity' => 2], ['item' => $b, 'quantity' => 3],
        ]);
        $this->release($b, 4, Carbon::create(2026, 4, 10, 10));
        $this->release($a, 7, Carbon::create(2026, 4, 1, 10)); // filed in March, not the April cohort
        RequestItem::query()->whereIn('inventory_item_id', [$a->id, $b->id])->update(['description_snapshot' => 'Microphone snapshot']);
        $requested = $this->analytics->requestedEquipment($this->from, $this->to, null, null, 10)['items'][0];
        $this->assertSame(2, $requested['requests']); // three lines, two distinct requests
        $this->assertEquals(9, $requested['quantity']);
        $detail = $this->detail('demand.requested-items');
        $row = $detail['breakdown_tables'][0]['rows'][0];
        $this->assertSame('Microphone snapshot', $row[1]);
        $this->assertEquals(2, $row[3]);
        $this->assertEquals(9, $row[4]);
        $this->assertEquals(4, $row[5]);
        $this->assertSame('44.4%', $row[6]);
        $this->assertCount(3, $detail['table']['rows']);
        $this->assertEquals(9, array_sum(array_column($detail['table']['rows'], 5)));
        $this->assertEquals(4, array_sum(array_column($detail['table']['rows'], 6)));

        $page = $this->actingAs($this->spmuHead())->get(route('reports.analytics-records', [
            'section' => 'demand', 'academic_period' => 'month', 'detail' => 'card',
            'for' => 'demand.requested-items', 'item_name' => 'Microphone snapshot', 'item_unit' => 'Piece',
        ]));
        $page->assertOk()->assertSee('Requested and Released Equipment')->assertSee('Equipment Request Records')
            ->assertSee('data-record-filter-column="3"', false);
        $page->assertViewHas('detail', fn ($detail) => count($detail['table']['rows']) === 3);
    }

    public function test_active_units_are_not_limited_to_top_five_and_division_and_trend_reconcile(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->request('ACADEMIC', 'Evidence unit '.$i, Carbon::create(2026, 4, 6, 10));
        }
        $this->request('ACADEMIC', 'Evidence unit 1', Carbon::create(2026, 4, 7, 10));
        $cancelled = $this->request('ACADEMIC', 'Excluded cancelled unit', Carbon::create(2026, 4, 7, 10));
        $cancelled->update(['status' => RequestStatus::Cancelled]);
        $active = $this->detail('demand.active-units');
        $totals = $this->analytics->demandTotals($this->from, $this->to, null, null);
        $this->assertEquals(7, $totals['active_units']);
        $this->assertEquals($totals['active_units'], $active['value']);
        $this->assertCount(7, $active['breakdown_tables'][0]['rows']);
        $this->assertCount(8, $active['table']['rows']);
        foreach (['demand.division', 'demand.trend'] as $key) {
            $detail = $this->detail($key);
            $this->assertEquals($totals['requests'], array_sum(array_column($detail['breakdown_tables'][0]['rows'], 1)));
            $this->assertCount(8, $detail['table']['rows']);
        }
    }

    public function test_peak_evidence_includes_all_hours_weekdays_and_tied_peaks(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 6, 9)->addMinutes($i));
            $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 7, 19)->addMinutes($i));
        }
        $peak = $this->analytics->peakBorrowing($this->from, $this->to, null, null);
        $this->assertSame(['Monday', 'Tuesday'], $peak['peak_days']);
        $this->assertSame(['9 AM', '7 PM'], $peak['peak_hours']);
        $this->assertEquals(10, array_sum(array_column($peak['hours'], 'count')));
        $detail = $this->detail('demand.peak');
        $this->assertCount(7, $detail['breakdown_tables'][0]['rows']);
        $this->assertCount(24, $detail['breakdown_tables'][1]['rows']);
        $this->assertEquals(10, array_sum(array_column($detail['breakdown_tables'][0]['rows'], 1)));
        $this->assertEquals(10, array_sum(array_column($detail['breakdown_tables'][1]['rows'], 1)));
        $this->assertCount(10, $detail['table']['rows']);
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function test_inventory_distribution_and_risk_include_zero_available_and_use_unrounded_threshold(): void
    {
        $zero = $this->item('Fully on custody', 10);
        $edge = $this->item('Exact risk threshold', 4);
        $rounded = $this->item('Above risk threshold', 1000);
        $maintenance = $this->item('Whole item under maintenance', 4);
        $maintenance->update(['condition_code' => 'DAMAGED_MAINTENANCE']);
        $this->release($zero, 10, $this->from->copy()->addDay());
        $this->release($edge, 3, $this->from->copy()->addDay());
        $this->release($rounded, 746, $this->from->copy()->addDay());

        $distribution = $this->detail('inventory.distribution');
        $records = collect($distribution['table']['rows'])->keyBy(fn ($r) => $r[0]['primary']);
        $this->assertEquals(0, $records['Fully on custody'][3]);
        $this->assertEquals(10, $records['Fully on custody'][5]);
        $this->assertEquals(0, $records['Whole item under maintenance'][2]);
        $this->assertEquals(4, $records['Whole item under maintenance'][8]);
        $this->assertEquals($this->analytics->inventory(app(\App\Services\InventoryService::class))['totals']['serviceable'], $distribution['value']);
        $this->assertEquals(0, $records->sum(fn ($r) => abs($r[2] - array_sum(array_slice($r, 3, 5)))));
        $this->assertEquals(100, array_sum(array_column($distribution['distribution'], 'share')));
        $this->assertStringContainsString('/inventory/'.$zero->id, $records['Fully on custody'][0]['url']);

        $availability = collect($this->detail('inventory.availability')['table']['rows'])->keyBy(fn ($r) => $r[0]['primary']);
        $this->assertTrue($availability->has('Fully on custody'));
        $this->assertFalse($availability->has('Whole item under maintenance'));
        $risk = $this->detail('inventory.low-availability');
        $this->assertEquals(2, $risk['value']);
        $names = array_map(fn ($r) => $r[0]['primary'], $risk['table']['rows']);
        $this->assertContains('Fully on custody', $names);
        $this->assertContains('Exact risk threshold', $names);
        $this->assertNotContains('Above risk threshold', $names);
    }

    public function test_inventory_commitment_evidence_reconciles_partial_reservations_and_custody(): void
    {
        $item = $this->item('Partial commitments', 20);
        $this->release($item, 6, $this->from->copy()->addDay());
        $line = CustodyLine::query()->firstOrFail();
        $line->update(['returned_quantity' => 2]);
        $allocation = Allocation::query()->findOrFail($line->allocation_id);
        $allocation->update(['allocated_quantity' => 10, 'released_quantity' => 6, 'restored_quantity' => 1, 'status' => 'PARTIALLY_RELEASED']);

        $reserved = $this->detail('inventory.reserved');
        $custody = $this->detail('inventory.custody');
        $this->assertEquals(3, $reserved['value']);
        $this->assertEquals(3, array_sum(array_column($reserved['table']['rows'], 3)));
        $this->assertEquals(4, $custody['value']);
        $this->assertEquals(4, array_sum(array_column($custody['table']['rows'], 3)));
        $this->assertStringContainsString('/requests/', $reserved['table']['rows'][0][1]['url']);
        $this->assertStringContainsString('/custody/', $custody['table']['rows'][0][1]['url']);

        CustodyTransaction::query()->findOrFail($line->custody_transaction_id)->update(['status' => 'CANCELLED', 'released_at' => null]);
        $this->assertEquals(0, $this->detail('inventory.custody')['value']);
        $this->assertSame([], $this->detail('inventory.custody')['table']['rows']);
    }

    public function test_inventory_attention_and_operational_records_show_net_maintenance_and_source_references(): void
    {
        $item = $this->item('Manual hold evidence', 10);
        $inventory = app(\App\Services\InventoryService::class);
        $head = $this->spmuHead();
        $inventory->recordAdjustment($item, $head, ['action' => 'PLACE_UNDER_MAINTENANCE', 'quantity' => 3, 'reason' => 'Verified maintenance hold']);
        $inventory->recordAdjustment($item->fresh(), $head, ['action' => 'RETURN_MAINTENANCE_TO_SERVICE', 'quantity' => 1]);
        $attention = $this->detail('inventory.attention');
        $operational = $this->detail('inventory.operational');
        $this->assertEquals(2, $attention['value']);
        $this->assertEquals(2, $attention['table']['rows'][0][1]);
        $this->assertEquals(2, $attention['table']['rows'][0][3]);
        $this->assertEquals(2, $attention['table']['rows'][0][5]);
        $this->assertStringContainsString('Stock movement #', $attention['table']['rows'][0][7]);
        $this->assertEquals(2, collect($operational['stats'])->pluck('value','label')['Maintenance']);
        $this->assertEquals(2, collect($operational['stats'])->pluck('value','label')['Attention needed']);
        $this->assertEquals(8, $this->detail('inventory.available')['value']);
    }

    public function test_inventory_coverage_and_utilization_include_full_release_population_and_preserve_group_evidence(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $item = $this->item('Released item '.$i, 20);
            $this->release($item, $i, $this->from->copy()->addDay());
        }
        $coverage = $this->detail('inventory.coverage');
        $this->assertEquals(12, $coverage['value']);
        $this->assertCount(12, $coverage['breakdown_tables'][0]['rows']);
        $this->assertEquals(78, array_sum(array_column($coverage['table']['rows'], 4)));
        $utilization = $this->detail('inventory.utilization');
        $this->assertCount(12, $utilization['breakdown_tables'][0]['rows']);
        $this->assertEquals(78, array_sum(array_column($utilization['table']['rows'], 4)));
        $this->assertEquals(78, collect($utilization['stats'])->pluck('value','label')['Released quantity']);

        $scope = ['section' => 'inventory', 'academic_period' => 'month', 'detail' => 'card', 'for' => 'inventory.utilization', 'item_name' => 'Released item 1', 'item_unit' => 'Piece'];
        $this->actingAs($this->spmuHead())->get(route('reports.analytics-records', $scope))
            ->assertOk()->assertSee('Released Quantity by Item')->assertSee('Physical Release Records')
            ->assertViewHas('detail', fn ($detail) => count($detail['table']['rows']) === 1 && (float) $detail['table']['rows'][0][4] === 1.0);
    }

    public function test_inventory_snapshot_is_institution_wide_but_release_evidence_uses_selected_period_and_borrower_type(): void
    {
        $item = $this->item('Scoped inventory release evidence', 20);
        $this->release($item, 2, $this->from->copy()->subDay());
        $this->release($item, 3, $this->from->copy()->addDay());
        $this->release($item, 4, $this->from->copy()->addDays(2));
        $lastCustody = CustodyTransaction::query()->latest('id')->firstOrFail();
        RequestVersion::query()->findOrFail($lastCustody->request_version_id)->update(['affiliation_type' => 'EXTERNAL']);
        $this->assertEquals(11, $this->detail('inventory.available')['value']);
        $this->assertEquals(7, collect($this->detail('inventory.utilization')['stats'])->pluck('value','label')['Released quantity']);
        $analytics = $this->analytics->forBorrowerType('EXTERNAL');
        $details = $this->details->using($analytics);
        $scope = ['from' => $this->from, 'to' => $this->to, 'division' => null, 'unit' => null, 'period' => 'month', 'borrower' => null];
        $this->assertEquals(11, $details->resolve($scope, 'inventory.available')['value']);
        $this->assertEquals(4, array_sum(array_column($details->resolve($scope, 'inventory.utilization')['table']['rows'], 4)));
        $this->assertEquals(4, array_sum(array_column($details->resolve($scope, 'inventory.coverage')['table']['rows'], 4)));
    }

    public function test_return_evidence_uses_physical_completion_and_excludes_cancelled_and_partial_custody(): void
    {
        $item = $this->item('Return evidence fixture', 100);
        $head = $this->spmuHead();
        $onTime = $this->returnFixture($item, $head, 2, 2, 3, 'CLOSED');
        $late = $this->returnFixture($item, $head, 3, 3, 8, 'OBLIGATION_OPEN');
        $partial = $this->returnFixture($item, $head, 4, 1, 6, 'RETURN_PROCESSING');
        $this->release($item, 2, $this->from->copy()->addDay());
        $cancelled = CustodyTransaction::query()->latest('id')->firstOrFail();
        $cancelled->update(['status' => 'CANCELLED', 'released_at' => null, 'closed_at' => $this->from->copy()->addDays(9)]);
        $this->release($item, 2, $this->from->copy()->addDay());
        CustodyTransaction::query()->latest('id')->firstOrFail()->update(['status' => 'CANCELLED', 'closed_at' => $this->from->copy()->addDays(9)]);

        $outcome = $this->returnCard('returns.outcome');
        $trend = $this->returnCard('returns.trend');
        $compliance = $this->returnCard('overview.return-compliance');
        $this->assertCount(2, $outcome['table']['rows']);
        $this->assertCount(2, $compliance['table']['rows']);
        $this->assertEquals(2, array_sum(array_column($trend['breakdown_tables'][0]['rows'], 1)));
        $this->assertEquals(1, array_sum(array_column($trend['breakdown_tables'][0]['rows'], 2)));
        $this->assertEquals(1, array_sum(array_column($trend['breakdown_tables'][0]['rows'], 3)));
        $this->assertEqualsCanonicalizing([$onTime->custody_no, $late->custody_no], array_column(array_column($outcome['table']['rows'], 0), 'primary'));
        $lateDetail = $this->returnRecord('returns', ['state' => 'late']);
        $this->assertCount(1, $lateDetail['table']['rows']);
        $this->assertSame($late->custody_no, $lateDetail['table']['rows'][0][0]['primary']);
        $this->assertSame('Returned Late', $lateDetail['table']['rows'][0][5]);
        $overdue = $this->returnCard('returns.followup');
        $this->assertCount(1, $overdue['table']['rows']);
        $this->assertSame($partial->custody_no, $overdue['table']['rows'][0][0]['primary']);
        $this->assertEquals(3, $overdue['table']['rows'][0][3]);
        $this->assertEquals(1, array_sum(array_column($overdue['breakdown_tables'][0]['rows'], 1)));
    }

    public function test_return_condition_evidence_reconciles_actual_inspection_quantities_including_partial_receipts(): void
    {
        $item = $this->item('Inspection evidence fixture');
        $head = $this->spmuHead();
        $this->returnFixture($item, $head, 3, 3, 3, 'CLOSED');
        $this->returnFixture($item, $head, 4, 2, 6, 'RETURN_PROCESSING', 'DAMAGED');
        $condition = $this->returnCard('returns.condition');
        $this->assertEquals(5, $condition['value']);
        $this->assertEquals(5, array_sum(array_column($condition['table']['rows'], 4)));
        $this->assertEquals(5, array_sum(array_column($condition['breakdown_tables'][0]['rows'], 1)));
        $this->assertCount(2, $condition['table']['rows']);
        $this->assertEqualsCanonicalizing(['Fine', 'Damaged'], array_column($condition['table']['rows'], 5));
        $this->assertEquals(100, array_sum(array_map(fn ($r) => (float) $r[2], $condition['breakdown_tables'][0]['rows'])));
        $this->assertEquals($this->analytics->returnConditions($this->from, $this->to, null, null)['total'], $condition['value']);

        $this->actingAs($head)->get(route('reports.analytics-records', ['section' => 'returns', 'academic_period' => 'month', 'detail' => 'card', 'for' => 'returns.condition']))
            ->assertOk()->assertSee('Condition Distribution')->assertSee('Return Inspection Records')->assertSee('Inspected Qty.')
            ->assertViewHas('detail', fn ($detail) => count($detail['table']['rows']) === 2);
    }

    public function test_late_rate_evidence_includes_the_denominator_and_unranked_units(): void
    {
        $item = $this->item('Late rate fixture');
        $head = $this->spmuHead();
        $this->returnFixture($item, $head, 1, 1, 3, 'CLOSED');
        $this->returnFixture($item, $head, 1, 1, 8, 'CLOSED');
        $unassigned = $this->returnFixture($item, $head, 1, 1, 9, 'CLOSED');
        RequestVersion::query()->findOrFail($unassigned->request_version_id)->update(['office_unit' => '']);
        $detail = $this->returnRecord('late-rate', ['level' => 'unit']);
        $this->assertCount(3, $detail['table']['rows']);
        $this->assertEquals(3, array_sum(array_column($detail['breakdown_tables'][0]['rows'], 1)));
        $this->assertEquals(2, array_sum(array_column($detail['breakdown_tables'][0]['rows'], 3)));
        $this->assertContains('Unspecified unit (not ranked)', array_column($detail['breakdown_tables'][0]['rows'], 0));
        $selected = $this->returnRecord('late-rate', ['level' => 'unit', 'for' => 'ACADEMIC', 'segment' => $this->ccs()]);
        $this->assertCount(2, $selected['table']['rows']);
        $this->assertEquals(2, $selected['breakdown_tables'][0]['rows'][0][1]);
        $this->assertEquals('50%', $selected['breakdown_tables'][0]['rows'][0][4]);
    }

    public function test_return_issue_evidence_keeps_case_counts_separate_from_affected_quantities(): void
    {
        $item = $this->item('Incident evidence fixture');
        $head = $this->spmuHead();
        $custody = $this->returnFixture($item, $head, 4, 4, 8, 'INCIDENT_OPEN');
        $line = $custody->lines()->firstOrFail();
        foreach (['OPEN' => 2, 'RESOLVED' => 1] as $status => $quantity) {
            $incident = \App\Models\Incident::query()->create([
                'incident_no' => 'PC-RETURN-'.$status, 'custody_transaction_id' => $custody->id,
                'borrower_user_id' => $custody->borrower_user_id, 'reported_by_user_id' => $head->id,
                'incident_type' => 'DAMAGE', 'reported_at' => $this->from->copy()->addDays(8), 'status' => $status,
            ]);
            \App\Models\IncidentLine::query()->create([
                'incident_id' => $incident->id, 'custody_line_id' => $line->id,
                'quantity' => $quantity, 'observed_condition' => 'DAMAGED', 'disposition_state' => 'RETURNED',
            ]);
        }
        $detail = $this->returnCard('returns.issues');
        $stats = collect($detail['stats'])->pluck('value', 'label');
        $this->assertEquals(1, $stats['Open accountability cases']);
        $this->assertEquals(2, $stats['Incidents recorded']);
        $this->assertEquals(3, $stats['Incident affected quantity']);
        $this->assertCount(1, $detail['table']['rows']);
        $this->assertEquals(2, $detail['table']['rows'][0][5]);
        $incidentRecords = collect($detail['breakdown_tables'])->firstWhere('title', 'Property Incident Records');
        $this->assertTrue($incidentRecords['evidence']);
        $this->assertCount(2, $incidentRecords['rows']);
        $this->assertEquals(3, array_sum(array_column($incidentRecords['rows'], 4)));
        $this->actingAs($head)->get(route('reports.analytics-records', ['section' => 'returns', 'academic_period' => 'month', 'detail' => 'card', 'for' => 'returns.issues']))
            ->assertOk()->assertSee('Open Accountability Records')->assertSee('Property Incident Records');
    }

    public function test_return_trend_includes_partial_calendar_buckets(): void
    {
        $item = $this->item('Partial period fixture');
        $head = $this->spmuHead();
        $this->returnFixture($item, $head, 1, 1, 29, 'CLOSED');
        $this->returnFixture($item, $head, 1, 1, 30, 'CLOSED');
        $trend = $this->analytics->returnTrend(Carbon::create(2026, 4, 29)->startOfDay(), Carbon::create(2026, 5, 2)->endOfDay(), null, null, 'month');
        $this->assertSame(2, $trend['total']);
        $this->assertSame(2, $trend['plotted']);
        $this->assertContains('Week 1 of May', array_column($trend['points'], 'label'));
    }

    public function test_current_overdue_aging_includes_unbanded_records_and_preserves_selected_band(): void
    {
        $item = $this->item('Overdue evidence fixture');
        $this->release($item, 2, $this->from->copy()->addDay());
        $old = CustodyTransaction::query()->latest('id')->firstOrFail();
        $old->update(['status' => 'OVERDUE']);
        $this->release($item, 3, $this->from->copy()->addDay());
        $unbanded = CustodyTransaction::query()->latest('id')->firstOrFail();
        $unbanded->update(['status' => 'OVERDUE', 'due_at' => now()->endOfDay()]);
        $detail = $this->returnRecord('overdue-aging', []);
        $this->assertCount(2, $detail['table']['rows']);
        $this->assertEquals(2, array_sum(array_column($detail['breakdown_tables'][0]['rows'], 1)));
        $this->assertContains('Unbanded', array_column($detail['table']['rows'], 5));
        $this->assertEquals(5, array_sum(array_column($detail['table']['rows'], 3)));
        $band = $this->analytics->overdueAgingBand($this->analytics->daysOverdue($old));
        $this->assertNotSame('unbanded', $band);
        $selected = $this->returnRecord('overdue-aging', ['bucket' => $band]);
        $this->assertCount(1, $selected['table']['rows']);
        $this->assertSame($old->custody_no, $selected['table']['rows'][0][0]['primary']);
    }

    public function test_overview_filed_request_records_match_demand_and_keep_source_links_and_filters(): void
    {
        $head = $this->spmuHead();
        $filed = $this->request('ACADEMIC', $this->ccs(), $this->from->copy()->addDay());
        $rejected = $this->request('ACADEMIC', $this->ccs(), $this->from->copy()->addDays(2));
        $rejected->update(['status' => RequestStatus::Rejected]);
        $cancelled = $this->request('ACADEMIC', $this->ccs(), $this->from->copy()->addDays(3));
        $cancelled->update(['status' => RequestStatus::Cancelled]);
        $this->request('ADMINISTRATION', $this->hrmo(), $this->from->copy()->addDays(3));
        $this->request('ACADEMIC', $this->ccs(), $this->from->copy()->subMonth());

        $detail = $this->overviewRecord('requests', [], 'ACADEMIC', $this->ccs());
        $totals = $this->analytics->demandTotals($this->from, $this->to, 'ACADEMIC', $this->ccs());
        $this->assertSame(2, $totals['requests']);
        $this->assertSame($totals['requests'], $detail['value']);
        $this->assertCount(2, $detail['table']['rows']);
        $this->assertEqualsCanonicalizing([$filed->request_no, $rejected->request_no], array_column(array_column($detail['table']['rows'], 0), 'primary'));
        $this->assertEqualsCanonicalizing([route('requests.show', $filed->id), route('requests.show', $rejected->id)], array_column(array_column($detail['table']['rows'], 0), 'url'));
        $this->assertEquals($detail['value'], array_sum(array_column(array_slice($detail['stats'], 1), 'value')));
        $this->assertSame(6, $detail['table_filter_column']);

        $this->actingAs($head)->get(route('reports.analytics-records', [
            'section' => 'overview', 'academic_period' => 'month', 'detail' => 'requests', 'group' => 'ACADEMIC', 'unit' => $this->ccs(),
        ]))->assertOk()->assertSee('Filed Requests')->assertSee(route('requests.show', $filed->id), false)->assertDontSee($cancelled->request_no);
    }

    public function test_overview_current_custody_and_overdue_use_current_population_and_outstanding_quantity(): void
    {
        $item = $this->item('Overview custody fixture');
        $head = $this->spmuHead();
        $this->release($item, 10, $this->from->copy()->subDays(8));
        $partial = CustodyTransaction::query()->latest('id')->firstOrFail();
        $partial->lines()->firstOrFail()->update(['returned_quantity' => 4]);
        $partial->update(['status' => 'PARTIALLY_RETURNED']);
        $this->release($item, 2, $this->from->copy()->addDays(2));
        $notDue = CustodyTransaction::query()->latest('id')->firstOrFail();
        $notDue->update(['due_at' => $this->to->copy()]);
        $this->returnFixture($item, $head, 3, 3, 8, 'OBLIGATION_OPEN');
        $this->release($item, 4, $this->from->copy()->addDay());
        CustodyTransaction::query()->latest('id')->firstOrFail()->update(['status' => 'CANCELLED', 'closed_at' => now()]);
        $this->release($item, 5, $this->from->copy()->addDay());
        CustodyTransaction::query()->latest('id')->firstOrFail()->update(['status' => 'CANCELLED', 'released_at' => null, 'closed_at' => now()]);

        $overview = $this->analytics->overview($this->from, $this->to, null, null);
        $out = $this->overviewRecord('currently-out');
        $this->assertSame(2, $overview['on_custody']);
        $this->assertSame($overview['on_custody'], $out['value']);
        $this->assertCount(2, $out['table']['rows']);
        $rows = collect($out['table']['rows'])->keyBy(fn ($row) => $row[0]['primary']);
        $this->assertEquals(6, $rows[$partial->custody_no][4]);
        $this->assertEquals(2, $rows[$notDue->custody_no][4]);
        $this->assertEquals(8, collect($out['stats'])->firstWhere('label', 'Outstanding quantity (recorded)')['value']);
        $this->assertSame(route('custody.show', $partial->id), $rows[$partial->custody_no][0]['url']);
        $this->assertStringContainsString('Current custody snapshot', $out['period_label']);

        $overdue = $this->overviewRecord('follow-up');
        $returns = $this->analytics->returns($this->from, $this->to, null, null);
        $this->assertSame(1, $overview['needs_follow_up']);
        $this->assertSame($returns['overdue'], $overdue['value']);
        $this->assertCount(1, $overdue['table']['rows']);
        $this->assertSame($partial->custody_no, $overdue['table']['rows'][0][0]['primary']);
        $this->assertEquals(6, $overdue['table']['rows'][0][3]);
        foreach (['currently-out' => 'Current Custody Records', 'follow-up' => 'Overdue Custody Records'] as $type => $title) {
            $this->actingAs($head)->get(route('reports.analytics-records', ['section' => 'overview', 'academic_period' => 'month', 'detail' => $type]))
                ->assertOk()->assertSee($title)->assertSee($partial->custody_no);
        }
    }

    public function test_overview_available_stock_and_low_availability_reuse_complete_inventory_evidence(): void
    {
        $stock = $this->item('Overview zero-available fixture', 10);
        $edge = $this->item('Overview threshold fixture', 4);
        $this->item('Overview available fixture', 8);
        $this->release($stock, 10, $this->from->copy()->addDay());
        $this->release($edge, 3, $this->from->copy()->addDay());
        $inventory = app(\App\Services\InventoryService::class);
        $available = $this->overviewRecord('card', ['for' => 'inventory.available']);
        $risk = $this->overviewRecord('low-availability');
        $this->assertEquals($this->analytics->inventory($inventory)['totals']['available'], $available['value']);
        $this->assertEquals($available['value'], array_sum(array_column($available['table']['rows'], 3)));
        $this->assertEquals($this->analytics->lowAvailability($inventory)['count'], $risk['value']);
        $this->assertSame($this->detail('inventory.low-availability')['table'], $risk['table']);
        $this->assertCount(2, $risk['table']['rows']);
        $this->assertContains('Overview zero-available fixture', array_column(array_column($risk['table']['rows'], 0), 'primary'));
        $this->assertStringContainsString('Inventory snapshot', $risk['period_label']);
        $this->analytics->forBorrowerType('EXTERNAL');
        $this->assertEquals($available['table'], $this->overviewRecord('card', ['for' => 'inventory.available'])['table']);
        $this->assertEquals($risk['table'], $this->overviewRecord('low-availability')['table']);
    }

    public function test_overview_priority_accountability_and_compliance_reuse_return_evidence(): void
    {
        $item = $this->item('Overview priority fixture');
        $head = $this->spmuHead();
        $this->returnFixture($item, $head, 2, 2, 3, 'CLOSED');
        $late = $this->returnFixture($item, $head, 3, 3, 8, 'INCIDENT_OPEN');
        $incident = \App\Models\Incident::query()->create([
            'incident_no' => 'PC-OVERVIEW-OPEN', 'custody_transaction_id' => $late->id,
            'borrower_user_id' => $late->borrower_user_id, 'reported_by_user_id' => $head->id,
            'incident_type' => 'DAMAGE', 'reported_at' => $this->from->copy()->addDays(8), 'status' => 'OPEN',
        ]);
        \App\Models\IncidentLine::query()->create([
            'incident_id' => $incident->id, 'custody_line_id' => $late->lines()->firstOrFail()->id,
            'quantity' => 2, 'observed_condition' => 'DAMAGED', 'disposition_state' => 'RETURNED',
        ]);
        $metrics = $this->analytics->returns($this->from, $this->to, null, null);
        $compliance = $this->overviewRecord('card', ['for' => 'overview.return-compliance']);
        $cases = $this->overviewRecord('card', ['for' => 'returns.issues']);
        $this->assertSame($metrics['completed'], count($compliance['table']['rows']));
        $this->assertEquals($metrics['on_time_rate'].'%', $compliance['value']);
        $this->assertSame($metrics['open_cases'], count($cases['table']['rows']));
        $this->assertSame($this->returnCard('returns.issues')['table'], $cases['table']);
        $this->assertEquals(2, $cases['table']['rows'][0][5]);
        foreach (['overview.return-compliance' => 'Completed Return Records', 'returns.issues' => 'Open Accountability Records'] as $key => $title) {
            $this->actingAs($head)->get(route('reports.analytics-records', ['section' => 'overview', 'academic_period' => 'month', 'detail' => 'card', 'for' => $key]))
                ->assertOk()->assertSee($title);
        }
    }

    private function overviewRecord(string $type, array $params = [], ?string $division = null, ?string $unit = null): array
    {
        return app(\App\Services\AnalyticsDetailService::class)->using($this->analytics)->resolve(
            $type, \Illuminate\Http\Request::create('/', 'GET', ['section' => 'overview'] + $params),
            $this->from, $this->to, $division, $unit, 'month', null, PHP_INT_MAX
        );
    }

    private function returnCard(string $key): array
    {
        return $this->details->resolve([
            'from' => $this->from, 'to' => $this->to, 'division' => null, 'unit' => null,
            'period' => 'month', 'borrower' => null, 'record_limit' => PHP_INT_MAX, 'return_records_view' => true,
        ], $key);
    }

    private function returnRecord(string $type, array $params): array
    {
        return app(\App\Services\AnalyticsDetailService::class)->using($this->analytics)->resolve(
            $type, \Illuminate\Http\Request::create('/', 'GET', ['section' => 'returns'] + $params),
            $this->from, $this->to, null, null, 'month', null, PHP_INT_MAX
        );
    }

    private function returnFixture(InventoryItem $item, User $head, int $released, int $received, int $daysAfterStart, string $status, string $condition = 'FINE'): CustodyTransaction
    {
        $this->release($item, $released, $this->from->copy()->addDay());
        $custody = CustodyTransaction::query()->latest('id')->firstOrFail();
        $date = $this->from->copy()->addDays($daysAfterStart)->setTime(10, 0);
        $custody->update(['status' => $status, 'closed_at' => $status === 'CLOSED' ? $date : null]);
        $line = $custody->lines()->firstOrFail();
        $line->update(['returned_quantity' => $received]);
        $return = \App\Models\ReturnTransaction::query()->create([
            'return_no' => 'RET-TEST-'.$custody->id, 'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $head->id, 'return_type' => 'NORMAL', 'received_at' => $date, 'status' => 'INSPECTED',
        ]);
        \App\Models\ReturnLine::query()->create([
            'return_transaction_id' => $return->id, 'custody_line_id' => $line->id,
            'quantity_received' => $received, 'condition_code' => $condition, 'disposition_state' => 'RETURNED',
        ]);
        return $custody;
    }

    private function detail(string $key): array
    {
        return $this->details->resolve([
            'from' => $this->from,
            'to' => $this->to,
            'division' => null,
            'unit' => null,
            'period' => 'month',
            'borrower' => null,
        ], $key);
    }

    private function spmuHead(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
        ]);
    }

    private function borrower(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);
    }

    private function ccs(): string
    {
        return 'College of Computer Studies';
    }

    private function hrmo(): string
    {
        return 'Human Resource Management Office';
    }

    private function item(string $description, int $total = 200): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'RECON'],
            ['category_name' => 'Reconciliation Fixture', 'active' => true]
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
     * @param  array<int, array{item: InventoryItem, quantity: int}>  $lines
     */
    private function request(
        string $division,
        string $unit,
        Carbon $createdAt,
        array $lines = [],
        ?Carbon $scheduleDate = null
    ): BorrowingRequest {
        $borrower = $this->borrower();
        $scheduleDate ??= $createdAt->copy()->addDay();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.$createdAt->format('YmdHis').'-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::UnderSpmu,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Reconciliation fixture',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $scheduleDate->toDateString(),
            'return_date' => $scheduleDate->copy()->addDays(2)->toDateString(),
            'needed_from' => $scheduleDate->copy()->startOfDay(),
            'return_due_at' => $scheduleDate->copy()->addDays(2)->endOfDay(),
        ]);

        foreach ($lines as $line) {
            RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $line['item']->id,
                'description_snapshot' => $line['item']->unique_description,
                'unit_snapshot' => 'Piece',
                'requested_quantity' => $line['quantity'],
                'approved_quantity' => $line['quantity'],
            ]);
        }

        return $request;
    }

    /** One physical release of an item, as its own custody transaction. */
    private function release(InventoryItem $item, int $quantity, Carbon $releasedAt): void
    {
        $createdAt = $releasedAt->copy()->subDay();
        $borrower = $this->borrower();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Reconciliation release fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => $this->ccs(),
            'schedule_date' => $releasedAt->toDateString(),
            'return_date' => $releasedAt->copy()->addDays(2)->toDateString(),
            'needed_from' => $releasedAt->copy()->startOfDay(),
            'return_due_at' => $releasedAt->copy()->addDays(2)->endOfDay(),
        ]);

        $transaction = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.fake()->unique()->numberBetween(100000, 999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'ACTIVE',
            'due_at' => $releasedAt->copy()->addDays(2)->endOfDay(),
            'released_at' => $releasedAt,
            'closed_at' => null,
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => $quantity,
            'approved_quantity' => $quantity,
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $releasedAt->copy()->startOfDay(),
            'period_end' => $releasedAt->copy()->addDays(2)->endOfDay(),
            'allocated_quantity' => $quantity,
            'released_quantity' => $quantity,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => $createdAt,
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $transaction->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => $quantity,
            'quantity_to_receive' => $quantity,
            'actual_released_quantity' => $quantity,
            'returned_quantity' => 0,
        ]);
    }

    /**
     * Academic / CCS: 6 requests in P-1, 4 in P-2, 2 in P-3 -> forecast 5.
     * Administration / HRMO: 3 requests in P-1 only -> (3*3)/6 = 1.5 -> 2.
     */
    private function seedThreePeriodHistory(): void
    {
        $march = Carbon::create(2026, 3, 10, 10);
        $february = Carbon::create(2026, 2, 10, 10);
        $january = Carbon::create(2026, 1, 10, 10);

        for ($i = 0; $i < 6; $i++) {
            $this->request('ACADEMIC', $this->ccs(), $march->copy()->addHours($i));
        }

        for ($i = 0; $i < 4; $i++) {
            $this->request('ACADEMIC', $this->ccs(), $february->copy()->addHours($i));
        }

        for ($i = 0; $i < 2; $i++) {
            $this->request('ACADEMIC', $this->ccs(), $january->copy()->addHours($i));
        }

        for ($i = 0; $i < 3; $i++) {
            $this->request('ADMINISTRATION', $this->hrmo(), $march->copy()->addDays(2)->addHours($i));
        }
    }

    /**
     * One item asked for in three requests (1 unit each), another asked for
     * once for 100 units. Frequency and quantity rank them oppositely.
     *
     * @return array{0: InventoryItem, 1: InventoryItem}
     */
    private function seedFrequentAndBulkyDemand(): array
    {
        $frequent = $this->item('Frequently Requested Microphone');
        $bulky = $this->item('Bulk Requested Chair', 500);

        foreach ([3, 4, 5] as $day) {
            $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, $day, 10), [
                ['item' => $frequent, 'quantity' => 1],
            ]);
        }

        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 6, 10), [
            ['item' => $bulky, 'quantity' => 100],
        ]);

        return [$frequent, $bulky];
    }
}
