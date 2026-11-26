<?php

namespace Tests\Feature;

use App\Enums\{AccessClassification, AccountStatus, RequestStatus};
use App\Models\{AuditEvent, BorrowingRequest, GeneratedDocument, InventoryItem, NotificationDelivery, NotificationEvent, SignatureSnapshot, StoredFile, TemporaryDelegation, User, UserSignature};
use App\Services\{AuditService, CustodyService, NotificationService, ProtectedFileService, RequestWorkflowService, SimplePdfService};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail, Storage};
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ActualExternalApprovalFlow;
use Tests\Support\EvidenceFixtures;
use Tests\TestCase;

class ExternalBorrowingApprovalTest extends TestCase
{
    use RefreshDatabase, ActualExternalApprovalFlow;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.seed_demo_users' => true]);
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-13 09:00:00', 'Asia/Manila'));
        Mail::fake();
        Storage::fake('local');
    }

    public static function packets(): array
    {
        return ['off-campus regular' => [true, false, false],
            'on-campus regular' => [false, false, false], 'on-campus linen and non-linen' => [false, true, false]];
    }

    private function items(bool $off, bool $linen): array
    {
        $regular = InventoryItem::where('unique_description', 'Barricade')->sole();
        $items = [$regular->id => [2, $off ? 'OFF_CAMPUS' : 'ON_CAMPUS']];
        if ($linen) {
            $item = InventoryItem::where('laundry_required', true)->where('borrowable', true)->where('active', true)->firstOrFail();
            $items[$item->id] = [1, 'ON_CAMPUS'];
        }
        return $items;
    }

    #[DataProvider('packets')]
    public function test_actual_corrected_approval_reserves_exactly_once_and_generates_only_applicable_current_packet(bool $off, bool $linen, bool $activity): void
    {
        $request = $this->ready($this->items($off, $linen), $activity);
        $old = $this->oldEvidence($request);
        $this->approve($request)->assertSessionHasNoErrors()->assertRedirect(route('approvals.index'));
        $request->refresh();
        $v = $request->currentVersion;
        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->status);
        $this->assertSame('Reviewed External Agency', $v->external_company_name);
        $this->assertNull($request->accountable_unit_id);
        $this->assertNull($v->office_unit);
        $this->assertNull($v->division_code);
        $head = $v->approvalSteps()->where('sequence_no', 2)->sole();
        $this->assertSame('APPROVED', $head->decision);
        $snapshot = SignatureSnapshot::findOrFail($head->signature_snapshot_id);
        $this->assertSame($this->headUser()->id, $snapshot->signer_user_id);
        $this->assertSame('SPMU_REQUEST_APPROVAL', $snapshot->purpose_code);
        $this->assertSame('SPMU_HEAD', $snapshot->signer_role);
        $custody = $request->custody;
        $this->assertSame('PREPARING_RELEASE', $custody->status);
        $this->assertNull($custody->prepared_at);
        $this->assertNull($custody->released_at);
        $this->assertNotNull($custody->scheduled_release_at);
        foreach ($v->items as $item) {
            $a = $item->allocation()->sole();
            $this->assertSame((float) $item->requested_quantity, (float) $item->approved_quantity);
            $this->assertSame((float) $item->requested_quantity, (float) $a->allocated_quantity);
            $this->assertSame(0.0, (float) $a->released_quantity);
            $this->assertSame(0.0, (float) $a->restored_quantity);
            $this->assertSame('ACTIVE', $a->status);
            $this->assertSame($custody->scheduled_release_at->toDateString(), $a->period_start->toDateString());
            $this->assertSame($v->return_due_at->toDateString(), $a->period_end->toDateString());
            $line = $custody->lines()->where('request_item_id', $item->id)->sole();
            $this->assertSame((float) $item->requested_quantity, (float) $line->approved_quantity);
            $this->assertSame(0.0, (float) $line->actual_released_quantity);
        }
        $txn = DB::table('inventory_transactions')->where('transaction_type', 'SPMU_APPROVAL_RESERVATION')->where('source_id', $v->id)->sole();
        $this->assertSame(count($v->items), DB::table('inventory_transaction_lines')->where('inventory_transaction_id', $txn->id)->count());
        $expected = ['BORROWER_SLIP'];
        if ($off) $expected[] = 'GATE_PASS';
        if ($linen) $expected[] = 'LAUNDRY_FORM';
        $packet = GeneratedDocument::where('request_version_id', $v->id)->get();
        $this->assertEqualsCanonicalizing($expected, $packet->pluck('document_type')->all());
        foreach ($packet as $document) {
            $this->assertSame('FINAL', $document->status);
            $this->assertSame($custody->id, $document->subject_id);
            $this->assertSame($custody::class, $document->subject_type);
            $bytes = app(ProtectedFileService::class)->bytes($document->file);
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertSame(hash('sha256', $bytes), $document->sha256);
            $this->assertSame('local', $document->file->disk);
            $this->as($request->borrower)->get(route('documents.download', $document))->assertOk()->assertStreamedContent($bytes);
            $this->as($this->sao())->get(route('documents.view', $document))->assertOk()->assertStreamedContent($bytes);
            $this->as($this->headUser())->get(route('documents.view', $document))->assertOk()->assertStreamedContent($bytes);
            $this->as(User::where('email', 'borrower@spmu.test')->sole())->get(route('documents.view', $document))->assertForbidden();
        }
        if ($off) {
            $gp = $custody->gatePass;
            $this->assertSame('READY_FOR_PRINTING', $gp->status);
            $this->assertSame($snapshot->id, $gp->approver_signature_snapshot_id);
            $this->assertSame($v->approvalSteps()->where('sequence_no', 1)->sole()->signature_snapshot_id, $gp->prepared_verifier_signature_snapshot_id);
            $this->assertNull($gp->guard_signed_at);
        } else $this->assertNull($custody->gatePass);
        $this->assertDatabaseCount('laundry_jobs', 0);
        $this->assertDatabaseHas('audit_events', ['action_code' => 'SPMU_REQUEST_DECISION', 'record_id' => $head->id]);
        $notices = NotificationEvent::where('event_code', 'REQUEST_APPROVED')->where('source_id', $request->id)->get();
        $this->assertCount(2, $notices);
        $this->assertEqualsCanonicalizing([$request->borrower_user_id, $this->sao()->id], NotificationDelivery::whereIn('notification_event_id', $notices->pluck('id'))->where('channel', 'SYSTEM')->pluck('recipient_user_id')->all());
        $this->as($this->headUser())->get(route('approvals.index'))->assertOk()->assertDontSeeText($request->request_no);
        $this->as($this->sao())->get(route('verifications.index'))->assertOk()->assertDontSeeText($request->request_no);
        $this->assertSame($old, $this->oldEvidence($request));
        $before = $this->state($request);
        $this->approve($request)->assertSessionHasErrors('decision');
        $this->assertSame($before, $this->state($request));
        $this->as($this->sao());
        app(CustodyService::class)->prepare($custody, $this->sao());
        $this->assertSame($packet->pluck('id')->all(), GeneratedDocument::where('request_version_id', $v->id)->pluck('id')->all());
        $this->assertNotNull($custody->fresh()->prepared_at);
        $this->assertNull($custody->fresh()->released_at);
    }

    public static function affiliations(): array { return ['null' => [null], 'empty' => [''], 'whitespace' => [" \t "]]; }

    #[DataProvider('affiliations')]
    public function test_missing_saved_company_fails_closed_without_live_profile_fallback(?string $company): void
    {
        $r = $this->ready();
        $r->currentVersion->update(['external_company_name' => $company]);
        $before = $this->state($r);
        $this->approve($r)->assertSessionHasErrors('affiliation');
        $this->assertSame($before, $this->state($r));
    }

    public function test_fabricated_external_internal_assignment_cannot_be_approved(): void
    {
        $r = $this->ready();
        $r->currentVersion->update(['office_unit' => 'Fabricated CSPC office']);
        $before = $this->state($r);
        $this->approve($r)->assertSessionHasErrors('affiliation');
        $this->assertSame($before, $this->state($r));
    }

    public static function evidenceFailures(): array
    {
        return ['letter missing' => ['letter', 'missing'], 'letter changed' => ['letter', 'changed'],
            'borrower snapshot missing' => ['borrower', 'missing'], 'borrower snapshot changed' => ['borrower', 'changed'],
            'SAO snapshot missing' => ['sao', 'missing'], 'SAO snapshot changed' => ['sao', 'changed'],
            'Head signature missing' => ['head', 'missing'], 'Head signature changed' => ['head', 'changed']];
    }

    public static function legacyPtcFiles(): array
    {
        return [['missing'], ['corrupt']];
    }

    #[DataProvider('legacyPtcFiles')]
    public function test_legacy_external_ptc_does_not_block_approval_or_get_marked_as_a_required_document(string $condition): void
    {
        $request = $this->ready();
        $version = $request->currentVersion;
        $version->update(['represents_student_activity' => true]);
        $file = app(ProtectedFileService::class)->storeBytes(EvidenceFixtures::pdfBytes('Historical external PTC'),
            'legacy-external-ptc', 'old-ptc.pdf', 'application/pdf', 'pdf', 'REQUEST_SUPPORTING_DOCUMENT', $request->borrower_user_id);
        $ptc = \App\Models\RequestSupportingDocument::create([
            'request_id' => $request->id, 'request_version_id' => $version->id,
            'document_type' => \App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT,
            'version_no' => 1, 'stored_file_id' => $file->id, 'uploaded_by_user_id' => $request->borrower_user_id,
            'uploaded_at' => now(), 'is_current' => true, 'verification_status' => 'PENDING_VERIFICATION',
        ])->fresh();
        $before = $ptc->getAttributes();
        if ($condition === 'missing') {
            Storage::disk('local')->delete($file->storage_path);
        } else {
            Storage::disk('local')->put($file->storage_path, 'Invalid historical bytes');
        }
        $html = $this->as($this->headUser())->get(route('requests.show', $request))->assertOk()->getContent();
        $this->assertStringContainsString('data-required-supporting-present="1"', $html);
        $this->assertStringNotContainsString('aria-label="Submitted request documents"', $html);
        $this->approve($request->fresh())->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->fresh()->status);
        $this->assertSame($before, $ptc->fresh()->getAttributes());
    }

    #[DataProvider('evidenceFailures')]
    public function test_approval_revalidates_current_private_documents_and_saved_signatures(string $target, string $failure): void
    {
        $r = $this->ready();
        $v = $r->currentVersion;
        $file = match ($target) {
            'letter' => $v->supportingDocuments()->where('document_type', \App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER)->sole()->file,
            'borrower' => $v->borrowerSignature->file,
            'sao' => SignatureSnapshot::findOrFail($v->approvalSteps()->where('sequence_no', 1)->sole()->signature_snapshot_id)->file,
            'head' => $this->headUser()->currentSignature()->sole()->file,
        };
        if ($failure === 'missing') Storage::disk('local')->delete($file->storage_path);
        else Storage::disk('local')->put($file->storage_path, 'corrupted synthetic evidence');
        $before = $this->state($r);
        $this->approve($r)->assertSessionHasErrors(match($target) {'letter' => 'approved_request_letter', default => 'signature'});
        $this->assertSame($before, $this->state($r));
    }

    public static function authorities(): array { return ['inactive' => ['inactive'], 'demoted' => ['demoted'], 'revoked role' => ['revoked']]; }

    #[DataProvider('authorities')]
    public function test_stale_bound_actor_must_still_have_current_active_head_authority(string $failure): void
    {
        $r = $this->ready();
        $head = $this->headUser();
        $this->as($head);
        match ($failure) {
            'inactive' => User::whereKey($head->id)->update(['account_status' => 'INACTIVE']),
            'demoted' => User::whereKey($head->id)->update(['access_classification' => AccessClassification::SpmuOfficer->value]),
            'revoked' => DB::table('user_roles')->where('user_id', $head->id)->update(['revoked_at' => now()]),
        };
        $before = $this->state($r);
        try {
            app(RequestWorkflowService::class)->decide($r, $head, 'APPROVED', null, true, $r->currentVersion->id);
            $this->fail('Stale authority must be denied.');
        } catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertSame($before, $this->state($r));
    }

    public static function confirmations(): array { return ['consent' => ['confirm_e_signature'], 'details' => ['details_complete'], 'documents' => ['documents_complete'], 'availability' => ['availability_verified']]; }

    #[DataProvider('confirmations')]
    public function test_on_campus_approval_requires_each_confirmation(string $field): void
    {
        $r = $this->ready($this->items(false, false));
        $before = $this->state($r);
        $this->approve($r, [$field => 0])->assertSessionHasErrors($field);
        $this->assertSame($before, $this->state($r));
    }

    public function test_stale_review_id_cannot_allocate(): void
    {
        $r = $this->ready();
        $before = $this->state($r);
        $this->approve($r, ['reviewed_version_id' => $r->versions()->where('version_no', 1)->sole()->id])->assertSessionHasErrors('decision');
        $this->assertSame($before, $this->state($r));
    }

    public function test_existing_mixed_off_campus_item_restriction_is_preserved(): void
    {
        $r = $this->ready();
        $before = $this->state($r);
        $this->travel(1)->seconds();
        $this->as($r->borrower)->post(route('requests.store'),
            $this->payload($this->items(true, true), false, 'Synthetic forbidden mixed request') + $this->uploads(false, 'mixed'))
            ->assertSessionHasErrors();
        $this->assertSame($before, $this->state($r));
    }

    public static function snapshotOwners(): array { return ['borrower' => ['borrower'], 'SAO' => ['sao']]; }

    #[DataProvider('snapshotOwners')]
    public function test_foreign_snapshot_cannot_authorize_external_approval(string $target): void
    {
        $r = $this->ready();
        if ($target === 'borrower') {
            $r->currentVersion->update(['borrower_signature_snapshot_id' => $r->currentVersion->approvalSteps()->where('sequence_no', 1)->sole()->signature_snapshot_id]);
        } else {
            $r->currentVersion->approvalSteps()->where('sequence_no', 1)->update(['signature_snapshot_id' => $r->currentVersion->borrower_signature_snapshot_id]);
        }
        $before = $this->state($r);
        $this->approve($r)->assertSessionHasErrors('signature');
        $this->assertSame($before, $this->state($r));
    }

    #[DataProvider('snapshotOwners')]
    public function test_same_signer_snapshot_from_an_older_version_cannot_authorize_current_approval(string $target): void
    {
        $r = $this->ready();
        $previous = $r->currentVersion;
        $oldSao = $previous->approvalSteps()->where('sequence_no', 1)->sole()->signature_snapshot_id;
        $this->as($this->headUser())->post(route('approvals.decide', $r),
            ['decision' => 'RETURNED_FOR_REVISION', 'remarks' => 'Correct synthetic v2 venue again.'])->assertSessionHasNoErrors();
        $items = $previous->items->mapWithKeys(fn ($i) => [$i->inventory_item_id => [$i->requested_quantity, $i->use_location]])->all();
        $this->sign($r->borrower, 'Third borrower ink');
        $this->put(route('requests.update', $r), $this->payload($items, false, 'Third corrected venue') + $this->uploads(false, 'third'))->assertSessionHasNoErrors();
        $r->refresh();
        $this->as($this->sao())->post(route('verifications.verify', $r), [
            'decision' => 'VERIFIED', 'reviewed_version_id' => $r->currentVersion->id,
            'details_complete' => 1, 'documents_complete' => 1, 'availability_verified' => 1, 'confirm_e_signature' => 1,
        ])->assertSessionHasNoErrors();
        $r->refresh();
        if ($target === 'borrower') $r->currentVersion->update(['borrower_signature_snapshot_id' => $previous->borrower_signature_snapshot_id]);
        else $r->currentVersion->approvalSteps()->where('sequence_no', 1)->update(['signature_snapshot_id' => $oldSao]);
        $before = $this->state($r);
        $this->approve($r)->assertSessionHasErrors('signature');
        $this->assertSame($before, $this->state($r));
    }

    public function test_off_campus_approval_requires_current_verified_sao_step(): void
    {
        $r = $this->ready();
        $r->currentVersion->approvalSteps()->where('sequence_no', 1)->update(['decision' => 'RECEIVED']);
        $before = $this->state($r);
        $this->approve($r)->assertSessionHasErrors('decision');
        $this->assertSame($before, $this->state($r));
    }

    public static function delegates(): array { return ['effective' => [false], 'revoked' => [true]]; }

    #[DataProvider('delegates')]
    public function test_existing_formal_delegation_is_preserved_and_revocation_blocks_approval(bool $revoked): void
    {
        $r = $this->ready();
        $delegation = TemporaryDelegation::create([
            'office_role' => 'SPMU', 'absent_head_user_id' => $this->headUser()->id, 'delegate_user_id' => $this->sao()->id,
            'recorded_by_user_id' => $this->headUser()->id, 'authority_reference' => 'SYNTHETIC-ONLY-TEST-AUTHORITY',
            'reason' => 'Synthetic existing authority regression', 'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(), 'status' => 'ACTIVE',
            'revoked_at' => $revoked ? now() : null,
        ]);
        $before = $this->state($r);
        $response = $this->as($this->sao())->post(route('approvals.decide', $r), [
            'decision' => 'APPROVED', 'reviewed_version_id' => $r->currentVersion->id, 'confirm_e_signature' => 1,
        ]);
        if ($revoked) {
            $response->assertForbidden();
            $this->assertSame($before, $this->state($r));
        } else {
            $response->assertSessionHasNoErrors();
            $step = $r->currentVersion->approvalSteps()->where('sequence_no', 2)->sole();
            $this->assertSame($delegation->id, $step->temporary_delegation_id);
            $this->assertSame('SPMU_DELEGATE', SignatureSnapshot::findOrFail($step->signature_snapshot_id)->signer_role);
            $this->assertSame(RequestStatus::ApprovedReadyForRelease, $r->fresh()->status);
        }
    }

    public function test_approval_email_observes_committed_complete_packet_only(): void
    {
        $r = $this->ready();
        $attempts = 0;
        Mail::shouldReceive('html')->andReturnUsing(function () use ($r, &$attempts) {
            $attempts++;
            $this->assertSame(RequestStatus::ApprovedReadyForRelease, $r->fresh()->status);
            $this->assertSame(2, GeneratedDocument::count());
            $this->assertDatabaseHas('audit_events', ['action_code' => 'SPMU_REQUEST_DECISION']);
            $this->assertLessThanOrEqual(1, DB::transactionLevel(), 'Only the test isolation transaction may remain.');
        });
        $this->approve($r)->assertSessionHasNoErrors();
        $this->assertSame(2, $attempts);
    }

    public function test_multi_item_stock_conflict_rolls_back_all_partial_allocations_and_reports_saved_return(): void
    {
        $r = $this->ready($this->items(false, true));
        $items = $r->currentVersion->items()->orderBy('id')->get();
        $items->last()->inventoryItem->update(['total_quantity' => 0]);
        $old = $this->oldEvidence($r);
        $this->approve($r)->assertSessionHasNoErrors()->assertSessionHas('status', fn ($message) => str_contains($message, 'returned for revision') && !str_contains($message, 'E-signed and approved'));
        $this->assertSame(RequestStatus::ReturnedForRevision, $r->fresh()->status);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('custody_transactions', 0);
        $this->assertDatabaseCount('generated_documents', 0);
        $this->assertSame(0, $r->currentVersion->items()->where('approved_quantity', '>', 0)->count());
        $this->assertSame(0, DB::table('inventory_transactions')->where('transaction_type', 'SPMU_APPROVAL_RESERVATION')->count());
        $this->assertDatabaseHas('audit_events', ['action_code' => 'SPMU_RESERVATION_CONFLICT']);
        $this->assertDatabaseMissing('notification_events', ['event_code' => 'REQUEST_APPROVED']);
        $this->assertSame($old, $this->oldEvidence($r));
    }

    public static function rollbacks(): array
    {
        return ['signature audit' => ['E_SIGNATURE_APPLIED'], 'ready audit' => ['REQUEST_READY_FOR_PICKUP_SCHEDULING'],
            'final audit' => ['SPMU_REQUEST_DECISION'], 'second PDF' => ['pdf'], 'file metadata' => ['metadata'],
            'first notification' => ['notice1'], 'second notification' => ['notice2'], 'late delivery persistence' => ['delivery'],
            'conflict audit' => ['SPMU_RESERVATION_CONFLICT']];
    }

    #[DataProvider('rollbacks')]
    public function test_failed_approval_removes_only_new_rows_files_and_never_attempts_precommit_email(string $failure): void
    {
        $r = $this->ready();
        if ($failure === 'SPMU_RESERVATION_CONFLICT') $r->currentVersion->items->first()->inventoryItem->update(['total_quantity' => 0]);
        $before = $this->state($r);
        $attempts = 0;
        Mail::shouldReceive('html')->andReturnUsing(function () use (&$attempts) { $attempts++; });
        $calls = 0;
        if ($failure === 'pdf') {
            $real = app(SimplePdfService::class);
            $this->mock(SimplePdfService::class)->shouldReceive('html')->andReturnUsing(function (...$args) use ($real, &$calls) {
                if (++$calls === 2) throw new \RuntimeException('Synthetic approval failure');
                return $real->html(...$args);
            });
        } elseif ($failure === 'metadata') {
            StoredFile::creating(function ($file) {
                if ($file->classification === 'CONTROLLED_DOCUMENT') throw new \RuntimeException('Synthetic approval failure');
            });
        } elseif (str_starts_with($failure, 'notice')) {
            NotificationEvent::creating(function ($event) use ($failure, &$calls) {
                if ($event->event_code === 'REQUEST_APPROVED' && ++$calls === (int) substr($failure, -1)) throw new \RuntimeException('Synthetic approval failure');
            });
        } elseif ($failure === 'delivery') {
            NotificationDelivery::creating(function ($delivery) use (&$calls) {
                if (++$calls === 3) throw new \RuntimeException('Synthetic approval failure');
            });
        } else {
            $real = app(AuditService::class);
            $this->mock(AuditService::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($real, $failure) {
                if ($args[0] === $failure) throw new \RuntimeException('Synthetic approval failure');
                return $real->record(...$args);
            });
        }
        $this->withoutExceptionHandling();
        try {
            $this->approve($r);
            $this->fail('Injected persistence/render failure must roll back.');
        } catch (\RuntimeException $e) { $this->assertSame('Synthetic approval failure', $e->getMessage()); }
        $this->assertSame($before, $this->state($r));
        $this->assertSame(0, $attempts, 'No approval/correction email before commit.');
    }

    public function test_mail_transport_failure_after_commit_records_failed_delivery_without_undoing_approval(): void
    {
        $r = $this->ready();
        Mail::shouldReceive('html')->andThrow(new \RuntimeException('Synthetic transport outage'));
        $this->approve($r)->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $r->fresh()->status);
        $events = NotificationEvent::where('event_code', 'REQUEST_APPROVED')->pluck('id');
        $this->assertSame(2, NotificationDelivery::whereIn('notification_event_id', $events)->where('channel', 'EMAIL')->where('delivery_status', 'FAILED')->count());
        $this->assertSame(2, GeneratedDocument::count());
        $this->assertDatabaseCount('allocations', 1);
    }

    public static function writeFailures(): array { return ['write returns false' => [false], 'write throws' => [true]]; }

    #[DataProvider('writeFailures')]
    public function test_partial_private_pdf_write_is_cleaned_up_when_storage_fails(bool $throws): void
    {
        $r = $this->ready();
        $before = $this->state($r);
        $disk = Storage::disk('local');
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('put')->andReturnUsing(function ($path, $bytes, ...$args) use ($disk, $throws) {
            if (str_starts_with($path, 'generated-documents/')) {
                $disk->put($path, substr($bytes, 0, 20));
                if ($throws) throw new \RuntimeException('Synthetic storage write failure');
                return false;
            }
            return $disk->put($path, $bytes, ...$args);
        });
        Storage::set('local', $proxy);
        $this->withoutExceptionHandling();
        try {
            $this->approve($r);
            $this->fail('Storage write failure must abort approval.');
        } catch (\App\Exceptions\ProtectedFileStorageException $e) {
            // Main redacts raw storage errors; Incoming still requires full rollback.
            $this->assertSame('The file could not be written to the [local] disk.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame($before, $this->state($r));
    }

    public static function invalidPackets(): array
    {
        return ['missing Slip' => ['BORROWER_SLIP', 'missing'], 'missing Gate Pass' => ['GATE_PASS', 'missing'], 'missing Laundry' => ['LAUNDRY_FORM', 'missing'],
            'changed Slip' => ['BORROWER_SLIP', 'changed'], 'changed Gate Pass' => ['GATE_PASS', 'changed'], 'changed Laundry' => ['LAUNDRY_FORM', 'changed'],
            'wrong version' => ['BORROWER_SLIP', 'version'], 'wrong document hash' => ['GATE_PASS', 'hash']];
    }

    #[DataProvider('invalidPackets')]
    public function test_sao_preparation_blocks_invalid_packet_without_regeneration_or_physical_release(string $type, string $failure): void
    {
        $r = $this->ready($this->items($type === 'GATE_PASS', $type === 'LAUNDRY_FORM'));
        $this->approve($r)->assertSessionHasNoErrors();
        $doc = GeneratedDocument::where('document_type', $type)->sole();
        match ($failure) {
            'missing' => $doc->update(['status' => 'INVALIDATED']),
            'version' => $doc->update(['request_version_id' => $r->versions()->where('version_no', 1)->sole()->id]),
            'hash' => $doc->update(['sha256' => str_repeat('0', 64)]),
            'changed' => Storage::disk('local')->put($doc->file->storage_path, 'changed PDF'),
        };
        $before = $this->state($r);
        $this->as($this->sao())->post(route('custody.prepare', $r->fresh()->custody))->assertSessionHasErrors('documents');
        $this->assertSame($before, $this->state($r));
    }

    private function oldEvidence(BorrowingRequest $r): array
    {
        $old = $r->versions()->where('version_no', 1)->sole();
        $snapshots = SignatureSnapshot::whereIn('id', [$old->borrower_signature_snapshot_id, $r->currentVersion->borrower_signature_snapshot_id])->get();
        $files = $old->supportingDocuments()->with('file')->get()->map->file->merge($snapshots->map->file);
        return ['old_version' => $old->getAttributes(), 'old_documents' => $old->supportingDocuments()->get()->map->getAttributes()->all(),
            'snapshots' => $snapshots->map->getAttributes()->all(), 'bytes' => $files->mapWithKeys(fn ($f) => [$f->id => hash('sha256', app(ProtectedFileService::class)->bytes($f))])->all()];
    }

    private function state(BorrowingRequest $r): array
    {
        $tables = ['borrowing_requests', 'request_versions', 'request_items', 'approval_steps', 'request_supporting_documents',
            'signature_snapshots', 'stored_files', 'allocations', 'inventory_transactions', 'inventory_transaction_lines',
            'custody_transactions', 'custody_lines', 'gate_passes', 'generated_documents', 'request_status_histories',
            'audit_events', 'notification_events', 'notification_deliveries', 'laundry_jobs'];
        $rows = [];
        foreach ($tables as $table) $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $files = [];
        foreach (Storage::disk('local')->allFiles() as $path) $files[$path] = hash('sha256', Storage::disk('local')->get($path));
        ksort($files);
        return ['rows' => $rows, 'files' => $files];
    }
}
