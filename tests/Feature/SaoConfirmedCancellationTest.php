<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\NotificationEvent;
use App\Models\RequestCancellation;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CustodyService;
use App\Services\RequestWorkflowService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ActualExternalApprovalFlow;
use Tests\Support\EvidenceFixtures;
use Tests\TestCase;

class SaoConfirmedCancellationTest extends TestCase
{
    use ActualExternalApprovalFlow, RefreshDatabase;

    #[DataProvider('cancelledTrackerStates')]
    public function test_cancelled_tracker_never_uses_closing_date_as_completed_return(string $custodyStatus): void
    {
        [$request, $custody] = $this->fixture();
        $request->update(['status' => RequestStatus::Cancelled]);
        $custody->update(['status' => $custodyStatus, 'closed_at' => now()]);
        $html = Blade::render('<x-request-progress-tracker :request="$request" />', ['request' => $request->fresh()]);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        // Full rail stays visible; a closing date is only cancellation metadata.
        $this->assertSame(7, $xpath->query('//*[@data-workflow-title]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-workflow-title="Completed"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-workflow-title="Cancelled"]')->length);
        $this->assertSame('Not reached', $xpath->query('//*[@data-workflow-title="Completed"]/@data-workflow-meta')->item(0)->nodeValue);
        $this->assertSame('Cancelled · '.now()->format('d M Y, g:i A'), $xpath->query('//li[contains(@class,"is-stopped")]//*[@data-workflow-title]/@data-workflow-meta')->item(0)->nodeValue);
        $this->assertStringNotContainsString('Transaction completed.', $html);
    }

    public static function cancelledTrackerStates(): array
    {
        return [['CANCELLED'], ['CLOSED']];
    }

    public function test_normal_completed_tracker_retains_actual_completion_date(): void
    {
        [$request, $custody] = $this->fixture();
        $custody->update(['status' => 'CLOSED', 'closed_at' => now(), 'released_at' => now()->subDay()]);
        $custody->lines()->update(['actual_released_quantity' => 2, 'returned_quantity' => 2]);
        $html = Blade::render('<x-request-progress-tracker :request="$request" />', ['request' => $request->fresh()]);
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(now()->format('d M Y, g:i A'), $xpath->query('//*[@data-workflow-title="Completed"]/@data-workflow-meta')->item(0)->nodeValue);
        $this->assertSame('Transaction completed.', $xpath->query('//*[@data-workflow-title="Completed"]/@data-workflow-description')->item(0)->nodeValue);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 18:00:01', 'Asia/Manila'));
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_actual_deadline_command_never_cancels_or_restores_reserved_stock(): void
    {
        [$request, $custody, $allocation, $document] = $this->fixture(false);
        $hash = $document->file->sha256;
        foreach (['2026-10-05 18:01:00', '2026-10-10 12:00:00', '2026-12-01 12:00:00'] as $time) {
            $this->travelTo(Carbon::parse($time, 'Asia/Manila'));
            $this->artisan('spmu:process-deadlines')->assertExitCode(0);
            $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->fresh()->status);
            $this->assertSame('PREPARING_RELEASE', $custody->fresh()->status);
            $this->assertNull($custody->fresh()->closed_at);
            $this->assertSame('ACTIVE', $allocation->fresh()->status);
            $this->assertEquals(0, $allocation->fresh()->restored_quantity);
            $this->assertSame('FINAL', $document->fresh()->status);
            $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($document->file->storage_path)));
            $this->assertSame(0, RequestCancellation::where('request_id', $request->id)->count());
            $this->assertSame(0, $this->restorations($request)->count());
        }
        $this->assertNotNull($custody->fresh()->pickup_expired_at);
        $this->assertSame(1, NotificationEvent::where('event_code', 'PICKUP_EXPIRED')->where('source_id', $custody->id)->count());
    }

    public function test_sao_explicit_confirmation_records_identity_exact_stock_and_retains_file_bytes(): void
    {
        [$request, $custody, $allocation, $document] = $this->fixture();
        $oldFile = $document->file->getAttributes();
        $this->as($this->sao())->post(route('requests.cancel', $request), ['reason' => 'Pickup was missed; SAO confirms cancellation.', 'return_to' => 'release'])
            ->assertRedirect(route('custody.release.index'))->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame('CANCELLED', $custody->fresh()->status);
        $this->assertNotNull($custody->fresh()->closed_at);
        $this->assertSame('CANCELLED', $allocation->fresh()->status);
        $this->assertEquals(2, $allocation->fresh()->restored_quantity);
        $this->assertEquals(0, $allocation->fresh()->released_quantity);
        $this->assertSame(1, $this->restorations($request)->count());
        $this->assertEquals(2, DB::table('inventory_transaction_lines')->whereIn('inventory_transaction_id', $this->restorations($request)->select('id'))->sum('quantity'));
        $this->assertDatabaseHas('request_cancellations', ['request_id' => $request->id, 'status' => 'CONFIRMED', 'reviewed_by_user_id' => $this->sao()->id, 'cancelled_by_user_id' => $this->sao()->id]);
        $this->assertSame('INVALIDATED', $document->fresh()->status);
        $this->assertSame($oldFile, $document->file->fresh()->getAttributes());
        $this->assertSame($oldFile['sha256'], hash('sha256', Storage::disk('local')->get($oldFile['storage_path'])));
        $this->assertDatabaseHas('audit_events', ['record_id' => $request->id, 'record_type' => BorrowingRequest::class, 'action_code' => 'REQUEST_CANCELLED', 'actor_user_id' => $this->sao()->id]);
    }

    public function test_admin_cannot_replace_sao_confirmation_for_missed_pickup(): void
    {
        [$request, $custody, $allocation, $document] = $this->fixture();
        $before = $this->state();
        $this->as($this->headUser())->post(route('requests.cancel', $request), ['reason' => 'Admin tries to confirm missed pickup.'])->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('invalidReasons')]
    public function test_invalid_sao_remarks_do_not_cancel_or_release_inventory(mixed $reason): void
    {
        [$request] = $this->fixture();
        $before = $this->state();
        $this->as($this->sao())->post(route('requests.cancel', $request), ['reason' => $reason])->assertSessionHasErrors('reason');
        $this->assertSame($before, $this->state());
    }

    public static function invalidReasons(): array
    {
        return [[null], ['   '], [str_repeat('x', 1001)], [['unexpected']]];
    }

    public function test_cached_duplicate_cancellation_cannot_repeat_history_or_ledger(): void
    {
        [$request] = $this->fixture();
        $cached = $request->fresh()->load('currentVersion.items.allocation', 'custody.gatePass');
        $this->actingAs($this->sao());
        app(RequestWorkflowService::class)->cancel($request, $this->sao(), 'First explicit SAO confirmation.');
        $before = $this->state();
        try {
            app(RequestWorkflowService::class)->cancel($cached, $this->sao(), 'Stale second confirmation.');
            $this->fail('A stale duplicate must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cancel', $exception->errors());
        }
        $this->assertSame($before, $this->state());
        $this->assertSame(1, RequestCancellation::where('request_id', $request->id)->count());
        $this->assertSame(1, $this->restorations($request)->count());
    }

    #[DataProvider('staleStates')]
    public function test_cached_terminal_or_continued_pickup_state_is_rechecked(string $change): void
    {
        [$request, $custody, $allocation] = $this->fixture();
        $cached = $request->fresh()->load('currentVersion.items.allocation', 'custody.gatePass');
        match ($change) {
            'released' => $custody->update(['released_at' => now(), 'status' => 'ACTIVE']),
            'continued' => $custody->update(['pickup_expired_at' => null]),
            'rejected' => $request->update(['status' => RequestStatus::Rejected]),
            'line_issued' => $custody->lines()->update(['actual_released_quantity' => 1]),
            'allocation_issued' => $allocation->update(['released_quantity' => 1, 'status' => 'PARTIALLY_RELEASED']),
        };
        $before = $this->state();
        try {
            app(RequestWorkflowService::class)->cancel($cached, $this->sao(), 'Cached SAO cancellation must not proceed.');
            $this->fail('Changed persistent state must prevent cancellation.');
        } catch (ValidationException|HttpException|AuthorizationException $exception) {
            $this->assertTrue($exception instanceof ValidationException || $exception instanceof AuthorizationException || $exception->getStatusCode() === 403);
        }
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('staleActors')]
    public function test_stale_disabled_unverified_or_revoked_sao_is_denied(string $change): void
    {
        [$request] = $this->fixture();
        $actor = $this->sao();
        match ($change) {
            'inactive' => User::whereKey($actor->id)->update(['account_status' => AccountStatus::Inactive]),
            'unverified' => User::whereKey($actor->id)->update(['email_verified_at' => null]),
            'revoked' => DB::table('user_roles')->where('user_id', $actor->id)->update(['revoked_at' => now()]),
            'head' => User::whereKey($actor->id)->update(['access_classification' => AccessClassification::SpmuHead]),
        };
        $before = $this->state();
        try {
            app(RequestWorkflowService::class)->cancel($request, $actor, 'Stale actor tries to confirm.');
            $this->fail('A stale unauthorized actor must be rejected.');
        } catch (HttpException|AuthorizationException $exception) {
            $this->assertTrue($exception instanceof AuthorizationException || $exception->getStatusCode() === 403);
        }
        $this->assertSame($before, $this->state());
    }

    public static function staleStates(): array
    {
        return array_map(fn ($state) => [$state], ['released', 'continued', 'rejected', 'line_issued', 'allocation_issued']);
    }

    public static function staleActors(): array
    {
        return array_map(fn ($state) => [$state], ['inactive', 'unverified', 'revoked', 'head']);
    }

    public function test_old_reviewed_version_cannot_cancel_the_current_request(): void
    {
        [$request] = $this->fixture();
        $before = $this->state();
        $this->as($this->sao())->post(route('requests.cancel', $request), ['reason' => 'Stale version.', 'reviewed_version_id' => $request->currentVersion->id + 999])->assertSessionHasErrors('cancel');
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('corruptCustodyLinks')]
    public function test_mismatched_custody_owner_or_version_is_not_silently_cancelled(string $field): void
    {
        [$request, $custody] = $this->fixture();
        if ($field === 'borrower_user_id') {
            $custody->update([$field => $this->headUser()->id]);
        } else {
            $other = $request->versions()->create(['version_no' => 2, 'purpose_event' => 'Unrelated retained version', 'location' => 'CSPC', 'schedule_date' => '2026-10-06', 'return_date' => '2026-10-09', 'needed_from' => '2026-10-06 08:00:00', 'return_due_at' => '2026-10-09 17:00:00']);
            $custody->update([$field => $other->id]);
        }
        $before = $this->state();
        $this->as($this->sao())->post(route('requests.cancel', $request), ['reason' => 'Attempt on mismatched record.'])->assertSessionHasErrors('cancel');
        $this->assertSame($before, $this->state());
    }

    public static function corruptCustodyLinks(): array
    {
        return [['borrower_user_id'], ['request_version_id']];
    }

    public function test_bound_request_version_change_is_rechecked_before_terminal_writes(): void
    {
        [$request] = $this->fixture();
        $cached = $request->fresh();
        $request->versions()->create(['version_no' => 2, 'purpose_event' => 'Changed saved version', 'location' => 'CSPC', 'schedule_date' => '2026-10-06', 'return_date' => '2026-10-09', 'needed_from' => '2026-10-06 08:00:00', 'return_due_at' => '2026-10-09 17:00:00']);
        $request->update(['current_version_no' => 2]);
        $before = $this->state();
        try {
            app(RequestWorkflowService::class)->cancel($cached, $this->sao(), 'Stale request binding.');
            $this->fail('A changed bound version must be reviewed again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cancel', $exception->errors());
        }
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('unreadableOutputs')]
    public function test_cancellation_invalidates_output_without_repairing_missing_or_corrupt_original(string $kind): void
    {
        [$request, , , $document] = $this->fixture();
        $file = $document->file;
        $metadata = $file->getAttributes();
        if ($kind === 'missing') {
            Storage::disk('local')->delete($file->storage_path);
        } else {
            Storage::disk('local')->put($file->storage_path, 'Corrupt synthetic original bytes');
        }
        $this->as($this->sao())->post(route('requests.cancel', $request), ['reason' => 'SAO confirms terminal cancellation.'])->assertSessionHasNoErrors();
        $this->assertSame('INVALIDATED', $document->fresh()->status);
        $this->assertSame($metadata, $file->fresh()->getAttributes());
        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
        if ($kind === 'missing') {
            $this->assertFalse(Storage::disk('local')->exists($file->storage_path));
        } else {
            $this->assertSame('Corrupt synthetic original bytes', Storage::disk('local')->get($file->storage_path));
        }
    }

    public static function unreadableOutputs(): array
    {
        return [['missing'], ['corrupt']];
    }

    #[DataProvider('validRemarkLengths')]
    public function test_existing_reason_boundaries_are_retained(int $length): void
    {
        [$request] = $this->fixture();
        $this->as($this->sao())->post(route('requests.cancel', $request), ['reason' => str_repeat('x', $length)])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('x', $length), RequestCancellation::where('request_id', $request->id)->sole()->reason);
    }

    public static function validRemarkLengths(): array
    {
        return [[1], [1000]];
    }

    public function test_expiry_boundary_only_detects_after_cutoff_without_any_cancellation(): void
    {
        [$request, $custody, $allocation] = $this->fixture(false);
        $this->travelTo($custody->pickup_expires_at);
        $this->assertSame(0, app(CustodyService::class)->expirePickupWindows());
        $this->assertNull($custody->fresh()->pickup_expired_at);
        $this->travelTo($custody->pickup_expires_at->copy()->addSecond());
        $this->assertSame(1, app(CustodyService::class)->expirePickupWindows());
        $this->assertNotNull($custody->fresh()->pickup_expired_at);
        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->fresh()->status);
        $this->assertEquals(0, $allocation->fresh()->restored_quantity);
        $this->assertSame(0, $this->restorations($request)->count());
    }

    public function test_ictu_cannot_confirm_missed_pickup_using_a_forged_workspace(): void
    {
        [$request] = $this->fixture();
        $actor = User::factory()->create(['access_classification' => AccessClassification::IctuMaintainer]);
        $before = $this->state();
        $this->actingAs($actor)->withSession(['active_workspace' => 'SPMU'])->post(route('requests.cancel', $request), ['reason' => 'Forged workspace.'])->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    public function test_stale_release_after_cancellation_cannot_regenerate_laundry_forms_or_issue_stock(): void
    {
        [$request, $custody] = $this->fixture();
        $custody->update(['prepared_at' => now()]);
        $cached = $custody->fresh()->load('request.currentVersion', 'lines.requestItem.inventoryItem', 'gatePass');
        $cached->lines->first()->requestItem->inventoryItem->update(['laundry_required' => true]);
        $this->actingAs($this->sao());
        app(RequestWorkflowService::class)->cancel($request, $this->sao(), 'SAO cancellation before any physical release.');
        $before = $this->state();
        $paths = Storage::disk('local')->allFiles();
        try {
            app(CustodyService::class)->release($cached, $this->sao(), 'Stale handover.');
            $this->fail('A cancelled request must never be released.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already closed', $exception->errors()['release'][0]);
        }
        $this->assertSame($before, $this->state());
        $this->assertSame($paths, Storage::disk('local')->allFiles());
    }

    public function test_late_audit_failure_restores_complete_state_and_sends_no_cancellation_notice(): void
    {
        [$request] = $this->fixture();
        $before = $this->state();
        $this->partialMock(AuditService::class, function ($mock): void {
            $mock->shouldReceive('record')->withArgs(fn ($action) => $action === 'REQUEST_CANCELLED')->andThrow(new \RuntimeException('Injected cancellation audit failure'));
        });
        $this->actingAs($this->sao());
        try {
            app(RequestWorkflowService::class)->cancel($request, $this->sao(), 'Rollback test.');
            $this->fail('The injected late failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected cancellation audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
    }

    public function test_actual_corrected_external_approval_packet_can_be_cancelled_only_by_sao_with_evidence_retained(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 09:00:00', 'Asia/Manila'));
        $request = $this->ready();
        $this->approve($request)->assertSessionHasNoErrors();
        $request = $request->fresh();
        $custody = $request->custody;
        $files = StoredFile::orderBy('id')->get()->mapWithKeys(fn ($file) => [$file->id => $file->getAttributes()])->all();
        $versions = $request->versions()->orderBy('id')->get()->map->getAttributes()->all();
        $this->travelTo($custody->pickup_expires_at->copy()->addSecond());
        $this->artisan('spmu:process-deadlines')->assertExitCode(0);
        $this->as($this->sao())->withSession(['last_user_activity_at' => now()->timestamp])
            ->post(route('requests.cancel', $request), ['reason' => 'SAO reviewed the missed external pickup and confirms cancellation.', 'reviewed_version_id' => $request->currentVersion->id])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame('Reviewed External Agency', $request->fresh()->currentVersion->external_company_name);
        $this->assertNull($request->fresh()->accountable_unit_id);
        $this->assertSame($versions, $request->versions()->orderBy('id')->get()->map->getAttributes()->all());
        foreach ($files as $id => $attributes) {
            $this->assertSame($attributes, StoredFile::findOrFail($id)->getAttributes());
            $this->assertSame($attributes['sha256'], hash('sha256', Storage::disk($attributes['disk'])->get($attributes['storage_path'])));
        }
        $this->assertSame(0, $request->fresh()->currentVersion->documents()->where('status', 'FINAL')->count());
        $this->assertSame('VOID', $request->fresh()->custody->gatePass->status);
        $this->assertSame(1, $this->restorations($request)->count());
    }

    private function fixture(bool $missed = true): array
    {
        $borrower = User::where('access_classification', AccessClassification::BorrowerOnly)->firstOrFail();
        $item = InventoryItem::where('unique_description', 'Barricade')->sole();
        $request = BorrowingRequest::create(['request_no' => 'BR-SAO-CANCEL-TEST', 'borrower_user_id' => $borrower->id, 'accountable_unit_id' => $borrower->organizational_unit_id, 'current_version_no' => 1, 'status' => RequestStatus::ApprovedReadyForRelease, 'final_approved_at' => now()->subDay()]);
        $version = $request->versions()->create(['version_no' => 1, 'purpose_event' => 'Synthetic cancellation fixture', 'location' => 'CSPC', 'schedule_date' => '2026-10-06', 'return_date' => '2026-10-09', 'needed_from' => '2026-10-06 08:00:00', 'return_due_at' => '2026-10-09 17:00:00']);
        $requestItem = $version->items()->create(['inventory_item_id' => $item->id, 'description_snapshot' => $item->unique_description, 'unit_snapshot' => $item->unit->unit_name, 'requested_quantity' => 2, 'approved_quantity' => 2, 'use_location' => 'ON_CAMPUS']);
        $allocation = Allocation::create(['request_item_id' => $requestItem->id, 'period_start' => '2026-10-05 08:00:00', 'period_end' => '2026-10-09 17:00:00', 'allocated_quantity' => 2, 'released_quantity' => 0, 'restored_quantity' => 0, 'status' => 'ACTIVE', 'allocated_at' => now()->subDay()]);
        $custody = CustodyTransaction::create(['custody_no' => 'CUS-SAO-CANCEL-TEST', 'request_id' => $request->id, 'request_version_id' => $version->id, 'borrower_user_id' => $borrower->id, 'status' => 'PREPARING_RELEASE', 'scheduled_release_at' => '2026-10-05 08:00:00', 'pickup_scheduled_at' => '2026-10-04 09:00:00', 'pickup_expires_at' => '2026-10-05 17:00:00', 'pickup_expired_at' => $missed ? now() : null, 'due_at' => '2026-10-09 17:00:00']);
        CustodyLine::create(['custody_transaction_id' => $custody->id, 'request_item_id' => $requestItem->id, 'allocation_id' => $allocation->id, 'approved_quantity' => 2, 'quantity_to_receive' => 2, 'actual_released_quantity' => 0, 'returned_quantity' => 0]);
        $bytes = EvidenceFixtures::pdfBytes('Retained synthetic cancellation output');
        Storage::disk('local')->put('cancel-test/output.pdf', $bytes);
        $file = StoredFile::create(['disk' => 'local', 'storage_path' => 'cancel-test/output.pdf', 'original_name' => 'output.pdf', 'mime_type' => 'application/pdf', 'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'classification' => 'OFFICIAL']);
        $document = GeneratedDocument::create(['stored_file_id' => $file->id, 'request_version_id' => $version->id, 'subject_type' => CustodyTransaction::class, 'subject_id' => $custody->id, 'document_no' => 'DOC-SAO-CANCEL-TEST', 'document_type' => 'BORROWER_SLIP', 'status' => 'FINAL', 'sha256' => $file->sha256, 'generated_at' => now()]);

        return [$request->fresh(), $custody, $allocation, $document];
    }

    private function restorations(BorrowingRequest $request): Builder
    {
        return DB::table('inventory_transactions')->where('transaction_type', 'ALLOCATION_RESTORATION')->where('source_type', BorrowingRequest::class)->where('source_id', $request->id);
    }

    private function state(): array
    {
        $tables = [];
        foreach (['borrowing_requests', 'request_versions', 'custody_transactions', 'custody_lines', 'allocations', 'request_cancellations', 'request_status_histories', 'generated_documents', 'gate_passes', 'stored_files', 'inventory_transactions', 'inventory_transaction_lines', 'audit_events', 'notification_events', 'notification_deliveries'] as $table) {
            $tables[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $tables;
    }
}
