<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\ApprovalStep;
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
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EvidenceFixtures;
use Tests\TestCase;

class ExternalBorrowingSubmissionTest extends TestCase
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

    #[DataProvider('premises')]
    public function test_external_signed_submission_routes_to_existing_reviewer_without_reserving(bool $offCampus): void
    {
        $borrower = $this->borrower();
        $this->signature($borrower);
        $this->post(route('requests.store'), $this->payload($offCampus, submit: true) + ['approved_request_letter' => $this->letter()])
            ->assertSessionHasNoErrors()->assertRedirect();
        $request = BorrowingRequest::query()->sole();
        $version = $request->currentVersion;
        $this->assertSame(RequestStatus::UnderSpmu, $request->status);
        $this->assertNull($request->accountable_unit_id);
        $this->assertNull($version->division_code);
        $this->assertNull($version->office_unit);
        $this->assertSame('Example External Agency', $version->external_company_name);
        $this->assertTrue($version->accuracy_certified);
        $this->assertNotNull($version->submitted_at);
        $snapshot = SignatureSnapshot::query()->sole();
        $this->assertSame($borrower->id, $snapshot->signer_user_id);
        $this->assertSame('BORROWER_REQUEST_CERTIFICATION', $snapshot->purpose_code);
        $this->assertSame('BORROWER', $snapshot->signer_role);
        $this->assertSame($snapshot->id, $version->borrower_signature_snapshot_id);
        $this->assertSame($snapshot->captured_at->toIso8601String(), $version->signed_at->toIso8601String());
        $source = $borrower->currentSignature()->firstOrFail()->file;
        $this->assertSame($source->sha256, $snapshot->sha256);
        $this->assertNotSame($source->storage_path, $snapshot->file->storage_path);
        $this->assertSame(app(ProtectedFileService::class)->bytes($source), app(ProtectedFileService::class)->bytes($snapshot->file));
        $step = ApprovalStep::query()->sole();
        $this->assertSame($offCampus ? 1 : 2, $step->sequence_no);
        $this->assertSame('RECEIVED', $step->decision);
        $this->assertSame('PENDING_VERIFICATION', $version->supportingDocuments()->sole()->verification_status);
        $this->assertDatabaseHas('audit_events', ['action_code' => 'REQUEST_SUBMITTED', 'actor_user_id' => $borrower->id, 'record_id' => $request->id]);
        $this->assertDatabaseHas('audit_events', ['action_code' => 'E_SIGNATURE_APPLIED', 'actor_user_id' => $borrower->id]);
        $this->assertNoReservation();

        $reviewer = $this->reviewer($offCampus);
        $wrongReviewer = $this->reviewer(! $offCampus);
        $event = NotificationEvent::query()->where('event_code', 'REQUEST_SUBMITTED')->sole();
        $this->assertSame([$reviewer->id], NotificationDelivery::query()->where('notification_event_id', $event->id)->distinct()->pluck('recipient_user_id')->all());
        $this->actingAs($reviewer)->withSession(['active_workspace' => 'SPMU'])->get(route($offCampus ? 'verifications.index' : 'approvals.index'))
            ->assertOk()->assertSeeText($request->request_no);
        $this->get(route('requests.show', $request))->assertOk()->assertSeeText('Example External Agency');
        $this->actingAs($wrongReviewer)->get(route('requests.show', $request))->assertForbidden();
        $this->post(route($offCampus ? 'approvals.decide' : 'verifications.verify', $request), [
            'decision' => $offCampus ? 'APPROVED' : 'VERIFIED', 'details_complete' => 1, 'documents_complete' => 1,
            'availability_verified' => 1, 'confirm_e_signature' => 1,
        ])->assertSessionHasErrors('decision');
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertDatabaseCount('approval_steps', 1);
        $this->assertNoReservation();
    }

    public static function premises(): array
    {
        return ['on campus' => [false], 'off campus' => [true]];
    }

    public function test_external_student_activity_and_ptc_are_rejected_without_writing_documents(): void
    {
        $borrower = $this->borrower();
        $this->signature($borrower);
        $payload = $this->payload(submit: true) + ['represents_student_activity' => 1, 'approved_request_letter' => $this->letter()];
        $before = Storage::disk('local')->allFiles();
        $this->post(route('requests.store'), $payload)->assertSessionHasErrors('represents_student_activity');
        $this->assertDatabaseCount('borrowing_requests', 0);
        $this->assertEqualsCanonicalizing($before, Storage::disk('local')->allFiles());
        $this->post(route('requests.store'), $payload + ['permission_to_conduct_letter' => $this->letter('ptc.pdf')])
            ->assertSessionHasErrors(['represents_student_activity', 'permission_to_conduct_letter']);
        $this->assertDatabaseCount('borrowing_requests', 0);
        $this->assertDatabaseCount('request_supporting_documents', 0);
        $this->assertEqualsCanonicalizing($before, Storage::disk('local')->allFiles());
    }

    public static function externalFormStages(): array
    {
        return [['create'], ['draft'], ['revision']];
    }

    #[DataProvider('externalFormStages')]
    public function test_external_forms_have_no_student_activity_or_ptc_controls(string $stage): void
    {
        if ($stage === 'create') {
            $this->borrower();
            $url = route('requests.create');
        } else {
            $request = $this->draft(withLetter: true);
            // Existing External drafts/revisions may predate the CSPC-only rule.
            $request->currentVersion->update(['represents_student_activity' => true]);
            if ($stage === 'revision') {
                $request->update(['status' => RequestStatus::ReturnedForRevision]);
            }
            $url = route('requests.edit', $request);
        }
        $response = $this->get($url)->assertOk()->assertDontSee('This request represents a student activity');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        foreach (['//input[@name="represents_student_activity"]', '//input[@name="permission_to_conduct_letter"]', '//*[@id="ptc-document-box"]', '//*[@id="summary-student-activity"]'] as $query) {
            $this->assertSame(0, $xpath->query($query)->length, $query);
        }
        $this->assertSame(1, $xpath->query('//input[@name="approved_request_letter"]')->length);
    }

    public function test_cspc_form_retains_student_activity_and_ptc_controls(): void
    {
        $borrower = User::where('email', 'borrower@spmu.test')->sole();
        $response = $this->actingAs($borrower)->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('requests.create'))->assertOk()->assertSee('This request represents a student activity');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//input[@id="student-activity-toggle"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="permission_to_conduct_letter"]')->length);
    }

    public static function unsupportedExternalInputs(): array
    {
        $cases = [];
        foreach (['create', 'draft', 'revision'] as $stage) {
            foreach (['represents_student_activity', 'permission_to_conduct_letter', 'student_organization', 'represented_program_department', 'represented_year_level'] as $field) {
                $cases[$stage.' '.$field] = [$stage, $field];
            }
        }
        return $cases;
    }

    #[DataProvider('unsupportedExternalInputs')]
    public function test_external_student_activity_inputs_cannot_bypass_the_form(string $stage, string $field): void
    {
        $request = null;
        if ($stage === 'create') {
            $this->borrower();
        } else {
            $request = $this->draft(withLetter: true);
            if ($stage === 'revision') {
                $request->update(['status' => RequestStatus::ReturnedForRevision]);
            }
        }
        $before = collect(['borrowing_requests', 'request_versions', 'request_items', 'request_supporting_documents', 'stored_files', 'audit_events'])
            ->mapWithKeys(fn ($table) => [$table => \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $paths = Storage::disk('local')->allFiles();
        $value = match ($field) {
            'represents_student_activity' => 1,
            'permission_to_conduct_letter' => $this->letter('unsupported-ptc.pdf'),
            default => 'Unsupported student detail',
        };
        $payload = $this->payload() + [$field => $value];
        ($request ? $this->put(route('requests.update', $request), $payload) : $this->post(route('requests.store'), $payload))
            ->assertSessionHasErrors($field);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $this->assertEqualsCanonicalizing($paths, Storage::disk('local')->allFiles());
    }

    public function test_saved_legacy_external_activity_submits_without_ptc_and_retains_historical_evidence(): void
    {
        $request = $this->draft(withLetter: true);
        $ptc = $this->legacyPtc($request);
        $attributes = $ptc->getAttributes();
        // Unused historical PTC bytes must not block the required request letter.
        Storage::disk('local')->delete($ptc->file->storage_path);
        $this->submit($request)->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame(RequestStatus::UnderSpmu, $request->status);
        $this->assertFalse($request->currentVersion->requiresPermissionToConduct());
        $this->assertSame($attributes, $ptc->fresh()->getAttributes());
        $this->get(route('requests.show', $request))->assertOk()->assertDontSee('data-document-type="PTC_LETTER"', false);
    }

    public function test_editing_a_legacy_external_draft_supersedes_ptc_without_deleting_its_file(): void
    {
        $request = $this->draft(withLetter: true);
        $ptc = $this->legacyPtc($request);
        $bytes = Storage::disk('local')->get($ptc->file->storage_path);
        $this->put(route('requests.update', $request), $this->payload())->assertSessionHasNoErrors();
        $this->assertFalse($request->fresh()->currentVersion->represents_student_activity);
        $this->assertFalse($ptc->fresh()->is_current);
        $this->assertNotNull($ptc->fresh()->superseded_at);
        $this->assertSame($bytes, Storage::disk('local')->get($ptc->file->storage_path));
        $this->assertDatabaseHas('audit_events', ['action_code' => 'REQUEST_SUPPORTING_DOCUMENT_SUPERSEDED', 'record_id' => $request->id]);
        $this->get(route('files.show', $ptc->file))->assertOk();
    }

    private function legacyPtc(BorrowingRequest $request): RequestSupportingDocument
    {
        $request->currentVersion->update(['represents_student_activity' => true]);
        $file = app(ProtectedFileService::class)->storeUpload($this->letter('historical-ptc.pdf'), 'legacy-ptc-fixtures', 'REQUEST_SUPPORTING_DOCUMENT');
        $file->update(['uploaded_by_user_id' => $request->borrower_user_id]);
        return RequestSupportingDocument::create([
            'request_id' => $request->id, 'request_version_id' => $request->currentVersion->id,
            'document_type' => RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT, 'version_no' => 1,
            'stored_file_id' => $file->id, 'uploaded_by_user_id' => $request->borrower_user_id,
            'uploaded_at' => now(), 'is_current' => true, 'verification_status' => 'RETURNED_FOR_REVISION',
        ])->fresh();
    }

    #[DataProvider('missingConsent')]
    public function test_direct_submission_requires_explicit_acknowledgment_and_signature_confirmation(string $field): void
    {
        $borrower = $this->borrower();
        $this->signature($borrower);
        $payload = $this->payload(submit: true) + ['approved_request_letter' => $this->letter()];
        unset($payload[$field]);
        $this->post(route('requests.store'), $payload)->assertSessionHasErrors($field);
        $this->assertDatabaseCount('borrowing_requests', 0);
        $this->assertDatabaseCount('signature_snapshots', 0);
    }

    public static function missingConsent(): array
    {
        return [['borrower_acknowledgement'], ['confirm_e_signature']];
    }

    #[DataProvider('missingConsent')]
    public function test_draft_submission_requires_explicit_acknowledgment_and_signature_confirmation(string $field): void
    {
        $request = $this->draft(withLetter: true);
        $payload = ['borrower_acknowledgement' => 1, 'confirm_e_signature' => 1];
        unset($payload[$field]);

        $this->post(route('requests.submit', $request), $payload)->assertSessionHasErrors($field);
        $this->assertUnsigned($request);
    }

    public function test_missing_signature_or_letter_cannot_submit(): void
    {
        $borrower = $this->borrower();
        $this->post(route('requests.store'), $this->payload(submit: true) + ['approved_request_letter' => $this->letter()])->assertSessionHasErrors('signature');
        $this->signature($borrower);
        $this->post(route('requests.store'), $this->payload(submit: true))->assertSessionHasErrors('approved_request_letter');
        $this->assertDatabaseCount('borrowing_requests', 0);
        $this->assertNoReservation();
    }

    #[DataProvider('invalidUploads')]
    public function test_invalid_or_unreadable_supporting_upload_is_rejected_before_writes(string $kind): void
    {
        $this->signature($this->borrower());
        $upload = match ($kind) {
            'truncated pdf' => UploadedFile::fake()->createWithContent('broken.pdf', '%PDF-1.4 truncated'),
            'invalid image' => UploadedFile::fake()->createWithContent('broken.png', "\x89PNG\r\n\x1a\ninvalid"),
            'wrong type' => UploadedFile::fake()->createWithContent('payload.html', '<script>bad</script>'),
            'over limit' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf'),
        };
        $before = Storage::disk('local')->allFiles();
        $this->post(route('requests.store'), $this->payload(submit: true) + ['approved_request_letter' => $upload])->assertSessionHasErrors('approved_request_letter');
        $this->assertDatabaseCount('borrowing_requests', 0);
        $this->assertEqualsCanonicalizing($before, Storage::disk('local')->allFiles());
    }

    public static function invalidUploads(): array
    {
        return [['truncated pdf'], ['invalid image'], ['wrong type'], ['over limit']];
    }

    #[DataProvider('corruptEvidence')]
    public function test_saved_supporting_evidence_must_exist_and_match_the_stored_integrity(string $kind): void
    {
        $request = $this->draft(withLetter: true);
        $document = $request->currentVersion->supportingDocuments()->sole();
        $file = $document->file;
        match ($kind) {
            'missing' => Storage::disk('local')->delete($file->storage_path),
            'tampered' => Storage::disk('local')->put($file->storage_path, EvidenceFixtures::pdfBytes('different evidence')),
            'size' => $file->update(['byte_size' => 1]),
            'mime' => $file->update(['mime_type' => 'text/html']),
            'unreadable' => $this->corruptWithMatchingMetadata($file, '%PDF-1.4 truncated'),
            'wrong request' => $document->update(['request_id' => BorrowingRequest::query()->create([
                'request_no' => 'OTHER-DOCUMENT-OWNER', 'borrower_user_id' => $request->borrower_user_id,
                'accountable_unit_id' => null, 'current_version_no' => 1, 'status' => RequestStatus::Draft,
            ])->id]),
            'superseded' => $document->update(['is_current' => false]),
        };
        $this->submit($request)->assertSessionHasErrors('approved_request_letter');
        $this->assertUnsigned($request);
    }

    public static function corruptEvidence(): array
    {
        return [['missing'], ['tampered'], ['size'], ['mime'], ['unreadable'], ['wrong request'], ['superseded']];
    }

    #[DataProvider('invalidSignatures')]
    public function test_saved_signature_must_be_current_owned_readable_and_intact(string $kind): void
    {
        $request = $this->draft(withLetter: true);
        $signature = $request->borrower->currentSignature()->firstOrFail();
        match ($kind) {
            'missing' => Storage::disk('local')->delete($signature->file->storage_path),
            'tampered' => Storage::disk('local')->put($signature->file->storage_path, EvidenceFixtures::signatureBytes('different')),
            'unreadable' => $this->corruptWithMatchingMetadata($signature->file, "\x89PNG\r\n\x1a\ninvalid"),
            'expired' => $signature->update(['effective_to' => now()->subMinute()]),
            'future' => $signature->update(['effective_from' => now()->addDay()]),
            'wrong owner' => $signature->file->update(['uploaded_by_user_id' => $this->reviewer(true)->id]),
        };
        $this->submit($request)->assertSessionHasErrors('signature');
        $this->assertUnsigned($request);
    }

    public static function invalidSignatures(): array
    {
        return [['missing'], ['tampered'], ['unreadable'], ['expired'], ['future'], ['wrong owner']];
    }

    public function test_edit_and_submit_failure_preserves_existing_draft_items_documents_and_files(): void
    {
        $request = $this->draft(withLetter: true);
        $original = $request->currentVersion;
        $originalItemId = $original->items()->sole()->id;
        $before = Storage::disk('local')->allFiles();
        $payload = $this->payload(submit: true);
        $payload['purpose_event'] = 'Failed edit must not be saved';
        $payload['represents_student_activity'] = 1;
        $payload['approved_request_letter'] = $this->letter('replacement.pdf');
        $this->put(route('requests.update', $request), $payload)->assertSessionHasErrors('represents_student_activity');
        $this->assertSame($original->purpose_event, $request->fresh()->currentVersion->purpose_event);
        $this->assertSame($originalItemId, $request->fresh()->currentVersion->items()->sole()->id);
        $this->assertSame(1, $original->supportingDocuments()->current()->count());
        $this->assertDatabaseCount('request_supporting_documents', 1);
        $this->assertDatabaseMissing('audit_events', ['action_code' => 'REQUEST_DRAFT_UPDATED']);
        $this->assertEqualsCanonicalizing($before, Storage::disk('local')->allFiles());
        $this->assertUnsigned($request);
    }

    public function test_external_edit_and_submit_uses_frozen_company_and_new_signature_without_rewriting_old_evidence(): void
    {
        $request = $this->draft(withLetter: true);
        $this->submit($request)->assertSessionHasNoErrors();
        $original = $request->fresh()->currentVersion;
        $snapshotBytes = app(ProtectedFileService::class)->bytes($original->borrowerSignature->file);
        $originalDoc = $original->supportingDocuments()->sole();
        $request->update(['status' => RequestStatus::ReturnedForRevision]);
        $request->borrower->borrowerRegistration->update(['company_name' => 'Later changed company']);
        $this->signature($request->borrower, 'new signature');
        $this->put(route('requests.update', $request), $this->payload(submit: true) + ['approved_request_letter' => $this->letter('corrected.pdf')])->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame(2, $request->versions()->count());
        $this->assertSame('Example External Agency', $request->currentVersion->external_company_name);
        $this->assertNull($request->accountable_unit_id);
        $this->assertNotSame($original->borrower_signature_snapshot_id, $request->currentVersion->borrower_signature_snapshot_id);
        $this->assertSame($snapshotBytes, app(ProtectedFileService::class)->bytes($original->fresh()->borrowerSignature->file));
        $this->assertSame($originalDoc->stored_file_id, $original->supportingDocuments()->sole()->stored_file_id);
        $this->assertNoReservation();
    }

    public function test_stale_preloaded_request_cannot_be_signed_and_routed_twice(): void
    {
        $request = $this->draft(withLetter: true);
        $stale = $request->fresh()->load('currentVersion.items.inventoryItem', 'currentVersion.supportingDocuments');
        app(RequestWorkflowService::class)->submit($request, $request->borrower, true);
        try {
            app(RequestWorkflowService::class)->submit($stale, $request->borrower, true);
            $this->fail('Stale draft must not be submitted twice.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('signature', $exception->errors());
        }
        $this->assertDatabaseCount('signature_snapshots', 1);
        $this->assertDatabaseCount('approval_steps', 1);
        $this->assertSame(1, AuditEvent::query()->where('action_code', 'REQUEST_SUBMITTED')->count());
        $this->assertNoReservation();
    }

    public function test_submission_audit_failure_rolls_back_signature_bytes_and_all_submission_records(): void
    {
        $request = $this->draft(withLetter: true);
        $before = Storage::disk('local')->allFiles();
        $real = app(AuditService::class);
        $this->mock(AuditService::class)->shouldReceive('record')->andReturnUsing(function (...$arguments) use ($real) {
            if ($arguments[0] === 'REQUEST_SUBMITTED') {
                throw new \RuntimeException('Synthetic submission audit failure');
            }

            return $real->record(...$arguments);
        });
        try {
            app(RequestWorkflowService::class)->submit($request, $request->borrower, true);
            $this->fail('Audit failure must abort submission.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic submission audit failure', $exception->getMessage());
        }
        $this->assertUnsigned($request);
        $this->assertEqualsCanonicalizing($before, Storage::disk('local')->allFiles());
        $this->assertDatabaseMissing('audit_events', ['action_code' => 'E_SIGNATURE_APPLIED']);
    }

    #[DataProvider('blockedActors')]
    public function test_unapproved_inactive_other_borrower_and_technical_staff_cannot_submit(string $kind): void
    {
        $request = $this->draft(withLetter: true);
        $actor = $request->borrower;
        if ($kind === 'pending') {
            $actor->borrowerRegistration->update(['status' => 'PENDING']);
        } elseif ($kind === 'inactive') {
            $actor->update(['account_status' => 'INACTIVE']);
        } elseif ($kind === 'other') {
            $actor = $this->borrower();
        } elseif ($kind === 'ictu') {
            $actor = User::query()->where('access_classification', AccessClassification::IctuMaintainer->value)->firstOrFail();
        }
        $response = $this->actingAs($actor->fresh())->withSession(['active_workspace' => $kind === 'ictu' ? 'ICTU' : 'BORROWER'])
            ->post(route('requests.submit', $request), ['borrower_acknowledgement' => 1, 'confirm_e_signature' => 1]);
        $this->assertContains($response->status(), [302, 403]);
        $this->assertUnsigned($request);
    }

    public static function blockedActors(): array
    {
        return [['pending'], ['inactive'], ['other'], ['ictu']];
    }

    #[DataProvider('invalidItems')]
    public function test_submission_rechecks_current_item_eligibility(string $kind): void
    {
        $request = $this->draft(withLetter: true);
        $item = $request->currentVersion->items()->sole()->inventoryItem;
        match ($kind) {
            'inactive' => $item->update(['active' => false]),
            'not borrowable' => $item->update(['borrowable' => false]),
            'damaged' => $item->update(['condition_code' => 'DAMAGED']),
            'off campus restricted' => $request->currentVersion->items()->sole()->update(['use_location' => 'OFF_CAMPUS']),
        };
        $this->submit($request)->assertSessionHasErrors($kind === 'off campus restricted' ? 'locations' : 'items');
        $this->assertUnsigned($request);
    }

    public static function invalidItems(): array
    {
        return [['inactive'], ['not borrowable'], ['damaged'], ['off campus restricted']];
    }

    #[DataProvider('premises')]
    public function test_submission_notices_exclude_inactive_unverified_and_revoked_role_staff(bool $offCampus): void
    {
        $request = $this->draft(withLetter: true, offCampus: $offCampus);
        foreach (['inactive', 'unverified', 'revoked'] as $kind) {
            $classification = $offCampus ? AccessClassification::SpmuOfficer : AccessClassification::SpmuHead;
            $staff = User::factory()->create(['access_classification' => $classification]);
            app(UserRoleAssignmentService::class)->synchronize($staff, $classification, $this->reviewer(false)->id);
            if ($kind === 'inactive') {
                $staff->update(['account_status' => 'INACTIVE']);
            } elseif ($kind === 'unverified') {
                $staff->forceFill(['email_verified_at' => null])->save();
            } else {
                $staff->roles()->updateExistingPivot($staff->roles()->sole()->id, ['revoked_at' => now()]);
            }
        }
        $this->submit($request)->assertSessionHasNoErrors();
        $event = NotificationEvent::query()->where('event_code', 'REQUEST_SUBMITTED')->sole();
        $this->assertSame([$this->reviewer($offCampus)->id], $event->deliveries()->distinct()->pluck('recipient_user_id')->all());
    }

    public function test_off_campus_sao_verification_is_signed_and_routes_to_admin_without_reservation(): void
    {
        $this->signature($this->borrower());
        $this->post(route('requests.store'), $this->payload(true, true) + ['approved_request_letter' => $this->letter()])->assertSessionHasNoErrors();
        $request = BorrowingRequest::query()->sole();
        $unverifiedHead = User::factory()->create(['access_classification' => AccessClassification::SpmuHead, 'email_verified_at' => null]);
        app(UserRoleAssignmentService::class)->synchronize($unverifiedHead, AccessClassification::SpmuHead, $this->reviewer(false)->id);
        $officer = $this->reviewer(true);
        $this->signature($officer);
        $this->withSession(['active_workspace' => 'SPMU'])->post(route('verifications.verify', $request), [
            'decision' => 'VERIFIED', 'details_complete' => 1, 'documents_complete' => 1,
            'availability_verified' => 1, 'confirm_e_signature' => 1,
        ])->assertSessionHasNoErrors();
        $version = $request->fresh()->currentVersion;
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertSame('VERIFIED', $version->approvalSteps()->where('sequence_no', 1)->sole()->decision);
        $this->assertSame('RECEIVED', $version->approvalSteps()->where('sequence_no', 2)->sole()->decision);
        $this->assertDatabaseHas('signature_snapshots', ['signer_user_id' => $officer->id, 'purpose_code' => 'SPMU_REQUEST_VERIFICATION']);
        $this->assertSame('VERIFIED', $version->supportingDocuments()->sole()->verification_status);
        $event = NotificationEvent::query()->where('event_code', 'REQUEST_VERIFIED')->sole();
        $this->assertSame([$this->reviewer(false)->id], $event->deliveries()->distinct()->pluck('recipient_user_id')->all());
        $this->actingAs($this->reviewer(false))->withSession(['active_workspace' => 'SPMU'])
            ->get(route('approvals.index'))->assertOk()->assertSeeText($request->request_no);
        $this->assertNoReservation();
    }

    public function test_guest_submission_is_denied(): void
    {
        $request = $this->draft(withLetter: true);
        auth()->forgetGuards();
        $this->post(route('requests.submit', $request), ['borrower_acknowledgement' => 1, 'confirm_e_signature' => 1])->assertRedirect(route('login'));
        $this->assertUnsigned($request);
    }

    public function test_failed_storage_write_cannot_create_stored_file_metadata(): void
    {
        $this->borrower();
        $before = StoredFile::query()->count();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(ProtectedFileService::class)->storeBytes('synthetic', 'verification', 'test.txt', 'text/plain', 'txt');
            $this->fail('Failed write must not create a stored file.');
        } catch (\App\Exceptions\ProtectedFileStorageException $exception) {
            $this->assertSame('The file could not be written to the [local] disk.', $exception->getMessage());
        }
        $this->assertSame($before, StoredFile::query()->count());
    }

    private function borrower(): User
    {
        $user = User::factory()->create(['employee_no' => null, 'employment_type' => null, 'organizational_unit_id' => null,
            'access_classification' => AccessClassification::BorrowerOnly]);
        BorrowerRegistration::query()->create(['user_id' => $user->id, 'registration_type' => 'EXTERNAL',
            'company_name' => 'Example External Agency', 'status' => 'APPROVED']);
        app(UserRoleAssignmentService::class)->synchronize($user, AccessClassification::BorrowerOnly, $this->reviewer(true)->id);
        $this->actingAs($user)->withSession(['active_workspace' => 'BORROWER']);

        return $user;
    }

    private function signature(User $user, string $ink = 'synthetic signature'): void
    {
        $this->actingAs($user)->withSession(['active_workspace' => 'BORROWER'])
            ->post(route('profile.signature'), ['signature' => UploadedFile::fake()->createWithContent('signature.png', EvidenceFixtures::signatureBytes($ink))])
            ->assertSessionHasNoErrors();
        Cache::flush();
    }

    private function payload(bool $offCampus = false, bool $submit = false): array
    {
        $item = InventoryItem::query()->where('unique_description', $offCampus ? 'Barricade' : 'Monoblock Chairs')->sole();
        $date = now()->addWeekday();

        return ['purpose_event' => 'External submission', 'location' => 'Synthetic venue', 'schedule_date' => $date->toDateString(),
            'return_date' => $date->copy()->addWeekday()->toDateString(), 'item_ids' => [$item->id], 'quantities' => [$item->id => 1],
            'locations' => [$item->id => $offCampus ? 'OFF_CAMPUS' : 'ON_CAMPUS'], 'intent' => $submit ? 'submit' : 'draft',
            'borrower_acknowledgement' => 1, 'confirm_e_signature' => 1];
    }

    private function letter(string $name = 'activity-letter.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, EvidenceFixtures::pdfBytes());
    }

    private function draft(bool $withLetter = false, bool $offCampus = false): BorrowingRequest
    {
        $this->signature($this->borrower());
        $this->post(route('requests.store'), $this->payload($offCampus) + ($withLetter ? ['approved_request_letter' => $this->letter()] : []))->assertSessionHasNoErrors();

        return BorrowingRequest::query()->sole();
    }

    private function submit(BorrowingRequest $request)
    {
        return $this->post(route('requests.submit', $request), ['borrower_acknowledgement' => 1, 'confirm_e_signature' => 1]);
    }

    private function reviewer(bool $officer): User
    {
        return User::query()->where('email', $officer ? 'spmu@spmu.test' : 'spmu-head@spmu.test')->sole();
    }

    private function corruptWithMatchingMetadata(StoredFile $file, string $bytes): bool
    {
        Storage::disk('local')->put($file->storage_path, $bytes);

        return $file->update(['sha256' => hash('sha256', $bytes), 'byte_size' => strlen($bytes)]);
    }

    private function assertNoReservation(): void
    {
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('custody_transactions', 0);
    }

    private function assertUnsigned(BorrowingRequest $request): void
    {
        $this->assertSame(RequestStatus::Draft, $request->fresh()->status);
        $this->assertNull($request->fresh()->currentVersion->signed_at);
        $this->assertNull($request->fresh()->currentVersion->borrower_signature_snapshot_id);
        $this->assertDatabaseCount('signature_snapshots', 0);
        $this->assertDatabaseCount('approval_steps', 0);
        $this->assertNoReservation();
    }
}
