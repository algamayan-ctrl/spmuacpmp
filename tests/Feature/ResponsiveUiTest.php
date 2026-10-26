<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GatePass;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\LaundryJobLine;
use App\Models\OverdueCase;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponsiveUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_create_request_event_label_keeps_the_existing_field_and_value(): void
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $response = $this->actingAs($borrower)->withSession([
            'active_workspace' => 'BORROWER',
            '_old_input' => ['purpose_event' => 'Synthetic annual event'],
        ])->get('/requests/create')->assertOk();

        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $field = $xpath->query('//input[@name="purpose_event"]')->item(0);
        $this->assertNotNull($field);
        $this->assertSame('Synthetic annual event', $field->getAttribute('value'));
        $this->assertSame('Event', trim($xpath->evaluate('string(text()[1])', $field->parentNode)));
        $this->assertTrue($field->hasAttribute('required'));
        $this->assertSame('255', $field->getAttribute('maxlength'));
    }

    /** Actual controller-rendered pages; optional exports are disposable visual QA fixtures. */
    public function test_role_workspaces_render_with_the_shared_responsive_shell(): void
    {
        $custody = $this->custodyFixture();
        $returnedCustody = $this->custodyFixture(released: true);
        $laundry = LaundryJob::query()->create(['custody_transaction_id' => $returnedCustody->id, 'status' => 'FOR_LAUNDRY']);
        LaundryJobLine::query()->create([
            'laundry_job_id' => $laundry->id, 'custody_line_id' => $returnedCustody->lines->first()->id,
            'issued_quantity' => 1, 'affected_quantity' => 0,
        ]);
        $gatePass = GatePass::query()->create([
            'custody_transaction_id' => $returnedCustody->id, 'status' => 'GENERATED',
            'custody_line_id' => $returnedCustody->lines->first()->id,
            'bearer_name' => 'Synthetic Borrower', 'destination' => 'Synthetic conference venue',
            'purpose' => 'Annual planning conference',
        ]);
        OverdueCase::query()->create([
            'custody_transaction_id' => $returnedCustody->id, 'borrower_user_id' => $returnedCustody->borrower_user_id,
            'grace_expires_at' => now()->subDay(), 'overdue_started_at' => now()->subDay(),
            'offense_level' => 1, 'rate_snapshot' => 25, 'accrued_amount' => 25, 'status' => 'OPEN',
        ]);
        $item = $custody->lines->first()->requestItem->inventoryItem;
        $request = $custody->request;
        $pages = [
            'borrower' => [AccessClassification::BorrowerOnly, [
                '/dashboard', '/inventory', '/inventory/'.$item->id, '/calendar',
                '/requests', '/requests/create', '/requests/'.$request->id,
                '/custody', '/custody/'.$custody->id, '/accountability', '/notifications', '/profile',
            ]],
            'officer' => [AccessClassification::SpmuOfficer, [
                '/dashboard', '/inventory', '/inventory/'.$item->id,
                '/calendar', '/requests', '/requests/'.$request->id,
                '/verifications', '/release', '/release/'.$custody->id, '/return', '/return/'.$returnedCustody->id,
                '/gate-passes', '/gate-passes/'.$gatePass->id, '/laundry', '/laundry/'.$laundry->id, '/laundry/completed',
                '/accountability', '/accountability/borrowers/'.$returnedCustody->borrower_user_id,
                '/registrations', '/notifications', '/profile',
            ]],
            'head' => [AccessClassification::SpmuHead, [
                '/dashboard', '/inventory', '/inventory/create', '/inventory/'.$item->id.'/edit', '/calendar', '/requests', '/requests/'.$request->id,
                '/approvals', '/custody/'.$custody->id, '/accountability',
                '/administration/users', '/administration/users/create',
                '/administration/users/'.$this->user(AccessClassification::BorrowerOnly)->id.'/edit',
                '/administration/policies', '/administration/settings?section=late-return-fee',
                '/administration', '/administration/settings?scope=system', '/reports/notifications',
                '/analytics', '/reports', '/reports/audit', '/notifications', '/profile',
            ]],
        ];
        $manifest = [];
        foreach ($pages as $role => [$classification, $urls]) {
            foreach ($urls as $url) {
                $response = $this->actingAs($this->user($classification))
                    ->withSession(['active_workspace' => $classification === AccessClassification::BorrowerOnly ? 'BORROWER' : 'SPMU'])
                    ->get($url);
                $this->assertSame(200, $response->getStatusCode(), $role.' '.$url);
                $response->assertSee('name="viewport"', false);
                $response->assertSee('primary-sidebar', false);
                $manifest[] = $this->exportSnapshot($role, $url, $response->getContent());
            }
        }
        foreach ([2, 3] as $stage) {
            $input = [
                'purpose_event' => 'Synthetic annual conference', 'location' => 'Campus conference venue',
                'schedule_date' => now()->addWeek()->toDateString(), 'return_date' => now()->addDays(9)->toDateString(),
            ];
            if ($stage === 3) {
                $input += ['item_ids' => [$item->id], 'quantities' => [$item->id => 1], 'locations' => [$item->id => 'ON_CAMPUS']];
            }
            $response = $this->actingAs($this->user(AccessClassification::BorrowerOnly))->withSession([
                'active_workspace' => 'BORROWER', '_old_input' => $input,
            ])->get('/requests/create')->assertOk();
            $response->assertSee('const resumeStage = '.$stage.';', false);
            $manifest[] = $this->exportSnapshot('borrower-stage-'.$stage, '/requests/create', $response->getContent());
        }
        auth()->logout();
        foreach (['/', '/login', '/register', '/forgot-password', '/privacy-policy', '/terms-of-service'] as $url) {
            $response = $this->get($url)->assertOk();
            $response->assertSee('name="viewport"', false);
            $manifest[] = $this->exportSnapshot('public', $url === '/' ? '/home' : $url, $response->getContent());
        }
        if ($directory = getenv('SPMU_UI_SNAPSHOTS')) {
            file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    private function user(AccessClassification $classification): User
    {
        return User::query()->where('access_classification', $classification->value)->firstOrFail();
    }

    private function exportSnapshot(string $role, string $url, string $html): array
    {
        $filename = $role.'-'.trim(preg_replace('/[^a-z0-9]+/i', '-', $url), '-').'.html';
        if ($directory = getenv('SPMU_UI_SNAPSHOTS')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            // Resolve local assets without exporting any test authentication/session data.
            $html = str_replace(['http://localhost/', 'https://localhost/'], '/', $html);
            file_put_contents($directory.'/'.$filename, $html);
        }

        return ['role' => $role, 'route' => $url, 'file' => $filename];
    }

    private function custodyFixture(bool $released = false): CustodyTransaction
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $item = InventoryItem::query()->where('active', true)->where('borrowable', true)->where('total_quantity', '>', 10)->with('unit')->firstOrFail();
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-RESPONSIVE-'.($released ? '002' : '001'), 'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id, 'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
            'final_approved_at' => now(),
        ]);
        $version = $request->versions()->create([
            'version_no' => 1, 'purpose_event' => 'Synthetic annual planning conference with a long event title',
            'event_details' => 'Synthetic UI fixture only.', 'location' => 'CSPC Campus conference venue',
            'schedule_date' => now()->addWeek()->toDateString(), 'return_date' => now()->addDays(9)->toDateString(),
            'needed_from' => now()->addWeek(), 'return_due_at' => now()->addDays(9),
            'off_campus' => false, 'created_by_user_id' => $borrower->id,
        ]);
        $line = $version->items()->create([
            'inventory_item_id' => $item->id, 'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name, 'requested_quantity' => 1, 'approved_quantity' => 1,
            'use_location' => 'ON_CAMPUS',
        ]);
        $allocation = Allocation::query()->create([
            'request_item_id' => $line->id, 'period_start' => $version->needed_from, 'period_end' => $version->return_due_at,
            'allocated_quantity' => 1, 'released_quantity' => 0, 'restored_quantity' => 0,
            'status' => 'ACTIVE', 'allocated_at' => now(),
        ]);
        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-RESPONSIVE-'.($released ? '002' : '001'), 'request_id' => $request->id, 'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id, 'status' => $released ? 'ON_CUSTODY' : 'PREPARING_RELEASE',
            'released_at' => $released ? now() : null,
            'scheduled_release_at' => now()->addWeekdays(3)->setTime(8, 0),
            'pickup_expires_at' => now()->addWeekdays(3)->setTime(17, 0), 'pickup_scheduled_at' => now(),
            'due_at' => $version->return_due_at,
        ]);
        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id, 'request_item_id' => $line->id, 'allocation_id' => $allocation->id,
            'approved_quantity' => 1, 'quantity_to_receive' => 1, 'actual_released_quantity' => $released ? 1 : 0, 'returned_quantity' => 0,
            'item_status' => $released ? 'ON_CUSTODY' : 'RESERVED_FOR_PICKUP', 'compliance_status' => 'PENDING',
        ]);

        return $custody->fresh();
    }
}
