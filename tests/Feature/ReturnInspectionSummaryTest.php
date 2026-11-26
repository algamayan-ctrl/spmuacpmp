<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\OverdueCase;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\User;
use App\Support\ReturnInspectionSummary;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReturnInspectionSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-26 09:00:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_actual_conditions_do_not_double_count_the_linked_incident_or_claim_accounted_items_were_received(): void
    {
        [$request, $custody] = $this->record('property');
        $before = $this->snapshot();
        $summary = ReturnInspectionSummary::for($custody->fresh());
        $this->assertSame('SLDDP findings', $summary['category']);
        $this->assertStringContainsString('Damaged: 1', $summary['reason']);
        $this->assertStringContainsString('Missing: 1', $summary['reason']);
        $this->assertSame(['Good', 'Damaged', 'Missing'], array_column($summary['rows'][0]['findings'], 'label'));
        $this->assertSame(['2', '1', '1'], array_column($summary['rows'][0]['findings'], 'quantity'));
        $this->assertSame(['Inspection', 'Inspection', 'Inspection'], array_column($summary['rows'][0]['findings'], 'source'));
        $this->assertSame('Pending Admin review', $summary['decision']);
        $html = $this->render($custody);
        $this->assertStringNotContainsString('physically received', strtolower($html));
        $this->assertStringNotContainsString('Release condition', $html);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_reported_findings_are_not_presented_as_completed_inspection(): void
    {
        [, $custody] = $this->record('reported');
        $summary = ReturnInspectionSummary::for($custody);
        $this->assertFalse($summary['rows'][0]['inspected']);
        $this->assertSame('Reported', $summary['rows'][0]['findings'][0]['source']);
        $html = $this->render($custody);
        $this->assertStringContainsString('No recorded inspection', $html);
        $this->assertStringContainsString('Reported: Missing', $html);
        $this->assertStringNotContainsString('Good', $html);
    }

    public function test_late_days_are_the_frozen_assessment_including_zero_and_never_recomputed(): void
    {
        [, $custody] = $this->record('late');
        $this->travelTo(now()->addMonths(3));
        $before = $this->snapshot();
        $summary = ReturnInspectionSummary::for($custody->fresh());
        $this->assertSame(2, $summary['late']['days']);
        $this->assertSame('25 Nov 2026', $summary['late']['physical_return']);
        $this->assertSame('23 Nov 2026', $summary['late']['effective_due']);
        $this->assertSame('Late return', $summary['category']);
        $this->assertStringNotContainsString('SLDDP', $summary['category']);
        $this->assertSame($before, $this->snapshot());
        $custody->overdueCase->update(['late_days' => 0]);
        $this->assertSame(0, ReturnInspectionSummary::for($custody->fresh())['late']['days']);
    }

    public function test_unfinalized_overdue_estimate_is_never_labeled_an_assessed_late_return(): void
    {
        [, $custody] = $this->record('late');
        $custody->overdueCase->update(['actual_return_date' => null, 'late_days' => 99, 'status' => 'OVERDUE']);
        $summary = ReturnInspectionSummary::for($custody->fresh());
        $this->assertNull($summary['late']['days']);
        $html = $this->render($custody->fresh());
        $this->assertStringContainsString('No finalized assessment recorded', $html);
        $this->assertStringNotContainsString('99 SPMU return days', $html);
    }

    public function test_linen_late_encoding_does_not_replace_physical_receipt_date(): void
    {
        [, $custody] = $this->record('late');
        $item = $custody->lines->first()->requestItem->inventoryItem;
        $item->update(['laundry_required' => true]);
        LaundryJob::create(['custody_transaction_id' => $custody->id, 'status' => 'FOR_LAUNDRY', 'worker_received_at' => '2026-11-23 09:00:00']);
        $custody->overdueCase->update(['actual_return_date' => '2026-11-23', 'return_date_source' => 'LAUNDRY_RECEIPT', 'late_days' => 1]);
        $summary = ReturnInspectionSummary::for($custody->fresh());
        $this->assertSame('23 Nov 2026', $summary['late']['physical_return']);
        $this->assertSame('Laundry received date', $summary['late']['source']);
        $this->assertSame(1, $summary['late']['days']);
        $this->assertSame('26 Nov 2026, 8:00 AM', $summary['inspections'][0]['recorded_at']);
    }

    public function test_resolved_and_cleared_cases_retain_original_findings_with_the_actual_admin_decision(): void
    {
        [, $custody] = $this->record('resolved');
        $summary = ReturnInspectionSummary::for($custody);
        $this->assertStringContainsString('Missing: 1', $summary['reason']);
        $this->assertSame('Resolved', $summary['decision']);
        $custody->incidents()->update(['remarks' => Incident::HEAD_DECISION_PREFIX.' '.Incident::HEAD_DECISION_CLEARED.' Verified correction.']);
        $summary = ReturnInspectionSummary::for($custody->fresh());
        $this->assertSame('Finding cleared', $summary['decision']);
        $this->assertSame('Verified correction.', $summary['cases'][0]['decision_reason']);
    }

    public function test_missing_conditions_and_legacy_case_information_do_not_inherit_serviceable_release_condition(): void
    {
        [, $custody] = $this->record('legacy');
        $summary = ReturnInspectionSummary::for($custody);
        $this->assertTrue($summary['flagged']);
        $this->assertStringContainsString('reason was not recorded', $summary['reason']);
        $this->assertSame([], $summary['rows'][0]['findings']);
        $this->assertStringNotContainsString('Good', $this->render($custody));
        $return = $this->inspection($custody);
        ReturnLine::create(['return_transaction_id' => $return->id, 'custody_line_id' => $custody->lines->first()->id, 'quantity_received' => 4, 'condition_code' => '', 'disposition_state' => 'AVAILABLE']);
        $summary = ReturnInspectionSummary::for($custody->fresh());
        $this->assertSame('Condition not recorded', $summary['rows'][0]['findings'][0]['label']);
    }

    public function test_void_inspection_is_not_added_to_current_findings_and_unreleased_normal_records_stay_compact(): void
    {
        [, $custody] = $this->record('property');
        $void = $this->inspection($custody, 'VOID_CORRECTION');
        ReturnLine::create(['return_transaction_id' => $void->id, 'custody_line_id' => $custody->lines->first()->id, 'quantity_received' => 30, 'condition_code' => 'STOLEN', 'disposition_state' => 'STOLEN']);
        $this->assertStringNotContainsString('Stolen', $this->render($custody->fresh()));
        [, $release] = $this->record('release');
        $this->assertNull(ReturnInspectionSummary::for($release));
        $this->assertSame('', trim($this->render($release)));
        $this->assertNull(ReturnInspectionSummary::for(null));
    }

    public function test_saved_item_description_and_remarks_are_escaped_and_not_replaced_by_later_inventory_edits(): void
    {
        [, $custody] = $this->record('property');
        $unsafe = '<script>alert("summary")</script>';
        $custody->lines->first()->requestItem->update(['description_snapshot' => $unsafe]);
        $custody->returns->first()->update(['remarks' => $unsafe]);
        $custody->lines->first()->requestItem->inventoryItem->update(['unique_description' => 'Later inventory description']);
        $html = $this->render($custody->fresh());
        $this->assertStringContainsString(e($unsafe), $html);
        $this->assertStringNotContainsString($unsafe, $html);
        $this->assertStringNotContainsString('Later inventory description', $html);
    }

    public static function additionalConditions(): array
    {
        return [['LOST', 'Lost'], ['STOLEN', 'Stolen'], ['DESTROYED', 'Destroyed']];
    }

    #[DataProvider('additionalConditions')]
    public function test_all_property_finding_types_keep_their_recorded_condition(string $condition, string $label): void
    {
        [, $custody] = $this->record('property');
        ReturnLine::where('condition_code', 'MISSING')->update(['condition_code' => $condition]);
        $custody->incidents()->update(['incident_type' => $condition]);
        IncidentLine::query()->update(['observed_condition' => $condition]);
        $before = $this->snapshot();
        $summary = ReturnInspectionSummary::for($custody->fresh());
        $this->assertStringContainsString($label.': 1', $summary['reason']);
        $this->assertSame($label, $summary['rows'][0]['findings'][2]['label']);
        $this->assertSame('Pending Admin review', $summary['decision']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_incident_without_linked_quantity_does_not_assume_the_entire_issued_quantity_is_missing(): void
    {
        [, $custody] = $this->record('reported');
        IncidentLine::query()->delete();
        $summary = ReturnInspectionSummary::for($custody->fresh());
        $this->assertStringContainsString('affected quantity not recorded', $summary['reason']);
        $this->assertSame([], $summary['rows'][0]['findings']);
        $this->assertSame('4', $summary['rows'][0]['issued']);
        $this->assertSame('Pending Admin review', $summary['decision']);
    }

    public static function recordRoutes(): array
    {
        $cases = [];
        foreach (['borrower', 'officer', 'head'] as $role) {
            foreach (['property', 'late', 'combined', 'reported', 'legacy', 'resolved', 'good'] as $phase) {
                foreach (['requests.show', $role === 'officer' ? 'custody.return.show' : 'custody.show'] as $route) {
                    $cases[$role.' '.$phase.' '.$route] = [$role, $phase, $route];
                }
            }
        }
        return $cases;
    }

    #[DataProvider('recordRoutes')]
    public function test_one_shared_summary_below_the_tracker_for_each_permitted_role_and_record_layout(string $role, string $phase, string $route): void
    {
        [$request, $custody] = $this->record($phase);
        $actor = $role === 'borrower' ? $request->borrower : $this->staff($role);
        $before = $this->snapshot();
        $response = $this->actingAs($actor)->withSession(['active_workspace' => $role === 'borrower' ? 'BORROWER' : 'SPMU'])
            ->get(route($route, $route === 'requests.show' ? $request : $custody))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//*[@data-return-inspection-summary]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-transaction-tracker]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-transaction-tracker]/following::section[@data-return-inspection-summary]')->length);
        $this->assertSame(0, $xpath->query('//details[@data-inspection-details and @open]')->length);
        $this->assertSame(1, $xpath->query('//link[contains(@href,"transaction-record-details.css")]')->length);
        $this->assertSame($before, $this->snapshot(), 'GET presentation must not change stored facts.');
        $other = User::factory()->create();
        $this->actingAs($other)->withSession(['active_workspace' => 'BORROWER'])
            ->get(route($route === 'requests.show' ? 'requests.show' : 'custody.show', $route === 'requests.show' ? $request : $custody))->assertForbidden();
    }

    private function render(CustodyTransaction $custody): string
    {
        return Blade::render('<x-return-inspection-summary :custody="$custody" />', compact('custody'));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        return new DOMXPath($document);
    }

    private function staff(string $role): User
    {
        return User::where('access_classification', $role === 'head' ? AccessClassification::SpmuHead : AccessClassification::SpmuOfficer)->firstOrFail();
    }

    private function record(string $phase): array
    {
        $borrower = User::where('email', 'borrower@spmu.test')->firstOrFail();
        $request = BorrowingRequest::create(['request_no' => 'INSPECTION-'.uniqid(), 'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id, 'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease, 'final_approved_at' => now()->subWeek()]);
        $version = $request->versions()->create(['version_no' => 1, 'purpose_event' => 'Inspection summary fixture', 'location' => 'Fixture venue',
            'schedule_date' => '2026-11-20', 'return_date' => '2026-12-01', 'needed_from' => '2026-11-20 00:00:00',
            'return_due_at' => '2026-12-01 23:59:59', 'created_by_user_id' => $borrower->id]);
        $custody = CustodyTransaction::create(['custody_no' => 'CUS-INSPECT-'.uniqid(), 'request_id' => $request->id,
            'request_version_id' => $version->id, 'borrower_user_id' => $borrower->id,
            'status' => match ($phase) { 'resolved', 'good' => 'CLOSED', 'legacy' => 'INCIDENT_OPEN', 'release' => 'PREPARING_RELEASE', default => 'ON_CUSTODY' },
            'released_at' => $phase === 'release' ? null : now()->subDays(6),
            'closed_at' => in_array($phase, ['resolved', 'good']) ? now() : null,
            'due_at' => '2026-12-01 23:59:59', 'original_due_at' => '2026-12-01 23:59:59']);
        $item = InventoryItem::with('unit')->where('laundry_required', false)->where('borrowable', true)->firstOrFail();
        $requestItem = $version->items()->create(['inventory_item_id' => $item->id, 'description_snapshot' => 'Saved folding chair',
            'unit_snapshot' => $item->unit->unit_name, 'requested_quantity' => 4, 'approved_quantity' => 4, 'use_location' => 'ON_CAMPUS']);
        $allocation = Allocation::create(['request_item_id' => $requestItem->id, 'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at, 'allocated_quantity' => 4, 'released_quantity' => $phase === 'release' ? 0 : 4,
            'restored_quantity' => 0, 'status' => 'ACTIVE', 'allocated_at' => now()->subWeek()]);
        $line = CustodyLine::create(['custody_transaction_id' => $custody->id, 'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id, 'approved_quantity' => 4, 'quantity_to_receive' => 4,
            'actual_released_quantity' => $phase === 'release' ? 0 : 4,
            'returned_quantity' => in_array($phase, ['reported', 'legacy', 'release']) ? 0 : 4, 'release_condition' => 'SERVICEABLE']);
        if (! in_array($phase, ['reported', 'legacy', 'release'])) {
            $return = $this->inspection($custody);
            $conditions = in_array($phase, ['property', 'combined', 'resolved']) ? ['FINE' => 2, 'DAMAGED' => 1, 'MISSING' => 1] : ['FINE' => 4];
            foreach ($conditions as $condition => $quantity) {
                ReturnLine::create(['return_transaction_id' => $return->id, 'custody_line_id' => $line->id,
                    'quantity_received' => $quantity, 'condition_code' => $condition, 'disposition_state' => 'AVAILABLE', 'remarks' => 'Recorded '.$condition.' observation.']);
            }
        }
        if (in_array($phase, ['property', 'combined', 'reported', 'resolved'])) {
            $incident = Incident::create(['incident_no' => 'INC-INSPECT-'.uniqid(), 'custody_transaction_id' => $custody->id,
                'borrower_user_id' => $borrower->id, 'reported_by_user_id' => $this->staff('officer')->id,
                'incident_type' => 'MISSING', 'reported_at' => now()->subHour(), 'status' => $phase === 'resolved' ? 'RESOLVED' : 'F2F_PROCESSING']);
            IncidentLine::create(['incident_id' => $incident->id, 'custody_line_id' => $line->id, 'quantity' => 1, 'observed_condition' => 'MISSING', 'disposition_state' => 'LOST']);
        }
        if (in_array($phase, ['late', 'combined'])) {
            $custody->update(['due_at' => '2026-11-23 23:59:59', 'original_due_at' => '2026-11-23 23:59:59']);
            OverdueCase::create(['custody_transaction_id' => $custody->id, 'borrower_user_id' => $borrower->id,
                'grace_expires_at' => '2026-11-23 23:59:59', 'overdue_started_at' => '2026-11-24 00:00:00',
                'offense_level' => 1, 'rate_snapshot' => 75, 'accrued_amount' => 150, 'status' => 'FOR_HEAD_APPROVAL',
                'actual_return_date' => '2026-11-25', 'return_date_source' => 'RETURN_INSPECTION', 'late_days' => 2]);
        }
        return [$request->fresh(), $custody->fresh()];
    }

    private function inspection(CustodyTransaction $custody, string $status = 'RECORDED'): ReturnTransaction
    {
        return ReturnTransaction::create(['return_no' => 'RT-INSPECT-'.uniqid(), 'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $this->staff('officer')->id, 'return_type' => 'FULL', 'received_at' => now()->subHour(),
            'physical_return_date' => '2026-11-25', 'physical_return_source' => 'AO_RECORDED_PHYSICAL_DATE', 'status' => $status]);
    }

    private function snapshot(): array
    {
        return collect(['borrowing_requests', 'request_versions', 'custody_transactions', 'custody_lines', 'allocations',
            'return_transactions', 'return_lines', 'incidents', 'incident_lines', 'overdue_cases', 'laundry_jobs', 'inventory_items', 'audit_events'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
