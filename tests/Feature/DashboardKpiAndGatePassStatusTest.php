<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GatePass;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\Role;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\BorrowerObligationService;
use App\Services\ProtectedFileService;
use App\Services\UserRoleAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EvidenceFixtures;
use Tests\TestCase;

/**
 * Regression coverage for the dashboard KPI drilldown/color-semantics pass
 * and the Gate-Pass-only-pending borrower status correction.
 *
 * A returned transaction that is only waiting on SPMU to record the
 * accomplished Gate Pass must never present to the borrower as an
 * "Accountability Pending" obligation (My Obligations has no row for it -
 * that was the bug), and KPI cards must drill down to exactly the record
 * subset they counted.
 */
class DashboardKpiAndGatePassStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Carbon::setTestNow(Carbon::create(2026, 9, 11, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function grantDirectoryRole(User $actor): void
    {
        // This class intentionally has no reference seed; provision the actor's
        // canonical role without adding unrelated users or changing KPI counts.
        Role::firstOrCreate(['role_code' => 'SPMU'], ['role_name' => 'Supply and Property Management Unit', 'active' => true]);
        app(UserRoleAssignmentService::class)->synchronize($actor, AccessClassification::SpmuHead);
    }

    public static function staffDashboardRoles(): array
    {
        return ['Admin' => ['SPMU_HEAD', 'Priority Actions'], 'SAO' => ['SPMU_OFFICER', 'Verification Queue']];
    }

    private function htmlXPath(string $html): \DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument;
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

            return new \DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function operationalDocument(CustodyTransaction $custody, string $type, string $status = 'FINAL'): GeneratedDocument
    {
        $file = app(ProtectedFileService::class)->storeBytes(
            EvidenceFixtures::pdfBytes('Synthetic QA '.$type), 'tests/qa-documents',
            $type.'.pdf', 'application/pdf', 'pdf', 'GENERATED_DOCUMENT', $custody->borrower_user_id,
        );

        return GeneratedDocument::create([
            'stored_file_id' => $file->id, 'request_version_id' => $custody->request_version_id,
            'subject_type' => CustodyTransaction::class, 'subject_id' => $custody->id,
            'document_no' => 'QA-'.Str::uuid(), 'document_type' => $type, 'version_no' => 1,
            'sha256' => $file->sha256, 'status' => $status, 'generated_at' => now(),
        ]);
    }

    private function borrower(): User
    {
        return User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly]);
    }

    /**
     * A custody transaction with one line, optionally released/fully
     * returned, enough to satisfy Incident/GatePass foreign keys and to
     * drive requests/show.blade.php's "Forms for Physical Processing" /
     * "Transaction Forms" section.
     */
    private function custody(User $borrower, bool $released, bool $returned): CustodyTransaction
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'DASHFIX'],
            ['category_name' => 'Dashboard Fixture', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        $item = InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => 'Dashboard Fixture Item '.fake()->unique()->numberBetween(1, 99999),
            'total_quantity' => 50,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => true,
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);

        $unit = OrganizationalUnit::query()->firstOrCreate(
            ['unit_code' => 'DASHFIX'],
            ['unit_name' => 'Dashboard Fixture Unit', 'unit_type' => 'OFFICE', 'active' => true]
        );

        $dueAt = now()->addDays(3);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-DASH-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
            'final_approved_at' => now()->subDays(5),
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Dashboard fixture',
            'location' => 'Off-campus venue',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'College of Computer Studies',
            'schedule_date' => now()->subDays(4)->toDateString(),
            'return_date' => $dueAt->toDateString(),
            'needed_from' => now()->subDays(4)->startOfDay(),
            'return_due_at' => $dueAt,
            'off_campus' => true,
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => 1,
            'approved_quantity' => 1,
            'use_location' => 'OFF_CAMPUS',
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => now()->subDays(4)->startOfDay(),
            'period_end' => $dueAt,
            'allocated_quantity' => 1,
            'released_quantity' => $released ? 1 : 0,
            'restored_quantity' => 0,
            'status' => $released ? 'RELEASED' : 'ALLOCATED',
            'allocated_at' => now()->subDays(4),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-DASH-'.fake()->unique()->numberBetween(1000, 999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $returned ? 'OBLIGATION_OPEN' : ($released ? 'ACTIVE' : 'PREPARING_RELEASE'),
            'due_at' => $dueAt,
            'released_at' => $released ? now()->subDays(3) : null,
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => $released ? 1 : 0,
            'returned_quantity' => $returned ? 1 : 0,
        ]);

        return $custody->fresh(['lines']);
    }

    private function unverifiedGatePass(CustodyTransaction $custody): GatePass
    {
        return GatePass::query()->create([
            'custody_transaction_id' => $custody->id,
            'custody_line_id' => $custody->lines->first()->id,
            'bearer_name' => $custody->borrower->full_name ?? 'Test Borrower',
            'destination' => 'Off-campus venue',
            'purpose' => 'Fixture gate pass',
            'status' => 'READY_FOR_PRINTING',
        ]);
    }

    /* ================================================================== */
    /* Gate Pass processing must never present as a borrower obligation */
    /* ================================================================== */

    public function test_gate_pass_only_pending_does_not_count_as_a_borrower_obligation(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: true);
        $this->unverifiedGatePass($custody);

        $this->assertTrue($custody->fresh()->isGatePassOnlyPending());

        $overview = app(BorrowerObligationService::class)->overview($borrower->id);
        $this->assertSame(0, $overview['count'], 'SPMU-internal Gate Pass processing must never count as a borrower obligation.');
    }

    public function test_gate_pass_only_pending_uses_shared_staff_status_on_my_borrowings(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: true);
        $this->unverifiedGatePass($custody);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)->get(route('custody.index'));

        $response->assertOk()->assertSee($custody->custody_no)->assertSee('Accountability Review')
            ->assertSee('data-borrowings-status="ACCOUNTABILITY_REVIEW"', false)
            ->assertDontSee('Accountability Pending')->assertDontSee('View Obligation');
        $this->assertSame('OBLIGATION_OPEN', $custody->fresh()->status);
        $this->assertSame(0, app(BorrowerObligationService::class)->overview($borrower->id)['count']);
    }

    public function test_gate_pass_only_pending_shows_as_info_not_accountability_pending_on_my_requests(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: true);
        $this->unverifiedGatePass($custody);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.show', $custody->request));

        $response->assertOk()
            ->assertSee('Gate Pass Processing')
            ->assertSee('The physical return is complete. SPMU is recording the accomplished Gate Pass.')
            ->assertDontSee('Accountability Pending')
            ->assertDontSee('View My Obligations');
    }

    public function test_gate_pass_only_pending_does_not_appear_in_actions_requiring_attention(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: true);
        $this->unverifiedGatePass($custody);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('No current action is required.');

        $this->assertSame(0, $response->viewData('statistics')['Active Obligations']);
    }

    public function test_genuine_obligation_still_shows_normally_even_with_gate_pass_also_pending(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: true);
        $this->unverifiedGatePass($custody);

        Incident::query()->create([
            'incident_no' => 'INC-DASH-'.fake()->unique()->numberBetween(1000, 999999),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $borrower->id,
            'reported_by_user_id' => $borrower->id,
            'incident_type' => 'DAMAGE',
            'reported_at' => now(),
            'status' => 'OPEN',
        ]);

        $this->assertFalse($custody->fresh()->isGatePassOnlyPending(), 'A genuine incident must take precedence over Gate Pass processing.');

        $overview = app(BorrowerObligationService::class)->overview($borrower->id);
        $this->assertSame(1, $overview['count'], 'The genuine obligation must not be hidden merely because Gate Pass processing is also pending.');

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.show', $custody->request));

        $response->assertOk()->assertSee('View My Obligations');
    }

    /* ================================================================== */
    /* Forms for Physical Processing is lifecycle-aware */
    /* ================================================================== */

    public function test_pre_release_document_access_and_pickup_guidance_follow_existing_records(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: false, returned: false);

        // No documents or schedule exist yet: never claim they were generated.
        $empty = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)->get(route('requests.show', $custody->request));
        $empty->assertOk()->assertSee('Borrowing documents')->assertSee('No current approved document is available.')
            ->assertDontSee('Proceed to SPMU on the scheduled pickup date');

        $start = now()->addDays(5)->startOfDay();
        $due = $start->copy()->addDays(2);
        $custody->request->currentVersion->forceFill([
            'schedule_date' => $start->toDateString(), 'needed_from' => $start,
            'return_date' => $due->toDateString(), 'return_due_at' => $due,
        ])->save();
        $custody->forceFill([
            'due_at' => $due, 'prepared_at' => now(),
            'pickup_scheduled_at' => now(), 'scheduled_release_at' => now()->subMinute(),
            'pickup_expires_at' => now()->addHour(),
        ])->save();
        $slip = $this->operationalDocument($custody, 'BORROWER_SLIP');
        $pass = $this->operationalDocument($custody, 'GATE_PASS');

        $this->get(route('requests.show', $custody->request))->assertOk()
            ->assertSee(route('documents.preview', $slip), false)
            ->assertSee(route('documents.preview', $pass), false);
        $this->get(route('custody.show', $custody))->assertOk()
            ->assertSee('Ready for physical release')
            ->assertSee('Your pickup window is open. Proceed to SPMU for the handover and Borrower Slip.');
        $this->assertNull($custody->fresh()->released_at);
        $this->assertSame('PREPARING_RELEASE', $custody->fresh()->status);
    }

    public function test_post_release_forms_no_longer_show_stale_pickup_instruction(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: false);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.show', $custody->request));

        $response->assertOk()
            ->assertSee('Borrowing documents')
            ->assertDontSee('Proceed to SPMU on the scheduled pickup date')
            ->assertDontSee('Forms for Physical Processing');
    }

    public function test_post_return_forms_retain_document_access_without_pickup_wording(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: true);
        $this->unverifiedGatePass($custody)->forceFill(['status' => 'VERIFIED'])->save();
        $custody->forceFill(['status' => 'CLOSED', 'closed_at' => now()])->save();
        $slip = $this->operationalDocument($custody, 'BORROWER_SLIP');
        $pass = $this->operationalDocument($custody, 'GATE_PASS');
        $invalidated = $this->operationalDocument($custody, 'BORROWER_SLIP', 'INVALIDATED');
        $before = $slip->file->getAttributes();

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)->get(route('requests.show', $custody->request));
        $response->assertOk()->assertSee('Completed')->assertSee('Borrowing documents')
            ->assertSee(route('documents.preview', $slip), false)
            ->assertSee(route('documents.preview', $pass), false)
            ->assertDontSee(route('documents.preview', $invalidated), false)
            ->assertDontSee('Proceed to SPMU on the scheduled pickup date');
        $this->get(route('documents.preview', $slip))->assertOk();
        $this->get(route('documents.view', $slip))->assertOk();
        $this->assertSame('CLOSED', $custody->fresh()->status);
        $this->assertSame($before, $slip->file->fresh()->getAttributes());
    }

    public function test_request_document_links_exclude_another_custody_and_version(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: false);
        $other = $this->custody($this->borrower(), released: true, returned: false);
        $current = $this->operationalDocument($custody, 'BORROWER_SLIP');
        $wrongSubject = $this->operationalDocument($other, 'GATE_PASS');
        // Simulate mismatched historical metadata; matching the version alone
        // must not leak a link belonging to another custody transaction.
        $wrongSubject->forceFill(['request_version_id' => $custody->request_version_id])->save();
        $wrongVersion = $this->operationalDocument($custody, 'GATE_PASS');
        $wrongVersion->forceFill(['request_version_id' => $other->request_version_id])->save();

        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)
            ->get(route('requests.show', $custody->request))->assertOk()
            ->assertSee(route('documents.preview', $current), false)
            ->assertDontSee(route('documents.preview', $wrongSubject), false)
            ->assertDontSee(route('documents.preview', $wrongVersion), false);
        $this->get(route('documents.preview', $wrongSubject))->assertForbidden();
        $this->get(route('documents.view', $wrongSubject))->assertForbidden();
        $this->get(route('documents.preview', $wrongVersion))->assertForbidden();
        $this->get(route('documents.download', $wrongSubject))->assertForbidden();
    }

    public function test_recorded_delivery_status_and_provider_content_are_escaped_and_preserved(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::create(['event_code' => 'QA_HISTORY', 'source_type' => User::class,
            'source_id' => $ictu->id, 'occurred_at' => now()]);
        $recorded = '<b>RAW</b>';
        $provider = '<script>QA-INJECTION</script>';
        $delivery = NotificationDelivery::create(['notification_event_id' => $event->id,
            'recipient_user_id' => $ictu->id, 'channel' => 'SMS', 'delivery_status' => $recorded,
            'provider_response' => $provider, 'attempted_at' => now()]);

        $response = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)
            ->get(route('reports.notifications', ['legacy' => 1]));
        $response->assertOk()->assertSee(e($recorded), false)->assertSee(e($provider), false)
            ->assertDontSee($provider, false)->assertDontSee('Mark as Resolved');
        $dom = $this->htmlXPath($response->getContent());
        $this->assertCount(0, $dom->query('//script[contains(.,"QA-INJECTION")]'));
        $this->assertSame($recorded, $delivery->fresh()->delivery_status);
        $this->assertSame($provider, $delivery->fresh()->provider_response);
        $this->assertNull($delivery->fresh()->resolved_at);
    }

    /* ================================================================== */
    /* KPI drilldowns land on exactly the counted record subset */
    /* ================================================================== */

    public function test_head_active_custodies_kpi_drilldown_matches_exact_count(): void
    {
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $borrower = $this->borrower();

        $active = $this->custody($borrower, released: true, returned: false);
        $this->custody($borrower, released: false, returned: false); // not released - excluded

        $dashboard = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('dashboard'));

        $dashboard->assertOk();
        $expectedCount = $dashboard->viewData('statistics')['Active Custodies'];

        $drilldown = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('requests.index', ['kpi' => 'active_custodies']));

        $drilldown->assertOk()->assertSee($active->request->request_no)->assertSee('Active Custodies');
        $this->assertSame($expectedCount, $drilldown->viewData('requests')->count());
    }
    /**
     * Historical rows written while SMS support existed but no provider was
     * ever configured. Channel=SMS, delivery_status=FAILED, with the exact
     * message the old unconfigured-provider code path used to write. SMS
     * has no dispatch path in the current codebase at all.
     */
    private function unconfiguredSmsFailure(NotificationEvent $event, User $recipient, ?Carbon $attemptedAt = null): NotificationDelivery
    {
        return NotificationDelivery::query()->create([
            'notification_event_id' => $event->id,
            'recipient_user_id' => $recipient->id,
            'channel' => 'SMS',
            'delivery_status' => 'FAILED',
            'provider_response' => 'SMS provider/webhook is not configured; system and email delivery remain available.',
            'attempted_at' => $attemptedAt ?? now()->subDays(30),
        ]);
    }

    private function genuineEmailFailure(NotificationEvent $event, User $recipient): NotificationDelivery
    {
        return NotificationDelivery::query()->create([
            'notification_event_id' => $event->id,
            'recipient_user_id' => $recipient->id,
            'channel' => 'EMAIL',
            'delivery_status' => 'FAILED',
            'provider_response' => 'Connection refused by the configured mail transport.',
            'attempted_at' => now(),
        ]);
    }

    public function test_sms_not_configured_does_not_create_a_misleading_ordinary_failure(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $this->unconfiguredSmsFailure($event, $ictu);

        // SMS is not a current operational channel, so this row is not on
        // the normal Delivery Records view at all - it only ever surfaces
        // in the explicit legacy audit view.
        $log = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->get(route('reports.notifications', ['legacy' => 1]));

        $log->assertOk();
        $log->assertSee('SMS Not Configured');
        $log->assertDontSee('Delivery attempt failed. Select View Details');
    }

    public function test_normal_delivery_records_view_excludes_sms_but_legacy_view_preserves_it(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $sms = $this->unconfiguredSmsFailure($event, $ictu);

        $normal = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)->get(route('reports.notifications'));
        $normal->assertOk();
        $this->assertFalse($normal->viewData('deliveries')->contains(fn ($delivery) => $delivery->is($sms)));
        $normal->assertDontSee('SMS Not Configured');
        $normal->assertDontSee('data-delivery-channel="SMS"');

        $legacy = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->get(route('reports.notifications', ['legacy' => 1]));
        $legacy->assertOk();
        $this->assertTrue($legacy->viewData('deliveries')->contains(fn ($delivery) => $delivery->is($sms)));
        $legacy->assertSee('SMS Not Configured');
        // Legacy audit context preserves original channel, raw status,
        // provider response, and timestamp.
        $legacy->assertSee($sms->channel);
        $legacy->assertSee('Recorded Status');
        $legacy->assertSee($sms->provider_response);
    }
    private function resolvedEmailFailure(NotificationEvent $event, User $recipient, User $resolver): NotificationDelivery
    {
        return NotificationDelivery::query()->create([
            'notification_event_id' => $event->id,
            'recipient_user_id' => $recipient->id,
            'channel' => 'EMAIL',
            'delivery_status' => 'FAILED',
            'provider_response' => 'SMTP 550: mailbox unavailable.',
            'attempted_at' => now()->subDays(3),
            'resolved_at' => now(),
            'resolved_by_user_id' => $resolver->id,
            'resolution_note' => 'Recipient address corrected and re-sent manually.',
        ]);
    }
    public function test_resolved_failure_still_exists_in_delivery_records_as_historical_failure_with_original_error_preserved(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $resolved = $this->resolvedEmailFailure($event, $ictu, $ictu);

        $log = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)->get(route('reports.notifications'));

        $log->assertOk();
        $log->assertSee('Historical Delivery Failed');
        // The original provider error is never overwritten by resolution.
        $log->assertSee('SMTP 550: mailbox unavailable.');
        $log->assertSee('Recipient address corrected and re-sent manually.');
        $this->assertSame('FAILED', $resolved->fresh()->delivery_status);
    }

    public function test_sms_not_configured_remains_separate_from_historical_failure(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $sms = $this->unconfiguredSmsFailure($event, $ictu);
        $historical = $this->resolvedEmailFailure($event, $ictu, $ictu);

        // Now on entirely separate pages (normal vs. legacy), so "remains
        // separate" means neither view mixes the other's category in.
        $normal = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)->get(route('reports.notifications'));
        $normal->assertOk();
        $normal->assertSee('Historical Delivery Failed');
        $normal->assertDontSee('SMS Not Configured');
        $this->assertFalse($normal->viewData('deliveries')->contains(fn ($delivery) => $delivery->is($sms)));

        $legacy = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->get(route('reports.notifications', ['legacy' => 1]));
        $legacy->assertOk();
        $legacy->assertSee('SMS Not Configured');
        $this->assertFalse($legacy->viewData('deliveries')->contains(fn ($delivery) => $delivery->is($historical)));
    }

    public function test_legacy_sms_not_configured_and_skipped_statuses_group_with_failed_under_one_category(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        // The three legacy representations SMS ever used for "no provider
        // was ever configured", before SMS support was removed entirely.
        $failed = $this->unconfiguredSmsFailure($event, $ictu);
        $notConfigured = NotificationDelivery::query()->create([
            'notification_event_id' => $event->id,
            'recipient_user_id' => $ictu->id,
            'channel' => 'SMS',
            'delivery_status' => 'NOT_CONFIGURED',
            'provider_response' => 'SMS is disabled by system configuration.',
            'attempted_at' => now()->subDays(10),
        ]);
        $skipped = NotificationDelivery::query()->create([
            'notification_event_id' => $event->id,
            'recipient_user_id' => $ictu->id,
            'channel' => 'SMS',
            'delivery_status' => 'SKIPPED',
            'provider_response' => 'SMS provider credentials are not configured.',
            'attempted_at' => now()->subDays(5),
        ]);

        // These legacy statuses only ever surface in the explicit legacy
        // audit view now - the normal view excludes channel=SMS entirely.
        $log = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->get(route('reports.notifications', ['legacy' => 1]));
        $log->assertOk();
        $content = $log->getContent();

        // All three render under the same presentational category/filter bucket.
        $this->assertSame(3, substr_count($content, 'data-delivery-status="SMS_NOT_CONFIGURED"'));
        // The filter dropdown offers exactly one "SMS Not Configured" option,
        // not three separate raw-status options.
        $this->assertSame(1, $this->htmlXPath($content)->query('//select[@id="delivery-status-filter"]/option[@value="SMS_NOT_CONFIGURED" and normalize-space()="SMS Not Configured"]')->length);

        // The literal, raw delivery_status remains visible per row in the
        // audit details, distinct from the shared presentational label.
        $log->assertSee('Recorded Status');
        $dom = $this->htmlXPath($content);
        foreach ([$failed, $notConfigured, $skipped] as $delivery) {
            $status = $dom->query('//tr[@id="delivery-details-'.$delivery->id.'"]//dt[normalize-space()="Recorded Status"]/following-sibling::dd');
            $this->assertCount(1, $status);
            $this->assertSame($delivery->delivery_status, trim($status->item(0)->textContent));
        }

        // Nothing in the database was rewritten.
        $this->assertSame('FAILED', $failed->fresh()->delivery_status);
        $this->assertSame('NOT_CONFIGURED', $notConfigured->fresh()->delivery_status);
        $this->assertSame('SKIPPED', $skipped->fresh()->delivery_status);
    }

    public function test_sent_rows_remain_unchanged(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $sent = NotificationDelivery::query()->create([
            'notification_event_id' => $event->id,
            'recipient_user_id' => $ictu->id,
            'channel' => 'EMAIL',
            'delivery_status' => 'SENT',
            'attempted_at' => now(),
        ]);

        $log = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)->get(route('reports.notifications'));

        $log->assertOk();
        $sent->refresh();
        $this->assertSame('SENT', $sent->delivery_status);
        $this->assertNull($sent->resolved_at);
        $this->assertTrue($log->viewData('deliveries')->contains(fn ($delivery) => $delivery->is($sent) && $delivery->delivery_status === 'SENT'));
    }

    public function test_only_the_spmu_head_can_resolve_a_delivery_failure(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $officer = User::factory()->create(['access_classification' => AccessClassification::SpmuOfficer]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $failure = $this->genuineEmailFailure($event, $ictu);

        $blocked = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('reports.notifications.resolve', $failure), ['resolution_note' => 'Attempted resolution by a non-Head user.']);

        $blocked->assertForbidden();
        $this->assertNull($failure->fresh()->resolved_at);

        $allowed = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->post(route('reports.notifications.resolve', $failure), ['resolution_note' => 'Mail transport credentials rotated; verified next attempt succeeds.']);

        $allowed->assertRedirect();
        $failure->refresh();
        $this->assertNotNull($failure->resolved_at);
        $this->assertSame($ictu->id, $failure->resolved_by_user_id);
        $this->assertSame('Mail transport credentials rotated; verified next attempt succeeds.', $failure->resolution_note);
        $this->assertSame('FAILED', $failure->delivery_status);
    }

    public function test_resolve_action_rejects_sms_rows_and_already_resolved_rows(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $sms = $this->unconfiguredSmsFailure($event, $ictu);
        $alreadyResolved = $this->resolvedEmailFailure($event, $ictu, $ictu);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->post(route('reports.notifications.resolve', $sms), ['resolution_note' => 'Should not be allowed.'])
            ->assertNotFound();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->post(route('reports.notifications.resolve', $alreadyResolved), ['resolution_note' => 'Should not be allowed either.'])
            ->assertNotFound();
    }

    public function test_historical_failure_row_never_renders_resolution_form_or_button(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $this->resolvedEmailFailure($event, $ictu, $ictu);

        $log = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)->get(route('reports.notifications'));

        $log->assertOk();
        $log->assertSee('Historical Delivery Failed');
        $log->assertDontSee('Mark as Resolved');
        $log->assertDontSee('class="notification-delivery-select-checkbox"');
    }

    public function test_sms_not_configured_row_never_renders_resolution_form_or_button(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $this->unconfiguredSmsFailure($event, $ictu);

        // Legacy/audit-only view - resolution is never offered here either.
        $log = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->get(route('reports.notifications', ['legacy' => 1]));

        $log->assertOk();
        $log->assertSee('SMS Not Configured');
        $log->assertDontSee('Mark as Resolved');
        $log->assertDontSee('class="notification-delivery-select-checkbox"');
    }

    public function test_resolution_form_appears_only_for_unresolved_current_failure_when_all_three_categories_present(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $this->unconfiguredSmsFailure($event, $ictu);
        $this->resolvedEmailFailure($event, $ictu, $ictu);
        $this->genuineEmailFailure($event, $ictu);

        $log = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)->get(route('reports.notifications'));

        $log->assertOk();
        // Exactly one resolution form should be present: the unresolved
        // current failure. The historical failure contributes none, and
        // the SMS row doesn't even appear on this (normal, non-legacy) view.
        $this->assertSame(1, substr_count($log->getContent(), 'Mark as Resolved'));
    }

    public function test_bulk_resolve_writes_the_same_resolution_to_all_selected_current_failures(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $first = $this->genuineEmailFailure($event, $ictu);
        $second = $this->genuineEmailFailure($event, $ictu);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->post(route('reports.notifications.resolveBulk'), [
                'delivery_ids' => [$first->id, $second->id],
                'resolution_note' => 'SMTP/DNS connectivity was verified operational.',
            ]);

        $response->assertRedirect();
        $first->refresh();
        $second->refresh();

        $this->assertNotNull($first->resolved_at);
        $this->assertNotNull($second->resolved_at);
        $this->assertTrue($first->resolved_at->equalTo($second->resolved_at));
        $this->assertSame($ictu->id, $first->resolved_by_user_id);
        $this->assertSame($ictu->id, $second->resolved_by_user_id);
        $this->assertSame('SMTP/DNS connectivity was verified operational.', $first->resolution_note);
        $this->assertSame('SMTP/DNS connectivity was verified operational.', $second->resolution_note);
        $this->assertSame('FAILED', $first->delivery_status);
        $this->assertSame('FAILED', $second->delivery_status);
    }

    public function test_bulk_resolve_preserves_the_original_provider_error(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $failure = $this->genuineEmailFailure($event, $ictu);
        $originalProviderResponse = $failure->provider_response;

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->post(route('reports.notifications.resolveBulk'), [
                'delivery_ids' => [$failure->id],
                'resolution_note' => 'Root cause identified and corrected.',
            ]);

        $failure->refresh();
        $this->assertSame($originalProviderResponse, $failure->provider_response);
        $this->assertSame('FAILED', $failure->delivery_status);
    }

    public function test_only_the_spmu_head_can_bulk_resolve_delivery_failures(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $officer = User::factory()->create(['access_classification' => AccessClassification::SpmuOfficer]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $failure = $this->genuineEmailFailure($event, $ictu);

        $blocked = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('reports.notifications.resolveBulk'), [
                'delivery_ids' => [$failure->id],
                'resolution_note' => 'Attempted by a non-ICTU user.',
            ]);

        $blocked->assertForbidden();
        $this->assertNull($failure->fresh()->resolved_at);
    }

    public function test_bulk_resolve_excludes_non_actionable_rows_from_a_mixed_selection(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $current = $this->genuineEmailFailure($event, $ictu);
        $historical = $this->resolvedEmailFailure($event, $ictu, $ictu);
        $sms = $this->unconfiguredSmsFailure($event, $ictu);
        $sent = NotificationDelivery::query()->create([
            'notification_event_id' => $event->id,
            'recipient_user_id' => $ictu->id,
            'channel' => 'EMAIL',
            'delivery_status' => 'SENT',
            'attempted_at' => now(),
        ]);

        $originalHistoricalResolvedAt = $historical->resolved_at;
        $originalHistoricalNote = $historical->resolution_note;

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->post(route('reports.notifications.resolveBulk'), [
                'delivery_ids' => [$current->id, $historical->id, $sms->id, $sent->id],
                'resolution_note' => 'Bulk note intended only for the eligible row.',
            ]);

        $response->assertRedirect();

        $current->refresh();
        $historical->refresh();
        $sms->refresh();
        $sent->refresh();

        $this->assertNotNull($current->resolved_at);
        $this->assertSame('Bulk note intended only for the eligible row.', $current->resolution_note);

        // The already-resolved row keeps its own prior resolution untouched.
        $this->assertTrue($historical->resolved_at->equalTo($originalHistoricalResolvedAt));
        $this->assertSame($originalHistoricalNote, $historical->resolution_note);

        $this->assertNull($sms->resolved_at);
        $this->assertNull($sent->resolved_at);
        $this->assertSame('SENT', $sent->delivery_status);
    }

    public function test_bulk_resolve_rejects_a_batch_with_no_eligible_rows(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $sms = $this->unconfiguredSmsFailure($event, $ictu);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->post(route('reports.notifications.resolveBulk'), [
                'delivery_ids' => [$sms->id],
                'resolution_note' => 'Should not apply to anything.',
            ]);

        $response->assertSessionHasErrors('delivery_ids');
        $this->assertNull($sms->fresh()->resolved_at);
    }

    public function test_provider_error_details_preserve_dns_and_smtp_errors_without_automatic_repair_claims(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::create(['event_code' => 'TEST_EVENT', 'source_type' => User::class,
            'source_id' => $ictu->id, 'occurred_at' => now()]);
        $dns = NotificationDelivery::create(['notification_event_id' => $event->id, 'recipient_user_id' => $ictu->id,
            'channel' => 'EMAIL', 'delivery_status' => 'FAILED',
            'provider_response' => 'getaddrinfo for smtp.example.test failed: Name or service not known', 'attempted_at' => now()]);
        $smtp = $this->genuineEmailFailure($event, $ictu);

        $response = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)
            ->get(route('reports.notifications'));
        $response->assertOk()->assertSee($dns->provider_response)->assertSee($smtp->provider_response)
            ->assertDontSee('connectivity was verified operational.');
        $dom = $this->htmlXPath($response->getContent());
        foreach ([$dns, $smtp] as $failure) {
            $forms = $dom->query('//tr[@id="delivery-details-'.$failure->id.'"]//form[@action="'.route('reports.notifications.resolve', $failure).'"]');
            $this->assertCount(1, $forms);
            $notes = $dom->query('.//textarea[@name="resolution_note"]', $forms->item(0));
            $this->assertCount(1, $notes);
            $this->assertSame('', trim($notes->item(0)->textContent));
            $this->assertSame('FAILED', $failure->fresh()->delivery_status);
            $this->assertNull($failure->fresh()->resolved_at);
        }
    }

    public function test_individual_resolution_controls_only_render_for_current_failures(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $this->grantDirectoryRole($ictu);
        $event = NotificationEvent::create(['event_code' => 'TEST_EVENT', 'source_type' => User::class,
            'source_id' => $ictu->id, 'occurred_at' => now()]);
        $current = $this->genuineEmailFailure($event, $ictu);
        $historical = $this->resolvedEmailFailure($event, $ictu, $ictu);
        $sms = $this->unconfiguredSmsFailure($event, $ictu);
        $sent = NotificationDelivery::create(['notification_event_id' => $event->id, 'recipient_user_id' => $ictu->id,
            'channel' => 'EMAIL', 'delivery_status' => 'SENT', 'attempted_at' => now()]);

        $response = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)->get(route('reports.notifications'));
        $response->assertOk()->assertSee(route('reports.notifications.resolve', $current), false)
            ->assertDontSee(route('reports.notifications.resolve', $historical), false)
            ->assertDontSee(route('reports.notifications.resolve', $sms), false)
            ->assertDontSee(route('reports.notifications.resolve', $sent), false);
        $dom = $this->htmlXPath($response->getContent());
        $this->assertCount(1, $dom->query('//textarea[@name="resolution_note"]'));
        $this->assertCount(0, $dom->query('//input[@name="delivery_ids[]"]'));
        $this->get(route('reports.notifications', ['legacy' => 1]))->assertOk()->assertDontSee('Mark as Resolved');
        $officer = User::factory()->create(['access_classification' => AccessClassification::SpmuOfficer]);
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($officer)->get(route('reports.notifications'))
            ->assertForbidden();
        $this->assertNull($current->fresh()->resolved_at);
        $this->assertNull($sms->fresh()->resolved_at);
        $this->assertNull($sent->fresh()->resolved_at);
        $this->assertNotNull($historical->fresh()->resolved_at);
    }
    /* ================================================================== */
    /* Canonical KPI color/tone semantics */
    /* ================================================================== */

    /** The tone class applied to the KPI card `<a>` whose aria-label starts with $label. */
    private function kpiCardTone(string $content, string $label): ?string
    {
        preg_match(
            '/<a\s+class="([^"]*)"\s+href="[^"]*"\s+aria-label="'.preg_quote($label, '/').':/s',
            $content,
            $tagMatch
        );

        if (empty($tagMatch)) {
            return null;
        }

        preg_match('/kpi-accent-(\w+)/', $tagMatch[1], $toneMatch);

        return $toneMatch[1] ?? null;
    }

    public function test_returns_due_kpi_is_never_danger_toned(): void
    {
        $borrower = $this->borrower();

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('dashboard'));

        $response->assertOk();
        $tone = $this->kpiCardTone($response->getContent(), 'Returns Due');
        $this->assertNotNull($tone, 'Expected to find the Returns Due KPI card markup.');
        $this->assertNotSame('danger', $tone, 'Returns Due must not use the danger/red tone merely because it is due.');
    }
    public function test_gate_pass_processing_status_badge_uses_info_tone(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: true);
        $this->unverifiedGatePass($custody);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('custody.show', $custody));

        $response->assertOk();
        preg_match('/<span([^>]*)>\s*Gate Pass Processing\s*<\/span>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'Expected to find the Gate Pass Processing status badge.');
        $this->assertStringContainsString('status-info', $matches[1], 'SPMU-internal Gate Pass processing must use info/blue, not warning/amber, when the borrower has nothing to do.');
        $this->assertStringNotContainsString('status-warning', $matches[1]);
    }

    public function test_dashboard_filtered_drilldown_uses_compact_universal_filter_banner(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $this->grantDirectoryRole($ictu);
        User::factory()->create(['access_classification' => AccessClassification::IctuMaintainer, 'account_status' => 'ACTIVE']);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($ictu)
            ->get(route('administration.users.index', ['status' => 'active']));

        $response->assertOk();
        $response->assertSee('filter-context-banner', false);
        $response->assertSee('Filtered by:');
        $response->assertSee('Show all');
        // The old oversized colored alert pattern must not remain.
        $response->assertDontSee('from your dashboard.');
    }

    public function test_empty_dashboard_sections_use_the_shared_empty_state_pattern(): void
    {
        $borrower = $this->borrower();

        $response = $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('empty-state-panel', false);
    }

    public function test_head_dashboard_panel_no_longer_duplicates_the_active_custodies_kpi_title(): void
    {
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $borrower = $this->borrower();
        // Give the Custody Watch panel real content to render its title against.
        $custody = $this->custody($borrower, released: true, returned: false);
        $custody->forceFill(['due_at' => now()->addDays(2)])->save();

        $response = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($head)->get(route('dashboard'));

        $response->assertOk();
        $content = $response->getContent();

        // The KPI card keeps its exact label...
        $this->assertStringContainsString('<span class="kpi-label">Active Custodies</span>', $content);
        // ...but the lower operational panel is now distinctly named, so
        // the two no longer read as the same list.
        $this->assertStringContainsString('<h2>Custody Watch</h2>', $content);
        $this->assertStringNotContainsString('<h2>Active Custodies</h2>', $content, 'The panel title must not duplicate the KPI card\'s exact label.');
    }

    public function test_head_dashboard_priority_actions_panel_has_a_view_all_link_like_its_sibling_panel(): void
    {
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: false);
        $custody->forceFill(['due_at' => now()->addDays(2)])->save();

        $response = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($head)->get(route('dashboard'));

        $response->assertOk();
        $content = $response->getContent();

        // Both lower panels are the same paired component, so both must
        // offer the same "View all" affordance - not just Custody Watch.
        $this->assertSame(2, substr_count($content, 'class="dashboard-view-all"'), 'Both lower panels should offer a View all link.');
        $this->assertStringContainsString('href="'.route('approvals.index').'"', $content);
        $this->assertStringContainsString('href="'.route('requests.index').'"', $content);
        $this->assertStringNotContainsString('href="'.route('custody.index').'"', $content);
    }

    public function test_action_officer_dashboard_priority_actions_panel_has_a_view_all_link_like_its_sibling_panel(): void
    {
        $officer = User::factory()->create(['access_classification' => AccessClassification::SpmuOfficer]);
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, released: true, returned: false);
        $custody->forceFill(['due_at' => now()->addDays(2)])->save();

        $response = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($officer)->get(route('dashboard'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertSame(2, substr_count($content, 'class="dashboard-view-all"'), 'Both lower panels should offer a View all link.');
        $this->assertStringContainsString('href="'.route('verifications.index').'"', $content);
        $this->assertStringContainsString('href="'.route('custody.index').'"', $content);
    }

    #[DataProvider('staffDashboardRoles')]
    public function test_staff_dashboard_retains_zip_paired_panels_and_responsive_collapse(string $classification, string $actionTitle): void
    {
        $staff = User::factory()->create(['access_classification' => AccessClassification::from($classification)]);
        $custody = $this->custody($this->borrower(), released: true, returned: false);
        $custody->forceFill(['due_at' => now()->addDays(2)])->save();

        $response = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($staff)->get(route('dashboard'));
        $response->assertOk()->assertSee($actionTitle)->assertSee($classification === 'SPMU_HEAD' ? 'Custody Watch' : 'Upcoming Operations');
        $dom = $this->htmlXPath($response->getContent());
        $panels = $dom->query('//section[@id="dashboard-actions" and contains(@class,"dashboard-two-panel")]');
        $this->assertCount(1, $panels);
        $this->assertCount(2, $dom->query('./article', $panels->item(0)));
        $this->assertMatchesRegularExpression('/\.dashboard-balanced-grid\.dashboard-two-panel\s*\{\s*grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/', $response->getContent());
        $this->assertMatchesRegularExpression('/@media\s*\(max-width:980px\)\s*\{[^}]*\}\s*\.dashboard-balanced-grid\.dashboard-two-panel\s*\{\s*grid-template-columns:minmax\(0,1fr\)/', $response->getContent());
    }

    public function test_normal_delivery_records_channel_filter_never_offers_sms(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'TEST_EVENT',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $this->unconfiguredSmsFailure($event, $ictu);
        $this->genuineEmailFailure($event, $ictu);

        $response = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)->get(route('reports.notifications'));

        $response->assertOk();
        $response->assertDontSee('<option value="SMS">', false);
        $response->assertSee('<option value="EMAIL">', false);
    }

    public function test_notification_event_and_delivery_status_remain_semantically_separate(): void
    {
        $ictu = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
        $event = NotificationEvent::query()->create([
            'event_code' => 'PICKUP_WINDOW_EXPIRED',
            'source_type' => User::class,
            'source_id' => $ictu->id,
            'occurred_at' => now(),
        ]);

        $this->genuineEmailFailure($event, $ictu);

        $response = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($ictu)->get(route('reports.notifications'));

        $response->assertOk();
        // The event name is a recorded fact; the delivery outcome is a
        // separate, delivery-specific label - never a bare "Failed" that
        // could read as "the event itself failed."
        $response->assertSee('Pickup Window Expired');
        $response->assertSee('Delivery Failed');
    }
}
