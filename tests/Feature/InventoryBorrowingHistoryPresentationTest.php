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
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryBorrowingHistoryPresentationTest extends TestCase
{
    use RefreshDatabase;

    private InventoryItem $item;
    private User $officer;
    private User $borrower;
    private OrganizationalUnit $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00'));
        $category = InventoryCategory::create(['category_code' => 'HIST', 'category_name' => 'History Test']);
        $unit = UnitOfMeasure::create(['unit_code' => 'HIST-PC', 'unit_name' => 'Piece', 'decimal_scale' => 0]);
        $this->item = InventoryItem::create(['category_id' => $category->id, 'unit_id' => $unit->id,
            'unique_description' => 'History Chairs', 'total_quantity' => 1000, 'condition_code' => 'SERVICEABLE', 'borrowable' => true, 'active' => true]);
        $this->office = OrganizationalUnit::create(['unit_code' => 'HIST-OFFICE', 'unit_name' => 'History Test Office', 'unit_type' => 'OFFICE', 'active' => true]);
        $this->officer = User::factory()->create(['access_classification' => AccessClassification::SpmuOfficer]);
        $this->borrower = User::factory()->create(['full_name' => 'History Borrower', 'access_classification' => AccessClassification::BorrowerOnly, 'organizational_unit_id' => $this->office->id]);
    }

    private function loan(int $number, ?string $released = '2026-10-20 09:00:00', string $due = '2026-10-25 17:00:00', ?string $returned = '2026-10-24 10:00:00'): CustodyTransaction
    {
        $request = BorrowingRequest::create(['request_no' => 'BR-HISTORY-'.$number,
            'borrower_user_id' => $this->borrower->id, 'accountable_unit_id' => $this->office->id,
            'current_version_no' => 1, 'status' => RequestStatus::ApprovedReadyForRelease]);
        $version = RequestVersion::create(['request_id' => $request->id, 'version_no' => 1,
            'purpose_event' => 'Training Session', 'location' => 'Training Hall', 'division_code' => 'ACADEMIC',
            'office_unit' => $this->office->unit_name, 'schedule_date' => '2026-10-20', 'return_date' => '2026-10-25',
            'needed_from' => '2026-10-20 08:00:00', 'return_due_at' => $due]);
        $requestItem = RequestItem::create(['request_version_id' => $version->id, 'inventory_item_id' => $this->item->id,
            'description_snapshot' => 'History Chairs', 'unit_snapshot' => 'Piece', 'requested_quantity' => 5, 'approved_quantity' => 5]);
        $allocation = Allocation::create(['request_item_id' => $requestItem->id, 'period_start' => '2026-10-20 08:00:00',
            'period_end' => $due, 'allocated_quantity' => 5, 'released_quantity' => $released ? 5 : 0,
            'restored_quantity' => 0, 'status' => $released ? 'RELEASED' : 'ACTIVE', 'allocated_at' => '2026-10-19 09:00:00']);
        $custody = CustodyTransaction::create(['custody_no' => 'CUS-HISTORY-'.$number, 'request_id' => $request->id,
            'request_version_id' => $version->id, 'borrower_user_id' => $this->borrower->id,
            'status' => $released ? ($returned ? 'CLOSED' : 'OBLIGATION_OPEN') : 'PREPARING_RELEASE',
            'released_at' => $released, 'due_at' => $due, 'closed_at' => $released ? $returned : null]);
        $line = CustodyLine::create(['custody_transaction_id' => $custody->id, 'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id, 'approved_quantity' => 5, 'quantity_to_receive' => 5,
            'actual_released_quantity' => $released ? 5 : 0, 'returned_quantity' => $released && $returned ? 5 : 0]);
        if ($released && $returned) {
            $receipt = ReturnTransaction::create(['return_no' => 'RET-HISTORY-'.$number, 'custody_transaction_id' => $custody->id,
                'received_by_user_id' => $this->officer->id, 'return_type' => 'FULL', 'received_at' => $returned, 'status' => 'CONFIRMED']);
            ReturnLine::create(['return_transaction_id' => $receipt->id, 'custody_line_id' => $line->id,
                'quantity_received' => 5, 'condition_code' => 'FINE', 'disposition_state' => 'AVAILABLE']);
        }

        return $custody;
    }

    private function url(array $filters = []): string
    {
        return route('inventory.show', array_merge(['inventory' => $this->item->id, 'tab' => 'borrowing-history'], $filters));
    }

    public function test_admin_history_paginates_without_reducing_the_filtered_summary(): void
    {
        for ($number = 1; $number <= 12; $number++) $this->loan($number);
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $response = $this->actingAs($head)->get($this->url(['history_search' => 'History Borrower']))->assertOk();
        $page = $response->viewData('saoBorrowingHistory')['page'];
        $this->assertSame(12, $page->total());
        $this->assertCount(7, $page->items());
        $this->assertSame(12, $response->viewData('historySummary')['records']);
        $this->assertSame(60.0, $response->viewData('historySummary')['issued']);
        $second = $this->get($page->nextPageUrl())->assertOk();
        $this->assertCount(5, $second->viewData('saoBorrowingHistory')['page']->items());
        $this->assertSame(12, $second->viewData('historySummary')['records']);
        $second->assertSee('aria-label="Borrowing history pages"', false);
    }

    public function test_both_staff_history_selects_show_the_supported_page_sizes_and_selected_default(): void
    {
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        foreach ([$head, $this->officer] as $actor) {
            $response = $this->actingAs($actor)->get($this->url())->assertOk();
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $options = $xpath->query('//select[@name="history_per_page"]/option');
            $this->assertSame(['7', '15', '30', '50'], array_map(fn ($option) => $option->getAttribute('value'), iterator_to_array($options)));
            $this->assertSame(['7 entries', '15 entries', '30 entries', '50 entries'], array_map(fn ($option) => trim($option->textContent), iterator_to_array($options)));
            $this->assertSame('7', $xpath->query('//select[@name="history_per_page"]/option[@selected]')->item(0)?->getAttribute('value'));
        }
    }

    public function test_officer_history_uses_the_shared_records_filters_and_summary(): void
    {
        $custody = $this->loan(1);
        $this->loan(2, released: null, returned: null);
        $response = $this->actingAs($this->officer)->get($this->url())->assertOk()
            ->assertSeeText('History Borrower')->assertSeeText('History Test Office')->assertSeeText('Outstanding')
            ->assertSeeText('View Custody')->assertSeeText('Returned On Time')
            ->assertSeeText('Apply Filter')->assertSeeText('Reset')
            ->assertSeeText('Includes records whose actual custody overlaps')
            ->assertSee('class="inventory-history-summary"', false);
        $history = $response->viewData('saoBorrowingHistory')['page'];
        $this->assertSame(1, $history->total());
        $this->assertSame($custody->id, $history->first()['custody']->id);
        $this->assertSame(5.0, $history->first()['issued_quantity']);
        $this->assertSame(5.0, $history->first()['returned_quantity']);
        $this->assertEquals(0, $history->first()['outstanding_quantity']);
        $this->assertStringContainsString(route('custody.show', $custody), $response->getContent());
        $this->assertDatabaseCount('custody_transactions', 2);
        $this->assertEquals(1000, $this->item->fresh()->total_quantity);
    }

    public function test_history_filters_apply_before_pagination_and_links_keep_the_selected_filters(): void
    {
        for ($number = 1; $number <= 12; $number++) $this->loan($number);
        $this->loan(13, due: '2026-10-30 17:00:00', returned: null);
        $this->actingAs($this->officer);
        $filters = ['history_status' => 'RETURNED_ON_TIME', 'history_search' => 'History Borrower',
            'history_from' => '2026-10-01', 'history_to' => '2026-10-31'];
        $first = $this->get($this->url($filters))->assertOk()->viewData('saoBorrowingHistory')['page'];
        $this->assertSame(12, $first->total());
        $this->assertCount(7, $first->items());
        $second = $this->get($first->nextPageUrl())->assertOk()->viewData('saoBorrowingHistory')['page'];
        $this->assertSame(12, $second->total());
        $this->assertCount(5, $second->items());
        $this->assertStringContainsString('history_status=RETURNED_ON_TIME', $first->nextPageUrl());
        $this->assertStringContainsString('tab=borrowing-history', $first->nextPageUrl());
        $this->assertSame([], array_intersect($first->pluck('custody.id')->all(), $second->pluck('custody.id')->all()));
        $larger = $this->get($this->url(array_merge($filters, ['history_per_page' => 30])))->assertOk()->viewData('saoBorrowingHistory')['page'];
        $this->assertCount(12, $larger->items());
    }

    public function test_late_overdue_and_outstanding_statuses_keep_their_existing_meaning(): void
    {
        $this->loan(1, returned: '2026-10-27 10:00:00');
        $this->loan(2, returned: null);
        $this->loan(3, due: '2026-10-30 17:00:00', returned: null);
        $this->actingAs($this->officer);
        foreach (['RETURNED_LATE', 'OVERDUE', 'ON_CUSTODY'] as $status) {
            $response = $this->get($this->url(['history_status' => $status]))->assertOk();
            $rows = $response->viewData('saoBorrowingHistory')['page'];
            $this->assertSame(1, $rows->total());
            $this->assertSame($status, $rows->first()['item_status']);
        }
        $this->get($this->url(['history_status' => 'ON_CUSTODY']))->assertSeeText('On Custody');
        $stillOut = $this->get($this->url(['history_from' => '2026-11-01', 'history_to' => '2026-11-30']))
            ->assertOk()->viewData('saoBorrowingHistory')['page'];
        $this->assertSame(2, $stillOut->total());
        $this->get($this->url(['history_from' => '2026-10-01', 'history_to' => '2026-10-10']))
            ->assertOk()->assertSeeText('No actual borrowing record found for this period.');
    }

    public function test_head_layout_is_retained_and_invalid_page_sizes_are_rejected_for_sao(): void
    {
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $this->actingAs($head)->get($this->url())->assertOk()->assertSeeText('Apply Filter')->assertSeeText('Reset')
            ->assertDontSee('id="sao-borrowing-history-results"', false);
        $this->actingAs($this->officer)->getJson($this->url(['history_per_page' => 999]))->assertUnprocessable();
    }
}
