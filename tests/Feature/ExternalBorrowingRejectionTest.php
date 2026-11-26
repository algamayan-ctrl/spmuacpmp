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
use App\Models\SignatureSnapshot;
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

class ExternalBorrowingRejectionTest extends TestCase
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

    public static function activities(): array
    {
        return ['external regular' => [false]];
    }

    #[DataProvider('activities')]
    public function test_actual_corrected_sao_signing_then_head_rejection_preserves_evidence_without_reservation(bool $activity): void
    {
        $request = $this->corrected($activity);
        $versions = $request->versions()->get()->mapWithKeys(fn ($v) => [$v->id => $v->getAttributes()]);
        $snapshots = SignatureSnapshot::get()->mapWithKeys(fn ($s) => [$s->id => $s->getAttributes()]);
        $files = StoredFile::get()->mapWithKeys(fn ($f) => [$f->id => app(ProtectedFileService::class)->bytes($f)]);
        $this->queues($request, true, false);
        $this->sign($this->sao(), 'Owned SAO verification ink');
        $this->verify($request);
        $current = $request->fresh()->currentVersion;
        $saoStep = $current->approvalSteps()->where('sequence_no', 1)->sole();
        $signature = $saoStep->signatureSnapshot;
        $signatureAttributes = $signature->getAttributes();
        $signatureBytes = app(ProtectedFileService::class)->bytes($signature->file);
        $this->assertSame('VERIFIED', $saoStep->decision);
        $this->assertSame($this->sao()->id, $saoStep->approver_user_id);
        $this->assertSame($this->sao()->id, $signature->signer_user_id);
        $this->assertSame('SPMU_ACTION_OFFICER', $signature->signer_role);
        $this->assertSame('SPMU_REQUEST_VERIFICATION', $signature->purpose_code);
        $signatureAudit = AuditEvent::where('action_code', 'E_SIGNATURE_APPLIED')->where('record_id', $saoStep->id)
            ->where('record_type', $saoStep::class)->sole();
        $this->assertSame($current->id, $signatureAudit->after_json['request_version_id']);
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertSame('RECEIVED', $current->approvalSteps()->where('sequence_no', 2)->sole()->decision);
        foreach ($current->supportingDocuments as $doc) {
            $this->assertSame(RequestSupportingDocument::STATUS_VERIFIED, $doc->verification_status);
            $this->assertSame($this->sao()->id, $doc->verified_by_user_id);
            $this->assertNotNull($doc->verified_at);
        }
        $this->notice('REQUEST_VERIFIED', $this->headUser()->id, $this->sao()->id);
        $this->assertDatabaseHas('audit_events', ['action_code' => 'SPMU_ACTION_OFFICER_VERIFICATION', 'record_id' => $saoStep->id, 'actor_user_id' => $this->sao()->id]);
        $this->queues($request, false, true);
        $this->as($this->headUser())->get(route('requests.show', $request))->assertOk()
            ->assertSee('name="reviewed_version_id" value="'.$current->id.'"', false);
        $this->sign($this->sao(), 'Later profile ink must not rewrite verification');
        $this->as($this->headUser())->post(route('approvals.decide', $request), $this->rejection($request))
            ->assertSessionHasNoErrors()->assertRedirect(route('approvals.index'));
        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
        $headStep = $current->approvalSteps()->where('sequence_no', 2)->sole();
        $this->assertSame('REJECTED', $headStep->decision);
        $this->assertSame($this->headUser()->id, $headStep->approver_user_id);
        $this->assertSame('Synthetic Admin rejection with explanation.', $headStep->remarks);
        $this->assertNull($headStep->signature_snapshot_id);
        $this->assertSame(3, SignatureSnapshot::count());
        $this->assertSame($signatureAttributes, $signature->fresh()->getAttributes());
        $this->assertSame($signatureBytes, app(ProtectedFileService::class)->bytes($signature->file));
        foreach ($versions as $id => $attributes) {
            $this->assertSame($attributes, $request->versions()->findOrFail($id)->getAttributes());
        }
        foreach ($snapshots as $id => $attributes) {
            $this->assertSame($attributes, SignatureSnapshot::findOrFail($id)->getAttributes());
        }
        foreach ($files as $id => $bytes) {
            $file = StoredFile::findOrFail($id);
            $this->assertSame($bytes, app(ProtectedFileService::class)->bytes($file));
            $this->as($request->borrower)->get(route('files.show', $file))->assertOk();
        }
        foreach ($current->supportingDocuments as $doc) {
            $this->assertSame('VERIFIED', $doc->fresh()->verification_status);
        }
        $this->assertSame('Reviewed External Agency', $current->external_company_name);
        $this->assertNull($current->division_code);
        $this->assertNull($current->office_unit);
        $this->assertNull($request->accountable_unit_id);
        $this->assertDatabaseHas('request_status_histories', ['request_id' => $request->id, 'request_version_id' => $current->id,
            'to_status' => 'REJECTED', 'actor_user_id' => $this->headUser()->id]);
        $this->assertDatabaseHas('audit_events', ['action_code' => 'SPMU_REQUEST_DECISION', 'record_id' => $headStep->id, 'actor_user_id' => $this->headUser()->id]);
        $this->notice('REQUEST_REJECTED', $request->borrower_user_id, $this->headUser()->id);
        $this->assertStringContainsString($headStep->remarks, NotificationEvent::where('event_code', 'REQUEST_REJECTED')->sole()->payload_snapshot_json['message']);
        $this->queues($request, false, false);
        $this->as($request->borrower)->get(route('requests.show', $request))->assertOk()->assertSeeText($headStep->remarks)
            ->assertDontSeeText('Awaiting SPMU review')->assertSeeText('Request rejected');
    }

    public static function confirmations(): array
    {
        return array_combine($fields = ['details_complete', 'documents_complete', 'availability_verified', 'confirm_e_signature'], array_map(fn ($field) => [$field], $fields));
    }

    #[DataProvider('confirmations')]
    public function test_sao_requires_each_deliberate_confirmation(string $field): void
    {
        $request = $this->corrected();
        $this->sign($this->sao(), 'SAO ink');
        $payload = $this->verification($request);
        unset($payload[$field]);
        $this->as($this->sao())->post(route('verifications.verify', $request), $payload)->assertSessionHasErrors($field);
        $this->assertPendingSao($request);
    }

    public static function badSignatures(): array
    {
        return ['absent' => ['absent'], 'expired' => ['expired'], 'corrupt' => ['corrupt']];
    }

    #[DataProvider('badSignatures')]
    public function test_invalid_sao_signature_cannot_verify_or_forward(string $failure): void
    {
        $request = $this->corrected();
        if ($failure !== 'absent') {
            $this->sign($this->sao(), 'SAO ink');
            $profile = $this->sao()->currentSignature()->sole();
            if ($failure === 'expired') {
                $profile->update(['effective_to' => now()->subSecond()]);
            } else {
                Storage::disk('local')->put($profile->file->storage_path, 'corrupt bytes');
            }
        }
        $this->as($this->sao())->post(route('verifications.verify', $request), $this->verification($request))->assertSessionHasErrors('signature');
        $this->assertPendingSao($request);
    }

    public static function evidenceFailures(): array
    {
        return ['missing letter' => [RequestSupportingDocument::TYPE_REQUEST_LETTER, false], 'corrupt letter' => [RequestSupportingDocument::TYPE_REQUEST_LETTER, true]];
    }

    #[DataProvider('evidenceFailures')]
    public function test_sao_revalidates_required_corrected_private_evidence(string $type, bool $corrupt): void
    {
        $request = $this->corrected();
        $this->sign($this->sao(), 'SAO ink');
        $doc = $request->currentVersion->supportingDocuments()->where('document_type', $type)->sole();
        if ($corrupt) {
            Storage::disk('local')->put($doc->file->storage_path, 'corrupt PDF');
        } else {
            Storage::disk('local')->delete($doc->file->storage_path);
        }
        $response = $this->as($this->sao())->post(route('verifications.verify', $request), $this->verification($request));
        $response->assertSessionHasErrors();
        $this->assertPendingSao($request);
    }

    public function test_admin_cannot_reject_before_current_sao_verification(): void
    {
        $request = $this->corrected();
        $this->as($this->headUser())->post(route('approvals.decide', $request), $this->rejection($request))->assertSessionHasErrors('decision');
        $this->assertPendingSao($request);
    }

    public static function wrongActors(): array
    {
        return ['SAO' => ['sao'], 'borrower' => ['borrower'], 'ICTU' => ['ictu'], 'revoked Admin' => ['revoked']];
    }

    #[DataProvider('wrongActors')]
    public function test_rejection_preserves_existing_authority(string $actor): void
    {
        $request = $this->verified();
        $user = match ($actor) {
            'sao' => $this->sao(), 'borrower' => $request->borrower,
            'ictu' => User::where('email', 'ictu@spmu.test')->sole(), default => $this->headUser(),
        };
        if ($actor === 'revoked') {
            $user->roles()->detach();
        }
        $this->as($user)->post(route('approvals.decide', $request), $this->rejection($request))->assertForbidden();
        $this->assertPendingHead($request);
    }

    public function test_rejection_requires_nonblank_bounded_remarks_and_accepts_maximum(): void
    {
        $request = $this->verified();
        foreach (['', '  ', str_repeat('x', 2001)] as $remarks) {
            $payload = $this->rejection($request);
            $payload['remarks'] = $remarks;
            $this->as($this->headUser())->post(route('approvals.decide', $request), $payload)->assertSessionHasErrors('remarks');
            $this->assertPendingHead($request);
        }
        $payload['remarks'] = str_repeat('x', 2000);
        $this->as($this->headUser())->post(route('approvals.decide', $request), $payload)->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
        $this->noAllocation();
    }

    public function test_repeated_verification_and_rejection_do_not_duplicate_decisions_or_notices(): void
    {
        $request = $this->verified();
        $this->as($this->sao())->post(route('verifications.verify', $request), $this->verification($request))->assertSessionHasErrors('decision');
        $this->assertSame(3, SignatureSnapshot::count());
        $this->assertSame(1, NotificationEvent::where('event_code', 'REQUEST_VERIFIED')->count());
        $this->as($this->headUser())->post(route('approvals.decide', $request), $this->rejection($request))->assertSessionHasNoErrors();
        Cache::flush();
        $this->post(route('approvals.decide', $request), $this->rejection($request))->assertSessionHasErrors('decision');
        $this->assertSame(1, AuditEvent::where('action_code', 'SPMU_REQUEST_DECISION')->count());
        $this->assertSame(1, NotificationEvent::where('event_code', 'REQUEST_REJECTED')->count());
        $this->noAllocation();
    }

    public static function invalidReviewedIdentities(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'text' => ['bad'], 'fractional' => [1.5]];
    }

    #[DataProvider('invalidReviewedIdentities')]
    public function test_invalid_admin_review_identity_does_not_mutate_pending_decision(mixed $identity): void
    {
        $request = $this->verified();
        $payload = $this->rejection($request);
        $payload['reviewed_version_id'] = $identity;
        $this->as($this->headUser())->post(route('approvals.decide', $request), $payload)->assertSessionHasErrors('reviewed_version_id');
        $this->assertPendingHead($request);
    }

    public function test_borrower_rejection_reason_is_escaped_and_does_not_offer_edit_or_resubmit(): void
    {
        $request = $this->verified();
        $payload = $this->rejection($request);
        $payload['remarks'] = '<script>alert("synthetic")</script> Evidence does not meet the reviewed request.';
        $this->as($this->headUser())->post(route('approvals.decide', $request), $payload)->assertSessionHasNoErrors();
        $this->as($request->borrower)->get(route('requests.show', $request))->assertOk()->assertSeeText('Rejection reason')
            ->assertSee(e($payload['remarks']), false)->assertDontSee($payload['remarks'], false)
            ->assertDontSee('Create Corrected Version')->assertDontSee('Edit Draft &amp; Upload Documents', false);
        $this->noAllocation();
    }

    public function test_stale_bound_admin_review_cannot_reject_a_later_verified_correction(): void
    {
        $request = $this->verified();
        $stale = $request->fresh()->load('currentVersion.approvalSteps');
        $this->thirdVersion($request);
        $this->as($this->headUser());
        try {
            app(RequestWorkflowService::class)->decide($stale, $this->headUser(), 'REJECTED', 'Stale v2 rejection', false);
            $this->fail('Stale bound review must not reject v3.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }
        $this->assertPendingHead($request);
    }

    public function test_stale_admin_form_cannot_reject_a_later_verified_correction(): void
    {
        $request = $this->verified();
        $payload = $this->rejection($request);
        $this->thirdVersion($request);
        $this->as($this->headUser())->post(route('approvals.decide', $request), $payload)->assertSessionHasErrors('decision');
        $this->assertPendingHead($request);
    }

    public static function auditFailures(): array
    {
        return ['SAO verification' => ['verify'], 'Admin rejection' => ['reject']];
    }

    #[DataProvider('auditFailures')]
    public function test_failed_audit_rolls_back_without_attempting_a_misleading_notice(string $stage): void
    {
        $request = $stage === 'verify' ? $this->corrected() : $this->verified();
        if ($stage === 'verify') {
            $this->sign($this->sao(), 'Rollback SAO ink');
        }
        $beforeFiles = Storage::disk('local')->allFiles();
        $metadata = StoredFile::count();
        $snapshots = SignatureSnapshot::count();
        $deliveries = NotificationDelivery::count();
        $steps = $request->currentVersion->approvalSteps()->get()->map->getAttributes()->all();
        $documents = $request->currentVersion->supportingDocuments()->get()->map->getAttributes()->all();
        $realAudit = app(AuditService::class);
        $action = $stage === 'verify' ? 'SPMU_ACTION_OFFICER_VERIFICATION' : 'SPMU_REQUEST_DECISION';
        $this->mock(AuditService::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($realAudit, $action) {
            if ($args[0] === $action) {
                throw new \RuntimeException('Synthetic review audit failure');
            }

            return $realAudit->record(...$args);
        });
        $attempts = 0;
        Mail::shouldReceive('html')->andReturnUsing(function () use (&$attempts) {
            $attempts++;
        });
        $actor = $stage === 'verify' ? $this->sao() : $this->headUser();
        $this->as($actor)->withoutExceptionHandling();
        try {
            $this->post(route($stage === 'verify' ? 'verifications.verify' : 'approvals.decide', $request),
                $stage === 'verify' ? $this->verification($request) : $this->rejection($request));
            $this->fail('Review audit failure must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic review audit failure', $exception->getMessage());
        }
        $this->assertSame($steps, $request->fresh()->currentVersion->approvalSteps()->get()->map->getAttributes()->all());
        $this->assertSame($documents, $request->fresh()->currentVersion->supportingDocuments()->get()->map->getAttributes()->all());
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertSame($metadata, StoredFile::count());
        $this->assertSame($snapshots, SignatureSnapshot::count());
        $this->assertSame($deliveries, NotificationDelivery::count());
        $this->assertEqualsCanonicalizing($beforeFiles, Storage::disk('local')->allFiles());
        $this->assertSame(0, $attempts, 'Known failed audit must not attempt a misleading email.');
        $this->noAllocation();
    }

    private function corrected(bool $activity = false): BorrowingRequest
    {
        $borrower = User::factory()->create(['employee_no' => null, 'employment_type' => null, 'organizational_unit_id' => null, 'access_classification' => AccessClassification::BorrowerOnly]);
        BorrowerRegistration::create(['user_id' => $borrower->id, 'registration_type' => 'EXTERNAL', 'company_name' => 'Reviewed External Agency', 'status' => 'APPROVED']);
        app(UserRoleAssignmentService::class)->synchronize($borrower, AccessClassification::BorrowerOnly, $this->sao()->id);
        $this->sign($borrower, 'Original borrower ink');
        $this->post(route('requests.store'), $this->payload($activity, 'Original external venue') + $this->documents($activity, 'original'))->assertSessionHasNoErrors();
        $request = BorrowingRequest::sole();
        $this->as($this->sao())->post(route('verifications.verify', $request), ['decision' => 'RETURNED_FOR_REVISION', 'remarks' => 'Correct the venue and evidence.'])->assertSessionHasNoErrors();
        $borrower->borrowerRegistration->update(['company_name' => 'Later profile agency']);
        $this->sign($borrower, 'Corrected borrower ink');
        $this->put(route('requests.update', $request), $this->payload($activity, 'Corrected external venue') + $this->documents($activity, 'corrected'))->assertSessionHasNoErrors();
        $this->assertSame(2, $request->fresh()->current_version_no);
        $this->noAllocation();

        return $request->fresh();
    }

    private function verified(bool $activity = false): BorrowingRequest
    {
        $request = $this->corrected($activity);
        $this->sign($this->sao(), 'Owned SAO ink');
        $this->verify($request);

        return $request->fresh();
    }

    private function thirdVersion(BorrowingRequest $request): void
    {
        $this->as($this->headUser())->post(route('approvals.decide', $request), ['decision' => 'RETURNED_FOR_REVISION', 'remarks' => 'Correct v2 venue.'])->assertSessionHasNoErrors();
        $this->as($request->borrower)->put(route('requests.update', $request), $this->payload(false, 'Third corrected venue') + $this->documents(false, 'third'))->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame(3, $request->current_version_no);
        $this->verify($request);
    }

    private function verify(BorrowingRequest $request): void
    {
        $this->as($this->sao())->post(route('verifications.verify', $request), $this->verification($request))->assertSessionHasNoErrors()->assertRedirect(route('verifications.index'));
        $this->noAllocation();
    }

    private function verification(BorrowingRequest $request): array
    {
        return ['decision' => 'VERIFIED', 'reviewed_version_id' => $request->fresh()->currentVersion->id,
            'details_complete' => 1, 'documents_complete' => 1, 'availability_verified' => 1, 'confirm_e_signature' => 1];
    }

    private function rejection(BorrowingRequest $request): array
    {
        return ['decision' => 'REJECTED', 'reviewed_version_id' => $request->fresh()->currentVersion->id, 'remarks' => 'Synthetic Admin rejection with explanation.'];
    }

    private function sign(User $user, string $ink): void
    {
        $this->as($user)->post(route('profile.signature'), ['signature' => UploadedFile::fake()->createWithContent('signature.png', EvidenceFixtures::signatureBytes($ink))])->assertSessionHasNoErrors();
    }

    private function as(User $user): static
    {
        Cache::flush();
        $this->actingAs($user)->withSession(['active_workspace' => $user->primaryWorkspace()]);

        return $this;
    }

    private function payload(bool $activity, string $venue): array
    {
        $item = InventoryItem::where('unique_description', 'Barricade')->sole();
        $date = now()->addWeekdays(3);

        return ['purpose_event' => 'Synthetic external rejection', 'location' => $venue, 'schedule_date' => $date->toDateString(), 'return_date' => $date->copy()->addWeekdays(2)->toDateString(),
            'item_ids' => [$item->id], 'quantities' => [$item->id => 1], 'locations' => [$item->id => 'OFF_CAMPUS'],
            'represents_student_activity' => $activity ? 1 : 0, 'student_organization' => $activity ? 'Synthetic represented activity' : null,
            'intent' => 'submit', 'borrower_acknowledgement' => 1, 'confirm_e_signature' => 1];
    }

    private function documents(bool $activity, string $tag): array
    {
        $documents = ['approved_request_letter' => UploadedFile::fake()->createWithContent($tag.'-letter.pdf', EvidenceFixtures::pdfBytes('Synthetic '.$tag.' letter'))];
        if ($activity) {
            $documents['permission_to_conduct_letter'] = UploadedFile::fake()->createWithContent($tag.'-ptc.pdf', EvidenceFixtures::pdfBytes('Synthetic '.$tag.' PTC'));
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

    private function queues(BorrowingRequest $request, bool $sao, bool $head): void
    {
        $this->assertSame($sao, $this->as($this->sao())->get(route('verifications.index'))->assertOk()->viewData('requests')->contains('id', $request->id));
        $this->assertSame($head, $this->as($this->headUser())->get(route('approvals.index'))->assertOk()->viewData('requests')->contains('id', $request->id));
        $this->noAllocation();
    }

    private function notice(string $code, int $recipient, int $actor): void
    {
        $notice = NotificationEvent::where('event_code', $code)->sole();
        $this->assertSame($actor, $notice->created_by_user_id);
        $this->assertSame([$recipient], $notice->deliveries()->distinct()->pluck('recipient_user_id')->all());
    }

    private function assertPendingSao(BorrowingRequest $request): void
    {
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertSame('RECEIVED', $request->fresh()->currentVersion->approvalSteps()->sole()->decision);
        $this->assertSame(2, SignatureSnapshot::count());
        $this->assertSame(0, NotificationEvent::where('event_code', 'REQUEST_VERIFIED')->count());
        foreach ($request->fresh()->currentVersion->supportingDocuments as $doc) {
            $this->assertSame('PENDING_VERIFICATION', $doc->verification_status);
            $this->assertNull($doc->verified_by_user_id);
        }
        $this->noAllocation();
    }

    private function assertPendingHead(BorrowingRequest $request): void
    {
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertSame('RECEIVED', $request->fresh()->currentVersion->approvalSteps()->where('sequence_no', 2)->sole()->decision);
        $this->assertSame(0, NotificationEvent::where('event_code', 'REQUEST_REJECTED')->count());
        $this->noAllocation();
    }

    private function noAllocation(): void
    {
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('custody_transactions', 0);
        $this->assertDatabaseCount('generated_documents', 0);
    }
}
