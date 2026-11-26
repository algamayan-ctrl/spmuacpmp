<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\ApprovalStep;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GatePass;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\ReturnTransaction;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StandardTransactionTrackerTest extends TestCase
{
    use RefreshDatabase;

    private const LABELS = ['Submitted', 'Action Officer Verification', 'SPMU Head Review', 'Pickup Scheduled', 'Release Processing', 'Return Processing', 'Completed'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-26 09:00:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
    }

    public static function phases(): array
    {
        return [
            'on-campus review' => ['review', false, 2, 'is-current'],
            'off-campus verification' => ['review', true, 1, 'is-current'],
            'Officer revision' => ['officer-revision', true, 1, 'is-warning'],
            'Head revision' => ['head-revision', true, 2, 'is-warning'],
            'cancel before submission' => ['cancel-draft', false, 0, 'is-stopped'],
            'cancel at Head review' => ['cancel-review', false, 2, 'is-stopped'],
            'cancel at Officer verification' => ['cancel-review', true, 1, 'is-stopped'],
            'cancel after verification' => ['cancel-verified', true, 2, 'is-stopped'],
            'cancel before pickup confirmed' => ['cancel-approved', true, 3, 'is-stopped'],
            'cancel before physical release' => ['cancel-release', true, 4, 'is-stopped'],
            'cancel missed pickup' => ['cancel-missed', true, 3, 'is-stopped'],
            'legacy cancellation after release' => ['cancel-return', true, 5, 'is-stopped'],
            'Head rejection on-campus' => ['reject-head', false, 2, 'is-stopped'],
            'Head rejection off-campus' => ['reject-head', true, 2, 'is-stopped'],
            'legacy Officer rejection' => ['reject-officer', true, 1, 'is-stopped'],
            'release' => ['release', false, 4, 'is-current'],
            'on custody' => ['return', true, 5, 'is-current'],
            'completed off-campus' => ['closed', true, 6, 'is-complete'],
            'completed on-campus' => ['closed', false, 6, 'is-complete'],
            'F2F after physical return' => ['f2f', true, 5, 'is-current'],
            'closed row with unresolved case' => ['closed-f2f', false, 5, 'is-current'],
            'closed row with pending Gate Pass' => ['closed-gate-pass', true, 5, 'is-current'],
        ];
    }

    #[DataProvider('phases')]
    public function test_full_standard_flow_and_truthful_phase_states(string $phase, bool $offCampus, int $position, string $state): void
    {
        [$request] = $this->record($phase, $offCampus);
        $before = $this->snapshot();
        $html = Blade::render('<x-request-progress-tracker :request="$request" />', ['request' => $request->fresh()]);
        $xpath = $this->tracker($html);
        $nodes = $xpath->query('//*[@data-transaction-tracker]//ol/li');
        foreach ($nodes as $index => $node) {
            $expected = ! $offCampus && $index === 1 ? 'is-not-required'
                : ($index < $position ? 'is-complete' : ($index === $position ? $state : 'is-pending'));
            $this->assertStringContainsString($expected, $node->getAttribute('class'), self::LABELS[$index]);
            $copy = $xpath->query('.//*[@data-workflow-title]', $node)->item(0);
            if ($expected === 'is-not-required') {
                $this->assertSame('Not required', $copy->getAttribute('data-workflow-meta'));
                $this->assertStringNotContainsString('is-complete', $node->getAttribute('class'));
                $this->assertSame(1, $xpath->query('.//span[contains(@class,"request-tracker__state-label")]', $copy)->length);
            }
            if ($state === 'is-stopped' && $index > $position && $expected !== 'is-not-required') {
                $this->assertSame('Not reached', $copy->getAttribute('data-workflow-meta'));
                $this->assertSame(0, $xpath->query('.//time', $copy)->length);
            }
        }
        if ($state === 'is-stopped') {
            $stop = $xpath->query('//li[contains(@class,"is-stopped")]')->item(0);
            $outcome = str_starts_with($phase, 'reject') ? 'Rejected' : 'Cancelled';
            $this->assertSame(1, $xpath->query('//li[contains(@class,"is-stopped")]')->length);
            $copy = $xpath->query('.//*[@data-workflow-title]', $stop)->item(0);
            $this->assertSame($outcome.' · '.now()->format('d M Y, g:i A'), $copy->getAttribute('data-workflow-meta'));
            $this->assertStringContainsString($outcome, $copy->textContent);
            $this->assertSame('step', $stop->getAttribute('aria-current'));
        }
        if (in_array($phase, ['f2f', 'closed-f2f', 'closed-gate-pass'])) {
            $complete = $xpath->query('//*[@data-workflow-title="Completed"]')->item(0);
            $this->assertSame(0, $xpath->query('.//time', $complete)->length);
            $this->assertStringContainsString('is-pending', $complete->parentNode->getAttribute('class'));
            $this->assertStringNotContainsString('Transaction completed.', $html);
        }
        $this->assertSame($before, $this->snapshot(), 'Tracker rendering must not mutate business records.');
    }

    public static function roleRoutes(): array
    {
        $cases = [];
        foreach (['borrower', 'officer', 'head'] as $role) {
            foreach (['review', 'closed', 'f2f', 'cancel-review', 'reject-head'] as $phase) {
                $cases[$role.' request '.$phase] = [$role, 'requests.show', $phase];
            }
            foreach (['release', 'return', 'closed', 'f2f', 'closed-f2f'] as $phase) {
                $route = $role === 'officer'
                    ? ($phase === 'release' ? 'custody.release.show' : 'custody.return.show')
                    : 'custody.show';
                $cases[$role.' custody '.$phase] = [$role, $route, $phase];
            }
        }
        return $cases;
    }

    #[DataProvider('roleRoutes')]
    public function test_each_record_layout_has_exactly_one_full_tracker_without_changing_records(string $role, string $route, string $phase): void
    {
        [$request, $custody] = $this->record($phase, true);
        $actor = $role === 'borrower' ? $request->borrower : $this->staff($role);
        $before = $this->snapshot();
        $response = $this->actingAs($actor)->withSession(['active_workspace' => $role === 'borrower' ? 'BORROWER' : 'SPMU'])
            ->get(route($route, $route === 'requests.show' ? $request : $custody));
        if ($role === 'officer' && in_array($phase, ['cancel-review', 'reject-head'])) {
            // Existing historical access rules remain unchanged by this UI revision.
            $response->assertForbidden();
            $this->assertSame($before, $this->snapshot());
            return;
        }
        $response->assertOk();
        $xpath = $this->tracker($response->getContent());
        if (in_array($phase, ['f2f', 'closed-f2f'])) {
            $this->assertSame(1, $xpath->query('//li[contains(@class,"is-current")]/*[@data-workflow-title="Return Processing"]')->length);
            $this->assertSame(1, $xpath->query('//li[contains(@class,"is-pending")]/*[@data-workflow-title="Completed"]')->length);
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function tracker(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@data-transaction-tracker]')->length);
        $this->assertSame(1, $xpath->query('//ol[contains(@class,"request-tracker__rail")]')->length);
        $labels = [];
        foreach ($xpath->query('//*[@data-transaction-tracker]//*[@data-workflow-title]') as $copy) {
            $labels[] = $copy->getAttribute('data-workflow-title');
        }
        $this->assertSame(self::LABELS, $labels);
        return $xpath;
    }

    private function staff(string $role): User
    {
        return User::where('access_classification', $role === 'head' ? AccessClassification::SpmuHead : AccessClassification::SpmuOfficer)->firstOrFail();
    }

    private function record(string $phase, bool $offCampus): array
    {
        $borrower = User::where('email', 'borrower@spmu.test')->firstOrFail();
        $early = in_array($phase, ['review', 'officer-revision', 'head-revision', 'cancel-draft', 'cancel-review', 'cancel-verified', 'reject-head', 'reject-officer']);
        $cancelled = str_starts_with($phase, 'cancel');
        $rejected = str_starts_with($phase, 'reject');
        $revision = str_ends_with($phase, 'revision');
        $status = $cancelled ? RequestStatus::Cancelled : ($rejected ? RequestStatus::Rejected
            : ($revision ? RequestStatus::ReturnedForRevision : ($early ? RequestStatus::UnderSpmu : RequestStatus::ApprovedReadyForRelease)));
        $request = BorrowingRequest::create([
            'request_no' => 'TRACKER-'.uniqid(), 'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id, 'current_version_no' => 1,
            'status' => $status, 'final_approved_at' => $early ? null : now()->subDays(2),
        ]);
        $version = $request->versions()->create([
            'version_no' => 1, 'purpose_event' => 'Tracker regression fixture', 'location' => 'Fixture venue',
            'schedule_date' => now()->addDay()->toDateString(), 'return_date' => now()->addDays(5)->toDateString(),
            'needed_from' => now()->addDay()->startOfDay(), 'return_due_at' => now()->addDays(5)->endOfDay(),
            'created_by_user_id' => $borrower->id, 'off_campus' => $offCampus,
        ]);
        if ($phase !== 'cancel-draft') {
            $request->statusHistory()->create(['request_version_id' => $version->id, 'actor_user_id' => $borrower->id,
                'from_status' => 'SIGNED', 'to_status' => 'UNDER_SPMU', 'changed_at' => now()->subDays(3)]);
        }
        $verified = ! $early || in_array($phase, ['head-revision', 'cancel-verified', 'reject-head']);
        ApprovalStep::create(['request_version_id' => $version->id, 'stage_code' => 'SPMU', 'sequence_no' => 1,
            'decision' => $phase === 'reject-officer' ? 'REJECTED' : ($phase === 'officer-revision' ? 'RETURNED_FOR_REVISION' : ($verified ? 'VERIFIED' : 'PENDING')),
            'decided_at' => $phase === 'reject-officer' ? now() : ($verified ? now()->subDays(2) : null), 'remarks' => 'Fixture review reason.']);
        ApprovalStep::create(['request_version_id' => $version->id, 'stage_code' => 'SPMU', 'sequence_no' => 2,
            'decision' => $phase === 'reject-head' ? 'REJECTED' : ($phase === 'head-revision' ? 'RETURNED_FOR_REVISION' : ($early ? 'PENDING' : 'APPROVED')),
            'decided_at' => $phase === 'reject-head' ? now() : ($early ? null : now()->subDays(2)), 'remarks' => 'Fixture review reason.']);
        if ($cancelled || $rejected) {
            $request->statusHistory()->create(['request_version_id' => $version->id, 'actor_user_id' => $borrower->id,
                'from_status' => $phase === 'cancel-draft' ? 'DRAFT' : ($early ? 'UNDER_SPMU' : 'APPROVED_READY_FOR_RELEASE'),
                'to_status' => $cancelled ? 'CANCELLED' : 'REJECTED', 'changed_at' => now()]);
        }
        if ($early) {
            return [$request->fresh(), null];
        }
        $released = in_array($phase, ['return', 'closed', 'f2f', 'closed-f2f', 'closed-gate-pass', 'cancel-return']);
        $returned = in_array($phase, ['closed', 'f2f', 'closed-f2f', 'closed-gate-pass']);
        $closed = str_starts_with($phase, 'closed');
        $scheduled = $phase !== 'cancel-approved';
        $custody = CustodyTransaction::create([
            'custody_no' => 'CUS-TRACKER-'.uniqid(), 'request_id' => $request->id, 'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id, 'status' => $cancelled ? 'CANCELLED' : ($closed ? 'CLOSED' : ($released ? 'ON_CUSTODY' : 'PREPARING_RELEASE')),
            'due_at' => $version->return_due_at, 'original_due_at' => $version->return_due_at,
            'scheduled_release_at' => $scheduled ? now()->addDay() : null,
            'pickup_expires_at' => $scheduled ? now()->addDays(2) : null,
            'pickup_scheduled_at' => $scheduled ? now()->subDay() : null,
            'pickup_expired_at' => $phase === 'cancel-missed' ? now()->subHour() : null,
            'released_at' => $released ? now()->subHour() : null, 'closed_at' => $closed || $cancelled ? now() : null,
        ]);
        $item = InventoryItem::with('unit')->where('laundry_required', false)->where('borrowable', true)->firstOrFail();
        $line = $version->items()->create(['inventory_item_id' => $item->id, 'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name, 'requested_quantity' => 1, 'approved_quantity' => 1, 'use_location' => $offCampus ? 'OFF_CAMPUS' : 'ON_CAMPUS']);
        $allocation = Allocation::create(['request_item_id' => $line->id, 'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at, 'allocated_quantity' => 1, 'released_quantity' => $released ? 1 : 0,
            'restored_quantity' => $returned ? 1 : 0, 'status' => 'ACTIVE', 'allocated_at' => now()->subDays(2)]);
        $custodyLine = CustodyLine::create(['custody_transaction_id' => $custody->id, 'request_item_id' => $line->id, 'allocation_id' => $allocation->id,
            'approved_quantity' => 1, 'quantity_to_receive' => 1, 'actual_released_quantity' => $released ? 1 : 0,
            'returned_quantity' => $returned ? 1 : 0, 'item_status' => 'PENDING']);
        if ($returned) {
            ReturnTransaction::create(['return_no' => 'RT-TRACKER-'.uniqid(), 'custody_transaction_id' => $custody->id,
                'received_by_user_id' => $this->staff('officer')->id, 'return_type' => 'FULL', 'received_at' => now(), 'status' => 'RECORDED']);
        }
        if (in_array($phase, ['f2f', 'closed-f2f'])) {
            Incident::create(['incident_no' => 'INC-TRACKER-'.uniqid(), 'custody_transaction_id' => $custody->id,
                'borrower_user_id' => $borrower->id, 'reported_by_user_id' => $this->staff('officer')->id,
                'incident_type' => 'MISSING', 'reported_at' => now(), 'status' => 'F2F_PROCESSING']);
        }
        if ($phase === 'closed-gate-pass') {
            GatePass::create(['custody_transaction_id' => $custody->id, 'custody_line_id' => $custodyLine->id,
                'bearer_name' => 'Fixture borrower', 'destination' => 'Fixture destination', 'purpose' => 'Tracker fixture', 'status' => 'GENERATED']);
        }
        return [$request->fresh(), $custody];
    }

    private function snapshot(): array
    {
        return collect(['borrowing_requests', 'request_versions', 'approval_steps', 'request_status_histories',
            'custody_transactions', 'custody_lines', 'allocations', 'incidents', 'gate_passes', 'audit_events'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
