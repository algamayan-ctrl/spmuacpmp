<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\User;
use App\Services\OperationalCalendarService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the AO Release page redesign: the numbered
 * "Release Process" step container is replaced by standalone cards (tracker,
 * schedule/requirements, Approved Items with merged preparation, Release
 * Documents, Physical Release), without changing any release workflow logic,
 * routes, permissions, validation, or document generation.
 */
class CustodyReleasePageRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_on_campus_release_tracker_keeps_ao_visible_as_not_required(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();
        $response->assertSee('Action Officer Verification')->assertSee('Not required');
        $response->assertDontSee('Request Prepared');
        $this->assertTrackerLayout($response->getContent(), 7);
    }

    public function test_off_campus_release_tracker_includes_ao_verification(): void
    {
        $custody = $this->preparingCustody(offCampus: true);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();
        $response->assertSee('Action Officer Verification');
        $response->assertDontSee('Request Prepared');
        $this->assertTrackerLayout($response->getContent(), 7);
    }

    public function test_release_tracker_stage_is_labelled_release_processing(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();
        $response->assertSee('Release Processing');
    }

    public function test_release_overview_combines_details_and_documents_into_one_compact_two_column_row(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();
        $response->assertSee('Borrowing Details', false);
        $response->assertSee('Release Documents');
        $response->assertSeeInOrder(['Purpose', 'Date Needed', 'Return Date', 'Use Location', 'Release Documents']);
    }

    public function test_the_numbered_release_process_container_and_its_step_chrome_are_gone(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();
        // "Release Process" is checked as a whole word/heading, not a bare
        // substring, since it is otherwise also a prefix of the (correct,
        // still-present) "Release Processing" tracker stage label.
        $response->assertDontSee('>Release Process<', false);
        $response->assertDontSee('Physically check each approved item before release');
        $response->assertDontSee('Complete pickup scheduling and item preparation before recording physical handover');
    }

    public function test_item_preparation_now_occurs_inside_approved_items(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();
        $response->assertSeeInOrder(['Approved Items', 'Preparation', 'Confirm Items Prepared']);
        $response->assertDontSee('<h3>Item Preparation</h3>', false);
    }

    public function test_report_inventory_discrepancy_is_absent_from_release_ui(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();
        $response->assertDontSee('Report Inventory Discrepancy');
        $response->assertDontSee('Submit Discrepancy Report');
        $response->assertDontSee('Request Inventory Review');

        // No discrepancy modal/form or its submission route may be present
        // anywhere in the rendered page, confirmation-only per the AO Release
        // UI's final decision: the discrepancy backend stays intact, but this
        // page never offers a way to invoke it.
        $response->assertDontSee('data-item-preparation-form', false);
        $response->assertDontSee('release-preparation-report-panel', false);
        $response->assertDontSee(route('custody.report-preparation-issue', $custody), false);
    }

    public function test_historical_preparation_issue_status_is_preserved_without_a_discrepancy_action(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $line = $custody->lines->first();

        // Created directly through the still-functional backend service (not
        // the removed UI form) to represent a discrepancy that already exists
        // on this transaction - e.g. reported before this UI change shipped,
        // or recorded through another legitimate entry point.
        app(\App\Services\CustodyService::class)->reportPreparationIssue(
            $custody,
            $officer,
            $line->id,
            'ITEM_NOT_READY',
            'Legacy/historical discrepancy record for regression coverage.'
        );

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();

        // The read-only status label reflecting the existing record is
        // backend/historical compatibility, not an action - it must remain.
        $response->assertSee('Inventory Review Required');

        // No action to report a new discrepancy or resolve this one is ever
        // offered from the AO Release page itself.
        $response->assertDontSee('Report Inventory Discrepancy');
        $response->assertDontSee('Submit Discrepancy Report');
        $response->assertDontSee('Request Inventory Review');
        $response->assertDontSee(route('custody.report-preparation-issue', $custody), false);
        $response->assertDontSee('resolve-preparation-issue', false);
    }

    public function test_confirm_items_prepared_still_performs_the_same_preparation_transition(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        // prepare() requires the approved Borrower Slip to already exist
        // (CustodyService::validateApprovedReleaseDocuments) - unchanged,
        // pre-existing business rule, reproduced here rather than relaxed.
        \Illuminate\Support\Facades\Storage::fake('local');
        $bytes = \Tests\Support\EvidenceFixtures::pdfBytes('Synthetic approved Borrower Slip');
        $path = 'tests/release/'.$custody->id.'/borrower-slip.pdf';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $bytes);
        $file = \App\Models\StoredFile::query()->create([
            'disk' => 'local', 'storage_path' => $path,
            'original_name' => 'borrower-slip.pdf', 'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'classification' => 'CONTROLLED_DOCUMENT',
        ]);
        GeneratedDocument::query()->create([
            'stored_file_id' => $file->id,
            'sha256' => $file->sha256,
            'request_version_id' => $custody->request_version_id,
            'subject_type' => CustodyTransaction::class,
            'subject_id' => $custody->id,
            'document_no' => 'DOC-'.$custody->custody_no,
            'document_type' => 'BORROWER_SLIP',
            'status' => 'FINAL',
            'generated_at' => now(),
        ]);

        $before = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));
        $before->assertOk();
        $before->assertSee('Pending');

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('custody.prepare', $custody))
            ->assertRedirect()->assertSessionHasNoErrors();

        $custody->refresh();
        $this->assertNotNull($custody->prepared_at);

        $after = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));
        $after->assertOk();
        $after->assertSee('Prepared');
        // The action is no longer offered once preparation is complete.
        $after->assertDontSee('Confirm Items Prepared');
    }

    public function test_applicable_release_documents_render_and_irrelevant_ones_are_simply_absent(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();
        $response->assertSee('Release Documents');

        // "Not required" legitimately still appears in the combined
        // Borrowing & Release Details card, so isolate the Release Documents
        // card itself. It must not fabricate rows for documents that were not
        // generated for this transaction.
        $content = $response->getContent();
        $documentsCardStart = strpos($content, '<article class="card release-documents-card" id="release-actions">');
        $documentsCardEnd = strpos($content, '</article>', $documentsCardStart);
        $this->assertNotFalse($documentsCardStart);
        $this->assertNotFalse($documentsCardEnd);
        $documentsCard = substr($content, $documentsCardStart, $documentsCardEnd - $documentsCardStart);

        $this->assertStringNotContainsString('Not required', $documentsCard);
        $this->assertStringContainsString('No operational form generated yet.', $documentsCard);
    }

    public function test_physical_release_stays_blocked_until_preparation_and_pickup_prerequisites_are_complete(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();
        $content = $response->getContent();

        $response->assertSee('Release Confirmation')
            ->assertSee('Confirm that the items are ready before proceeding.')
            ->assertSee('I confirm that the borrower has received the items and the required forms have been signed.')
            ->assertSee('Confirm Release');
        $dom = new \DOMDocument;
        @$dom->loadHTML($content);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " release-confirmation-fields ") and not(@hidden)]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="physical-signatures-confirmed"]/ancestor::*[@hidden]')->length);
        $this->assertSame(1, $xpath->query('//textarea[@name="remarks" and @disabled]')->length);
        $this->assertMatchesRegularExpression(
            '/id="physical-signatures-confirmed"[^>]*disabled/',
            $content,
            'The handover checkbox must stay disabled before preparation is confirmed.'
        );
        $this->assertMatchesRegularExpression(
            '/id="confirm-physical-release-button"[^>]*disabled/',
            $content,
            'The physical release button must start disabled.'
        );
    }

    public function test_release_submission_never_shows_a_transient_processing_label(): void
    {
        $custody = $this->preparingCustody(offCampus: false);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.release.show', $custody));

        $response->assertOk();
        $response->assertDontSee('Processing...');
    }

    private function assertTrackerLayout(string $html, int $expectedSteps): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $rail = $xpath->query('//ol[contains(concat(" ", normalize-space(@class), " "), " request-tracker__rail ")]')->item(0);

        $this->assertNotNull($rail);
        $this->assertSame($expectedSteps, $xpath->query('./li', $rail)->length);
        $this->assertStringContainsString('--request-tracker-step-count: '.$expectedSteps, $rail->getAttribute('style'));
        $labels = $xpath->query('.//*[@data-workflow-title]', $rail);
        $this->assertSame('Submitted', $labels->item(0)->getAttribute('data-workflow-title'));
        $this->assertSame('Completed', $labels->item($expectedSteps - 1)->getAttribute('data-workflow-title'));
        $current = $xpath->query('./li[@aria-current="step"]//*[@data-workflow-title]', $rail);
        $this->assertSame('Release Processing', $current->item(0)?->getAttribute('data-workflow-title'));
    }

    private function preparingCustody(bool $offCampus): CustodyTransaction
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);

        $item = InventoryItem::query()
            ->with('unit')
            ->where('active', true)
            ->where('borrowable', true)
            ->where('off_campus_allowed', $offCampus)
            ->firstOrFail();

        $calendar = app(OperationalCalendarService::class);
        $scheduleDate = now()->addWeek()->startOfDay();
        $pickupWindow = $calendar->automaticPickupWindowBefore($scheduleDate, now());

        $this->assertNotNull(
            $pickupWindow,
            'The seeded Operational Calendar must provide the system-generated pickup window used by an approved request.'
        );

        $returnDate = now()->addDays(3)->startOfDay();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-RELEASE-REDESIGN-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Release page redesign test',
            'event_details' => 'Current workflow operational custody authorization.',
            'location' => $offCampus ? 'Off-campus venue' : 'CSPC Campus',
            'schedule_date' => $scheduleDate->toDateString(),
            'return_date' => $returnDate->toDateString(),
            'needed_from' => $scheduleDate,
            'return_due_at' => $returnDate->copy()->endOfDay(),
            'off_campus' => $offCampus,
            'created_by_user_id' => $borrower->id,
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 1,
            'approved_quantity' => 1,
            'use_location' => $offCampus ? 'OFF_CAMPUS' : 'ON_CAMPUS',
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at,
            'allocated_quantity' => 1,
            'released_quantity' => 0,
            'restored_quantity' => 0,
            'status' => 'ACTIVE',
            'allocated_at' => now(),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-RELEASE-REDESIGN-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'PREPARING_RELEASE',
            'scheduled_release_at' => $pickupWindow['start'],
            'pickup_expires_at' => $pickupWindow['end'],
            'pickup_scheduled_at' => now(),
            'due_at' => $returnDate->copy()->endOfDay(),
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => 0,
            'returned_quantity' => 0,
            'item_status' => 'RESERVED_FOR_PICKUP',
            'compliance_status' => 'PENDING',
        ]);

        return $custody->fresh();
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }
}
