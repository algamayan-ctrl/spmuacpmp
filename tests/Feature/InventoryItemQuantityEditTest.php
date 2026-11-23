<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\InventoryStockCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class InventoryItemQuantityEditTest extends TestCase
{
    use RefreshDatabase;

    private User $head;
    private InventoryItem $item;
    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $office = OrganizationalUnit::create(['unit_code' => 'QTY', 'unit_name' => 'SPMU', 'unit_type' => 'OFFICE', 'active' => true]);
        $this->head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead, 'organizational_unit_id' => $office->id]);
        $category = InventoryCategory::create(['category_code' => 'QTY', 'category_name' => 'Equipment', 'active' => true]);
        $unit = UnitOfMeasure::create(['unit_code' => 'PC', 'unit_name' => 'Piece', 'active' => true]);
        $this->item = InventoryItem::create([
            'category_id' => $category->id, 'unit_id' => $unit->id, 'unique_description' => 'Barricade',
            'specification' => 'Original specification', 'total_quantity' => 6, 'condition_code' => 'SERVICEABLE',
            'borrowable' => true, 'laundry_required' => false, 'off_campus_allowed' => true,
            'provisional' => true, 'active' => true,
        ]);
        $this->inventory = app(InventoryService::class);
        $this->inventory->recordInitialStock($this->item, $this->head);
        $this->actingAs($this->head)->withSession(['active_workspace' => 'SPMU']);
    }

    private function payload(array $changes = []): array
    {
        return $changes + [
            'category_id' => $this->item->category_id, 'unit_id' => $this->item->unit_id,
            'unique_description' => $this->item->unique_description, 'specification' => $this->item->specification,
            'borrowable' => 1, 'laundry_required' => 0, 'off_campus_allowed' => 1, 'provisional' => 1, 'active' => 1,
            'change_reason' => 'Corrected after physical stock verification.',
            'new_total_quantity' => 8, 'original_total_quantity' => 6,
        ];
    }

    private function save(array $changes = []): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('inventory.edit', $this->item))->put(route('inventory.update', $this->item), $this->payload($changes));
    }

    private function balance(): array
    {
        return $this->inventory->availability($this->item->fresh(), now(), now()->addDay());
    }

    private function correction(): object
    {
        return DB::table('inventory_transactions')->where('transaction_type', 'INVENTORY_QUANTITY_CORRECTION')->sole();
    }

    public function test_admin_form_shows_current_and_new_quantity_with_existing_controls(): void
    {
        $this->get(route('inventory.edit', $this->item))->assertOk()
            ->assertSee('Current Total Quantity')->assertSee('New Total Quantity')->assertSee('SPMU Admin only')
            ->assertSee('name="original_total_quantity" value="6"', false)
            ->assertSee('View Inventory Overview')->assertSee('Master Condition')->assertSee('Operational flags')
            ->assertSee('data-inventory-quantity-change', false)->assertDontSee('name="condition_code"', false);
    }

    public function test_increase_and_detail_changes_share_one_audit_and_stock_card_correction(): void
    {
        $this->save(['specification' => 'Physically verified specification', 'provisional' => 0])->assertSessionHasNoErrors()->assertRedirect(route('inventory.index'));
        $this->assertSame(8, $this->item->fresh()->total_quantity);
        $this->assertEquals(8, $this->balance()['borrower_available']);
        $event = AuditEvent::where('action_code', 'INVENTORY_ITEM_UPDATED')->sole();
        $this->assertSame(6, $event->before_json['total_quantity']);
        $this->assertSame(8, $event->after_json['total_quantity']);
        $this->assertSame('Original specification', $event->before_json['specification']);
        $this->assertSame('Physically verified specification', $event->after_json['specification']);
        $this->assertFalse($event->after_json['provisional']);
        $this->assertSame($this->head->id, $event->actor_user_id);
        $tx = $this->correction();
        $this->assertSame($event->correlation_id, $tx->correlation_id);
        $this->assertSame($event->reason, $tx->reason);
        $line = DB::table('inventory_transaction_lines')->where('inventory_transaction_id', $tx->id)->sole();
        $this->assertSame('OUTSIDE_STOCK', $line->from_state);
        $this->assertSame('AVAILABLE', $line->to_state);
        $this->assertEquals(2, $line->quantity);
        $card = app(InventoryStockCardService::class)->forItem($this->item->fresh(), $this->balance());
        $this->assertNull($card['warning']);
        $this->assertSame(8.0, $card['rows']->last()['balance']);
        $this->assertSame('Total quantity corrected', $card['rows']->last()['remarks']);
    }

    public function test_decrease_is_a_documented_issue_and_zero_is_allowed_when_uncommitted(): void
    {
        $this->save(['new_total_quantity' => 0])->assertSessionHasNoErrors();
        $line = DB::table('inventory_transaction_lines')->where('inventory_transaction_id', $this->correction()->id)->sole();
        $this->assertSame('AVAILABLE', $line->from_state);
        $this->assertSame('OUTSIDE_STOCK', $line->to_state);
        $this->assertEquals(6, $line->quantity);
        $card = app(InventoryStockCardService::class)->forItem($this->item->fresh(), $this->balance());
        $this->assertSame(0.0, $card['current_balance']);
        $this->assertNull($card['warning']);
    }

    public function test_future_reservations_and_borrowed_stock_set_the_minimum_without_changing_commitments(): void
    {
        $line = $this->commitments(reserved: 2, borrowed: 2);
        $this->assertEquals(2, $this->balance()['reserved']);
        $this->assertEquals(2, $this->balance()['borrowed']);
        $this->save(['new_total_quantity' => 3, 'specification' => 'Must not save'])->assertSessionHasErrors('new_total_quantity');
        $this->assertSame('Original specification', $this->item->fresh()->specification);
        $this->assertDatabaseCount('audit_events', 0);
        $this->save(['new_total_quantity' => 4])->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->balance()['borrower_available']);
        $this->assertSame(2, $line->fresh()->actual_released_quantity);
        $this->assertDatabaseHas('allocations', ['id' => $line->allocation_id, 'allocated_quantity' => 4, 'released_quantity' => 2]);
    }

    public function test_laundry_incident_and_manual_maintenance_holds_all_set_the_minimum(): void
    {
        $line = $this->commitments(reserved: 0, borrowed: 2);
        $line->update(['returned_quantity' => 2]);
        $return = ReturnTransaction::create([
            'return_no' => 'RET-QTY', 'custody_transaction_id' => $line->custody_transaction_id,
            'received_by_user_id' => $this->head->id, 'return_type' => 'FINAL', 'received_at' => now(), 'status' => 'CONFIRMED',
        ]);
        ReturnLine::create(['return_transaction_id' => $return->id, 'custody_line_id' => $line->id, 'quantity_received' => 1, 'condition_code' => 'SERVICEABLE', 'disposition_state' => 'LAUNDRY']);
        $incident = Incident::create([
            'incident_no' => 'INC-QTY', 'custody_transaction_id' => $line->custody_transaction_id,
            'borrower_user_id' => $this->head->id, 'reported_by_user_id' => $this->head->id,
            'incident_type' => 'LOSS', 'reported_at' => now(), 'status' => 'OPEN',
        ]);
        DB::table('incident_lines')->insert(['incident_id' => $incident->id, 'custody_line_id' => $line->id, 'quantity' => 1, 'observed_condition' => 'LOST', 'disposition_state' => 'LOST', 'created_at' => now(), 'updated_at' => now()]);
        $this->inventory->recordAdjustment($this->item, $this->head, ['action' => 'PLACE_UNDER_MAINTENANCE', 'quantity' => 1, 'reason' => 'Manual repair hold.']);
        $balance = $this->balance();
        $this->assertEquals(1, $balance['laundry']);
        $this->assertEquals(1, $balance['incident']);
        $this->assertEquals(1, $balance['condition_hold']);
        $this->save(['new_total_quantity' => 2])->assertSessionHasErrors('new_total_quantity');
        $this->save(['new_total_quantity' => 3])->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->balance()['borrower_available']);
    }

    public function test_stale_form_cannot_overwrite_a_new_stock_receipt_or_metadata(): void
    {
        $this->inventory->recordAdjustment($this->item, $this->head, ['action' => 'STOCK_ADDITION', 'quantity' => 1, 'reason' => 'New delivery.']);
        $this->save(['specification' => 'Stale edit'])->assertSessionHasErrors('new_total_quantity');
        $this->assertSame(7, $this->item->fresh()->total_quantity);
        $this->assertSame('Original specification', $this->item->fresh()->specification);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseMissing('inventory_transactions', ['transaction_type' => 'INVENTORY_QUANTITY_CORRECTION']);
        $this->get(route('inventory.edit', $this->item))->assertSee('name="original_total_quantity" value="7"', false);
    }

    public function test_invalid_quantities_and_missing_reason_or_original_are_rejected_atomically(): void
    {
        foreach ([['new_total_quantity' => -1], ['new_total_quantity' => 2.5], ['new_total_quantity' => 1000000000], ['new_total_quantity' => ''], ['original_total_quantity' => null], ['change_reason' => '']] as $invalid) {
            $this->save($invalid + ['specification' => 'Invalid edit'])->assertSessionHasErrors();
            $this->assertSame(6, $this->item->fresh()->total_quantity);
            $this->assertSame('Original specification', $this->item->fresh()->specification);
        }
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_audit_failure_rolls_back_quantity_details_and_stock_movements(): void
    {
        $mock = Mockery::mock(AuditService::class);
        $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit write failure'));
        $this->app->instance(AuditService::class, $mock);
        $this->withoutExceptionHandling();
        try {
            $this->save(['specification' => 'Must roll back']);
            $this->fail('Expected the audit write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit write failure', $exception->getMessage());
        }
        $this->assertSame(6, $this->item->fresh()->total_quantity);
        $this->assertSame('Original specification', $this->item->fresh()->specification);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('inventory_transaction_lines', 1);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_metadata_only_edit_is_audited_without_a_stock_movement_and_noop_is_quiet(): void
    {
        $this->save(['new_total_quantity' => 6])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('audit_events', 0);
        $payload = $this->payload(['specification' => 'Updated metadata', 'total_quantity' => 999, 'condition_code' => 'CONDEMNED']);
        unset($payload['new_total_quantity'], $payload['original_total_quantity']);
        $this->put(route('inventory.update', $this->item), $payload)->assertSessionHasNoErrors();
        $this->assertSame(6, $this->item->fresh()->total_quantity);
        $this->assertSame('SERVICEABLE', $this->item->fresh()->condition_code);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertSame('Updated metadata', AuditEvent::sole()->after_json['specification']);
    }

    public function test_officers_and_borrowers_cannot_open_or_submit_the_quantity_editor(): void
    {
        foreach ([AccessClassification::SpmuOfficer, AccessClassification::BorrowerOnly] as $role) {
            $user = User::factory()->create(['access_classification' => $role]);
            $this->actingAs($user)->withSession(['active_workspace' => $role === AccessClassification::BorrowerOnly ? 'BORROWER' : 'SPMU']);
            $this->get(route('inventory.edit', $this->item))->assertForbidden();
            $this->save()->assertForbidden();
        }
        $this->assertSame(6, $this->item->fresh()->total_quantity);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('inventory_transactions', 1);
    }

    public function test_nonserviceable_quantity_stays_unavailable_and_cannot_forge_master_condition(): void
    {
        $this->item->update(['condition_code' => 'CONDEMNED']);
        $this->save(['condition_code' => 'SERVICEABLE'])->assertSessionHasNoErrors();
        $this->assertSame('CONDEMNED', $this->item->fresh()->condition_code);
        $this->assertEquals(0, $this->balance()['borrower_available']);
        $this->assertDatabaseHas('inventory_transaction_lines', ['inventory_transaction_id' => $this->correction()->id, 'to_state' => 'CONDEMNED']);
    }

    public function test_existing_audit_record_format_shows_quantity_and_recovery_never_reverses_stock(): void
    {
        $this->save(['specification' => 'Corrected specification'])->assertSessionHasNoErrors();
        $event = AuditEvent::where('action_code', 'INVENTORY_ITEM_UPDATED')->sole();
        $this->get(route('reports.audit', ['category' => 'INVENTORY', 'record_id' => $this->item->id]))->assertOk()
            ->assertSee('Inventory Item Updated')->assertSee('Operational Changes')->assertSee('Total Quantity')
            ->assertSee('data-audit-operational-changes', false)->assertSee('Corrected specification');
        $this->post(route('reports.audit.revert', ['event' => $event->id]), [
            'revert_reason' => 'Restore the previous specification only.', 'confirm_revert' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Original specification', $this->item->fresh()->specification);
        $this->assertSame(8, $this->item->fresh()->total_quantity);
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertSame(8, $event->fresh()->after_json['total_quantity']);
    }

    private function commitments(int $reserved, int $borrowed): CustodyLine
    {
        $request = BorrowingRequest::create(['request_no' => 'BR-QTY-'.Str::random(5), 'borrower_user_id' => $this->head->id, 'accountable_unit_id' => $this->head->organizational_unit_id, 'current_version_no' => 1, 'status' => RequestStatus::ApprovedReadyForRelease]);
        $version = RequestVersion::create(['request_id' => $request->id, 'version_no' => 1, 'purpose_event' => 'Stock verification test', 'location' => 'Campus', 'division_code' => 'ACADEMIC', 'office_unit' => 'SPMU', 'schedule_date' => now()->toDateString(), 'return_date' => now()->toDateString(), 'needed_from' => now(), 'return_due_at' => now()->addDay()]);
        $requestItem = RequestItem::create(['request_version_id' => $version->id, 'inventory_item_id' => $this->item->id, 'description_snapshot' => 'Barricade', 'unit_snapshot' => 'Piece', 'requested_quantity' => $reserved + $borrowed, 'approved_quantity' => $reserved + $borrowed]);
        $allocation = Allocation::create(['request_item_id' => $requestItem->id, 'period_start' => now()->addYear(), 'period_end' => now()->addYear()->addDay(), 'allocated_quantity' => $reserved + $borrowed, 'released_quantity' => $borrowed, 'restored_quantity' => 0, 'status' => $reserved ? 'PARTIALLY_RELEASED' : 'RELEASED', 'allocated_at' => now()]);
        $custody = CustodyTransaction::create(['custody_no' => 'CUS-QTY', 'request_id' => $request->id, 'request_version_id' => $version->id, 'borrower_user_id' => $this->head->id, 'status' => 'ACTIVE', 'released_at' => now(), 'due_at' => now()->addDay()]);
        return CustodyLine::create(['custody_transaction_id' => $custody->id, 'request_item_id' => $requestItem->id, 'allocation_id' => $allocation->id, 'approved_quantity' => $borrowed, 'quantity_to_receive' => $borrowed, 'actual_released_quantity' => $borrowed, 'returned_quantity' => 0]);
    }
}
