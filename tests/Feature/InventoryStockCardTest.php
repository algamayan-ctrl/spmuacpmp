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
use App\Models\ReturnTransaction;
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

    private function entry(InventoryItem $item, string $type, string $from, string $to, int $quantity, ?object $source = null, string $occurredAt = '2026-10-28 08:00:00'): int
    {
        $id = DB::table('inventory_transactions')->insertGetId([
            'transaction_type' => $type, 'source_type' => $source ? $source::class : InventoryItem::class,
            'source_id' => $source?->id ?? $item->id, 'correlation_id' => (string) Str::uuid(),
            'occurred_at' => $occurredAt, 'created_at' => now(), 'updated_at' => now(),
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
            ->assertOk()->assertSee('aria-label="Print Stock Card"', false)->assertSeeText('Generate')->assertSeeText('Read-only inventory ledger')
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

    private function custodyForStockCard(string $suffix, string $officeName): CustodyTransaction
    {
        $office = OrganizationalUnit::create(['unit_code' => 'STOCK-'.$suffix, 'unit_name' => $officeName, 'unit_type' => 'COLLEGE', 'active' => true]);
        $borrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly, 'organizational_unit_id' => $office->id]);
        $request = BorrowingRequest::create(['request_no' => 'BR-STOCK-'.$suffix, 'borrower_user_id' => $borrower->id, 'accountable_unit_id' => $office->id, 'status' => RequestStatus::ApprovedReadyForRelease]);
        $version = RequestVersion::create([
            'request_id' => $request->id, 'version_no' => 1, 'purpose_event' => 'Stock card test',
            'location' => 'Campus', 'division_code' => 'ACADEMIC', 'office_unit' => $officeName,
            'schedule_date' => now()->toDateString(), 'return_date' => now()->toDateString(),
            'needed_from' => now(), 'return_due_at' => now()->addDay(),
        ]);

        return CustodyTransaction::create(['custody_no' => 'CUS-STOCK-'.$suffix, 'request_id' => $request->id, 'request_version_id' => $version->id, 'borrower_user_id' => $borrower->id, 'status' => 'OBLIGATION_OPEN', 'due_at' => now()->addDay()]);
    }

    private function returnForStockCard(CustodyTransaction $custody, string $suffix, User $officer): ReturnTransaction
    {
        return ReturnTransaction::create([
            'return_no' => 'RET-STOCK-'.$suffix, 'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $officer->id, 'received_at' => now(), 'status' => 'INSPECTED',
        ]);
    }

    public function test_linked_returns_consolidate_by_custody_and_item_with_partial_and_overlapping_borrowings(): void
    {
        $item = $this->item(6);
        $otherItem = $this->item(5);
        $officer = $this->officer();
        $a = $this->custodyForStockCard('A', 'LGU');
        $b = $this->custodyForStockCard('B', 'Payak High School');
        $cancelled = $this->custodyForStockCard('CANCELLED', 'Other Office');
        $cancelled->update(['status' => 'CANCELLED', 'closed_at' => now()]);
        $returnA1 = $this->returnForStockCard($a, 'A1', $officer);
        $returnA2 = $this->returnForStockCard($a, 'A2', $officer);
        $returnB = $this->returnForStockCard($b, 'B', $officer);
        $service = app(InventoryStockCardService::class);

        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 6, null, '2026-10-19 08:00:00');
        $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 2, $a, '2026-10-20 08:00:00');
        $this->entry($otherItem, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 4, $a, '2026-10-20 08:00:00');
        $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 1, $b, '2026-10-21 08:00:00');
        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'LAUNDRY', 1, $returnA1, '2026-10-22 08:00:00');
        $this->entry($otherItem, 'PHYSICAL_RETURN', 'BORROWED', 'AVAILABLE', 4, $returnA1, '2026-10-22 08:00:00');
        $partial = $service->forItem($item, ['borrowed' => 2]);
        $this->assertSame([6.0, 5.0, 4.0], $partial['rows']->pluck('balance')->all());
        $this->assertSame([6.0, 1.0, null], $partial['rows']->pluck('receipt')->all());
        $this->assertSame([null, 2.0, 1.0], $partial['rows']->pluck('issue')->all());
        $this->assertNull($partial['warning']);

        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'AVAILABLE', 1, $returnB, '2026-10-23 08:00:00');
        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'DAMAGED_MAINTENANCE', 1, $returnA2, '2026-10-24 08:00:00');
        $this->entry($item, 'LAUNDRY_COMPLETION', 'LAUNDRY', 'AVAILABLE', 1, $a, '2026-10-25 08:00:00');
        $this->entry($item, 'SPMU_APPROVAL_RESERVATION', 'AVAILABLE', 'ALLOCATED', 1, $cancelled, '2026-10-26 08:00:00');
        $this->entry($item, 'ALLOCATION_RESTORATION', 'ALLOCATED', 'AVAILABLE', 1, $cancelled, '2026-10-26 09:00:00');
        $before = DB::table('inventory_transaction_lines')->count();
        $card = $service->forItem($item, []);
        $this->assertCount(3, $card['rows']);
        $this->assertSame([6.0, 6.0, 6.0], $card['rows']->pluck('balance')->all());
        $this->assertSame([6.0, 2.0, 1.0], $card['rows']->pluck('receipt')->all());
        $this->assertSame([null, 2.0, 1.0], $card['rows']->pluck('issue')->all());
        $this->assertSame('CUS-STOCK-A', $card['rows'][1]['reference']);
        $this->assertSame('CUS-STOCK-B', $card['rows'][2]['reference']);
        $this->assertSame('2026-10-20 08:00:00', $card['rows'][1]['date']);
        $this->assertSame('LGU', $card['rows'][1]['office']);
        $this->assertSame('Payak High School', $card['rows'][2]['office']);
        $this->assertSame(['RECEIPT', 'BOTH', 'BOTH'], $card['rows']->pluck('kind')->all());
        $this->assertNull($card['warning']);

        // Both filters include a combined row, and searching its original
        // return reference or return date still finds the custody reference.
        $this->actingAs($officer);
        foreach (['inventory.show', 'inventory.stock-card.print'] as $route) {
            foreach (['RECEIPT', 'ISSUE'] as $movement) {
                $response = $this->get(route($route, ['inventory' => $item->id, 'tab' => 'stock-card', 'stock_movement' => $movement, 'stock_q' => 'RET-STOCK-A2']))->assertOk();
                $this->assertSame(1, substr_count($response->getContent(), 'data-stock-card-row'));
                $response->assertSee('stock-balance">6</td>', false);
                $document = new \DOMDocument;
                @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
                $cells = (new \DOMXPath($document))->query('//tr[@data-stock-card-row]/td');
                $this->assertSame(['10-20-2026', 'CUS-STOCK-A', '2', '2', 'LGU', '6'], array_map(fn ($cell) => trim($cell->textContent), iterator_to_array($cells)));
            }
            $response = $this->get(route($route, ['inventory' => $item->id, 'tab' => 'stock-card', 'stock_q' => '10-24-2026']))->assertOk();
            $this->assertSame(1, substr_count($response->getContent(), 'data-stock-card-row'));
        }
        $this->assertDatabaseCount('inventory_transaction_lines', $before);
        $this->assertEquals(6, $item->fresh()->total_quantity);
    }

    public function test_unmatched_return_remains_a_separate_receipt_and_is_not_guessed(): void
    {
        $item = $this->item(6);
        $a = $this->custodyForStockCard('UNMATCHED-A', 'LGU');
        $b = $this->custodyForStockCard('UNMATCHED-B', 'LGU');
        $returnB = $this->returnForStockCard($b, 'UNMATCHED-B', $this->officer());
        $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 2, $a);
        $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'AVAILABLE', 2, $returnB);
        $card = app(InventoryStockCardService::class)->forItem($item, []);
        $this->assertSame(['CUS-STOCK-UNMATCHED-A', 'RET-STOCK-UNMATCHED-B'], $card['rows']->pluck('reference')->all());
        $this->assertSame([null, 2.0], $card['rows']->pluck('receipt')->all());
        $this->assertSame([4.0, 6.0], $card['rows']->pluck('balance')->all());
    }

    public function test_stock_card_print_and_generation_are_available_to_head_and_officer_only(): void
    {
        $item = $this->item();
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        foreach ([$head, $this->officer()] as $staff) {
            $this->actingAs($staff)->get(route('inventory.show', ['inventory' => $item->id, 'tab' => 'stock-card']))->assertOk()
                ->assertSee('aria-label="Print Stock Card"', false)->assertSeeText('Generate')->assertSeeText('Export / Print Stock Card');
            $this->get(route('inventory.stock-card.print', $item))->assertOk();
            $this->get(route('inventory.stock-card.export', ['inventory' => $item->id, 'format' => 'csv']))->assertOk();
        }
        $borrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly]);
        $this->actingAs($borrower)->get(route('inventory.stock-card.print', $item))->assertForbidden();
        $this->get(route('inventory.stock-card.export', ['inventory' => $item->id, 'format' => 'pdf']))->assertForbidden();
    }

    public function test_stock_card_exports_all_filtered_records_and_preserves_inventory(): void
    {
        $item = $this->item(10);
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 10);
        for ($i = 0; $i < 8; $i++) {
            $this->entry($item, 'PHYSICAL_RELEASE', 'ALLOCATED', 'BORROWED', 1);
            $this->entry($item, 'PHYSICAL_RETURN', 'BORROWED', 'AVAILABLE', 1);
        }
        $this->actingAs($this->officer());
        $query = ['inventory' => $item->id, 'stock_movement' => 'ISSUE', 'stock_page' => 1, 'stock_per_page' => 7];
        $csv = $this->get(route('inventory.stock-card.export', $query + ['format' => 'csv']))->assertOk();
        $this->assertStringStartsWith('text/csv', $csv->headers->get('Content-Type'));
        $lines = preg_split('/\r?\n/', trim($csv->getContent()));
        $this->assertCount(9, $lines);
        $this->assertSame(['Date','Reference','In','Out','Issued Office','Balance Qty.'], str_getcsv($lines[0], ',', '"', ''));
        foreach (array_slice($lines, 1) as $line) {
            $fields = str_getcsv($line, ',', '"', '');
            $this->assertSame('', $fields[2]);
            $this->assertSame('1', $fields[3]);
            $this->assertSame('9', $fields[5]);
        }
        $preview = $this->get(route('inventory.stock-card.print', $query + ['page_size' => 'LETTER', 'orientation' => 'portrait', 'margins' => 'custom', 'margin_left' => 12, 'font_size' => 10]))->assertOk();
        $preview->assertSee('size: LETTER portrait', false)->assertSee('margin: 20mm 20mm 20mm 12mm', false)->assertSee('font-size: 10pt', false);
        $this->assertSame(8, substr_count($preview->getContent(), 'data-stock-card-row'));
        $this->get(route('inventory.stock-card.export', $query + ['format' => 'print']))->assertRedirect();
        $this->getJson(route('inventory.stock-card.export', $query + ['format' => 'csv', 'stock_q' => str_repeat('x', 201)]))->assertUnprocessable();
        $this->assertDatabaseCount('inventory_transaction_lines', 17);
        $this->assertEquals(10, $item->fresh()->total_quantity);
    }

    public function test_stock_card_binary_exports_are_real_documents(): void
    {
        $item = $this->item(10);
        $this->entry($item, 'INVENTORY_INITIAL_STOCK', 'OPENING_BALANCE', 'AVAILABLE', 10);
        $this->actingAs($this->officer());
        $pdf = $this->get(route('inventory.stock-card.export', ['inventory' => $item->id, 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        foreach (['docx' => 'word/document.xml', 'xlsx' => 'xl/worksheets/sheet1.xml'] as $format => $entry) {
            $response = $this->get(route('inventory.stock-card.export', ['inventory' => $item->id, 'format' => $format]))->assertOk();
            $file = tempnam(sys_get_temp_dir(), 'stock-test-');
            file_put_contents($file, $response->getContent());
            $zip = new \ZipArchive;
            try {
                $this->assertTrue($zip->open($file));
                $xml = $zip->getFromName($entry);
                $this->assertNotFalse($xml);
                if ($format === 'docx') $this->assertStringContainsString('Balance Qty.', $xml);
                else $this->assertStringContainsString('Balance Qty.', (string) $zip->getFromName('xl/sharedStrings.xml'));
            } finally {
                if ($zip->status === \ZipArchive::ER_OK) $zip->close();
                @unlink($file);
            }
        }
        $this->assertDatabaseCount('inventory_transaction_lines', 1);
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
                ->assertOk()->assertDontSeeText('No. of Days to consume')->assertSee('<th scope="col">In</th>', false)->assertSee('<th scope="col">Out</th>', false)->assertSeeText('Issued Office')->assertSeeText('Balance Qty.')->assertSeeText('10-28-2026');
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new \DOMXPath($document);
            foreach (['fund-cluster', 'stock-number', 'reorder-point'] as $field) {
                $value = $xpath->query('//*[@data-stock-field="'.$field.'"]');
                $this->assertSame(0, $value->length);
            }
            $identity = $xpath->query('//table[@aria-label="Stock card item information"]');
            $this->assertSame(0, $identity->length);
            if ($route === 'inventory.stock-card.print') $response->assertSeeText($item->unique_description)->assertSeeText('Inventory Reference:');
            $consumptionCells = $xpath->query('//td[@class="stock-consumption"]');
            $this->assertSame(0, $consumptionCells->length);
            foreach ($consumptionCells as $cell) {
                $this->assertSame('', trim($cell->textContent));
            }
        }
        $this->assertDatabaseCount('inventory_transaction_lines', 3);
    }
}
