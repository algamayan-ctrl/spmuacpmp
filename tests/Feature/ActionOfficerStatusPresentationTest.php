<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\Allocation;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\OverdueCase;
use App\Models\ReturnTransaction;
use App\Models\User;
use App\Support\ActionOfficerStatusPresentation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActionOfficerStatusPresentationTest extends TestCase
{
    use RefreshDatabase;

    private const RECORD_STATUSES = [
        'all' => 'All statuses',
        'FOR_APPROVAL' => 'For Approval',
        'NEEDS_REVISION' => 'Needs Revision',
        'PICKUP_SCHEDULED' => 'Pickup Scheduled',
        'FOR_ISSUANCE' => 'For Issuance',
        'READY_FOR_PICKUP' => 'Ready for Pickup',
        'MISSED_PICKUP' => 'Missed Pickup',
        'ON_CUSTODY' => 'On Custody',
        'OVERDUE' => 'Overdue',
        'RETURNED_LATE' => 'Returned Late',
        'SLDDP' => 'SLDDP',
        'ACCOUNTABILITY_REVIEW' => 'Accountability Review',
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled',
        'REJECTED' => 'Rejected',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
    }

    #[DataProvider('pickupStates')]
    public function test_release_filter_groups_preparation_but_preserves_pickup_timing(string $state, string $expected): void
    {
        $custody = $this->custody('PREPARING_RELEASE', false);
        $changes = match ($state) {
            'no_schedule' => [],
            'unprepared' => ['scheduled_release_at' => now()->subHour(), 'pickup_expires_at' => now()->addHour()],
            'scheduled' => ['prepared_at' => now(), 'scheduled_release_at' => now()->addHour(), 'pickup_expires_at' => now()->addHours(2)],
            'ready' => ['prepared_at' => now(), 'scheduled_release_at' => now()->subHour(), 'pickup_expires_at' => now()->addHour()],
            'missed' => ['prepared_at' => now()->subDay(), 'scheduled_release_at' => now()->subHours(2), 'pickup_expires_at' => now()->subHour()],
        };
        $custody->update($changes);
        $before = $this->snapshot();
        $response = $this->officerRequest(route('custody.release.index'));
        $response->assertOk()->assertSee($expected)
            ->assertDontSee('value="PICKUP_SCHEDULING"', false)
            ->assertDontSee('value="ITEM_PREPARATION"', false)
            ->assertDontSee('value="PREPARING_RELEASE"', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(5, $xpath->query('//select[@id="operational-status"]/option')->length);
        $row = $xpath->query('//*[@data-operational-record]')->item(0);
        $this->assertStringContainsString($expected, $row->textContent);
        $this->assertSame($before, $this->snapshot());
    }

    public static function pickupStates(): array
    {
        return [
            ['no_schedule', 'For Preparation'], ['unprepared', 'For Preparation'],
            ['scheduled', 'Pickup Scheduled'], ['ready', 'Ready for Release'], ['missed', 'Pickup Missed'],
        ];
    }

    public function test_review_category_retains_the_actual_reviewer_without_changing_the_review_steps(): void
    {
        $borrower = User::where('access_classification', AccessClassification::BorrowerOnly->value)->firstOrFail();
        $request = BorrowingRequest::create([
            'request_no' => 'BR-REVIEW-'.uniqid(), 'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id, 'current_version_no' => 1,
            'status' => RequestStatus::UnderSpmu,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1, 'purpose_event' => 'Review stage', 'location' => 'Off-campus venue',
            'schedule_date' => now()->addDay()->toDateString(), 'return_date' => now()->addDays(2)->toDateString(),
            'needed_from' => now()->addDay(), 'return_due_at' => now()->addDays(2)->endOfDay(),
            'off_campus' => true, 'created_by_user_id' => $borrower->id,
        ]);
        $step = $version->approvalSteps()->create(['sequence_no' => 1, 'stage_code' => 'SPMU', 'decision' => 'PENDING']);
        $version->approvalSteps()->create(['sequence_no' => 2, 'stage_code' => 'SPMU', 'decision' => 'PENDING']);
        $pending = ActionOfficerStatusPresentation::forRequest($request->fresh());
        $this->assertSame('UNDER_REVIEW', $pending['filter']);
        $this->assertSame('For Verification', $pending['label']);
        $step->update(['decision' => 'VERIFIED']);
        $verified = ActionOfficerStatusPresentation::forRequest($request->fresh());
        $this->assertSame('UNDER_REVIEW', $verified['filter']);
        $this->assertSame('For Approval', $verified['label']);
        $this->assertSame(['VERIFIED', 'PENDING'], $version->approvalSteps()->orderBy('sequence_no')->pluck('decision')->all());
    }

    public function test_request_record_uses_admin_accountability_status_with_simultaneous_late_return(): void
    {
        $custody = $this->custody('OBLIGATION_OPEN', true, true);
        $this->incident($custody, 'COMPLIANCE_REQUIRED', 'DAMAGED', 'REPAIR');
        $this->lateCase($custody, 'FOR_HEAD_APPROVAL');
        $before = $this->snapshot();
        $response = $this->officerRequest(route('requests.index'));
        $response->assertOk();
        $this->assertRecord($response->getContent(), 'ACCOUNTABILITY_REVIEW', 'ACCOUNTABILITY_REVIEW', false);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_overdue_is_not_mistaken_for_a_completed_late_return_or_hidden_by_a_property_case(): void
    {
        $custody = $this->custody('OVERDUE');
        $this->incident($custody, 'COMPLIANCE_REQUIRED', 'LOST', 'REPLACEMENT');
        $this->lateCase($custody, 'OVERDUE');
        $response = $this->officerRequest(route('requests.index'));
        $response->assertOk();
        $this->assertRecord($response->getContent(), 'OVERDUE', 'OVERDUE', false);
    }

    public function test_a_cleared_late_return_uses_admin_returned_late_record_status(): void
    {
        $custody = $this->custody('CLOSED', true, true);
        $this->lateCase($custody, 'RESOLVED');
        $response = $this->officerRequest(route('requests.index'));
        $response->assertOk()->assertSee('Returned Late');
        $this->assertRecord($response->getContent(), 'RETURNED_LATE', 'RETURNED_LATE', false);
    }

    public function test_request_record_uses_admin_slddp_status_for_payment_stage(): void
    {
        $custody = $this->custody('OBLIGATION_OPEN', true, true);
        $this->incident($custody, 'RSLDDP_PAYMENT_REQUIRED', 'LOST');
        $response = $this->officerRequest(route('requests.index'));
        $response->assertOk();
        $this->assertRecord($response->getContent(), 'SLDDP', 'SLDDP', false);
    }

    public function test_both_staff_roles_share_admin_options_badges_and_filter_keys_for_every_stage(): void
    {
        $records = collect();
        $expected = [];
        $borrower = User::where('access_classification', AccessClassification::BorrowerOnly->value)->firstOrFail();
        foreach ([
            'SIGNED' => 'FOR_APPROVAL', 'SUBMITTED' => 'FOR_APPROVAL',
            'UNDER_SPMU' => 'FOR_APPROVAL', 'UNDER_GSU' => 'FOR_APPROVAL', 'UNDER_VPAF' => 'FOR_APPROVAL',
            'RETURNED_FOR_REVISION' => 'NEEDS_REVISION',
            'FINAL_APPROVED_AWAITING_DOWNLOAD' => 'FOR_ISSUANCE', 'APPROVED_READY_FOR_RELEASE' => 'FOR_ISSUANCE',
            'CANCELLED' => 'CANCELLED', 'EXPIRED' => 'CANCELLED', 'REJECTED' => 'REJECTED',
        ] as $raw => $key) {
            $record = BorrowingRequest::create([
                'request_no' => 'BR-RECORD-'.$raw, 'borrower_user_id' => $borrower->id,
                'accountable_unit_id' => $borrower->organizational_unit_id, 'current_version_no' => 1,
                'status' => $raw,
            ]);
            $record->versions()->create([
                'version_no' => 1, 'purpose_event' => self::RECORD_STATUSES[$key].' sample',
                'location' => 'Campus', 'needed_from' => now()->addDay(), 'return_due_at' => now()->addDays(2),
                'created_by_user_id' => $borrower->id,
            ]);
            $records->push($record->fresh());
            $expected[$record->request_no] = $key;
        }

        foreach ([
            'PREPARING_RELEASE' => 'FOR_ISSUANCE', 'ON_CUSTODY' => 'ON_CUSTODY',
            'OVERDUE' => 'OVERDUE', 'RETURN_PROCESSING' => 'ON_CUSTODY',
            'CLOSED' => 'COMPLETED', 'OBLIGATION_OPEN' => 'ACCOUNTABILITY_REVIEW',
        ] as $raw => $key) {
            $custody = $this->custody($raw, $raw !== 'PREPARING_RELEASE', $raw === 'CLOSED');
            if ($raw === 'CLOSED') {
                $custody->returns()->update(['received_at' => now()->subDays(2)]);
            }
            $record = $custody->request->fresh();
            $records->push($record);
            $expected[$record->request_no] = $key;
        }

        foreach ([
            'PICKUP_SCHEDULED' => ['prepared_at' => now(), 'scheduled_release_at' => now()->addHour(), 'pickup_expires_at' => now()->addHours(2)],
            'READY_FOR_PICKUP' => ['prepared_at' => now(), 'scheduled_release_at' => now()->subHour(), 'pickup_expires_at' => now()->addHour()],
            'MISSED_PICKUP' => ['scheduled_release_at' => now()->subHours(2), 'pickup_expires_at' => now()->subHour()],
        ] as $key => $changes) {
            $custody = $this->custody('PREPARING_RELEASE', false);
            $custody->update($changes);
            $record = $custody->request->fresh();
            $records->push($record);
            $expected[$record->request_no] = $key;
        }

        $custody = $this->custody('CLOSED', true, true);
        $records->push($custody->request->fresh());
        $expected[$custody->request->request_no] = 'RETURNED_LATE';
        $custody = $this->custody('OBLIGATION_OPEN', true, true);
        $this->incident($custody, 'RSLDDP_PAYMENT_REQUIRED', 'LOST');
        $records->push($custody->request->fresh());
        $expected[$custody->request->request_no] = 'SLDDP';

        $before = $this->snapshot();
        foreach ([AccessClassification::SpmuHead, AccessClassification::SpmuOfficer] as $role) {
            $staff = User::where('access_classification', $role->value)->firstOrFail();
            $this->actingAs($staff)->withSession(['active_workspace' => 'SPMU']);
            // Render the same records to test presentation independently of role visibility.
            // Route-level record access is covered by PremisesApprovalRoutingTest.
            $html = view('requests.index', [
                'requests' => $records, 'errors' => new \Illuminate\Support\ViewErrorBag,
            ])->render();
            if ($directory = getenv('SPMU_UI_EVIDENCE_DIR')) {
                file_put_contents($directory.'/'.$role->value.'.html', $html);
            }
            $document = new \DOMDocument;
            @$document->loadHTML($html);
            $xpath = new \DOMXPath($document);
            $options = [];
            foreach ($xpath->query('//select[@data-record-status-filter]/option') as $option) {
                $options[$option->getAttribute('value')] = trim($option->textContent);
            }
            $this->assertSame(self::RECORD_STATUSES, $options, $role->value);
            $rows = $xpath->query('//tr[@data-request-record]');
            $this->assertCount(count($expected), $rows);
            foreach ($rows as $row) {
                $number = trim($xpath->query('./td[1]/strong', $row)->item(0)->textContent);
                $key = $expected[$number];
                $this->assertSame($key, $row->getAttribute('data-status'), $number.' '.$role->value);
                $this->assertSame($key, $row->getAttribute('data-status-key'), $number.' '.$role->value);
                $this->assertSame(self::RECORD_STATUSES[$key], trim($xpath->query('./td[5]', $row)->item(0)->textContent));
            }
        }
        $this->assertSame($before, $this->snapshot(), 'Rendering must not change workflow, stock or request records.');
    }

    #[DataProvider('recordDetailStates')]
    public function test_admin_list_request_detail_and_custody_detail_use_the_same_main_status(string $state, string $expected): void
    {
        $preRelease = in_array($state, ['no_schedule', 'item_preparation', 'scheduled', 'ready', 'missed', 'cancelled'], true);
        $raw = match ($state) {
            'no_schedule', 'item_preparation', 'scheduled', 'ready', 'missed' => 'PREPARING_RELEASE',
            'completed', 'returned_late' => 'CLOSED',
            'accountability', 'slddp' => 'OBLIGATION_OPEN',
            'return_processing' => 'RETURN_PROCESSING',
            'overdue' => 'OVERDUE', 'cancelled' => 'CANCELLED',
            default => 'BORROWED',
        };
        $custody = $this->custody($raw, ! $preRelease, in_array($state, ['completed', 'returned_late'], true));
        if (in_array($state, ['item_preparation', 'scheduled', 'ready', 'missed'], true)) {
            $custody->update([
                'prepared_at' => $state === 'item_preparation' ? null : now(),
                'scheduled_release_at' => $state === 'scheduled' ? now()->addHour() : now()->subHours(2),
                'pickup_expires_at' => $state === 'missed' ? now()->subHour() : now()->addHours(2),
            ]);
        }
        if ($state === 'completed') {
            $custody->returns()->update(['received_at' => now()->subDays(2)]);
        }
        if ($state === 'slddp') {
            $this->incident($custody, 'RSLDDP_PAYMENT_REQUIRED', 'LOST');
        }
        if ($state === 'cancelled') {
            $custody->request->update(['status' => RequestStatus::Cancelled]);
        }
        // The existing custody page backfills this metadata on legacy rows.
        // Start with normalized fixtures so this check isolates status presentation.
        $custody->update(['original_due_at' => $custody->due_at]);
        $head = User::where('access_classification', AccessClassification::SpmuHead->value)->firstOrFail();
        $this->actingAs($head)->withSession(['active_workspace' => 'SPMU']);
        $before = $this->snapshot();
        $list = $this->get(route('requests.index'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($list->getContent());
        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//tr[@data-request-record]');
        $this->assertCount(1, $rows);
        $this->assertSame($expected, $rows->item(0)->getAttribute('data-status'));
        $this->assertSame(self::RECORD_STATUSES[$expected], trim($xpath->query('./td[5]', $rows->item(0))->item(0)->textContent));
        foreach ([route('requests.show', $custody->request), route('custody.show', $custody)] as $url) {
            $response = $this->get($url)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $badge = (new \DOMXPath($document))->query('//*[@data-record-status]');
            $this->assertCount(1, $badge, $url);
            $this->assertSame(self::RECORD_STATUSES[$expected], trim($badge->item(0)->textContent), $url);
        }
        $this->assertSame($before, $this->snapshot(), 'Status presentation must not change workflow or inventory records.');
    }

    public static function recordDetailStates(): array
    {
        return [
            ['no_schedule', 'FOR_ISSUANCE'], ['item_preparation', 'FOR_ISSUANCE'],
            ['scheduled', 'PICKUP_SCHEDULED'], ['ready', 'READY_FOR_PICKUP'], ['missed', 'MISSED_PICKUP'],
            ['on_custody', 'ON_CUSTODY'], ['return_processing', 'ON_CUSTODY'], ['overdue', 'OVERDUE'],
            ['completed', 'COMPLETED'], ['returned_late', 'RETURNED_LATE'],
            ['accountability', 'ACCOUNTABILITY_REVIEW'], ['slddp', 'SLDDP'], ['cancelled', 'CANCELLED'],
        ];
    }

    #[DataProvider('requestOnlyDetailStates')]
    public function test_admin_request_detail_matches_the_list_before_custody_exists(RequestStatus $status, string $expected): void
    {
        $custody = $this->custody('PREPARING_RELEASE', false);
        $request = $custody->request;
        $custody->lines()->delete();
        $custody->delete();
        $request->update(['status' => $status]);
        if ($status === RequestStatus::UnderSpmu) {
            $request->currentVersion->approvalSteps()->create(['sequence_no' => 1, 'stage_code' => 'SPMU', 'decision' => 'VERIFIED']);
            $request->currentVersion->approvalSteps()->create(['sequence_no' => 2, 'stage_code' => 'SPMU', 'decision' => 'PENDING']);
        }
        $head = User::where('access_classification', AccessClassification::SpmuHead->value)->firstOrFail();
        $this->actingAs($head)->withSession(['active_workspace' => 'SPMU']);
        $before = $this->snapshot();
        $list = $this->get(route('requests.index'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($list->getContent());
        $row = (new \DOMXPath($document))->query('//tr[@data-request-record]')->item(0);
        $this->assertNotNull($row);
        $this->assertSame($expected, $row->getAttribute('data-status'));
        $detail = $this->get(route('requests.show', $request))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($detail->getContent());
        $badge = (new \DOMXPath($document))->query('//*[@data-record-status]');
        $this->assertCount(1, $badge);
        $this->assertSame(self::RECORD_STATUSES[$expected], trim($badge->item(0)->textContent));
        $this->assertSame($before, $this->snapshot());
    }

    public static function requestOnlyDetailStates(): array
    {
        return [
            [RequestStatus::UnderSpmu, 'FOR_APPROVAL'],
            [RequestStatus::ReturnedForRevision, 'NEEDS_REVISION'],
            [RequestStatus::ApprovedReadyForRelease, 'FOR_ISSUANCE'],
            [RequestStatus::Cancelled, 'CANCELLED'], [RequestStatus::Expired, 'CANCELLED'],
            [RequestStatus::Rejected, 'REJECTED'],
        ];
    }

    private function assertRecord(string $html, string $filter, string $status, bool $attention): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $row = $xpath->query('//tr[@data-request-record]')->item(0);
        $this->assertNotNull($row);
        $this->assertSame($filter, $row->getAttribute('data-status'));
        $this->assertSame($status, $row->getAttribute('data-status-key'));
        $this->assertSame($attention ? '1' : '0', $row->getAttribute('data-attention'));
        $this->assertSame(count(self::RECORD_STATUSES), $xpath->query('//select[@data-record-status-filter]/option')->length);
    }

    private function officerRequest(string $url)
    {
        $officer = User::where('access_classification', AccessClassification::SpmuOfficer->value)->firstOrFail();
        return $this->withSession(['active_workspace' => 'SPMU', 'last_user_activity_at' => now()->timestamp])->actingAs($officer)->get($url);
    }

    private function custody(string $status, bool $released = true, bool $returned = false): CustodyTransaction
    {
        $borrower = User::where('access_classification', AccessClassification::BorrowerOnly->value)->firstOrFail();
        $item = InventoryItem::where('laundry_required', false)->with('unit')->firstOrFail();
        $request = BorrowingRequest::create([
            'request_no' => 'BR-STATUS-'.uniqid(), 'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id, 'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease, 'final_approved_at' => now()->subDays(2),
        ]);
        $version = $request->versions()->create([
            'version_no' => 1, 'purpose_event' => 'Status review', 'location' => 'Campus',
            'schedule_date' => now()->subDays(2)->toDateString(), 'return_date' => now()->subDay()->toDateString(),
            'needed_from' => now()->subDays(2), 'return_due_at' => now()->subDay()->endOfDay(),
            'off_campus' => false, 'created_by_user_id' => $borrower->id,
        ]);
        $itemRecord = $version->items()->create([
            'inventory_item_id' => $item->id, 'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name, 'requested_quantity' => 1, 'approved_quantity' => 1,
            'use_location' => 'ON_CAMPUS',
        ]);
        $custody = CustodyTransaction::create([
            'custody_no' => 'CUS-STATUS-'.uniqid(), 'request_id' => $request->id, 'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id, 'status' => $status, 'due_at' => now()->subDay()->endOfDay(),
            'released_at' => $released ? now()->subDays(2) : null, 'closed_at' => $status === 'CLOSED' ? now() : null,
        ]);
        $allocation = Allocation::create([
            'request_item_id' => $itemRecord->id, 'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at, 'allocated_quantity' => 1,
            'released_quantity' => $released ? 1 : 0, 'restored_quantity' => $returned ? 1 : 0,
            'status' => $released ? 'RELEASED' : 'ACTIVE', 'allocated_at' => now()->subDays(2),
        ]);
        CustodyLine::create([
            'custody_transaction_id' => $custody->id, 'request_item_id' => $itemRecord->id, 'allocation_id' => $allocation->id,
            'approved_quantity' => 1, 'quantity_to_receive' => 1, 'actual_released_quantity' => $released ? 1 : 0,
            'returned_quantity' => $returned ? 1 : 0,
        ]);
        if ($returned) {
            ReturnTransaction::create([
                'return_no' => 'RT-STATUS-'.uniqid(), 'custody_transaction_id' => $custody->id,
                'received_by_user_id' => User::where('access_classification', AccessClassification::SpmuOfficer->value)->value('id'),
                'return_type' => 'FULL', 'received_at' => now(), 'status' => 'RECORDED',
            ]);
        }
        return $custody;
    }

    private function incident(CustodyTransaction $custody, string $status, string $type, ?string $action = null): void
    {
        Incident::create([
            'incident_no' => 'INC-STATUS-'.uniqid(), 'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id, 'reported_by_user_id' => $custody->borrower_user_id,
            'incident_type' => $type, 'reported_at' => now(), 'status' => $status, 'compliance_action' => $action,
        ]);
    }

    private function lateCase(CustodyTransaction $custody, string $status): void
    {
        OverdueCase::create([
            'custody_transaction_id' => $custody->id, 'borrower_user_id' => $custody->borrower_user_id,
            'grace_expires_at' => $custody->due_at, 'overdue_started_at' => $custody->due_at->copy()->addDay(),
            'offense_level' => 1, 'rate_snapshot' => 75, 'accrued_amount' => 75, 'status' => $status,
        ]);
    }

    private function snapshot(): array
    {
        return collect(['borrowing_requests', 'custody_transactions', 'custody_lines', 'return_transactions', 'incidents', 'overdue_cases', 'inventory_items'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
