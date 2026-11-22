<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationalListPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
    }

    public static function lists(): array
    {
        return [
            'Request Records' => ['requests.index', 'requests'],
            'Admin Request Records' => ['requests.index', 'requests', 'SPMU_HEAD'],
            'Release' => ['custody.release.index', 'release'],
            'Return' => ['custody.return.index', 'return'],
        ];
    }

    #[DataProvider('lists')]
    public function test_full_result_sets_reach_the_active_pagination_with_approved_options(string $route, string $kind, string $role = 'SPMU_OFFICER'): void
    {
        $borrower = User::where('access_classification', AccessClassification::BorrowerOnly->value)->firstOrFail();
        $officer = User::where('access_classification', $role)->firstOrFail();
        $inventory = InventoryItem::where('laundry_required', false)->with('unit')->firstOrFail();
        $expected = [];
        for ($number = 1; $number <= 53; $number++) {
            $request = BorrowingRequest::create([
                'request_no' => 'PAGINATION-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'borrower_user_id' => $borrower->id,
                'accountable_unit_id' => $borrower->organizational_unit_id,
                'current_version_no' => 1,
                'status' => RequestStatus::ApprovedReadyForRelease,
                'final_approved_at' => now(),
            ]);
            $version = $request->versions()->create([
                'version_no' => 1, 'purpose_event' => 'Pagination event '.$number,
                'location' => 'Campus', 'needed_from' => now()->addDay(),
                'return_due_at' => now()->addDays(2), 'off_campus' => false,
                'created_by_user_id' => $borrower->id,
            ]);
            $expected[] = $request->request_no;
            if ($kind === 'requests') {
                continue;
            }
            $released = $kind === 'return';
            $item = $version->items()->create([
                'inventory_item_id' => $inventory->id, 'description_snapshot' => $inventory->unique_description,
                'unit_snapshot' => $inventory->unit->unit_name, 'requested_quantity' => 1,
                'approved_quantity' => 1, 'use_location' => 'ON_CAMPUS',
            ]);
            $custody = CustodyTransaction::create([
                'custody_no' => 'PAGINATION-CUS-'.$number, 'request_id' => $request->id,
                'request_version_id' => $version->id, 'borrower_user_id' => $borrower->id,
                'status' => $released ? ['ON_CUSTODY', 'RETURN_PROCESSING', 'OVERDUE'][$number % 3] : 'PREPARING_RELEASE',
                'released_at' => $released ? now() : null,
                'due_at' => $released && $number % 3 === 2 ? now()->subDays(2) : now()->addDays(2),
            ]);
            $allocation = Allocation::create([
                'request_item_id' => $item->id, 'period_start' => $version->needed_from,
                'period_end' => $version->return_due_at, 'allocated_quantity' => 1,
                'released_quantity' => $released ? 1 : 0, 'restored_quantity' => 0,
                'status' => $released ? 'RELEASED' : 'ACTIVE', 'allocated_at' => now(),
            ]);
            CustodyLine::create([
                'custody_transaction_id' => $custody->id, 'request_item_id' => $item->id,
                'allocation_id' => $allocation->id, 'approved_quantity' => 1,
                'quantity_to_receive' => 1, 'actual_released_quantity' => $released ? 1 : 0,
                'returned_quantity' => 0,
            ]);
        }

        $response = $this->actingAs($officer)->withSession(['active_workspace' => 'SPMU'])
            ->get(route($route, ['per_page' => 7]))->assertOk();
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $rows = $xpath->query($kind === 'requests' ? '//tr[@data-request-record]' : '//tr[@data-operational-record]');
        $this->assertCount(53, $rows, 'Client pagination must receive all records, including records beyond page 50.');
        $requestNumbers = [];
        foreach ($rows as $row) {
            $requestNumbers[] = $xpath->query('.//td[1]//'.($kind === 'requests' ? 'strong' : 'small'), $row)->item(0)->textContent;
            $this->assertNotSame('', $row->getAttribute('data-search'));
            $this->assertNotSame('', $row->getAttribute('data-status'));
        }
        sort($expected);
        sort($requestNumbers);
        $this->assertSame($expected, $requestNumbers);
        $this->assertCount(53, array_unique($requestNumbers));
        $select = $kind === 'requests' ? '//select[@data-sao-record-page-size]/option' : '//select[@data-release-page-size]/option';
        $this->assertSame(['7', '15', '30', '50'], array_map(
            fn ($option) => $option->getAttribute('value'), iterator_to_array($xpath->query($select))
        ));
        if ($kind === 'requests') {
            $response->assertSee('data-sao-record-pagination', false);
            $this->assertSame(['7 entries', '15 entries', '30 entries', '50 entries'], array_map(
                fn ($option) => trim($option->textContent), iterator_to_array($xpath->query($select))
            ));
            $script = trim(file_get_contents(resource_path('views/requests/partials/sao-record-pagination.blade.php')));
            $this->assertSame(1, substr_count($response->getContent(), $script));
        } else {
            $this->assertCount(1, $xpath->query('//*[@data-sao-release-browser]'));
            $this->assertCount(1, $xpath->query('//*[@data-release-pagination]'));
            $response->assertDontSee('data-operational-pagination', false);
            $script = trim(file_get_contents(resource_path('views/custody/partials/sao-release-list-interactions.blade.php')));
            $this->assertSame(1, substr_count($response->getContent(), $script), 'The active shared browser script must load exactly once.');
        }
        if ($kind === 'return') {
            $this->assertSame(['all', 'ON_CUSTODY', 'RETURN_INSPECTION', 'OVERDUE'], array_map(
                fn ($option) => $option->getAttribute('value'), iterator_to_array($xpath->query('//select[@id="operational-status"]/option'))
            ));
            $this->assertEqualsCanonicalizing(
                ['ON_CUSTODY', 'RETURN_INSPECTION', 'OVERDUE'],
                array_values(array_unique(array_map(fn ($row) => $row->getAttribute('data-status'), iterator_to_array($rows))))
            );
        }
        $this->assertSame(53, BorrowingRequest::count());
        $this->assertSame($kind === 'requests' ? 0 : 53, CustodyTransaction::count());
    }
}
