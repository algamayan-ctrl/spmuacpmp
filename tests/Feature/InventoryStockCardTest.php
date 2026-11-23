<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\InventoryStockCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryStockCardTest extends TestCase
{
    use RefreshDatabase;

    private function item(int $quantity = 100): InventoryItem
    {
        $category = InventoryCategory::create(['category_code' => Str::random(8), 'category_name' => 'Furniture']);
        $unit = UnitOfMeasure::firstOrCreate(['unit_code' => 'PC'], ['unit_name' => 'Piece', 'decimal_scale' => 0]);

        return InventoryItem::create([
            'category_id' => $category->id, 'unit_id' => $unit->id,
            'unique_description' => 'Test Chair '.Str::random(6), 'total_quantity' => $quantity,
            'condition_code' => 'SERVICEABLE', 'borrowable' => true, 'active' => true,
        ]);
    }

    private function entry(InventoryItem $item, string $type, string $from, string $to, int $quantity, ?object $source = null): int
    {
        $id = DB::table('inventory_transactions')->insertGetId([
            'transaction_type' => $type, 'source_type' => $source ? $source::class : InventoryItem::class,
            'source_id' => $source?->id ?? $item->id, 'correlation_id' => (string) Str::uuid(),
            'occurred_at' => '2026-10-28 08:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_transaction_lines')->insert([
            'inventory_transaction_id' => $id, 'inventory_item_id' => $item->id,
            'from_state' => $from, 'to_state' => $to, 'quantity' => $quantity,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function officer(): User
    {
        return User::factory()->create(['access_classification' => AccessClassification::SpmuOfficer]);
    }

    public function test_running_balances_count_actual_issues_and_receipts_without_deducting_reservations(): void
    {
        $item = $this->item();
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 100);
        $this->entry($item, 'SPMU_APPROVAL_RESERVATION', 'AVAILABLE', 'ALLOCATED', 20);
        $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 20);
        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'AVAILABLE', 20);
        $this->entry($item, 'ALLOCATION_RESTORATION', 'ALLOCATED', 'AVAILABLE', 5);
        $before = DB::table('inventory_transaction_lines')->count();

        $card = app(InventoryStockCardService::class)->forItem($item, ['borrowed' => 0]);

        $this->assertSame([100.0, 80.0, 100.0], $card['rows']->pluck('balance')->all());
        $this->assertSame([100.0, null, 20.0], $card['rows']->pluck('receipt')->all());
        $this->assertSame([null, 20.0, null], $card['rows']->pluck('issue')->all());
        $this->assertNull($card['warning']);
        $this->assertSame($before, DB::table('inventory_transaction_lines')->count());
        $this->assertEquals(100, $item->fresh()->total_quantity);
    }

    public function test_laundry_and_repair_transfers_do_not_double_count_returned_stock(): void
    {
        $item = $this->item(10);
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 10);
        $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 4);
        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'LAUNDRY', 4);
        $this->entry($item, 'LAUNDRY_COMPLETION', 'LAUNDRY', 'AVAILABLE', 4);
        $this->entry($item, 'INVENTORY_CONDITION_HOLD', 'AVAILABLE', 'DAMAGED_MAINTENANCE', 2);
        $this->entry($item, 'INVENTORY_CONDITION_RESTORATION', 'DAMAGED_MAINTENANCE', 'AVAILABLE', 2);

        $card = app(InventoryStockCardService::class)->forItem($item, []);

        $this->assertSame([10.0, 6.0, 10.0], $card['rows']->pluck('balance')->all());
        $this->assertSame('Returned — for laundry', $card['rows']->last()['remarks']);
        $this->assertNull($card['warning']);
    }

    public function test_missing_items_are_not_reported_as_receipts_and_recovery_adds_stock_once(): void
    {
        $item = $this->item(10);
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 10);
        $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 4);
        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'AVAILABLE', 3);
        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'LOST', 1);
        $service = app(InventoryStockCardService::class);
        $card = $service->forItem($item, ['lost' => 1]);
        $this->assertSame([10.0, 6.0, 9.0], $card['rows']->pluck('balance')->all());
        $this->assertNull($card['warning']);

        $this->entry($item, 'INVENTORY_INCIDENT_RESTORATION', 'LOST', 'AVAILABLE', 1);
        $card = $service->forItem($item, ['lost' => 0]);
        $this->assertSame(10.0, $card['rows']->last()['balance']);
        $this->assertSame(1.0, $card['rows']->last()['receipt']);
        $this->assertNull($card['warning']);
    }

    public function test_legacy_starting_quantity_is_used_without_inventing_an_opening_row(): void
    {
        $item = $this->item(10);
        $this->entry($item, 'INVENTORY_STOCK_ADDITION', 'OUTSIDE_STOCK', 'AVAILABLE', 4);
        $card = app(InventoryStockCardService::class)->forItem($item, []);

        $this->assertCount(1, $card['rows']);
        $this->assertSame(4.0, $card['rows']->first()['receipt']);
        $this->assertSame('2026-10-28 08:00:00', $card['rows']->first()['date']);
        $this->assertSame(10.0, $card['rows']->last()['balance']);
        $this->assertDatabaseCount('inventory_transaction_lines', 1);
    }

    public function test_undated_starting_quantity_is_hidden_on_screen_and_print_while_real_balances_are_retained(): void
    {
        $item = $this->item(1020);
        $service = app(InventoryStockCardService::class);
        $emptyCard = $service->forItem($item, []);
        $this->assertCount(0, $emptyCard['rows']);
        $this->assertSame(1020.0, $emptyCard['current_balance']);
        $this->assertDatabaseCount('inventory_transaction_lines', 0);

        $this->actingAs($this->officer())->get(route('inventory.stock-card.print', $item))
            ->assertOk()->assertSeeText('No stock entries have been recorded for this item.');

        $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 100);
        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'AVAILABLE', 100);
        $card = $service->forItem($item, []);
        $this->assertSame([920.0, 1020.0], $card['rows']->pluck('balance')->all());
        $this->assertSame(['ISSUE', 'RECEIPT'], $card['rows']->pluck('kind')->all());
        $this->assertNull($card['warning']);

        foreach (['inventory.show', 'inventory.stock-card.print'] as $route) {
            $response = $this->get(route($route, ['inventory' => $item->id, 'tab' => 'stock-card']))
                ->assertOk()->assertDontSeeText('Balance brought forward')->assertDontSeeText('Existing inventory record');
            $this->assertSame(2, substr_count($response->getContent(), 'data-stock-card-row'));
            $response->assertSee('stock-balance">920</td>', false)
                ->assertSee('stock-balance">1,020</td>', false);
        }
        $this->assertDatabaseCount('inventory_transaction_lines', 2);
        $this->assertEquals(1020, $item->fresh()->total_quantity);
    }

    public function test_unreconciled_balances_remain_blank_instead_of_creating_a_balancing_entry(): void
    {
        $item = $this->item(9);
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 10);
        $card = app(InventoryStockCardService::class)->forItem($item, []);

        $this->assertNotNull($card['warning']);
        $this->assertNull($card['rows']->first()['balance']);
        $this->assertNull($card['current_balance']);
        $this->assertDatabaseCount('inventory_transaction_lines', 1);
    }

    public function test_stock_retirement_reduces_the_balance_and_issue_quantity(): void
    {
        $item = $this->item(8);
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 10);
        $this->entry($item, 'INVENTORY_CONDITION_RETIREMENT', 'DAMAGED_MAINTENANCE', 'RETIRED', 2);
        $card = app(InventoryStockCardService::class)->forItem($item, []);

        $this->assertSame(8.0, $card['rows']->last()['balance']);
        $this->assertSame(2.0, $card['rows']->last()['issue']);
        $this->assertNull($card['warning']);
    }

    public function test_print_includes_all_matching_rows_beyond_the_page_and_legacy_100_row_limit(): void
    {
        $item = $this->item(10);
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 10);
        for ($index = 0; $index < 52; $index++) {
            $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 1);
            $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'AVAILABLE', 1);
        }
        $this->actingAs($this->officer());
        $screen = $this->get(route('inventory.show', ['inventory' => $item->id, 'tab' => 'stock-card']))
            ->assertOk()->assertSeeText('Print Stock Card')->assertSeeText('Read-only inventory ledger')
            ->assertDontSeeText('@else')->assertDontSeeText('No matching entries');
        $this->assertSame(7, substr_count($screen->getContent(), 'data-stock-card-row'));
        $copy = $this->get(route('inventory.stock-card.print', ['inventory' => $item->id, 'stock_page' => 2]))
            ->assertOk()->assertSeeText('all 105 matching entries')->assertDontSeeText('Prepared by:')->assertDontSeeText('Checked by:');
        $this->assertSame(105, substr_count($copy->getContent(), 'data-stock-card-row'));
        $this->assertStringNotContainsString('data:image', $copy->getContent());

        $filtered = $this->get(route('inventory.stock-card.print', ['inventory' => $item->id, 'stock_movement' => 'ISSUE']))
            ->assertOk()->assertSeeText('all 52 matching entries');
        $this->assertSame(52, substr_count($filtered->getContent(), 'data-stock-card-row'));
        $this->assertStringContainsString('stock-balance">9</td>', $filtered->getContent());
        $this->assertDatabaseCount('inventory_transaction_lines', 105);
    }

    public function test_office_and_reference_come_from_the_related_borrowing_record(): void
    {
        $item = $this->item(10);
        $office = OrganizationalUnit::create(['unit_code' => 'STOCK-TEST', 'unit_name' => 'College of Computer Studies', 'unit_type' => 'COLLEGE', 'active' => true]);
        $borrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly, 'organizational_unit_id' => $office->id]);
        $request = BorrowingRequest::create(['request_no' => 'BR-STOCK-TEST', 'borrower_user_id' => $borrower->id, 'accountable_unit_id' => $office->id, 'status' => RequestStatus::ApprovedReadyForRelease]);
        $version = RequestVersion::create([
            'request_id' => $request->id, 'version_no' => 1, 'purpose_event' => 'Stock card test',
            'location' => 'Campus', 'division_code' => 'ACADEMIC', 'office_unit' => $office->unit_name,
            'schedule_date' => now()->toDateString(), 'return_date' => now()->toDateString(),
            'needed_from' => now(), 'return_due_at' => now()->addDay(),
        ]);
        $custody = CustodyTransaction::create(['custody_no' => 'CUS-STOCK-TEST', 'request_id' => $request->id, 'request_version_id' => $version->id, 'borrower_user_id' => $borrower->id, 'status' => 'OBLIGATION_OPEN', 'due_at' => now()->addDay()]);
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 10);
        $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 2, $custody);
        $card = app(InventoryStockCardService::class)->forItem($item, ['borrowed' => 2]);

        $this->assertSame('College of Computer Studies', $card['rows']->last()['office']);
        $this->assertSame('CUS-STOCK-TEST', $card['rows']->last()['reference']);
        $this->assertSame(8.0, $card['rows']->last()['balance']);
    }

    public function test_admin_ledger_is_retained_and_print_access_is_restricted_to_action_officers(): void
    {
        $item = $this->item();
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $this->actingAs($head)->get(route('inventory.show', $item))->assertOk()
            ->assertSeeText('Read-only inventory ledger')->assertDontSeeText('Print Stock Card');
        $this->get(route('inventory.stock-card.print', $item))->assertForbidden();
        $borrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly]);
        $this->actingAs($borrower)->get(route('inventory.stock-card.print', $item))->assertForbidden();
        $this->actingAs($this->officer())->get(route('inventory.stock-card.print', $item))->assertOk();
    }

    public function test_filters_validate_inputs_and_keep_full_sequence_balances(): void
    {
        $item = $this->item(10);
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 10);
        $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 2);
        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'AVAILABLE', 2);
        $this->actingAs($this->officer());
        $response = $this->get(route('inventory.show', ['inventory' => $item->id, 'stock_q' => 'Released for borrowing', 'tab' => 'stock-card']))
            ->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'data-stock-card-row'));
        $response->assertSee('stock-balance">8</td>', false);
        $this->getJson(route('inventory.stock-card.print', ['inventory' => $item->id, 'stock_movement' => 'INVALID']))->assertUnprocessable();
        $this->getJson(route('inventory.stock-card.print', ['inventory' => $item->id, 'stock_per_page' => 999]))->assertUnprocessable();
    }

    public function test_reference_fields_stay_blank_instead_of_fabricating_official_codes_or_consumption(): void
    {
        $item = $this->item(10);
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 10);
        $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 2);
        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'AVAILABLE', 2);
        $this->actingAs($this->officer());

        foreach (['inventory.show', 'inventory.stock-card.print'] as $route) {
            $response = $this->get(route($route, ['inventory' => $item->id, 'tab' => 'stock-card']))
                ->assertOk()->assertDontSeeText('No. of Days to consume')->assertSeeText('Receipt Qty.')->assertSeeText('Issue Qty.')->assertSeeText('Issued Office')->assertSeeText('Balance Qty.')->assertSeeText('10-28-2026');
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new \DOMXPath($document);
            foreach (['fund-cluster', 'stock-number', 'reorder-point'] as $field) {
                $value = $xpath->query('//*[@data-stock-field="'.$field.'"]');
                $this->assertSame(1, $value->length);
                $this->assertSame('', trim($value->item(0)->textContent));
            }
            $consumptionCells = $xpath->query('//td[@class="stock-consumption"]');
            $this->assertSame(0, $consumptionCells->length);
            foreach ($consumptionCells as $cell) {
                $this->assertSame('', trim($cell->textContent));
            }
        }
        $this->assertDatabaseCount('inventory_transaction_lines', 3);
    }
}
