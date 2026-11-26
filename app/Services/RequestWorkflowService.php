<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\Allocation;
use App\Models\ApprovalStep;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\GatePass;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\RequestCancellation;
use App\Models\RequestStatusHistory;
use App\Models\RequestSupportingDocument;
use App\Models\SignatureSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

// This is the service for borrowing request verification, approval, and workflow transitions.
class RequestWorkflowService
{
    public function __construct(
        private InventoryService $inventory,
        private CustodyService $custody,
        private SignatureService $signatures,
        private DocumentService $documents,
        private NotificationService $notifications,
        private AuditService $audit,
        private OperationalCalendarService $operationalCalendar,
        private BorrowerObligationService $obligations,
    ) {}

    /**
     * The single source of truth for whether a borrower may create or
     * submit a new borrowing request right now. Combines the narrow
     * scheduler-lag safety check below (an item can be physically overdue
     * before the daily scheduler has recorded it as such) with
     * BorrowerObligationService::blockingReasons(), which is itself the
     * same "open case/obligation/restriction" definition already used by
     * the borrower dashboard and My Obligations - so this never disagrees
     * with what the borrower is shown there, and never decides eligibility
     * from only the borrower's latest sanction.
     *
     * Throws with every applicable reason at once (not just the first
     * found) so the borrower sees the complete picture instead of
     * resolving one blocker only to discover another on the next attempt.
     */
    public function assertEligibleToBorrow(User $borrower): void
    {
        /*
         * A normal active custody is NOT, by itself, a borrowing
         * restriction. Borrowers may submit another request while
         * previously issued property is still on custody, provided it is
         * still within the effective return period. RETURN_PROCESSING/
         * PARTIALLY_RETURNED records that are still on time are
         * deliberately allowed.
         */
        $overdueCustody = CustodyTransaction::query()
            ->where('borrower_user_id', $borrower->id)
            ->whereNull('closed_at')
            ->whereNotNull('released_at')
            ->where(function ($query): void {
                $query->where('status', 'OVERDUE')
                    ->orWhere(function ($query): void {
                        /*
                         * Do not depend only on the scheduler having already
                         * changed ACTIVE/RETURN_PROCESSING to OVERDUE. If the
                         * effective due time has passed and released quantity
                         * is still outstanding, this must already block.
                         */
                        $query->whereNotNull('due_at')
                            ->where('due_at', '<', now())
                            ->whereHas('lines', function ($lineQuery): void {
                                $lineQuery->whereColumn(
                                    'returned_quantity',
                                    '<',
                                    'actual_released_quantity'
                                );
                            });
                    });
            })
            ->latest('id')
            ->first();

        $reasons = $overdueCustody
            ? ["Property under {$overdueCustody->custody_no} is already overdue for return."]
            : [];

        $reasons = array_values(array_unique(array_merge(
            $reasons,
            $this->obligations->blockingReasons($borrower->id)
        )));

        if ($reasons !== []) {
            throw ValidationException::withMessages(['restriction' => $reasons]);
        }
    }

    /**
     * Submit to Admin on-campus, or Action Officer verification off-campus.
     *
     * Submission and Action Officer verification never approve the request or
     * reserve inventory. Only the later SPMU Head decision can do either.
     */
    public function submit(
        BorrowingRequest $request,
        User $borrower,
        bool $signatureConfirmed
    ): void {
        if (! $borrower->mayBorrow()) {
            abort(
                403,
                'This account classification is not permitted to borrow.'
            );
        }

        if (
            $request->borrower_user_id !== $borrower->id
            || ! in_array(
                $request->status,
                [
                    RequestStatus::Draft,
                    RequestStatus::ReturnedForRevision,
                ],
                true
            )
        ) {
            abort(403);
        }

        if (! $signatureConfirmed) {
            throw ValidationException::withMessages([
                'confirm_e_signature' => 'Confirm that you want to apply your registered E-signature before submitting.',
            ]);
        }

        $this->assertEligibleToBorrow($borrower);

        $academicNumbering = app(AcademicYearRequestNumbers::class)->enabled();
        DB::transaction(
            function () use (
                $request,
                $borrower,
                $academicNumbering
            ): void {
                if ($academicNumbering) app(AcademicYearRequestNumbers::class)->lockSubmission($request);
                // A route-bound or preloaded draft may be stale after another submit/edit.
                // Serialize the signing action on the request header and reload its version.
                $request = BorrowingRequest::query()->lockForUpdate()->findOrFail($request->id);
                abort_unless($request->borrower_user_id === $borrower->id
                    && in_array($request->status, [RequestStatus::Draft, RequestStatus::ReturnedForRevision], true), 403);
                $request->loadMissing([
                    'currentVersion.items.inventoryItem',
                    'currentVersion.supportingDocuments',
                ]);

                $version = $request->currentVersion;

                if (
                    ! $version
                    || $version->items->isEmpty()
                ) {
                    throw ValidationException::withMessages([
                        'items' =>
                            'Add at least one inventory item before submission.',
                    ]);
                }

                if (
                    $version->borrower_signature_snapshot_id
                    || $version->signed_at
                ) {
                    throw ValidationException::withMessages([
                        'signature' => 'This request version is already signed. Open the returned request for editing so the correction is saved as a new version before signing again.',
                    ]);
                }

                $scheduleDate = ($version->schedule_date ?: $version->needed_from)
                    ->copy()
                    ->startOfDay();

                $returnDate = ($version->return_date ?: $version->return_due_at)
                    ->copy()
                    ->startOfDay();

                if (
                    ! $scheduleDate->gt(now()->startOfDay())
                    || ! $returnDate->gt($scheduleDate)
                ) {
                    throw ValidationException::withMessages([
                        'schedule_date' =>
                            'Schedule Date must be a future calendar date and Return Date must be after Schedule Date.',
                    ]);
                }

                /*
                 * Items Needed From records when the borrower first needs the
                 * item(s). It is not the physical pickup appointment. The AO
                 * schedules pickup/release after approval, and the Operational
                 * Calendar validates the actual physical transaction date/time.
                 * Therefore a weekend, closure, or special date must not block
                 * online request submission solely because the need date itself
                 * is not a Pickup / Release day.
                 */

                /*
                 * Make sure the requested inventory items
                 * are still valid borrowable items.
                 *
                 * Do NOT reserve inventory here.
                 */
                foreach (
                    $version->items
                    as $requestItem
                ) {
                    $item = $requestItem->inventoryItem;

                    if (
                        ! $item
                        || ! $item->active
                        || ! $item->borrowable
                        || $item->condition_code !== 'SERVICEABLE'
                    ) {
                        throw ValidationException::withMessages([
                            'items' =>
                                "{$requestItem->description_snapshot} is no longer available for borrowing. Revise the request before submitting.",
                        ]);
                    }

                    if (
                        $requestItem->use_location
                            === 'OFF_CAMPUS'
                        && ! $item->off_campus_allowed
                    ) {
                        throw ValidationException::withMessages([
                            'locations' =>
                                "{$item->unique_description} is restricted to On-Campus use.",
                        ]);
                    }
                }

                /*
                 * The scanned approved Borrowing Request
                 * Letter is required.
                 *
                 * Permission to Conduct is also required
                 * when the request represents a student
                 * activity or organization.
                 */
                $this->validateRequiredSupportingDocuments(
                    $version
                );

                if ($academicNumbering) {
                    $request->request_no = app(AcademicYearRequestNumbers::class)->assign($request, now('Asia/Manila'));
                }

                $signatureSnapshot = $this->signatures->snapshot(
                    $borrower,
                    'BORROWER_REQUEST_CERTIFICATION',
                    UserRole::Borrower->value,
                    $version,
                    [
                        'request_id' => $request->id,
                        'request_no' => $request->request_no,
                        'request_version_id' => $version->id,
                        'request_version_no' => $version->version_no,
                        'action' => 'SIGN_AND_SUBMIT',
                    ]
                );

                $version->update([
                    'borrower_signature_snapshot_id' =>
                        $signatureSnapshot->id,

                    'accuracy_certified' =>
                        true,

                    'signed_at' =>
                        $signatureSnapshot->captured_at,

                    'submitted_at' =>
                        now(),
                ]);

                /*
                 * PREMISES ROUTING
                 * ----------------
                 * Sequence 1 is Action Officer verification; sequence 2 is the
                 * SPMU Head decision. Only off-campus requests carry the Gate
                 * Pass and off-campus eligibility checks that the Action
                 * Officer verifies, so only they open at sequence 1. An
                 * on-campus request has no verification stage: submission
                 * opens the Head decision step directly, which is what makes
                 * it immediately eligible in For Approval.
                 */
                $entrySequenceNo = $version->off_campus ? 1 : 2;

                ApprovalStep::query()->create([
                    'request_version_id' =>
                        $version->id,

                    'stage_code' =>
                        ApprovalStage::Spmu,

                    'sequence_no' =>
                        $entrySequenceNo,

                    'received_at' =>
                        now(),

                    'decision' =>
                        'RECEIVED',
                ]);

                /*
                 * Current uploaded supporting documents
                 * are awaiting SPMU verification.
                 */
                $version
                    ->supportingDocuments()
                    ->where(
                        'is_current',
                        true
                    )
                    ->when($version->isExternalAffiliation(), fn ($query) => $query->where('document_type', '!=', RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT))
                    ->update([
                        'verification_status' =>
                            RequestSupportingDocument::STATUS_PENDING,

                        'verified_by_user_id' =>
                            null,

                        'verified_at' =>
                            null,

                        'verification_remarks' =>
                            null,
                    ]);

                /*
                 * Submission only routes the request to
                 * SPMU. No allocation/reservation yet.
                 */
                $this->transition(
                    $request,
                    RequestStatus::UnderSpmu,
                    $borrower,
                    $entrySequenceNo === 1
                        ? 'E-signed borrowing request version and scanned supporting document(s) submitted to the SPMU Action Officer for verification.'
                        : 'E-signed borrowing request version and scanned supporting document(s) submitted to the SPMU Head for approval.'
                );

                $this->audit->record(
                    'REQUEST_SUBMITTED',
                    $request,
                    reason:
                        $entrySequenceNo === 1
                            ? 'E-signed request version routed to the SPMU Action Officer for document and request verification. No reservation was created at submission.'
                            : 'E-signed on-campus request version routed directly to the SPMU Head for review and decision. No reservation was created at submission.',
                    after: [
                        'request_version_id' => $version->id,
                        'request_version_no' => $version->version_no,
                        'signer_user_id' => $borrower->id,
                        'signature_snapshot_id' => $signatureSnapshot->id,
                        'signed_at' => $signatureSnapshot->captured_at?->toIso8601String(),
                        'reservation_created' => false,
                    ]
                );

                // The submission notice follows the same premises routing as
                // the entry approval step above.
                $submissionRecipients = User::query()
                    ->where('account_status', 'ACTIVE')
                    ->where(
                        'access_classification',
                        $entrySequenceNo === 1
                            ? AccessClassification::SpmuOfficer->value
                            : AccessClassification::SpmuHead->value
                    )
                    ->get()
                    ->filter(fn (User $recipient) => $recipient->hasVerifiedInstitutionalEmail()
                        && $recipient->hasApprovedRegistration() && $recipient->hasRole(UserRole::Spmu));

                $this->notifications->send(
                    'REQUEST_SUBMITTED',
                    $submissionRecipients,
                    $entrySequenceNo === 1
                        ? "Request {$request->request_no} is ready for Action Officer review."
                        : "On-campus request {$request->request_no} is ready for SPMU Head review and decision.",
                    $request,
                    ['SYSTEM', 'EMAIL'],
                );
            },
            3
        );
    }

    /**
     * Record the Action Officer's verification of the submitted request.
     *
     * VERIFIED creates the existing sequence-2 SPMU Head decision step. It
     * deliberately does not allocate inventory, approve the request, create a
     * custody transaction, or generate operational documents.
     */
    public function verifyByActionOfficer(
        BorrowingRequest $request,
        User $officer,
        string $decision,
        ?string $remarks,
        bool $signatureConfirmed,
        ?int $reviewedVersionId = null
    ): void {
        $decision = strtoupper($decision);

        abort_unless(
            $officer->access_classification === AccessClassification::SpmuOfficer
                && $officer->primaryWorkspace() === UserRole::Spmu->value
                && $officer->hasRole(UserRole::Spmu),
            403,
            'Only the SPMU Action Officer may verify a submitted request.'
        );

        if ($request->status !== RequestStatus::UnderSpmu) {
            throw ValidationException::withMessages([
                'decision' => 'This request is no longer awaiting Action Officer verification.',
            ]);
        }

        if (! in_array($decision, ['VERIFIED', 'RETURNED_FOR_REVISION'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Choose VERIFIED or RETURNED FOR REVISION.',
            ]);
        }

        if ($decision === 'RETURNED_FOR_REVISION' && blank($remarks)) {
            throw ValidationException::withMessages([
                'remarks' => 'Correction instructions are required when returning a request.',
            ]);
        }

        if ($decision === 'VERIFIED' && ! $signatureConfirmed) {
            throw ValidationException::withMessages([
                'confirm_e_signature' => 'Confirm that you want to apply your registered E-signature to this verification.',
            ]);
        }

        $expectedVersionNo = $request->current_version_no;
        DB::transaction(function () use ($request, $officer, $decision, $remarks, $expectedVersionNo, $reviewedVersionId): void {
            $request = BorrowingRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            if ($request->status !== RequestStatus::UnderSpmu) {
                throw ValidationException::withMessages([
                    'decision' => 'This request is no longer awaiting Action Officer verification.',
                ]);
            }

            if ($request->current_version_no !== $expectedVersionNo) {
                throw ValidationException::withMessages([
                    'decision' => 'The request version changed. Refresh the request and review the corrected version before deciding.',
                ]);
            }

            $request->loadMissing([
                'borrower',
                'currentVersion.items.inventoryItem',
                'currentVersion.approvalSteps',
                'currentVersion.supportingDocuments',
            ]);

            $version = $request->currentVersion;
            if ($reviewedVersionId !== null && (int) $version?->id !== $reviewedVersionId) {
                throw ValidationException::withMessages([
                    'decision' => 'The request version changed. Refresh the request and review the corrected version before deciding.',
                ]);
            }
            $step = $version?->approvalSteps
                ->where('sequence_no', 1)
                ->first(fn ($item) => $item->stage_code === ApprovalStage::Spmu);

            if (! $version || ! $step || ! in_array($step->decision, ['PENDING', 'RECEIVED'], true)) {
                throw ValidationException::withMessages([
                    'decision' => 'This Action Officer verification step has already been completed.',
                ]);
            }

            if ($decision === 'RETURNED_FOR_REVISION') {
                $step->update([
                    'approver_user_id' => $officer->id,
                    'signature_snapshot_id' => null,
                    'decision' => 'RETURNED_FOR_REVISION',
                    'decided_at' => now(),
                    'remarks' => $remarks,
                    'temporary_delegation_id' => null,
                ]);

                $this->markSupportingDocuments(
                    $version,
                    RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION,
                    $officer,
                    $remarks
                );

                $this->transition(
                    $request,
                    RequestStatus::ReturnedForRevision,
                    $officer,
                    $remarks
                );

                $this->audit->record(
                    'SPMU_ACTION_OFFICER_VERIFICATION',
                    $step,
                    reason: $remarks,
                    after: [
                        'decision' => 'RETURNED_FOR_REVISION',
                        'approval_granted' => false,
                        'reservation_created' => false,
                    ]
                );

                // A failed decision audit must abort before attempting a borrower notice.
                $this->notifications->send(
                    'REQUEST_RETURNED_FOR_REVISION',
                    collect([$request->borrower]),
                    "Request {$request->request_no} was returned by the SPMU Action Officer for correction. Reason: {$remarks}",
                    $request
                );

                return;
            }

            $this->validateRequiredSupportingDocuments($version);

            $signatureSnapshot = $this->signatures->snapshot(
                $officer,
                'SPMU_REQUEST_VERIFICATION',
                'SPMU_ACTION_OFFICER',
                $step,
                [
                    'request_id' => $request->id,
                    'request_no' => $request->request_no,
                    'request_version_id' => $version->id,
                    'approval_step_id' => $step->id,
                    'action' => 'SIGN_AND_VERIFY_NOT_APPROVE',
                ]
            );

            $step->update([
                'approver_user_id' => $officer->id,
                'signature_snapshot_id' => $signatureSnapshot->id,
                'decision' => 'VERIFIED',
                'decided_at' => $signatureSnapshot->captured_at,
                'remarks' => $remarks,
                'temporary_delegation_id' => null,
            ]);

            $this->markSupportingDocuments(
                $version,
                RequestSupportingDocument::STATUS_VERIFIED,
                $officer,
                $remarks
            );

            ApprovalStep::query()->create([
                'request_version_id' => $version->id,
                'stage_code' => ApprovalStage::Spmu,
                'sequence_no' => 2,
                'received_at' => now(),
                'decision' => 'RECEIVED',
            ]);

            $this->audit->record(
                'SPMU_ACTION_OFFICER_VERIFICATION',
                $step,
                after: [
                    'decision' => 'VERIFIED',
                    'approval_granted' => false,
                    'reservation_created' => false,
                    'head_decision_step_id' => $version->approvalSteps()->where('sequence_no', 2)->value('id'),
                    'signature_snapshot_id' => $signatureSnapshot->id,
                ]
            );

            $heads = User::query()
                ->where('account_status', 'ACTIVE')
                ->where('access_classification', AccessClassification::SpmuHead->value)
                ->get()
                ->filter(fn (User $recipient) => $recipient->hasVerifiedInstitutionalEmail()
                    && $recipient->hasApprovedRegistration() && $recipient->hasRole(UserRole::Spmu));

            $this->notifications->send(
                'REQUEST_VERIFIED',
                $heads,
                "Request {$request->request_no} was verified by the SPMU Action Officer and is ready for the SPMU Head's decision.",
                $request,
                ['SYSTEM', 'EMAIL']
            );

        }, 3);
    }

    /**
     * Record the SPMU Head's final decision after Action Officer verification.
     *
     * APPROVED
     * - final inventory availability is checked
     * - approved quantity becomes RESERVED
     *
     * RETURNED_FOR_REVISION
     * - borrower must correct the request/documents
     * - no reservation is created
     *
     * REJECTED
     * - request is closed as rejected
     * - no reservation is created
     */
    public function decide(
        BorrowingRequest $request,
        User $approver,
        string $decision,
        ?string $remarks,
        bool $signatureConfirmed,
        ?int $reviewedVersionId = null
    ): void {
        $decision = strtoupper($decision);

        if (
            $request->status
            !== RequestStatus::UnderSpmu
        ) {
            throw ValidationException::withMessages([
                'decision' =>
                    'This request is no longer awaiting the SPMU Head decision.',
            ]);
        }

        if (
            $approver->primaryWorkspace()
                !== UserRole::Spmu->value
            || ! $approver->hasRole(
                UserRole::Spmu
            )
        ) {
            abort(
                403,
                'Only authorized SPMU personnel may decide this request.'
            );
        }

        $isSpmuHead =
            $approver->access_classification
                === AccessClassification::SpmuHead;

        $activeSpmuDelegation =
            $approver->access_classification
                === AccessClassification::SpmuOfficer
                ? $approver->activeDelegationFor(UserRole::Spmu->value)
                : null;

        if (! $isSpmuHead && ! $activeSpmuDelegation) {
            abort(
                403,
                'Final borrowing decisions are reserved for the SPMU Head or a formally delegated SPMU Action Officer.'
            );
        }

        if (
            $request->borrower_user_id
                === $approver->id
        ) {
            throw ValidationException::withMessages([
                'decision' =>
                    'A user cannot decide their own borrowing request.',
            ]);
        }

        if (
            ! in_array(
                $decision,
                [
                    'APPROVED',
                    'REJECTED',
                    'RETURNED_FOR_REVISION',
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'decision' =>
                    'Choose a valid Head decision.',
            ]);
        }

        if (
            in_array(
                $decision,
                [
                    'REJECTED',
                    'RETURNED_FOR_REVISION',
                ],
                true
            )
            && blank($remarks)
        ) {
            throw ValidationException::withMessages([
                'remarks' =>
                    'Remarks are required when rejecting or returning a request for revision.',
            ]);
        }

        if ($decision === 'APPROVED' && ! $signatureConfirmed) {
            throw ValidationException::withMessages([
                'confirm_e_signature' => 'Confirm that you want to apply your registered E-signature to this approval.',
            ]);
        }

        $expectedVersionNo = $request->current_version_no;
        DB::transaction(
            function () use (
                $request,
                $approver,
                $decision,
                $remarks,
                $expectedVersionNo,
                $reviewedVersionId
            ): void {
                $request = BorrowingRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($request->id);

                if ($request->status !== RequestStatus::UnderSpmu) {
                    throw ValidationException::withMessages([
                        'decision' => 'This request is no longer awaiting the SPMU Head decision.',
                    ]);
                }

                if ($request->current_version_no !== $expectedVersionNo) {
                    throw ValidationException::withMessages([
                        'decision' => 'The request version changed. Refresh the request and review the corrected version before deciding.',
                    ]);
                }

                // Recheck a bound actor after waiting for the request lock.
                $approver = User::query()->lockForUpdate()->findOrFail($approver->id);
                abort_unless(
                    $approver->account_status === AccountStatus::Active
                        && $approver->primaryWorkspace() === UserRole::Spmu->value
                        && $approver->hasRole(UserRole::Spmu)
                        && ($approver->access_classification === AccessClassification::SpmuHead
                            || ($approver->access_classification === AccessClassification::SpmuOfficer
                                && $approver->activeDelegationFor(UserRole::Spmu->value))),
                    403,
                    'The current account no longer has active final approval authority.'
                );

                $request->loadMissing([
                    'borrower',
                    'currentVersion.items.inventoryItem',
                    'currentVersion.approvalSteps',
                    'currentVersion.supportingDocuments',
                ]);

                $version = $request->currentVersion;

                if (! $version) {
                    throw ValidationException::withMessages([
                        'decision' =>
                            'The current request version could not be found.',
                    ]);
                }

                if ($reviewedVersionId !== null && (int) $version->id !== $reviewedVersionId) {
                    throw ValidationException::withMessages([
                        'decision' => 'The request version changed. Refresh the request and review the corrected version before deciding.',
                    ]);
                }

                $verificationStep = $version->approvalSteps
                    ->where('sequence_no', 1)
                    ->firstWhere('stage_code', ApprovalStage::Spmu);

                $step = $version->approvalSteps
                    ->where('sequence_no', 2)
                    ->firstWhere('stage_code', ApprovalStage::Spmu);

                $temporaryDelegationId =
                    $approver->access_classification === AccessClassification::SpmuOfficer
                        ? $approver->activeDelegationFor(UserRole::Spmu->value)?->id
                        : null;

                /*
                 * Off-campus only: an on-campus request is routed straight to
                 * the Head at submission and has no sequence-1 step to check.
                 */
                if ($version->off_campus
                    && (! $verificationStep || $verificationStep->decision !== 'VERIFIED')) {
                    throw ValidationException::withMessages([
                        'decision' => 'Action Officer verification is required before the SPMU Head may decide this off-campus request.',
                    ]);
                }

                if (! $step || ! in_array($step->decision, ['PENDING', 'RECEIVED'], true)) {
                    throw ValidationException::withMessages([
                        'decision' => 'This SPMU Head decision step has already been completed.',
                    ]);
                }

                /*
                 * ===============================
                 * REJECT
                 * ===============================
                 */
                if (
                    $decision === 'REJECTED'
                ) {
                    $step->update([
                        'approver_user_id' =>
                            $approver->id,

                        'signature_snapshot_id' =>
                            null,

                        'received_at' =>
                            $step->received_at
                                ?: now(),

                        'decision' =>
                            'REJECTED',

                        'decided_at' =>
                            now(),

                        'remarks' =>
                            $remarks,

                        'temporary_delegation_id' =>
                            $temporaryDelegationId,
                    ]);

                    if (! $version->off_campus) {
                        $this->markSupportingDocuments(
                            $version,
                            RequestSupportingDocument::STATUS_REJECTED,
                            $approver,
                            $remarks
                        );
                    }

                    $this->transition(
                        $request,
                        RequestStatus::Rejected,
                        $approver,
                        $remarks
                    );

                    $this->audit->record(
                        'SPMU_REQUEST_DECISION',
                        $step,
                        reason:
                            $remarks,
                        after: [
                            'decision' =>
                                'REJECTED',

                            'reservation_created' =>
                                false,
                        ]
                    );

                    $this->notifications->send(
                        'REQUEST_REJECTED',
                        collect([
                            $request->borrower,
                        ]),
                        "Request {$request->request_no} was rejected by SPMU. Reason: {$remarks}",
                        $request
                    );

                    return;
                }

                /*
                 * ===============================
                 * RETURN FOR REVISION
                 * ===============================
                 */
                if (
                    $decision
                    === 'RETURNED_FOR_REVISION'
                ) {
                    $step->update([
                        'approver_user_id' =>
                            $approver->id,

                        'signature_snapshot_id' =>
                            null,

                        'received_at' =>
                            $step->received_at
                                ?: now(),

                        'decision' =>
                            'RETURNED_FOR_REVISION',

                        'decided_at' =>
                            now(),

                        'remarks' =>
                            $remarks,

                        'temporary_delegation_id' =>
                            $temporaryDelegationId,
                    ]);

                    if (! $version->off_campus) {
                        $this->markSupportingDocuments(
                            $version,
                            RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION,
                            $approver,
                            $remarks
                        );
                    }

                    $this->transition(
                        $request,
                        RequestStatus::ReturnedForRevision,
                        $approver,
                        $remarks
                    );

                    $this->notifications->send(
                        'REQUEST_RETURNED_FOR_REVISION',
                        collect([
                            $request->borrower,
                        ]),
                        "Request {$request->request_no} was returned for revision. Reason: {$remarks}",
                        $request
                    );

                    $this->audit->record(
                        'SPMU_REQUEST_DECISION',
                        $step,
                        reason:
                            $remarks,
                        after: [
                            'decision' =>
                                'RETURNED_FOR_REVISION',

                            'reservation_created' =>
                                false,
                        ]
                    );

                    return;
                }

                /*
                 * ===============================
                 * APPROVE
                 * ===============================
                 *
                 * Approval requires the complete current
                 * supporting-document set.
                 */
                $affiliation = app(DocumentAffiliationService::class)->resolve($version);
                if ($affiliation['is_external']) {
                    if (! $affiliation['company_name'] || $version->division_code !== null
                        || $version->office_unit !== null || $request->accountable_unit_id !== null) {
                        throw ValidationException::withMessages([
                            'affiliation' => 'The saved external request needs a reviewed company and must not have a CSPC division, office or accountable unit assignment. Return it for correction before approval.',
                        ]);
                    }
                    $this->validateApprovalEvidenceSnapshot(
                        $version->borrowerSignature,
                        $request->borrower_user_id,
                        'BORROWER_REQUEST_CERTIFICATION',
                        $version
                    );
                    if ($version->off_campus) {
                        $this->validateApprovalEvidenceSnapshot(
                            SignatureSnapshot::find($verificationStep->signature_snapshot_id),
                            (int) $verificationStep->approver_user_id,
                            'SPMU_REQUEST_VERIFICATION',
                            $verificationStep
                        );
                    }
                }
                $this->validateRequiredSupportingDocuments(
                    $version
                );

                /*
                 * The client-defined Pickup / Issuance date is automatic:
                 * use the latest valid SPMU operating day strictly before
                 * Items Needed From. Weekends, holidays, suspensions, and
                 * closed dates are skipped by the Operational Calendar.
                 */
                $automaticPickupWindow = $this->operationalCalendar
                    ->automaticPickupWindowBefore(
                        $version->needed_from,
                        now()
                    );

                /*
                 * Approval may still proceed after the originally suggested
                 * pickup day only when the calendar can offer a remaining
                 * window on or before Items Needed From. This is an
                 * exceptional late-approval path; a normal valid automatic
                 * window (including the remainder of today) is activated
                 * immediately below.
                 */
                $scheduleReviewReason = $automaticPickupWindow
                    ? null
                    : 'DELAYED_APPROVAL';

                $scheduleReviewWindow = $automaticPickupWindow
                    ? null
                    : $this->operationalCalendar->latestPickupWindowOnOrBefore(
                        $version->needed_from,
                        now()
                    );

                if (! $automaticPickupWindow && ! $scheduleReviewWindow) {
                    $neededFrom = CarbonImmutable::parse(
                        $version->needed_from,
                        config('app.timezone') ?: 'Asia/Manila'
                    )->startOfDay();
                    $requiredPickupDate = $neededFrom->subDay();
                    $requiredPickupLabel = $requiredPickupDate->format('d M Y');
                    $neededFromLabel = $neededFrom->format('d M Y');

                    $pickupMessage = $this->operationalCalendar->isOpenFor(
                        OperationalCalendarService::PICKUP,
                        $requiredPickupDate,
                        false
                    )
                        ? "No SPMU Pickup / Issuance operating window remains on or before the Items Needed From date ({$neededFromLabel}); the suggested pickup date ({$requiredPickupLabel}) has passed. Please return the request for revision so the borrower can choose new dates."
                        : "No SPMU Pickup / Issuance operating window remains on or before the Items Needed From date ({$neededFromLabel}); the suggested pickup date ({$requiredPickupLabel}) is a non-operating day. Please return the request for revision so the borrower can choose new dates.";

                    throw ValidationException::withMessages([
                        'pickup' => $pickupMessage,
                    ]);
                }

                /*
                 * This is the only point in this workflow where inventory
                 * reservation is created. Because physical custody begins on
                 * the earlier Pickup / Issuance date, reservation protection
                 * also begins on that system-generated date.
                 */
                try {
                    $this->inventory->allocate(
                        $version,
                        $automaticPickupWindow
                            ? $automaticPickupWindow['start']->startOfDay()
                            : CarbonImmutable::now(config('app.timezone') ?: 'Asia/Manila')->startOfDay()
                    );
                } catch (
                    ValidationException $exception
                ) {
                    /*
                     * Do not silently reduce an approved
                     * quantity simply because less stock
                     * is now available.
                     *
                     * Return the request for correction.
                     */
                    $reason =
                        $this->firstValidationMessage(
                            $exception,
                            'The approved quantity can no longer be fulfilled using the current inventory and schedule. Corrected approved documentation is required.'
                        );

                    $step->update([
                        'approver_user_id' =>
                            $approver->id,

                        'signature_snapshot_id' =>
                            null,

                        'received_at' =>
                            $step->received_at
                                ?: now(),

                        'decision' =>
                            'RETURNED_FOR_REVISION',

                        'decided_at' =>
                            now(),

                        'remarks' =>
                            $reason,

                        'temporary_delegation_id' =>
                            $temporaryDelegationId,
                    ]);

                    if (! $version->off_campus) {
                        $this->markSupportingDocuments(
                            $version,
                            RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION,
                            $approver,
                            $reason
                        );
                    }

                    $this->transition(
                        $request,
                        RequestStatus::ReturnedForRevision,
                        $approver,
                        $reason
                    );

                    $this->audit->record(
                        'SPMU_RESERVATION_CONFLICT',
                        $step,
                        reason:
                            $reason,
                        after: [
                            'decision' =>
                                'RETURNED_FOR_REVISION',

                            'reservation_created' =>
                                false,
                        ]
                    );

                    $this->notifications->send(
                        'REQUEST_RETURNED_FOR_REVISION',
                        collect([$request->borrower]),
                        "Request {$request->request_no} was returned because the approved quantity cannot currently be allocated for the selected schedule. {$reason}",
                        $request,
                        deferEmailUntilCommit: true
                    );

                    return;
                }

                /*
                 * Reservation succeeded. The authenticated authorized
                 * approver now explicitly signs this exact approval step.
                 */
                $signatureSnapshot = $this->signatures->snapshot(
                    $approver,
                    'SPMU_REQUEST_APPROVAL',
                    $temporaryDelegationId ? 'SPMU_DELEGATE' : 'SPMU_HEAD',
                    $step,
                    [
                        'request_id' => $request->id,
                        'request_no' => $request->request_no,
                        'request_version_id' => $version->id,
                        'request_version_no' => $version->version_no,
                        'approval_step_id' => $step->id,
                        'temporary_delegation_id' => $temporaryDelegationId,
                        'action' => 'SIGN_AND_APPROVE',
                    ]
                );

                $step->update([
                    'approver_user_id' =>
                        $approver->id,

                    'signature_snapshot_id' =>
                        $signatureSnapshot->id,

                    'received_at' =>
                        $step->received_at
                            ?: now(),

                    'decision' =>
                        'APPROVED',

                    'decided_at' =>
                        $signatureSnapshot->captured_at,

                    'remarks' =>
                        $remarks,

                    'temporary_delegation_id' =>
                        $temporaryDelegationId,
                ]);

                if (! $version->off_campus) {
                    $this->markSupportingDocuments(
                        $version,
                        RequestSupportingDocument::STATUS_VERIFIED,
                        $approver,
                        $remarks
                    );
                }

                $request->update([
                    'final_approved_at' =>
                        now(),

                    /*
                     * The current workflow does not
                     * require a generated approved-letter
                     * download before keeping reservation.
                     */
                    'download_deadline_at' =>
                        null,
                ]);

                /*
                 * Existing status enum retained so we do
                 * not unnecessarily break downstream
                 * modules.
                 *
                 * At this point:
                 * request = approved
                 * inventory = reserved
                 */
                $this->transition(
                    $request,
                    RequestStatus::ApprovedReadyForRelease,
                    $approver,
                    'SPMU Head verified and approved the request. The approved quantity is allocated/held for pickup.'
                );

                /*
                 * Create the pickup/custody record immediately
                 * after SPMU approval and reservation.
                 *
                 * The system assigns and activates the automatic Pickup /
                 * Issuance operating window here. The initial pickup window
                 * is included in the borrower's approval notification; the
                 * Action Officer does not perform a duplicate confirmation.
                 */
                $custody = $this->custody->ensurePickupRecord(
                    $request->fresh(),
                    $approver,
                    $automaticPickupWindow['start'] ?? null,
                    $automaticPickupWindow['end'] ?? null
                );

                if ($scheduleReviewWindow) {
                    $originalSuggestion = $this->operationalCalendar->automaticPickupWindowBefore(
                        $version->needed_from,
                        $version->submitted_at ?: $request->created_at
                    );

                    $this->audit->record(
                        'PICKUP_SCHEDULE_REVIEW_REQUIRED',
                        $custody,
                        after: [
                            'reason' => $scheduleReviewReason,
                            'items_needed_from' => CarbonImmutable::parse($version->needed_from)->toDateString(),
                            'original_suggested_pickup_date' => $originalSuggestion
                                ? $originalSuggestion['end']->toDateString()
                                : null,
                            'approved_at' => now()->toIso8601String(),
                            'pickup_schedule_created' => false,
                            'borrower_missed_pickup' => false,
                            'possible_pickup_from' => $scheduleReviewWindow['start']->toIso8601String(),
                            'possible_pickup_until' => $scheduleReviewWindow['end']->toIso8601String(),
                        ]
                    );
                }

                /*
                 * Generate the borrower's printable physical document packet
                 * immediately after approval. The borrower prints these forms
                 * and brings the applicable originals to SPMU for handwritten
                 * signatures/operational completion.
                 */
                $custody->loadMissing([
                    'borrower',
                    'request.currentVersion',
                    'lines.requestItem.inventoryItem',
                    'gatePass',
                ]);

                /*
                 * The borrower's uploaded, fully signed scanned Borrowing
                 * Request Letter remains the official Request Letter. The
                 * system does not generate a duplicate or "approved" letter;
                 * only the operational release documents are generated here.
                 */
                $this->documents->borrowerSlip($custody);
                $generatedOperationalDocuments = ['BORROWER_SLIP'];

                $offCampusLine = $custody->lines->first(
                    fn ($line) =>
                        $line->requestItem?->use_location === 'OFF_CAMPUS'
                        && (float) $line->approved_quantity > 0
                );

                if ($offCampusLine) {
                    /*
                     * Reuse the single Gate Pass transaction for this custody.
                     * The Action Officer's earlier verification signature and
                     * the Head's approval signature are both available now, so
                     * the official Gate Pass must be generated immediately and
                     * exposed before the scheduled physical handover.
                     */
                    $gatePass = GatePass::query()->updateOrCreate(
                        ['custody_transaction_id' => $custody->id],
                        [
                            'custody_line_id' => $offCampusLine->id,
                            'bearer_name' => $request->borrower->full_name,
                            'destination' => $version->location,
                            'purpose' => $version->purpose_event,
                            'status' => 'READY_FOR_PRINTING',
                            'prepared_verified_by_user_id' => $verificationStep->approver_user_id,
                            'prepared_verifier_signature_snapshot_id' => $verificationStep->signature_snapshot_id,
                            'prepared_verified_at' => $verificationStep->decided_at,
                            'approved_by_user_id' => $approver->id,
                            'approver_signature_snapshot_id' => $signatureSnapshot->id,
                            'temporary_delegation_id' => $temporaryDelegationId,
                            'approved_at' => $signatureSnapshot->captured_at,
                        ]
                    );

                    $gatePassDocument = $this->documents->conditionalForm(
                        $custody->fresh([
                            'borrower',
                            'request.currentVersion',
                            'lines.requestItem.inventoryItem',
                            'gatePass',
                        ]),
                        'GATE_PASS'
                    );

                    $gatePass->update([
                        'pass_document_id' => $gatePassDocument->id,
                    ]);

                    $generatedOperationalDocuments[] = 'GATE_PASS';
                }

                $hasLaundry = $custody->lines->contains(
                    fn ($line) =>
                        (bool) $line->requestItem?->inventoryItem?->laundry_required
                        && (float) $line->approved_quantity > 0
                );

                if ($hasLaundry) {
                    $this->documents->conditionalForm(
                        $custody->fresh([
                            'borrower',
                            'request.currentVersion',
                            'lines.requestItem.inventoryItem',
                        ]),
                        'LAUNDRY_FORM'
                    );
                    $generatedOperationalDocuments[] = 'LAUNDRY_FORM';
                }

                $this->audit->record(
                    'REQUEST_READY_FOR_PICKUP_SCHEDULING',
                    $request,
                    after: [
                        'custody_id' =>
                            $custody?->id,

                        'reservation_created' =>
                            true,

                        'pickup_schedule_created' =>
                            (bool) $automaticPickupWindow,

                        'pickup_schedule_confirmed' =>
                            (bool) $automaticPickupWindow,

                        'pickup_schedule_review_required' =>
                            (bool) $scheduleReviewWindow,

                        'automatic_pickup_at' =>
                            $automaticPickupWindow
                                ? $automaticPickupWindow['start']->toIso8601String()
                                : null,

                        'automatic_pickup_expires_at' =>
                            $automaticPickupWindow
                                ? $automaticPickupWindow['end']->toIso8601String()
                                : null,

                        'borrower_documents_generated' =>
                            true,

                        'generated_document_types' =>
                            $generatedOperationalDocuments,
                    ]
                );

                $this->audit->record(
                    'SPMU_REQUEST_DECISION',
                    $step,
                    reason: $remarks,
                    after: [
                        'decision' => 'APPROVED',
                        'reservation_created' => true,
                        'request_id' => $request->id,
                        'request_version_id' => $version->id,
                        'signer_user_id' => $approver->id,
                        'signature_snapshot_id' => $signatureSnapshot->id,
                        'signed_at' => $signatureSnapshot->captured_at?->toIso8601String(),
                    ]
                );

                $this->notifications->send(
                    'REQUEST_APPROVED',
                    collect([
                        $request->borrower,
                    ]),
                    $automaticPickupWindow
                        ? "Your borrowing request {$request->request_no} has been approved. Pickup is scheduled for {$automaticPickupWindow['start']->format('F j, Y g:i A')} to {$automaticPickupWindow['end']->format('g:i A')}. Please preview and print your Borrower Slip before pickup, together with any other required form shown in your request."
                        : "Your borrowing request {$request->request_no} has been approved. Because it was approved after the originally suggested pickup date, no pickup schedule has been set yet. SPMU will contact you to confirm that you still need the items and to arrange pickup. This is not recorded as a missed pickup.",
                    $request,
                    deferEmailUntilCommit: true
                );

                $actionOfficers = User::query()
                    ->where('account_status', 'ACTIVE')
                    ->where('access_classification', AccessClassification::SpmuOfficer->value)
                    ->get();

                $this->notifications->send(
                    'REQUEST_APPROVED',
                    $actionOfficers,
                    $automaticPickupWindow
                        ? "Request {$request->request_no} was approved. Pickup is scheduled for {$automaticPickupWindow['start']->format('F j, Y g:i A')} to {$automaticPickupWindow['end']->format('g:i A')}. Prepare the approved items for release."
                        : "Request {$request->request_no} was approved after its originally suggested pickup date. Schedule Review Required: confirm with the borrower that the equipment is still needed before continuing the release.",
                    $request,
                    ['SYSTEM', 'EMAIL'],
                    deferEmailUntilCommit: true
                );
            },
            3
        );
    }

    /**
     * Borrower cancellation rules.
     *
     * A borrower may cancel any open request until physical release. This
     * includes Draft, Submitted/Under Review, Approved/Allocated, Prepared,
     * and Pickup Scheduled requests. Once released/on custody, cancellation is
     * blocked and the Return workflow must be used instead.
     *
     * If approval already allocated inventory, cancellation immediately
     * restores that unreleased allocation to Available stock and invalidates
     * any pickup documents that were prepared for the cancelled transaction.
     */
    public function cancel(
        BorrowingRequest $request,
        User $actor,
        string $reason,
        ?int $reviewedVersionId = null
    ): void {
        $afterReservation = $request->status === RequestStatus::ApprovedReadyForRelease;
        $this->finalizeCancellation($request, $actor, $reason, $afterReservation, reviewedVersionId: $reviewedVersionId);
    }

    /**
     * Head/Admin terminal resolution for an AO-reported preparation
     * discrepancy when SPMU can no longer provide the complete approved
     * quantity. This is an SPMU-side administrative cancellation: nothing was
     * physically released, the borrower is not treated as a missed pickup,
     * the reservation is restored, and all generated pickup documents for the
     * approved version are invalidated.
     */
    public function cancelApprovedForPreparationDiscrepancy(
        CustodyTransaction $custody,
        User $spmuHead,
        AuditEvent $issueEvent,
        string $resolutionNotes
    ): void {
        abort_unless(
            $spmuHead->access_classification === AccessClassification::SpmuHead,
            403
        );

        $custody->loadMissing([
            'request.borrower',
            'request.currentVersion',
            'request.custody.gatePass',
        ]);

        if ($custody->released_at || $custody->status !== 'PREPARING_RELEASE') {
            throw ValidationException::withMessages([
                'preparation_issue' => 'This transaction is no longer eligible for preparation-issue cancellation.',
            ]);
        }

        $request = $custody->request;

        if (! $request || $request->status !== RequestStatus::ApprovedReadyForRelease) {
            throw ValidationException::withMessages([
                'preparation_issue' => 'Only an approved, unreleased request can be cancelled from Step 2.',
            ]);
        }

        $notes = trim($resolutionNotes);

        if ($notes === '') {
            throw ValidationException::withMessages([
                'resolution_notes' => 'Explain why SPMU cannot fulfill the complete approved quantity.',
            ]);
        }

        DB::transaction(function () use ($custody, $request, $spmuHead, $issueEvent, $notes): void {
            $issue = AuditEvent::query()
                ->lockForUpdate()
                ->findOrFail($issueEvent->id);

            abort_unless(
                $issue->action_code === 'PREPARATION_ISSUE_REPORTED'
                    && $issue->record_type === CustodyTransaction::class
                    && (int) $issue->record_id === (int) $custody->id,
                404
            );

            $alreadyClosedUnfulfilled = AuditEvent::query()
                ->where('record_type', CustodyTransaction::class)
                ->where('record_id', $custody->id)
                ->where('action_code', 'PREPARATION_ISSUE_CLOSED_UNFULFILLED')
                ->where('after_json->preparation_issue_event_id', $issue->id)
                ->exists();

            if ($alreadyClosedUnfulfilled) {
                throw ValidationException::withMessages([
                    'preparation_issue' => 'This inventory discrepancy has already been closed as unable to fulfill.',
                ]);
            }

            if ($custody->prepared_at) {
                throw ValidationException::withMessages([
                    'preparation_issue' => 'Items Prepared has already been confirmed. This preparation discrepancy can no longer be used to cancel the approved request.',
                ]);
            }

            $this->audit->record(
                'PREPARATION_ISSUE_CLOSED_UNFULFILLED',
                $custody,
                reason: $notes,
                after: [
                    'preparation_issue_event_id' => $issue->id,
                    'custody_line_id' => (int) data_get($issue->after_json, 'custody_line_id'),
                    'item_name' => (string) data_get($issue->after_json, 'item_name', 'Item'),
                    'resolution_outcome' => 'UNABLE_TO_FULFILL_APPROVED_REQUEST',
                    'resolution_notes' => $notes,
                    'borrower_missed_pickup' => false,
                    'partial_release_allowed' => false,
                ]
            );

            $reason = 'SPMU could not fulfill the complete approved quantity because of an inventory discrepancy found during physical preparation. '.$notes;

            $this->finalizeCancellation(
                $request,
                $spmuHead,
                $reason,
                true,
                'PREPARATION_UNABLE_TO_FULFILL',
                "SPMU could not complete preparation for request {$request->request_no} because the full approved quantity was not physically available. The approved request was cancelled before release. No items were released, this is not recorded as a missed pickup, and the reserved quantity has been returned to inventory. If you still need the items, submit a new borrowing request.",
                'PREPARATION_UNABLE_TO_FULFILL'
            );
        }, 3);
    }

    /**
     * Cancellation is finalized in one step for every current caller
     * (cancel(), cancelApprovedForPreparationDiscrepancy()) - no current
     * workflow defers a cancellation to a separate SPMU confirmation step,
     * so a RequestCancellation history record is always written here.
     */
    private function finalizeCancellation(
        BorrowingRequest $request,
        User $actor,
        string $reason,
        bool $afterReservation,
        string $notificationEventCode = 'REQUEST_CANCELLED',
        ?string $notificationMessage = null,
        string $auditActionCode = 'REQUEST_CANCELLED',
        ?int $reviewedVersionId = null
    ): void {
        $reason = trim($reason);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']])->validate();
        $boundVersionNo = $request->current_version_no;
        DB::transaction(function () use (
            $request,
            $actor,
            $reason,
            $afterReservation,
            $notificationEventCode,
            $notificationMessage,
            $auditActionCode,
            $reviewedVersionId,
            $boundVersionNo
        ): void {
            // Root lock serializes duplicate cancellation and current approval.
            $request = BorrowingRequest::query()->lockForUpdate()->findOrFail($request->id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $version = $request->versions()->where('version_no', $request->current_version_no)->lockForUpdate()->first();
            // Approval reads joined reservation/custody rows while holding
            // stock locks. Acquire the same stock locks before child locks.
            if ($version) {
                InventoryItem::query()->whereIn('id', $version->items()->pluck('inventory_item_id'))
                    ->orderBy('id')->lockForUpdate()->get();
            }
            $custody = $request->custody()->lockForUpdate()->first();
            $request->setRelation('currentVersion', $version);
            $request->setRelation('custody', $custody);
            abort_unless($actor->account_status === AccountStatus::Active
                && $actor->hasVerifiedInstitutionalEmail() && $actor->hasApprovedRegistration(), 403);

            // Preserve the Head's separate explicit preparation-discrepancy authority.
            if ($auditActionCode === 'PREPARATION_UNABLE_TO_FULFILL') {
                abort_unless($actor->access_classification === AccessClassification::SpmuHead
                    && $actor->hasRole(UserRole::Spmu), 403);
                if (! $custody || $custody->prepared_at || $custody->status !== 'PREPARING_RELEASE') {
                    throw ValidationException::withMessages(['preparation_issue' => 'This preparation transaction has changed. Reload its current record.']);
                }
            } else {
                Gate::forUser($actor)->authorize('cancel', $request);
            }

            if (! $request->status->isOpen() || $custody?->closed_at) {
                throw ValidationException::withMessages(['cancel' => 'This request is already closed.']);
            }
            if (! $version || (int) $boundVersionNo !== (int) $request->current_version_no
                || ($reviewedVersionId !== null && $reviewedVersionId !== (int) $version->id)) {
                throw ValidationException::withMessages(['cancel' => 'The request version has changed. Reload and review the current request before cancelling.']);
            }
            if ($custody && ((int) $custody->request_version_id !== (int) $version->id
                || (int) $custody->borrower_user_id !== (int) $request->borrower_user_id)) {
                throw ValidationException::withMessages(['cancel' => 'The custody owner or version does not match this request. Review the inconsistent record before cancelling.']);
            }
            if ($custody?->released_at) {
                throw ValidationException::withMessages(['cancel' => 'Items have already been physically released. Use the Return process instead of cancellation.']);
            }
            if ($custody?->pickup_expired_at && $actor->access_classification === AccessClassification::BorrowerOnly) {
                throw ValidationException::withMessages(['cancel' => 'This missed pickup is under SAO review. Only SAO can confirm its cancellation.']);
            }
            if ($custody?->pickup_expired_at && $custody->status !== 'PREPARING_RELEASE') {
                throw ValidationException::withMessages(['cancel' => 'The missed-pickup transaction has changed. Reload its current record.']);
            }

            $items = $version->items()->orderBy('id')->lockForUpdate()->get();
            $version->setRelation('items', $items);
            $allocations = Allocation::query()->whereIn('request_item_id', $items->pluck('id'))->orderBy('id')->lockForUpdate()->get()->keyBy('request_item_id');
            $issuedLine = $custody?->lines()->lockForUpdate()->get()->contains(fn ($line) => (float) $line->actual_released_quantity > 0);
            if ($issuedLine || $allocations->contains(fn ($allocation) => (float) $allocation->released_quantity > 0)) {
                throw ValidationException::withMessages(['cancel' => 'Issued quantities are already recorded. Use the Return process instead of cancellation.']);
            }
            foreach ($items as $item) {
                $item->setRelation('allocation', $allocations->get($item->id));
            }
            $afterReservation = $request->status === RequestStatus::ApprovedReadyForRelease;
            if ($afterReservation) {
                $this->inventory->restore(
                    $request,
                    'CANCELLED',
                    $reason
                );
            }

            $cancelledBySpmu = in_array(
                $actor->access_classification,
                [AccessClassification::SpmuHead, AccessClassification::SpmuOfficer],
                true
            );

            RequestCancellation::query()->create([
                'request_id' => $request->id,
                'request_version_id' => $request->currentVersion?->id,
                'cancelled_by_user_id' => $actor->id,
                'phase' => $afterReservation
                    ? 'AFTER_APPROVAL_BEFORE_RELEASE'
                    : 'BEFORE_FINAL_APPROVAL',
                'reason' => $reason,
                'status' => 'CONFIRMED',
                'requested_at' => now(),
                'reviewed_by_user_id' => $cancelledBySpmu ? $actor->id : null,
                'reviewed_at' => $cancelledBySpmu ? now() : null,
                'cancelled_at' => now(),
            ]);

            /*
             * Borrower Slip, Gate Pass, Laundry Form, request-letter copies,
             * and other generated documents tied to the current request
             * version can no longer be used after cancellation.
             */
            GeneratedDocument::query()
                ->where('request_version_id', $request->currentVersion?->id)
                ->whereIn('status', ['DRAFT', 'FINAL'])
                ->update([
                    'status' => 'INVALIDATED',
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'Request cancelled: '.$reason,
                ]);

            $custody = $request->custody;

            if ($custody) {
                // Preserve the historical pickup times but make any pending
                // pickup window inactive immediately.
                $custodyUpdates = [
                    'status' => 'CANCELLED',
                    'closed_at' => now(),
                ];

                if ($custody->scheduled_release_at && ! $custody->released_at) {
                    $custodyUpdates['pickup_expired_at'] = now();
                }

                $custody->update($custodyUpdates);

                // An off-campus Gate Pass prepared before release is void once
                // the underlying borrowing request is cancelled.
                GatePass::query()
                    ->where('custody_transaction_id', $custody->id)
                    ->where('status', '!=', 'VERIFIED')
                    ->update([
                        'status' => 'VOID',
                        'verification_remarks' => 'Voided because the borrowing request was cancelled. '.$reason,
                    ]);
            }

            $this->transition(
                $request,
                RequestStatus::Cancelled,
                $actor,
                $reason
            );

            $this->audit->record(
                $auditActionCode,
                $request,
                reason: $reason,
                after: ['reservation_released' => $afterReservation]
            );

            $this->notifications->send(
                $notificationEventCode,
                collect([$request->borrower]),
                $notificationMessage ?: "Your borrowing request {$request->request_no} has been cancelled. Reason for cancellation: {$reason}",
                $request
            );

            if ($request->borrower_user_id === $actor->id) {
                $actionOfficers = User::query()
                    ->where('account_status', 'ACTIVE')
                    ->where(
                        'access_classification',
                        AccessClassification::SpmuOfficer->value
                    )
                    ->get();

                if ($actionOfficers->isNotEmpty()) {
                    $this->notifications->send(
                        'REQUEST_CANCELLED',
                        $actionOfficers,
                        "The borrower cancelled request {$request->request_no} before pickup. No further release action is required.",
                        $request
                    );
                }
            }
        }, 3);
    }

    /**
     * Historical compatibility for old requests that
     * used an approved-letter download deadline.
     */
    public function expireUndownloaded(): int
    {
        $count = 0;

        BorrowingRequest::query()
            ->where(
                'status',
                RequestStatus::FinalApprovedAwaitingDownload->value
            )
            ->whereNotNull(
                'download_deadline_at'
            )
            ->where(
                'download_deadline_at',
                '<',
                now()
            )
            ->each(
                function (
                    BorrowingRequest $request
                ) use (
                    &$count
                ): void {
                    DB::transaction(
                        function () use (
                            $request,
                            &$count
                        ): void {
                            $this->inventory->restore(
                                $request,
                                'EXPIRED',
                                'Approved letter was not downloaded by the configured deadline.'
                            );

                            GeneratedDocument::query()
                                ->where(
                                    'request_version_id',
                                    $request
                                        ->currentVersion
                                        ?->id
                                )
                                ->where(
                                    'status',
                                    'FINAL'
                                )
                                ->update([
                                    'status' =>
                                        'EXPIRED',

                                    'invalidated_at' =>
                                        now(),

                                    'invalidation_reason' =>
                                        'Download deadline missed.',
                                ]);

                            $this->transition(
                                $request,
                                RequestStatus::Expired,
                                null,
                                'Approved letter was not downloaded by the configured deadline.'
                            );

                            $this->notifications->send(
                                'REQUEST_EXPIRED',
                                collect([
                                    $request->borrower,
                                ]),
                                "Request {$request->request_no} expired because the approved letter was not downloaded by the deadline.",
                                $request
                            );

                            $count++;
                        },
                        3
                    );
                }
            );

        return $count;
    }

    /** Validate immutable ink and the recorded signing action for this exact version/step. */
    private function validateApprovalEvidenceSnapshot(?SignatureSnapshot $snapshot, int $signerId, string $purpose, Model $signedRecord): void
    {
        if (! $snapshot || $snapshot->signer_user_id !== $signerId
            || $snapshot->purpose_code !== $purpose
            || (int) $snapshot->file?->uploaded_by_user_id !== $signerId
            || ! hash_equals((string) $snapshot->sha256, (string) $snapshot->file?->sha256)
            || ! AuditEvent::query()->where('action_code', 'E_SIGNATURE_APPLIED')
                ->where('actor_user_id', $signerId)
                ->where('record_type', $signedRecord::class)
                ->where('record_id', $signedRecord->getKey())
                ->where('after_json->signature_snapshot_id', $snapshot->id)->exists()) {
            throw ValidationException::withMessages([
                'signature' => 'The reviewed request needs its own valid borrower and applicable SAO E-signature evidence before approval.',
            ]);
        }
        app(SubmissionEvidenceService::class)->verifiedBytes($snapshot->file, 'signature', imageOnly: true);
    }

    /** Validate current supporting documents required for submission, verification and approval. */
    private function validateRequiredSupportingDocuments(
        $version
    ): void {
        $documents =
            $version
                ->supportingDocuments()
                ->where(
                    'is_current',
                    true
                )
                ->when($version->isExternalAffiliation(), fn ($query) => $query->where('document_type', '!=', RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT))
                ->with('file')
                ->get();

        foreach ($documents as $document) {
            $field = $document->isPermissionToConduct() ? 'permission_to_conduct_letter' : 'approved_request_letter';
            if ((int) $document->request_id !== (int) $version->request_id
                || (int) $document->uploaded_by_user_id !== (int) $version->request->borrower_user_id
                || (int) $document->file?->uploaded_by_user_id !== (int) $version->request->borrower_user_id) {
                throw ValidationException::withMessages([$field => 'The supporting document does not belong to this borrower and request version. Upload it again.']);
            }
            app(SubmissionEvidenceService::class)->verifiedBytes($document->file, $field);
        }

        $hasRequestLetter =
            $documents->contains(
                fn (
                    RequestSupportingDocument $document
                ) =>
                    $document->document_type
                        === RequestSupportingDocument::TYPE_REQUEST_LETTER
            );

        if (! $hasRequestLetter) {
            throw ValidationException::withMessages([
                'approved_request_letter' =>
                    'Upload the scanned, fully signed Borrowing Request Letter before submitting.',
            ]);
        }

        if (
            $version->requiresPermissionToConduct()
        ) {
            $hasPermission =
                $documents->contains(
                    fn (
                        RequestSupportingDocument $document
                    ) =>
                        $document->document_type
                            === RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
                );

            if (! $hasPermission) {
                throw ValidationException::withMessages([
                    'permission_to_conduct_letter' =>
                        'The Permission to Conduct Letter is required when the request represents a student activity.',
                ]);
            }
        }
    }

    /**
     * Update the SPMU verification result for the
     * currently active uploaded supporting documents.
     */
    private function markSupportingDocuments(
        $version,
        string $status,
        User $verifier,
        ?string $remarks
    ): void {
        $version
            ->supportingDocuments()
            ->where(
                'is_current',
                true
            )
            ->when($version->isExternalAffiliation(), fn ($query) => $query->where('document_type', '!=', RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT))
            ->update([
                'verification_status' =>
                    $status,

                'verified_by_user_id' =>
                    $verifier->id,

                'verified_at' =>
                    now(),

                'verification_remarks' =>
                    $remarks,
            ]);
    }

    /**
     * Extract the first useful validation message
     * generated by InventoryService.
     */
    private function firstValidationMessage(
        ValidationException $exception,
        string $fallback
    ): string {
        foreach (
            $exception->errors()
            as $messages
        ) {
            if (
                is_array($messages)
                && isset($messages[0])
            ) {
                return (string) $messages[0];
            }
        }

        return $fallback;
    }

    /**
     * Change request status and retain complete
     * status-history information.
     */
    private function transition(
        BorrowingRequest $request,
        RequestStatus $to,
        ?User $actor,
        ?string $reason
    ): void {
        $from = $request->status;

        $request->update([
            'status' =>
                $to,
        ]);

        RequestStatusHistory::query()
            ->create([
                'request_id' =>
                    $request->id,

                'request_version_id' =>
                    $request
                        ->currentVersion
                        ?->id,

                'actor_user_id' =>
                    $actor?->id,

                'from_status' =>
                    $from?->value,

                'to_status' =>
                    $to->value,

                'reason' =>
                    $reason,

                'changed_at' =>
                    now(),
            ]);
    }

    /**
     * Return active users having the requested role.
     */
    private function usersWithRole(
        UserRole $role
    ) {
        return User::query()
            ->where(
                'account_status',
                'ACTIVE'
            )
            ->whereHas(
                'roles',
                fn ($query) =>
                    $query
                        ->where(
                            'role_code',
                            $role->value
                        )
                        ->whereNull(
                            'user_roles.revoked_at'
                        )
            )
            ->get();
    }
}
