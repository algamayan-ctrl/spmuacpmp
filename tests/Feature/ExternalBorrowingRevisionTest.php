<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AuditEvent;
use App\Models\BorrowerRegistration;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\RequestSupportingDocument;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ProtectedFileService;
use App\Services\RequestWorkflowService;
use App\Services\UserRoleAssignmentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EvidenceFixtures;
use Tests\TestCase;

class ExternalBorrowingRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.seed_demo_users' => true]);
        $this->seed(DatabaseSeeder::class);
        Mail::fake();
        Storage::fake('local');
    }

    public static function activityCases(): array
    {
        return ['external regular' => [false]];
    }

    #[DataProvider('activityCases')]
    public function test_actual_sao_correction_and_resubmission_preserves_evidence_and_reenters_only_sao(bool $activity): void
    {
        $request = $this->submitted($activity);
        $original = $request->currentVersion;
        $originalAttributes = $original->getAttributes();
        $signature = $original->borrowerSignature;
        $signatureAttributes = $signature->getAttributes();
        $signatureBytes = app(ProtectedFileService::class)->bytes($signature->file);
        $originalDocuments = $original->supportingDocuments()->with('file')->get();
        $originalBytes = $originalDocuments->mapWithKeys(fn ($doc) => [$doc->id => app(ProtectedFileService::class)->bytes($doc->file)]);

        $this->returnBySao($request);
        $step = $original->approvalSteps()->sole();
        $this->assertSame('RETURNED_FOR_REVISION', $step->decision);
        $this->assertSame($this->sao()->id, $step->approver_user_id);
        $this->assertSame('Correct the venue and attach the corrected approved evidence.', $step->remarks);
        $this->assertNull($step->signature_snapshot_id);
        foreach ($originalDocuments as $doc) {
            $this->assertSame(RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION, $doc->fresh()->verification_status);
            $this->assertSame($this->sao()->id, $doc->fresh()->verified_by_user_id);
        }
        $notice = NotificationEvent::where('event_code', 'REQUEST_RETURNED_FOR_REVISION')->sole();
        $this->assertSame($this->sao()->id, $notice->created_by_user_id);
        $this->assertStringContainsString($step->remarks, $notice->payload_snapshot_json['message']);
        $this->assertSame([$request->borrower_user_id], $notice->deliveries()->distinct()->pluck('recipient_user_id')->all());
        $this->assertDatabaseHas('request_status_histories', ['request_id' => $request->id, 'request_version_id' => $original->id,
            'actor_user_id' => $this->sao()->id, 'to_status' => 'RETURNED_FOR_REVISION']);
        $this->assertDatabaseHas('audit_events', ['action_code' => 'SPMU_ACTION_OFFICER_VERIFICATION', 'record_id' => $step->id, 'actor_user_id' => $this->sao()->id]);
        $this->queues($request, false);

        $request->borrower->borrowerRegistration->update(['company_name' => 'Later profile company']);
        $this->sign($request->borrower, 'Corrected owned ink');
        $this->put(route('requests.update', $request), $this->payload($activity, true, 'Corrected external venue') + $this->documents($activity, 'corrected'))
            ->assertSessionHasNoErrors()->assertRedirect(route('requests.show', $request));
        $request->refresh();
        $current = $request->currentVersion;
        $this->assertSame(RequestStatus::UnderSpmu, $request->status);
        $this->assertSame(2, $request->current_version_no);
        $this->assertSame(2, $request->versions()->count());
        $this->assertSame('Corrected external venue', $current->location);
        $this->assertSame('Reviewed External Agency', $current->external_company_name);
        $this->assertTrue($current->isExternalAffiliation());
        $this->assertNull($request->accountable_unit_id);
        $this->assertNull($current->division_code);
        $this->assertNull($current->office_unit);
        $this->assertTrue($current->accuracy_certified);
        $this->assertNotNull($current->submitted_at);
        $this->assertNotSame($signature->id, $current->borrower_signature_snapshot_id);
        $this->assertNotSame($signature->sha256, $current->borrowerSignature->sha256);
        $this->assertSame($request->borrower_user_id, $current->borrowerSignature->signer_user_id);
        $this->assertSame($originalAttributes, $original->fresh()->getAttributes());
        $this->assertSame($signatureAttributes, $signature->fresh()->getAttributes());
        $this->assertSame($signatureBytes, app(ProtectedFileService::class)->bytes($signature->file));
        foreach ($originalDocuments as $doc) {
            $this->assertSame($originalBytes[$doc->id], app(ProtectedFileService::class)->bytes($doc->fresh()->file));
            $this->get(route('files.show', $doc->file))->assertOk();
        }
        $this->assertSame($activity ? 2 : 1, $current->supportingDocuments()->current()->count());
        foreach ($current->supportingDocuments as $doc) {
            $this->assertSame(RequestSupportingDocument::STATUS_PENDING, $doc->verification_status);
            $this->assertNull($doc->verified_by_user_id);
            $this->assertNull($doc->verified_at);
            $this->assertFalse($originalDocuments->pluck('stored_file_id')->contains($doc->stored_file_id));
        }
        $newStep = $current->approvalSteps()->sole();
        $this->assertSame(1, $newStep->sequence_no);
        $this->assertSame('RECEIVED', $newStep->decision);
        $this->assertDatabaseCount('signature_snapshots', 2);
        $this->assertSame(2, AuditEvent::where('action_code', 'REQUEST_SUBMITTED')->count());
        $this->assertDatabaseHas('audit_events', ['action_code' => 'REQUEST_DRAFT_UPDATED', 'record_id' => $request->id, 'actor_user_id' => $request->borrower_user_id]);
        $newNotice = NotificationEvent::where('event_code', 'REQUEST_SUBMITTED')->latest('id')->firstOrFail();
        $this->assertSame([$this->sao()->id], $newNotice->deliveries()->distinct()->pluck('recipient_user_id')->all());
        $this->queues($request, true);
        $this->as($this->headUser(), 'SPMU')->post(route('approvals.decide', $request), ['decision' => 'APPROVED', 'confirm_e_signature' => 1])
            ->assertSessionHasErrors('decision');
        $this->assertNoAllocation();
    }

    public function test_sao_requires_nonblank_bounded_remarks_without_decision_side_effects(): void
    {
        $request = $this->submitted();
        foreach (['', '   ', str_repeat('x', 2001)] as $remarks) {
            $this->as($this->sao(), 'SPMU')->post(route('verifications.verify', $request), ['decision' => 'RETURNED_FOR_REVISION', 'remarks' => $remarks])
                ->assertSessionHasErrors('remarks');
        }
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertSame('RECEIVED', $request->currentVersion->approvalSteps()->sole()->decision);
        $this->assertSame(0, NotificationEvent::where('event_code', 'REQUEST_RETURNED_FOR_REVISION')->count());
        $this->assertNoAllocation();
    }

    public function test_borrower_request_detail_shows_required_corrections_in_one_compact_revision_action(): void
    {
        $request = $this->submitted();
        $this->returnBySao($request);

        $this->as($request->borrower, 'BORROWER')
            ->get(route('requests.show', $request))
            ->assertOk()
            ->assertSeeText('Revision required')
            ->assertSeeText('Required corrections')
            ->assertSeeText('Correct the venue and attach the corrected approved evidence.')
            ->assertSeeText('Revise Request')
            ->assertSeeText('Update requested')
            ->assertSeeText('Pickup Scheduled')
            ->assertSeeText('Release Processing')
            ->assertSeeText('Return Processing')
            ->assertDontSeeText('Update the required details/documents and submit it again to continue.');
    }

    #[DataProvider('activityCases')]
    public function test_returned_evidence_remains_previewable_while_the_form_requires_fresh_uploads(bool $activity): void
    {
        $request = $this->submitted($activity);
        $documents = $request->currentVersion->supportingDocuments()->get();
        $this->returnBySao($request);
        $response = $this->as($request->borrower, 'BORROWER')->get(route('requests.edit', $request))->assertOk();
        $response->assertSeeText('Upload a new corrected, signed borrowing request letter to submit this revision.')
            ->assertSee('const requestLetterAlreadyUploaded = false;', false)
            ->assertSee('const ptcAlreadyUploaded = false;', false);
        $details = $this->as($request->borrower, 'BORROWER')->get(route('requests.show', $request))->assertOk();
        foreach ($documents as $document) {
            // The existing form intentionally hides old attachments while asking
            // for corrected uploads. Their previews remain in request details.
            $response->assertDontSee(route('files.preview', $document->file), false);
            $details->assertSee(route('files.preview', $document->file), false);
            $this->get(route('files.preview', $document->file))->assertOk();
        }
        $this->assertReturnedOriginal($request);
    }

    public static function unauthorizedReviewers(): array
    {
        return ['Admin' => ['head'], 'ICTU' => ['ictu'], 'other borrower' => ['borrower'], 'revoked SAO' => ['revoked']];
    }

    #[DataProvider('unauthorizedReviewers')]
    public function test_only_authorized_sao_can_return_the_external_request(string $actor): void
    {
        $request = $this->submitted();
        $user = match ($actor) {
            'head' => $this->headUser(),
            'ictu' => User::where('email', 'ictu@spmu.test')->sole(),
            'borrower' => User::factory()->create(),
            default => $this->sao(),
        };
        if ($actor === 'revoked') {
            $user->roles()->detach();
        }
        $this->as($user, $user->primaryWorkspace() ?? 'SPMU')->post(route('verifications.verify', $request), ['decision' => 'RETURNED_FOR_REVISION', 'remarks' => 'Unauthorized correction'])
            ->assertForbidden();
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertSame('RECEIVED', $request->currentVersion->approvalSteps()->sole()->decision);
        $this->assertNoAllocation();
    }

    public function test_old_signed_returned_version_cannot_be_resubmitted_without_a_new_correction(): void
    {
        $request = $this->submitted();
        $this->returnBySao($request);
        $this->as($request->borrower, 'BORROWER')->post(route('requests.submit', $request), ['borrower_acknowledgement' => 1, 'confirm_e_signature' => 1])
            ->assertSessionHasErrors('signature');
        $this->assertReturnedOriginal($request);
    }

    public static function missingCorrections(): array
    {
        return ['fresh letter' => ['approved_request_letter'],
            'E-sign consent' => ['confirm_e_signature'], 'acknowledgement' => ['borrower_acknowledgement']];
    }

    #[DataProvider('missingCorrections')]
    public function test_missing_required_correction_input_does_not_reuse_old_evidence_or_commit_a_version(string $field): void
    {
        $request = $this->submitted();
        $this->returnBySao($request);
        $beforeFiles = Storage::disk('local')->allFiles();
        $payload = $this->payload(false, true) + $this->documents(false, 'missing');
        unset($payload[$field]);
        $this->as($request->borrower, 'BORROWER')->put(route('requests.update', $request), $payload)->assertSessionHasErrors($field);
        $this->assertReturnedOriginal($request);
        $this->assertEqualsCanonicalizing($beforeFiles, Storage::disk('local')->allFiles());
        $this->assertSame(0, AuditEvent::where('action_code', 'REQUEST_DRAFT_UPDATED')->count());
    }

    public function test_saved_unsigned_correction_is_resumed_and_signed_once_without_creating_version_three(): void
    {
        $request = $this->submitted();
        $this->returnBySao($request);
        $this->as($request->borrower, 'BORROWER')->put(route('requests.update', $request), $this->payload(false, false))->assertSessionHasNoErrors();
        $current = $request->fresh()->currentVersion;
        $this->assertSame(2, $current->version_no);
        $this->assertNull($current->signed_at);
        $this->assertSame(0, $current->supportingDocuments()->count());
        $this->get(route('requests.edit', $request))->assertOk()->assertSee('Reviewed External Agency');
        $this->put(route('requests.update', $request), $this->payload(false, true) + $this->documents(false, 'resumed'))->assertSessionHasNoErrors();
        $this->assertSame(2, $request->versions()->count());
        $this->assertSame($current->id, $request->fresh()->currentVersion->id);
        $this->assertDatabaseCount('signature_snapshots', 2);
        $stale = $request->fresh();
        $this->put(route('requests.update', $request), $this->payload(false, true) + $this->documents(false, 'duplicate'))->assertRedirect()
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'already completed'));
        $this->assertSame(2, $request->versions()->count());
        Cache::flush();
        $this->put(route('requests.update', $request), $this->payload(false, true) + $this->documents(false, 'direct-stale'))->assertForbidden();
        $this->assertSame(2, $request->versions()->count());
        $this->assertSame('RECEIVED', $stale->currentVersion->approvalSteps()->sole()->decision);
        $this->assertNoAllocation();
    }

    public function test_another_borrower_cannot_edit_or_upload_a_returned_correction(): void
    {
        $request = $this->submitted();
        $this->returnBySao($request);
        $this->as(User::factory()->create(), 'BORROWER')->put(route('requests.update', $request), $this->payload(false, true) + $this->documents(false, 'foreign'))
            ->assertForbidden();
        $this->assertReturnedOriginal($request);
    }

    public function test_forged_cspc_assignment_cannot_be_attached_to_an_external_correction(): void
    {
        $request = $this->submitted();
        $this->returnBySao($request);
        $this->as($request->borrower, 'BORROWER')->put(route('requests.update', $request), $this->payload(false, true) + $this->documents(false, 'forged') + ['requesting_organizational_unit_id' => $this->sao()->organizational_unit_id])
            ->assertSessionHasErrors('requesting_organizational_unit_id');
        $this->assertReturnedOriginal($request);
        $this->assertNull($request->fresh()->accountable_unit_id);
    }

    public function test_corrupted_corrected_document_is_rejected_without_losing_the_returned_original(): void
    {
        $request = $this->submitted();
        $this->returnBySao($request);
        $this->as($request->borrower, 'BORROWER')->put(route('requests.update', $request), $this->payload(false, true) + ['approved_request_letter' => UploadedFile::fake()->createWithContent('corrupt.pdf', '%PDF-corrupt')])
            ->assertSessionHasErrors('approved_request_letter');
        $this->assertReturnedOriginal($request);
    }

    public function test_return_decision_audit_failure_rolls_back_documents_notice_and_state(): void
    {
        $request = $this->submitted();
        $mailAttempts = 0;
        Mail::shouldReceive('html')->andReturnUsing(function () use (&$mailAttempts) {
            $mailAttempts++;
        });
        $before = $request->currentVersion->supportingDocuments()->sole()->getAttributes();
        $realAudit = app(AuditService::class);
        $this->mock(AuditService::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($realAudit) {
            if ($args[0] === 'SPMU_ACTION_OFFICER_VERIFICATION') {
                throw new \RuntimeException('Synthetic SAO audit failure');
            }

            return $realAudit->record(...$args);
        });
        $this->as($this->sao(), 'SPMU')->withoutExceptionHandling();
        try {
            $this->post(route('verifications.verify', $request), ['decision' => 'RETURNED_FOR_REVISION', 'remarks' => 'Correction rollback']);
            $this->fail('Audit failure must abort correction decision.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic SAO audit failure', $exception->getMessage());
        }
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertSame('RECEIVED', $request->currentVersion->approvalSteps()->sole()->decision);
        $this->assertSame($before, $request->currentVersion->supportingDocuments()->sole()->getAttributes());
        $this->assertSame(0, NotificationEvent::where('event_code', 'REQUEST_RETURNED_FOR_REVISION')->count());
        $this->assertSame(0, $mailAttempts, 'A failed correction audit must not attempt a misleading borrower email.');
        $this->assertNoAllocation();
    }

    #[DataProvider('correctionFailures')]
    public function test_failed_atomic_correction_removes_only_new_files_and_retains_original_evidence(string $failure): void
    {
        $request = $this->submitted();
        $this->returnBySao($request);
        $beforeFiles = Storage::disk('local')->allFiles();
        $beforeMetadata = StoredFile::count();
        $beforeNotices = NotificationDelivery::count();
        if ($failure === 'audit') {
            $realAudit = app(AuditService::class);
            $this->mock(AuditService::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($realAudit) {
                if ($args[0] === 'REQUEST_SUBMITTED') {
                    throw new \RuntimeException('Synthetic correction failure');
                }

                return $realAudit->record(...$args);
            });
        } else {
            $realFiles = app(ProtectedFileService::class);
            $uploads = 0;
            $this->mock(ProtectedFileService::class)->shouldReceive('storeUpload')->andReturnUsing(function (...$args) use ($realFiles, &$uploads) {
                if (++$uploads === 1) {
                    throw new \RuntimeException('Synthetic correction failure');
                }

                return $realFiles->storeUpload(...$args);
            });
        }
        $this->as($request->borrower, 'BORROWER')->withoutExceptionHandling();
        try {
            $this->put(route('requests.update', $request), $this->payload(false, true) + $this->documents(false, 'failed'));
            $this->fail('Correction must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic correction failure', $exception->getMessage());
        }
        $this->assertReturnedOriginal($request);
        $this->assertSame($beforeMetadata, StoredFile::count());
        $this->assertSame($beforeNotices, NotificationDelivery::count());
        $this->assertEqualsCanonicalizing($beforeFiles, Storage::disk('local')->allFiles());
        $this->assertSame(0, AuditEvent::where('action_code', 'REQUEST_DRAFT_UPDATED')->count());
    }

    public static function correctionFailures(): array
    {
        return ['submission audit' => ['audit'], 'second upload write' => ['storage']];
    }

    public function test_stale_sao_review_cannot_return_a_newly_resubmitted_version(): void
    {
        $request = $this->submitted();
        $stale = $request->fresh()->load('currentVersion.approvalSteps');
        $this->returnBySao($request);
        $this->as($request->borrower, 'BORROWER')->put(route('requests.update', $request), $this->payload(false, true) + $this->documents(false, 'new'))
            ->assertSessionHasNoErrors();
        $this->as($this->sao(), 'SPMU');
        try {
            app(RequestWorkflowService::class)->verifyByActionOfficer($stale, $this->sao(), 'RETURNED_FOR_REVISION', 'Stale instructions for version one', false);
            $this->fail('A stale reviewer action must not act on the new version.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertSame('RECEIVED', $request->fresh()->currentVersion->approvalSteps()->sole()->decision);
        $this->assertSame(1, NotificationEvent::where('event_code', 'REQUEST_RETURNED_FOR_REVISION')->count());
        $this->assertNoAllocation();
    }

    public function test_stale_sao_browser_form_cannot_return_a_newly_resubmitted_version(): void
    {
        $request = $this->submitted();
        $reviewedId = $request->currentVersion->id;
        $this->returnBySao($request);
        $this->as($request->borrower, 'BORROWER')->put(route('requests.update', $request), $this->payload(false, true) + $this->documents(false, 'fresh-http'))
            ->assertSessionHasNoErrors();
        $this->as($this->sao(), 'SPMU')->get(route('requests.show', $request))->assertOk()
            ->assertSee('name="reviewed_version_id" value="'.$request->fresh()->currentVersion->id.'"', false);
        $this->post(route('verifications.verify', $request), ['decision' => 'RETURNED_FOR_REVISION',
            'remarks' => 'Instructions from a stale browser tab', 'reviewed_version_id' => $reviewedId])->assertSessionHasErrors('decision');
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertSame('RECEIVED', $request->fresh()->currentVersion->approvalSteps()->sole()->decision);
        $this->assertSame(1, NotificationEvent::where('event_code', 'REQUEST_RETURNED_FOR_REVISION')->count());
        $this->assertNoAllocation();
    }

    public static function invalidSignatures(): array
    {
        return ['expired profile signature' => ['expired'], 'corrupt profile bytes' => ['corrupt']];
    }

    #[DataProvider('invalidSignatures')]
    public function test_invalid_current_signature_cannot_sign_a_correction_or_mutate_the_original(string $failure): void
    {
        $request = $this->submitted();
        $original = $request->currentVersion->borrowerSignature;
        $originalBytes = app(ProtectedFileService::class)->bytes($original->file);
        $this->returnBySao($request);
        $profile = $request->borrower->currentSignature()->firstOrFail();
        if ($failure === 'expired') {
            $profile->update(['effective_to' => now()->subSecond()]);
        } else {
            Storage::disk('local')->put($profile->file->storage_path, 'corrupt signature bytes');
        }
        $this->as($request->borrower, 'BORROWER')->put(route('requests.update', $request), $this->payload(false, true) + $this->documents(false, 'bad-signature'))
            ->assertSessionHasErrors('signature');
        $this->assertReturnedOriginal($request);
        $this->assertSame($originalBytes, app(ProtectedFileService::class)->bytes($original->file));
    }

    private function submitted(bool $activity = false): BorrowingRequest
    {
        $borrower = User::factory()->create(['employee_no' => null, 'employment_type' => null, 'organizational_unit_id' => null, 'access_classification' => AccessClassification::BorrowerOnly]);
        BorrowerRegistration::create(['user_id' => $borrower->id, 'registration_type' => 'EXTERNAL', 'company_name' => 'Reviewed External Agency', 'status' => 'APPROVED']);
        app(UserRoleAssignmentService::class)->synchronize($borrower, AccessClassification::BorrowerOnly, $this->sao()->id);
        $this->sign($borrower, 'Original owned ink');
        $this->post(route('requests.store'), $this->payload($activity, true) + $this->documents($activity, 'original'))->assertSessionHasNoErrors();
        $this->assertNoAllocation();

        return BorrowingRequest::sole();
    }

    private function returnBySao(BorrowingRequest $request): void
    {
        $this->as($this->sao(), 'SPMU')->post(route('verifications.verify', $request), ['decision' => 'RETURNED_FOR_REVISION', 'remarks' => 'Correct the venue and attach the corrected approved evidence.'])
            ->assertSessionHasNoErrors()->assertRedirect(route('verifications.index'));
        $this->assertSame(RequestStatus::ReturnedForRevision, $request->fresh()->status);
        $this->assertNoAllocation();
    }

    private function sign(User $user, string $ink): void
    {
        $this->as($user, 'BORROWER')->post(route('profile.signature'), ['signature' => UploadedFile::fake()->createWithContent('signature.png', EvidenceFixtures::signatureBytes($ink))])
            ->assertSessionHasNoErrors();
        Cache::flush();
    }

    private function as(User $user, string $workspace): static
    {
        Cache::flush();
        $this->actingAs($user)->withSession(['active_workspace' => $workspace]);

        return $this;
    }

    private function payload(bool $activity, bool $submit, string $venue = 'Original external venue'): array
    {
        $item = InventoryItem::where('unique_description', 'Barricade')->sole();
        $date = now()->addWeekdays(3);

        return ['purpose_event' => 'Synthetic external correction', 'location' => $venue, 'schedule_date' => $date->toDateString(), 'return_date' => $date->copy()->addWeekdays(2)->toDateString(),
            'item_ids' => [$item->id], 'quantities' => [$item->id => 1], 'locations' => [$item->id => 'OFF_CAMPUS'],
            'represents_student_activity' => $activity ? 1 : 0, 'student_organization' => $activity ? 'Synthetic represented activity' : null,
            'intent' => $submit ? 'submit' : 'draft', 'borrower_acknowledgement' => 1, 'confirm_e_signature' => 1];
    }

    private function documents(bool $activity, string $tag): array
    {
        $documents = ['approved_request_letter' => UploadedFile::fake()->createWithContent($tag.'-letter.pdf', EvidenceFixtures::pdfBytes('Synthetic '.$tag.' approved letter'))];
        if ($activity) {
            $documents['permission_to_conduct_letter'] = UploadedFile::fake()->createWithContent($tag.'-ptc.pdf', EvidenceFixtures::pdfBytes('Synthetic '.$tag.' permission'));
        }

        return $documents;
    }

    private function sao(): User
    {
        return User::where('email', 'spmu@spmu.test')->sole();
    }

    private function headUser(): User
    {
        return User::where('email', 'spmu-head@spmu.test')->sole();
    }

    private function queues(BorrowingRequest $request, bool $saoPending): void
    {
        $queue = $this->as($this->sao(), 'SPMU')->get(route('verifications.index'))->assertOk();
        $this->assertSame($saoPending, $queue->viewData('requests')->contains('id', $request->id));
        $headQueue = $this->as($this->headUser(), 'SPMU')->get(route('approvals.index'))->assertOk();
        $this->assertFalse($headQueue->viewData('requests')->contains('id', $request->id));
        if ($saoPending) {
            $this->get(route('requests.show', $request))->assertForbidden();
        } else {
            // Preserve Admin's existing read-only historical oversight after a return.
            $this->get(route('requests.show', $request))->assertOk()->assertDontSee('data-verification-decision', false);
        }
        $this->assertNoAllocation();
    }

    private function assertReturnedOriginal(BorrowingRequest $request): void
    {
        $this->assertSame(RequestStatus::ReturnedForRevision, $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->current_version_no);
        $this->assertDatabaseCount('request_versions', 1);
        $this->assertDatabaseCount('signature_snapshots', 1);
        $this->assertSame('RETURNED_FOR_REVISION', $request->fresh()->currentVersion->approvalSteps()->sole()->decision);
        $this->assertNoAllocation();
    }

    private function assertNoAllocation(): void
    {
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('custody_transactions', 0);
        $this->assertDatabaseCount('generated_documents', 0);
    }
}
