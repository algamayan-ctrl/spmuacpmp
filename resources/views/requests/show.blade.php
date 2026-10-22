@extends('layouts.app', [
    'title' => 'Request '.$borrowingRequest->request_no,
    'topbarTitle' => session('active_workspace') === 'BORROWER' ? 'My Requests' : 'Request Records',
])
@section('content')
@php
    $v = $borrowingRequest->currentVersion;
    $workspace = session('active_workspace');
    $isBorrower = $workspace === 'BORROWER';
    $isSpmu = $workspace === 'SPMU';
    $hasCurrentESignature = auth()->user()->currentSignature()->whereHas('file')->exists();
    $currentDocs = $v->supportingDocuments->where('is_current', true);
    $requestLetterDoc = $currentDocs->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
    );
    $permissionToConductDoc = $currentDocs->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
    );
    $isUnderSpmuReview = $isSpmu
        && $borrowingRequest->status === App\Enums\RequestStatus::UnderSpmu;
    $actionOfficerStep = $v->approvalSteps->firstWhere('sequence_no', 1);
    $actionOfficerVerified = $actionOfficerStep?->decision === 'VERIFIED';
    $rejectionStep = $v->approvalSteps->first(fn ($step) => $step->stage_code === App\Enums\ApprovalStage::Spmu
        && $step->sequence_no === 2 && $step->decision === 'REJECTED');
    $actionOfficerDocumentsVerified = $actionOfficerVerified
        && $currentDocs->isNotEmpty()
        && $currentDocs->every(
            fn ($document) => $document->verification_status === App\Models\RequestSupportingDocument::STATUS_VERIFIED
        );

    // A request remains APPROVED_READY_FOR_RELEASE at the request-record level
    // after approval, while the custody transaction continues through release,
    // return, and completion. Use custody state for the visible operational
    // badge so the request detail never shows a stale "Ready for Release".
    $custody = $borrowingRequest->custody;
    $isOperationalRequestLayout = $isSpmu && (bool) $custody && ! $isUnderSpmuReview;
    $custodyWorkflowStatus = $custody?->workflowStatus();
    $requestIsCompleted = ($custodyWorkflowStatus['key'] ?? null) === 'COMPLETED';
    $requestIsCancelled = ($custodyWorkflowStatus['key'] ?? null) === 'CANCELLED'
        || $borrowingRequest->status === App\Enums\RequestStatus::Cancelled;
    $detailStatus = $borrowingRequest->status->value;
    $detailStatusLabel = null;
    $obligationSummary = $custody?->openObligationSummary();

    // SPMU still recording the accomplished Gate Pass is internal SPMU
    // processing, not a borrower accountability obligation - never present
    // it to the borrower as "Accountability Pending" or a dead-end link to
    // My Obligations (which has no row for it). Non-borrower viewers still
    // see the accurate operational status.
    $gatePassOnlyPending = $isBorrower && ($custody?->isGatePassOnlyPending() ?? false);

    $isBorrowerDraftWorkflow = $isBorrower
        && in_array(
            $borrowingRequest->status,
            [
                App\Enums\RequestStatus::Draft,
                App\Enums\RequestStatus::ReturnedForRevision,
            ],
            true
        );
    $requiresNewRevisionVersion = $borrowingRequest->status === App\Enums\RequestStatus::ReturnedForRevision;

    $signedRequestLetterReady = (bool) $requestLetterDoc;
    $ptcRequired = (bool) $v->represents_student_activity;
    $ptcReady = ! $ptcRequired || (bool) $permissionToConductDoc;
    $submissionReady = $signedRequestLetterReady && $ptcReady;

    $operationalDocuments = $custody
        ? $v->documents
            ->where('subject_type', App\Models\CustodyTransaction::class)
            ->where('subject_id', $custody->id)
            ->where('status', 'FINAL')
        : collect();

    $borrowerSlipDocument = $operationalDocuments
        ->where('document_type', 'BORROWER_SLIP')
        ->sortByDesc('id')
        ->first();

    $laundryFormDocument = $operationalDocuments
        ->where('document_type', 'LAUNDRY_FORM')
        ->sortByDesc('id')
        ->first();

    $gatePassDocument = $operationalDocuments
        ->where('document_type', 'GATE_PASS')
        ->sortByDesc('id')
        ->first();

    $requestHasLaundry = $custody?->lines?->contains(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    ) ?? false;

    $requestHasOffCampus = $custody?->lines?->contains(
        fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
    ) ?? false;

    $gatePassFinalized = $requestHasOffCampus
        && in_array(
            $custody?->gatePass?->status,
            ['READY_FOR_PRINTING', 'VERIFIED'],
            true
        )
        && (bool) $gatePassDocument;

    if ($custody) {
        $preparationComplete = (bool) $custody->prepared_at;
        $hasPickupSchedule = (bool) $custody->scheduled_release_at
            && (bool) $custody->pickup_expires_at
            && ! $custody->pickup_expired_at;

        $custodyWorkflowStatus ??= $custody->workflowStatus();
        $detailStatus = $custodyWorkflowStatus['key'];
        $detailStatusLabel = $obligationSummary['label'] ?? $custodyWorkflowStatus['label'];

        if ($gatePassOnlyPending) {
            $detailStatus = 'GATE_PASS_PROCESSING';
            $detailStatusLabel = 'Gate Pass Processing';
        }
    }

    $pickupMissedForBorrower = $isBorrower
        && ($custodyWorkflowStatus['key'] ?? null) === 'PICKUP_EXPIRED';
@endphp

@if($isOperationalRequestLayout)
    @include('requests.partials.operational-styles')
    <div class="request-operational-page">
    @include('requests.partials.operational-heading')
@else
<section class="page-heading">
    <div>
        <p class="eyebrow">{{ $isBorrower ? 'My request' : 'Borrowing request' }}</p>
        <h1>{{ $borrowingRequest->request_no }}</h1>
        <p>{{ $v->purpose_event }}</p>
        @if($isBorrower)
            <p class="meta">Review the request, documents, and current progress.</p>
        @else
            {{-- Identity line: who filed it and when, so the reviewer does not
                 have to scroll to Request Details to place the record. --}}
            <p class="meta request-heading-meta">
                <span>Borrower: <strong>{{ $borrowingRequest->borrower->full_name }}</strong></span>
                <span>Submitted: <strong>{{ optional($v->submitted_at ?: $borrowingRequest->created_at)->format('d M Y, g:i A') }}</strong></span>
            </p>
        @endif
    </div>

    <div class="request-heading-actions">
        <x-status-badge :status="$detailStatus" :label="$detailStatusLabel" />

        @if($isBorrower && $borrowingRequest->custody)
            <a
                class="button secondary ui-pressable"
                href="{{ route('custody.show', $borrowingRequest->custody) }}"
            >
                <span>View Borrowing</span>
            </a>
        @endif
    </div>
</section>
@endif

@include('requests.partials.processing-time-estimate')

@if($errors->any())
<section class="content-area">
    <div class="callout danger">
        <strong>Please review:</strong>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
</section>
@endif

@if($borrowingRequest->status === App\Enums\RequestStatus::Rejected && filled($rejectionStep?->remarks))
<section class="content-area">
    <div class="callout danger">
        <strong>Rejection reason</strong>
        <p>{{ $rejectionStep->remarks }}</p>
    </div>
</section>
@endif

@if($isBorrowerDraftWorkflow)
<section class="content-area borrower-current-action-area" id="borrower-next-action">
    <div class="action-panel action-neutral">
        <div>
            <p class="eyebrow">Current action</p>

            @if($requiresNewRevisionVersion)
                <h2>Create the corrected request version</h2>
                <p>Open the returned request, apply the required corrections, and save a new version before E-signing and resubmitting. The prior signed version remains unchanged.</p>
            @elseif(!$submissionReady)
                <h2>Upload the required documents</h2>
                <p class="borrower-current-action-copy">
                    Upload the approved, fully signed Borrowing Request Letter
                    {{ $ptcRequired ? 'and Permission to Conduct Letter' : '' }} before submitting to SPMU.
                </p>
            @else
                <h2>Ready to submit to SPMU</h2>
                <p>
                    The required scanned document(s) are complete. Review the draft if needed, then submit it to SPMU.
                </p>
            @endif

            <div class="inline-actions top-gap">
                <a
                    class="button secondary ui-pressable"
                    href="{{ route('requests.edit', $borrowingRequest) }}"
                >
                    <span>{{ $requiresNewRevisionVersion ? 'Create Corrected Version' : ($submissionReady ? 'Review / Edit Draft' : 'Edit Draft & Upload Documents') }}</span>
                </a>

                @if($submissionReady && !$requiresNewRevisionVersion)
                    <form method="post" action="{{ route('requests.submit', $borrowingRequest) }}" class="form-grid">
                        @csrf
                        @if($hasCurrentESignature)
                            <label class="checkbox">
                                <input type="checkbox" name="confirm_e_signature" value="1" required>
                                I confirm this submission and authorize use of my registered E-signature for this request version.
                            </label>
                            <button class="button primary ui-pressable" type="submit">
                                E-sign &amp; Submit to SPMU
                            </button>
                        @else
                            <div class="callout warning">
                                <strong>E-signature required</strong>
                                <p><a href="{{ route('profile.show') }}">Register your E-signature in Account Settings</a> before submitting.</p>
                            </div>
                        @endif
                    </form>
                @endif
            </div>

            @if(!$submissionReady)
                <p class="meta top-gap borrower-current-action-note">
                    Submit to SPMU will be enabled after the required signed document{{ $ptcRequired ? 's are' : ' is' }} uploaded.
                </p>
            @endif
        </div>
    </div>
</section>
@endif

@unless($isBorrower)
    @if(!$obligationSummary)
        <x-request-progress-tracker :request="$borrowingRequest" :show-current-status="false" :compact="$isOperationalRequestLayout" />
    @endif
@endunless

@if($isBorrower)
<style>
/* Keep the borrower action and progress cards visually separate and concise. */
.borrower-current-action-area {
    margin-bottom: 24px;
}

.borrower-current-action-copy {
    max-width: 980px;
    margin-bottom: 0;
    line-height: 1.55;
}

.borrower-current-action-note {
    margin-bottom: 0;
}

.request-tracker-card {
    margin-top: 14px;
}

.request-tracker-card .request-tracker {
    overflow: visible;
    padding: 18px 20px;
}

.request-tracker-card .request-tracker__header {
    gap: 12px;
    padding-bottom: 13px;
}

.request-tracker-card .request-tracker__header h2 {
    margin: 1px 0 2px;
}

.request-tracker-card .request-tracker__scroll {
    width: 100%;
    margin: 0;
    padding: 18px 0 12px;
    overflow: visible;
    overscroll-behavior-inline: auto;
    scrollbar-width: auto;
}

.request-tracker-card .request-tracker__rail {
    width: 100%;
    min-width: 0;
    grid-template-columns: repeat(8, minmax(0, 1fr));
}

.request-tracker-card .request-tracker__step {
    padding-inline: 6px;
}

.request-tracker-card .request-tracker__copy small {
    display: block;
    overflow: visible;
    max-width: 150px;
    -webkit-line-clamp: unset;
}

.request-tracker-card .request-tracker__step.is-complete::after {
    background: linear-gradient(90deg, #31B700 0%, #159000 100%);
}

.request-tracker-card .request-tracker__step.is-complete .request-tracker__marker {
    color: #fff;
    background: linear-gradient(180deg, #39E600 0%, #31B700 55%, #159000 100%);
    border-color: #159000;
    box-shadow: none;
}

.request-tracker-card .request-tracker__step.is-complete .request-tracker__marker .ui-icon {
    color: #fff;
}

.request-tracker-card .request-tracker__hint {
    margin-top: 10px;
    padding: 11px 0 0;
    background: transparent;
    border: 0;
    border-top: 1px solid var(--row-border);
    border-radius: 0;
    box-shadow: none;
}

@media (max-width: 1024px) {
    .request-tracker-card .request-tracker__rail {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        row-gap: 26px;
    }

    .request-tracker-card .request-tracker__step:nth-child(4n)::after {
        display: none;
    }
}

@media (max-width: 620px) {
    .request-tracker-card .request-tracker__header {
        gap: 6px;
    }

    .request-tracker-card .request-tracker__scroll {
        padding-top: 14px;
    }

    .request-tracker-card .request-tracker__rail {
        grid-template-columns: 1fr;
        row-gap: 0;
    }

    .request-tracker-card .request-tracker__step {
        display: grid;
        grid-template-columns: 40px minmax(0, 1fr);
        gap: 11px;
        padding: 0 0 18px;
        text-align: left;
    }

    .request-tracker-card .request-tracker__marker {
        width: 38px;
        height: 38px;
        margin: 0;
    }

    .request-tracker-card .request-tracker__copy {
        justify-items: start;
        gap: 2px;
        padding-top: 1px;
    }

    .request-tracker-card .request-tracker__copy small {
        max-width: none;
    }

    .request-tracker-card .request-tracker__step::after,
    .request-tracker-card .request-tracker__step:nth-child(4n)::after {
        display: block;
        top: 38px;
        left: 18px;
        width: 2px;
        height: calc(100% - 38px);
    }

    .request-tracker-card .request-tracker__step:last-child::after {
        display: none;
    }
}
</style>
@endif

@if($isUnderSpmuReview)
<section class="content-area spmu-verification-workspace" data-spmu-verification-workspace>
    <div class="spmu-review-layout">
        <div class="spmu-review-top-row">
            <div class="spmu-scan-slot">
                <x-document-review-viewer
                    :file="$requestLetterDoc?->file"
                    title="Inspect the signed Borrowing Request Letter"
                />
                @if($v->represents_student_activity)
                    <div
                        class="spmu-document-tabs"
                        data-spmu-document-tabs
                        role="tablist"
                        aria-label="Submitted request documents"
                    >
                        <button
                            type="button"
                            class="spmu-document-tab is-active"
                            role="tab"
                            aria-selected="true"
                            tabindex="0"
                            data-document-tab
                            @if($requestLetterDoc)
                                data-document-url="{{ route('files.show', $requestLetterDoc->file, false) }}"
                            @endif
                        >
                            Borrowing Request Letter
                        </button>

                        <button
                            type="button"
                            class="spmu-document-tab"
                            role="tab"
                            aria-selected="false"
                            tabindex="-1"
                            data-document-tab
                            @if($permissionToConductDoc)
                                data-document-url="{{ route('files.show', $permissionToConductDoc->file, false) }}"
                            @else
                                aria-disabled="true"
                                disabled
                                title="Permission to Conduct Letter is not available"
                            @endif
                        >
                            Permission to Conduct
                        </button>
                    </div>
                @endif
            </div>

            <article class="card spmu-checklist-panel">
            @if($canVerify || $canDecide)
                <div class="card-header">
                    <div>
                        <p class="eyebrow">{{ $canVerify ? 'SPMU Action Officer' : 'SPMU Head / Admin' }}</p>
                        <h2>{{ $canVerify ? 'Verify request and documents' : 'Review and decide' }}</h2>
                    </div>
                    <span class="status-badge status-info">{{ $canVerify ? 'For verification' : 'For approval' }}</span>
                </div>

                <p class="meta spmu-review-summary">
                    @if($canVerify)
                        Review the request and required documents before forwarding it for final approval.
                    @elseif($v->off_campus)
                        Review the Action Officer verification and make the final decision.
                    @else
                        Review the request and make the final decision.
                    @endif
                </p>

                @if($canDecide && $v->off_campus && $actionOfficerVerified)
                    <section class="spmu-ao-verification-summary" aria-label="Action Officer verification record">
                        <div class="spmu-ao-verification-summary__header">
                            <div>
                                <p class="eyebrow">Action Officer verification</p>
                                <strong>Verification record</strong>
                            </div>
                            <span class="status-badge status-success">Verified</span>
                        </div>

                        <dl class="spmu-ao-verification-summary__details">
                            <div>
                                <dt>Verified by</dt>
                                <dd>{{ $actionOfficerStep?->approver?->full_name ?? 'SPMU Action Officer' }}</dd>
                            </div>
                            <div>
                                <dt>Verified on</dt>
                                <dd>{{ $actionOfficerStep?->decided_at?->format('d M Y, g:i A') ?? 'Not recorded' }}</dd>
                            </div>
                            <div>
                                <dt>E-signature</dt>
                                <dd>{{ $actionOfficerStep?->signature_snapshot_id ? 'Applied' : 'Not recorded' }}</dd>
                            </div>
                            <div>
                                <dt>Documents</dt>
                                <dd>{{ $actionOfficerDocumentsVerified ? 'Verified' : 'Review required' }}</dd>
                            </div>
                        </dl>

                        @if(filled($actionOfficerStep?->remarks))
                            <div class="spmu-ao-verification-summary__remarks">
                                <span>Remarks</span>
                                <p>{{ $actionOfficerStep->remarks }}</p>
                            </div>
                        @endif
                    </section>
                @endif
<form
                    method="post"
                    action="{{ $canVerify ? route('verifications.verify', $borrowingRequest) : route('approvals.decide', $borrowingRequest) }}"
                    class="spmu-verification-form top-gap"
                    data-verification-form
                    data-required-supporting-present="{{ ($requestLetterDoc && (!$v->represents_student_activity || $permissionToConductDoc)) ? '1' : '0' }}"
                    data-has-current-e-signature="{{ $hasCurrentESignature ? '1' : '0' }}"
                >
                    @csrf

                    @if($canVerify || $canDecide)
                        <input type="hidden" name="reviewed_version_id" value="{{ $v->id }}">
                    @endif
                    <input type="hidden" name="decision" value="" data-verification-decision>
                    <input type="hidden" name="remarks" value="{{ old('remarks') }}" data-verification-remarks>
                    <input type="hidden" name="confirm_e_signature" value="" data-verification-e-signature>

                    <div class="spmu-checklist">
                        @if($canVerify)
                            <label class="spmu-check-row">
                                <input type="checkbox" name="details_complete" value="1" data-verification-check @checked(old('details_complete'))>
                                <span>
                                    <strong>Request details are consistent with the submitted letter</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Confirm that the submitted request matches the signed request letter.</small>
                                </span>
                            </label>
                            <label class="spmu-check-row">
                                <input type="checkbox" name="documents_complete" value="1" data-verification-check @checked(old('documents_complete'))>
                                <span>
                                    <strong>Required supporting documents are complete</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Verify the required attachments, including PTC when applicable.</small>
                                </span>
                            </label>
                            <label class="spmu-check-row">
                                <input type="checkbox" name="availability_verified" value="1" data-verification-check @checked(old('availability_verified'))>
                                <span>
                                    <strong>Off-campus requirements are verified</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Confirm that Gate Pass requirements are complete and valid.</small>
                                </span>
                            </label>
                        @elseif($v->off_campus)
                            {{-- AO verification already recorded; Head makes the final decision. --}}

                        @else
                            <label class="spmu-check-row">
                                <input type="checkbox" name="details_complete" value="1" data-verification-check @checked(old('details_complete'))>
                                <span>
                                    <strong>Request details and required documents are complete</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Review the submitted request and required supporting documents.</small>
                                </span>
                            </label>
                            <label class="spmu-check-row">
                                <input type="checkbox" name="documents_complete" value="1" data-verification-check @checked(old('documents_complete'))>
                                <span>
                                    <strong>Request is appropriate for approval</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Confirm that the purpose, schedule, and intended use may be authorized.</small>
                                </span>
                            </label>
                            <label class="spmu-check-row">
                                <input type="checkbox" name="availability_verified" value="1" data-verification-check @checked(old('availability_verified'))>
                                <span>
                                    <strong>Inventory availability is verified</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Confirm that the requested quantities may be allocated without conflict.</small>
                                </span>
                            </label>
                        @endif
                    </div>
                    @unless($canDecide && $v->off_campus)
<section
                        class="spmu-review-readiness"
                        data-review-readiness
                        aria-live="polite"
                    >
                        <div class="spmu-review-readiness-heading">
                            <div>
                                <p class="eyebrow">
                                    {{ $canVerify ? 'VERIFICATION READINESS' : 'APPROVAL READINESS' }}
                                </p>

                                <strong>Review completion</strong>
                            </div>

                            <span
                                class="spmu-review-readiness-status"
                                data-review-readiness-status
                            >
                                In progress
                            </span>
                        </div>

                        <div class="spmu-review-readiness-summary">
                            <strong data-review-readiness-count>
                                0 of 3 checks completed
                            </strong>

                            <span data-review-readiness-percent>
                                0%
                            </span>
                        </div>

                        <div
                            class="spmu-review-readiness-track"
                            role="progressbar"
                            aria-label="Review completion"
                            aria-valuemin="0"
                            aria-valuemax="3"
                            aria-valuenow="0"
                            data-review-readiness-progress
                        >
                            <span class="spmu-review-readiness-bar"></span>
                        </div>

                        <p
                            class="spmu-review-readiness-message"
                            data-review-readiness-message
                        >
                            Complete all review checks to continue.
                        </p>
                    </section>
@endunless

                    @unless($hasCurrentESignature)
                        <p class="field-error top-gap">
                            <a href="{{ route('profile.show') }}">Register your E-signature in Account Settings</a> to enable {{ $canVerify ? 'verification' : 'approval' }}.
                        </p>
                    @endunless

                    <p class="field-error top-gap" data-verification-inline-error hidden></p>

                    <div class="spmu-review-footer">
                        <div class="spmu-decision-actions">
                            <button
                                class="button primary ui-pressable"
                                type="button"
                                data-decision-trigger="{{ $canVerify ? 'VERIFIED' : 'APPROVED' }}"
                                data-approve-button
                                disabled
                            >
                                {{ $canVerify ? 'E-sign & Mark VERIFIED' : 'E-sign & Approve' }}
                            </button>

                            <button
                                class="button secondary ui-pressable"
                                type="button"
                                data-decision-trigger="RETURNED_FOR_REVISION"
                            >
                                Return for Revision
                            </button>

                            @if($canDecide)
                                <button
                                    class="button danger ui-pressable"
                                    type="button"
                                    data-decision-trigger="REJECTED"
                                >
                                    Reject
                                </button>
                            @endif
                        </div>
                    </div>
                </form>

                <dialog class="spmu-confirm-dialog" data-verification-confirm-dialog>
                    <div class="spmu-confirm-dialog__surface">
                        <div class="spmu-confirm-dialog__icon" aria-hidden="true">?</div>

                        <div>
                            <h2 data-confirm-title>Confirm decision</h2>
                            <p class="meta" data-confirm-message></p>

                            <div class="spmu-dialog-remarks" data-confirm-remarks-wrap hidden>
                                <label for="spmu-decision-remarks" data-confirm-remarks-label>Remarks</label>
                                <textarea
                                    id="spmu-decision-remarks"
                                    rows="5"
                                    maxlength="2000"
                                    data-confirm-remarks
                                    placeholder="Enter the reason or instructions for the borrower."
                                ></textarea>
                                <small data-confirm-remarks-help></small>
                                <p class="field-error" data-confirm-remarks-error hidden></p>
                            </div>
                        </div>

                        <div class="spmu-confirm-dialog__actions">
                            <button class="button secondary ui-pressable" type="button" data-confirm-cancel>Go Back</button>
                            <button class="button primary ui-pressable" type="button" data-confirm-submit>Confirm</button>
                        </div>
                    </div>
                </dialog>
            @else
                <div class="card-header">
                    <div>
                        <p class="eyebrow">SPMU review</p>
                        <h2>{{ $actionOfficerVerified ? 'Waiting for Head decision' : 'Waiting for Action Officer verification' }}</h2>
                    </div>
                </div>

                <div class="empty-state">
                    @if($actionOfficerVerified)
                        <strong>This VERIFIED request is awaiting the SPMU Head decision.</strong>
                        <span>Verification is not approval; operational preparation starts only after the Head's final approval.</span>
                    @else
                        <strong>This request is awaiting Action Officer verification.</strong>
                        <span>The SPMU Head decision workflow becomes available only after the Action Officer marks the request VERIFIED.</span>
                    @endif
                </div>
            @endif
            </article>
        </div>

        <article class="card spmu-left-borrowing-info">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Request details</p>
                    <h2>Borrowing information</h2>
                </div>
            </div>

            <div class="spmu-left-borrowing-grid spmu-left-borrowing-grid-clean">

                <div class="spmu-left-borrowing-cell">
                    <span>Borrower</span>
                    <strong>{{ $borrowingRequest->borrower->full_name }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>{{ $v->isExternalAffiliation() ? 'Company / Agency / Institution' : 'Office / College / Unit' }}</span>
                    <strong>{{ $v->affiliationLabel() }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Purpose of Borrowing</span>
                    <strong>{{ $v->purpose_event }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Event Location</span>
                    <strong>{{ $v->location }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Student Activity</span>
                    <strong>{{ $v->represents_student_activity ? 'Yes' : 'No' }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Items Needed From</span>
                    <strong>{{ optional($v->schedule_date ?: $v->needed_from)->format('d F Y') }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Expected Return Date</span>
                    <strong>{{ optional($v->return_date ?: $v->return_due_at)->format('d F Y') }}</strong>
                </div>

            </div></article>

        <div class="spmu-inventory-slot">
            @include('requests.partials.spmu-availability-review')
        </div>
    </div>
</section>
@endif

@if($isBorrower)
@include('requests.partials.borrower-detail-styles')
@if(!$obligationSummary)
<div class="borrower-progress-always" aria-label="Current request progress">
    <x-request-progress-tracker :request="$borrowingRequest" :show-current-status="false" />
</div>
@elseif($gatePassOnlyPending)
<div class="content-area">
    <div class="callout info">
        <strong>Gate Pass Processing</strong>
        <p>The physical return is complete. SPMU is recording the accomplished Gate Pass.</p>
    </div>
</div>
@elseif($obligationSummary)
<div class="content-area">
    <div class="callout warning">
        <strong>{{ $obligationSummary['title'] }}</strong>
        <p>{{ $obligationSummary['copy'] }}</p>
        <a class="button secondary small ui-pressable" href="{{ route('accountability.index') }}"><span>View My Obligations</span></a>
    </div>
</div>
@endif

<div class="content-area borrower-request-detail">
    <div class="borrower-detail-grid">
        <article class="borrower-detail-card">
            <h2 class="borrower-card-title">
                <span class="borrower-card-icon" aria-hidden="true"><x-icon name="requests" size="20" /></span>
                Borrowing Information
            </h2>

            <div class="borrower-fact-grid">
                <div>
                    <span>{{ $v->isExternalAffiliation() ? 'Company / Agency / Institution' : 'Office / College / Unit' }}</span>
                    <strong>{{ $v->affiliationLabel() }}</strong>
                </div>
                <div>
                    <span>Location</span>
                    <strong>{{ $v->location }}</strong>
                </div>
                <div>
                    <span>Scheduled Use</span>
                    <strong>{{ optional($v->schedule_date ?: $v->needed_from)->format('d M Y') }}</strong>
                </div>
                <div>
                    <span>Expected Return</span>
                    <strong>{{ optional($v->return_date ?: $v->return_due_at)->format('d M Y') }}</strong>
                </div>
                <div>
                    <span>Student Activity</span>
                    <strong>{{ $v->represents_student_activity ? 'Yes' : 'No' }}</strong>
                </div>
                <div>
                    <span>Premises</span>
                    <strong>{{ $v->off_campus ? 'Off-campus · Gate Pass required' : 'On-campus' }}</strong>
                </div>
            </div>
        </article>

        <article class="borrower-detail-card">
            @php
                $awaitingItemDecision = $v->items->every(
                    fn ($item) => $item->approved_quantity === null
                );
                $itemDecisionNote = $borrowingRequest->status === App\Enums\RequestStatus::Rejected
                    ? 'Request rejected'
                    : ($awaitingItemDecision ? 'Awaiting SPMU review' : 'Approved quantities');
            @endphp

            <h2 class="borrower-card-title">
                <span class="borrower-card-icon" aria-hidden="true"><x-icon name="box" size="20" /></span>
                Requested Items
                <span class="borrower-card-note">{{ $itemDecisionNote }}</span>
            </h2>

            <div class="borrower-item-list">
                @foreach($v->items as $item)
                    @php
                        $requestedQty = $item->requested_quantity + 0;
                        $approvedQty = $item->approved_quantity === null
                            ? null
                            : $item->approved_quantity + 0;
                        $quantityChanged = $approvedQty !== null
                            && (float) $approvedQty !== (float) $requestedQty;
                    @endphp

                    <div class="borrower-item-row">
                        <div>
                            <strong>{{ $item->description_snapshot }}</strong>
                        </div>

                        <div class="borrower-item-quantity">
                            <strong>{{ $approvedQty ?? $requestedQty }} {{ $item->unit_snapshot }}</strong>

                            @if($quantityChanged)
                                <small>Requested {{ $requestedQty }} {{ $item->unit_snapshot }}</small>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </article>
    </div>

    <article class="borrower-detail-card">
        <h2 class="borrower-card-title">
            <span class="borrower-card-icon" aria-hidden="true"><x-icon name="requests" size="20" /></span>
            Submitted Documents
        </h2>

        @if($currentDocs->isEmpty())
            <p class="borrower-documents-empty">No supporting documents have been uploaded yet.</p>
        @else
            <div class="borrower-documents-scroll">
                <table class="borrower-documents-table">
                    <thead>
                        <tr>
                            <th scope="col">Document</th>
                            <th scope="col">Description</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($currentDocs as $doc)
                            @php
                                $isRequestLetter = $doc->document_type
                                    === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER;

                                [$docStatusLabel, $docStatusTone] = match ($doc->verification_status) {
                                    App\Models\RequestSupportingDocument::STATUS_VERIFIED => ['Verified', 'success'],
                                    App\Models\RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION => ['Needs Replacement', 'warning'],
                                    App\Models\RequestSupportingDocument::STATUS_REJECTED => ['Rejected', 'danger'],
                                    default => ['Submitted', 'success'],
                                };
                            @endphp

                            <tr>
                                <td>
                                    <span class="borrower-document-name">
                                        <x-icon name="requests" size="18" />
                                        {{ $isRequestLetter ? 'Borrowing Request Letter' : 'Permission to Conduct Letter' }}
                                    </span>
                                </td>

                                <td>
                                    {{ $isRequestLetter
                                        ? 'Signed letter uploaded with this request.'
                                        : 'Supporting document for a student activity.' }}
                                </td>

                                <td>
                                    <span class="status-badge status-{{ $docStatusTone }}">{{ $docStatusLabel }}</span>
                                </td>

                                <td>
                                    <a
                                        class="button secondary small ui-pressable borrower-document-view"
                                        href="{{ route('files.preview', $doc->file) }}"
                                    >
                                        Preview
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>

    @if($borrowingRequest->custody && $borrowingRequest->final_approved_at)
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Approved documents</p>
                    <h2>Borrowing documents</h2>
                </div>
            </div>
            @forelse($operationalDocuments as $document)
                <div class="evidence-row">
                    <div>
                        <strong>{{ str($document->document_type)->replace('_', ' ')->title() }}</strong>
                        <small>{{ $document->document_no }}</small>
                    </div>
                    <a class="button secondary small ui-pressable" href="{{ route('documents.preview', $document) }}">Preview</a>
                </div>
            @empty
                <div class="empty-state"><strong>No current approved document is available.</strong></div>
            @endforelse
        </article>
    @endif
</div>
@elseif($isOperationalRequestLayout)
    @include('requests.partials.operational-details')
@elseif(!$isUnderSpmuReview)
<section class="content-grid two">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Request details</p>
                <h2>Borrowing information</h2>
            </div>
        </div>

        <dl class="detail-list">
            <dt>Borrower</dt>
            <dd>{{ $borrowingRequest->borrower->full_name }}</dd>

            <dt>{{ $v->isExternalAffiliation() ? 'Company / Agency / Institution' : 'Office / College / Unit' }}</dt>
            <dd>{{ $v->affiliationLabel() }}</dd>

            <dt>Event Details</dt>
            <dd>{{ $v->purpose_event }}</dd>

            <dt>Location</dt>
            <dd>{{ $v->location }}</dd>

            <dt>Schedule Date</dt>
            <dd>{{ optional($v->schedule_date ?: $v->needed_from)->format('d F Y') }}</dd>

            <dt>Expected Return Date</dt>
            <dd>{{ optional($v->return_date ?: $v->return_due_at)->format('d F Y') }}</dd>

            <dt>Student Activity</dt>
            <dd>{{ $v->represents_student_activity ? 'Yes' : 'No' }}</dd>
        </dl>
    </article>

    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Document verification</p>
                <h2>Uploaded scanned documents</h2>
            </div>
        </div>

        @forelse($currentDocs as $doc)
            <div class="evidence-row">
                <div>
                    <strong>
                        {{ $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
                            ? 'Approved Borrowing Request Letter'
                            : 'Permission to Conduct Letter' }}
                    </strong>
                    <small>
                        Version {{ $doc->version_no }}
                        ·
                        {{ str($doc->verification_status)->replace('_',' ')->title() }}
                    </small>
                </div>

                <a
                    class="button secondary small ui-pressable"
                    href="{{ route('files.preview', $doc->file) }}"
                >
                    Preview
                </a>
            </div>
        @empty
            <div class="empty-state">
                <strong>No current scanned supporting document.</strong>
            </div>
        @endforelse
    </article>
</section>

<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Requested property</p>
                <h2>Items and quantities</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Requested</th>
                        <th>Approved Quantity</th>
                        <th>Premises</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($v->items as $item)
                    <tr>
                        <td>{{ $item->description_snapshot }}</td>
                        <td>{{ $item->requested_quantity + 0 }} {{ $item->unit_snapshot }}</td>
                        <td>
                            {{ $item->approved_quantity === null
                                ? 'Not approved yet'
                                : ($item->approved_quantity + 0).' '.$item->unit_snapshot }}
                        </td>
                        <td>{{ str($item->use_location)->replace('_',' ')->title() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>
@endif



@if($borrowingRequest->custody && !$isBorrower && !$isOperationalRequestLayout)
<section class="content-area">
    <div class="action-panel action-neutral">
        <div>
            <p class="eyebrow">Operational record</p>
            <h2>{{ $requestIsCompleted ? 'Completed custody record' : 'Release & Return Record' }}</h2>
            <p>Open the linked custody record for release, physical return processing, and final reconciliation.</p>
        </div>

        <a
            class="button secondary ui-pressable"
            href="{{ route('custody.show', $borrowingRequest->custody) }}"
        >
            <span>View Custody Record</span>
        </a>
    </div>
</section>
@endif

@if(
    $isBorrower
    && !in_array(
        $borrowingRequest->status,
        [
            App\Enums\RequestStatus::Draft,
            App\Enums\RequestStatus::Cancelled,
            App\Enums\RequestStatus::Rejected,
            App\Enums\RequestStatus::Expired,
        ],
        true
    )
    && !$borrowingRequest->custody?->released_at
    && ! $pickupMissedForBorrower
)
<div class="content-area borrower-request-detail">
    <article class="request-cancel-card">
        <div class="borrower-cancel-copy">
            <h2 class="borrower-cancel-title">
                <x-icon name="warning" size="20" />
                Request Actions
            </h2>

            <p>
                You may cancel this request until items are physically released.
                @if($borrowingRequest->status === App\Enums\RequestStatus::ApprovedReadyForRelease)
                    Any approved but unreleased allocation returns to Available inventory, and any active pickup window, Borrower Slip, and Gate Pass prepared for this request will no longer be valid.
                @endif
            </p>
        </div>

        @include('requests.partials.borrower-cancel-control', [
            'borrowingRequest' => $borrowingRequest,
            'cancelTriggerClass' => 'button ui-pressable borrower-cancel-button borrower-cancel-trigger is-danger',
            'cancelTriggerLabel' => 'Cancel Request',
            'cancelDialogTitle' => 'Cancel this borrowing request?',
            'cancelDialogCopy' => $borrowingRequest->status === App\Enums\RequestStatus::ApprovedReadyForRelease
                ? 'This request will be cancelled immediately. The approved allocation returns to Available inventory and any pending pickup documents will be invalidated.'
                : 'This request will be cancelled immediately. Cancellation is allowed only before physical release.',
        ])
    </article>
</div>
@endif




@unless($isBorrower)
<section class="content-area">
    @include('requests.partials.document-history')
    @include('requests.partials.audit-history')
</section>
@endunless

@if($isUnderSpmuReview)
@include('requests.partials.spmu-review-styles')

<script>
(() => {
    const workspace = document.querySelector('[data-spmu-verification-workspace]');

    if (!workspace) return;

    const form = workspace.querySelector('[data-verification-form]');
    const dialog = workspace.querySelector('[data-verification-confirm-dialog]');

    if (!form || !dialog) return;

    const checks = [...form.querySelectorAll('[data-verification-check]')];
    const approveButton = form.querySelector('[data-approve-button]');
    const decisionInput = form.querySelector('[data-verification-decision]');
    const remarksInput = form.querySelector('[data-verification-remarks]');
    const eSignatureInput = form.querySelector('[data-verification-e-signature]');
    const inlineError = form.querySelector('[data-verification-inline-error]');
    const triggers = [...form.querySelectorAll('[data-decision-trigger]')];

    const confirmTitle = dialog.querySelector('[data-confirm-title]');
    const confirmMessage = dialog.querySelector('[data-confirm-message]');
    const confirmSubmit = dialog.querySelector('[data-confirm-submit]');
    const confirmCancel = dialog.querySelector('[data-confirm-cancel]');
    const remarksWrap = dialog.querySelector('[data-confirm-remarks-wrap]');
    const remarksLabel = dialog.querySelector('[data-confirm-remarks-label]');
    const remarksField = dialog.querySelector('[data-confirm-remarks]');
    const remarksHelp = dialog.querySelector('[data-confirm-remarks-help]');
    const remarksError = dialog.querySelector('[data-confirm-remarks-error]');

    const requiredSupportingPresent = form.dataset.requiredSupportingPresent === '1';
    const hasCurrentESignature = form.dataset.hasCurrentESignature === '1';
    let pendingDecision = '';

    const decisionCopy = {
        VERIFIED: {
            title: 'Confirm E-signature and Verification',
            message: 'Your registered e-signature will be applied to this Action Officer verification.',
            note: 'This will mark the request as VERIFIED and route it to the SPMU Head. It does not approve the request, reserve inventory, or generate release documents.',
            confirm: 'E-sign & Mark VERIFIED',
            tone: 'primary',
        },
        APPROVED: {
            title: 'Confirm E-signature and Approval',
            message: 'Your registered e-signature will be applied to this final approval.',
            note: 'Approved quantities will be reserved and the required release documents will be generated. The property is not yet physically released to the borrower.',
            confirm: 'E-sign & Approve',
            tone: 'primary',
        },
        RETURNED_FOR_REVISION: {
            title: 'Return this request for revision?',
            message: 'The borrower will receive your remarks and must correct the request or supporting documents before resubmitting.',
            confirm: 'Return for Revision',
            tone: 'secondary',
        },
        REJECTED: {
            title: 'Reject this borrowing request?',
            message: 'This closes the request as rejected. The borrower will receive the reason you provide below.',
            confirm: 'Reject Request',
            tone: 'danger',
        },
    };

    const isPositiveDecision = (decision) =>
        decision === 'VERIFIED' || decision === 'APPROVED';

    const headApprovalUsesPriorAoVerification =
        @json($canDecide && $v->off_campus && $actionOfficerVerified);

    // SPMU_AO_VERIFIED_HEAD_APPROVAL_FIX
    const checklistComplete = () =>
        requiredSupportingPresent &&
        hasCurrentESignature &&
        (
            headApprovalUsesPriorAoVerification ||
            (
                checks.length === 3 &&
                checks.every((checkbox) => checkbox.checked)
            )
        );

    const updateApproveState = () => {
        if (approveButton) approveButton.disabled = !checklistComplete();
    };

    const showInlineError = (message = '') => {
        if (!inlineError) return;
        inlineError.textContent = message;
        inlineError.hidden = message === '';
    };

    const clearRemarksError = () => {
        if (!remarksError) return;
        remarksError.textContent = '';
        remarksError.hidden = true;
    };

    const configureRemarks = (decision) => {
        const needsRemarks = !isPositiveDecision(decision);
        remarksWrap.hidden = !needsRemarks;
        clearRemarksError();

        if (!needsRemarks) {
            remarksField.value = '';
            return;
        }

        remarksField.value = remarksInput.value || '';

        if (decision === 'RETURNED_FOR_REVISION') {
            remarksLabel.textContent = 'Revision instructions *';
            remarksHelp.textContent = 'State exactly what the borrower needs to correct before resubmitting.';
            remarksField.placeholder = 'Example: Upload a clearer signed BR Letter and correct the requested quantity.';
        } else {
            remarksLabel.textContent = 'Reason for rejection *';
            remarksHelp.textContent = 'State a clear reason that can be shown to the borrower.';
            remarksField.placeholder = 'Example: The request cannot be approved for the selected activity or schedule.';
        }
    };

    const openConfirmation = (decision) => {
        const copy = decisionCopy[decision];

        pendingDecision = decision;
        decisionInput.value = decision;

        confirmTitle.textContent = copy.title;
        confirmMessage.textContent = copy.message;
        confirmSubmit.textContent = copy.confirm;
        confirmSubmit.className = `button ${copy.tone} ui-pressable`;

        /*
         * Approval receives the richer confirmation treatment.
         * Verification / revision / rejection retain the standard dialog.
         */
        const approvalMode = decision === 'APPROVED';

        dialog.classList.toggle(
            'is-admin-approval-confirmation',
            approvalMode
        );

        const surface = dialog.firstElementChild;

        if (surface) {
            surface.classList.toggle(
                'is-admin-approval-surface',
                approvalMode
            );
        }

        const copyWrap = confirmTitle.parentElement;

        if (copyWrap) {
            copyWrap.classList.toggle(
                'is-admin-approval-copy',
                approvalMode
            );
        }

        const actions = confirmSubmit.parentElement;

        if (actions) {
            actions.classList.toggle(
                'is-admin-approval-actions',
                approvalMode
            );
        }


        /* -----------------------------------------------
           Icon
           ----------------------------------------------- */

        const icon =
            dialog.querySelector('.spmu-confirm-dialog__icon');

        if (icon) {
            icon.classList.toggle(
                'is-admin-approval-icon',
                approvalMode
            );

            icon.textContent =
                approvalMode ? '✓' : '?';
        }


        /* -----------------------------------------------
           CONFIRMATION eyebrow
           ----------------------------------------------- */

        let eyebrow =
            dialog.querySelector(
                '[data-admin-approval-eyebrow]'
            );

        if (!eyebrow && copyWrap) {

            eyebrow = document.createElement('p');

            eyebrow.dataset.adminApprovalEyebrow = '';

            eyebrow.className =
                'admin-approval-eyebrow';

            eyebrow.textContent =
                'CONFIRMATION';

            copyWrap.insertBefore(
                eyebrow,
                confirmTitle
            );
        }

        if (eyebrow) {
            eyebrow.hidden =
                !approvalMode;
        }


        /* -----------------------------------------------
           Approval information panel
           ----------------------------------------------- */

        let approvalNote =
            dialog.querySelector(
                '[data-admin-approval-note]'
            );

        if (!approvalNote && surface) {

            approvalNote =
                document.createElement('div');

            approvalNote.dataset.adminApprovalNote = '';

            approvalNote.className =
                'admin-approval-note';

            approvalNote.setAttribute(
                'role',
                'note'
            );

            const infoIcon =
                document.createElement('span');

            infoIcon.className =
                'admin-approval-note-icon';

            infoIcon.setAttribute(
                'aria-hidden',
                'true'
            );

            infoIcon.textContent = 'i';

            const noteCopy =
                document.createElement('p');

            noteCopy.className =
                'admin-approval-note-copy';

            approvalNote.append(
                infoIcon,
                noteCopy
            );

            if (actions) {
                surface.insertBefore(
                    approvalNote,
                    actions
                );
            }
            else {
                surface.appendChild(
                    approvalNote
                );
            }
        }

        /*
         * Keep the approval information panel inside the normal
         * dialog content column. The shared SPMU dialog surface
         * is already a grid: adding this panel directly to the
         * surface places it in the narrow icon column.
         */
        if (
            approvalNote &&
            copyWrap &&
            approvalNote.parentElement !== copyWrap
        ) {
            const remarksBlock =
                copyWrap.querySelector(
                    '[data-confirm-remarks-wrap]'
                );

            if (remarksBlock) {
                copyWrap.insertBefore(
                    approvalNote,
                    remarksBlock
                );
            }
            else {
                copyWrap.appendChild(
                    approvalNote
                );
            }
        }

        if (approvalNote) {

            approvalNote.hidden =
                !approvalMode;

            const noteCopy =
                approvalNote.querySelector(
                    '.admin-approval-note-copy'
                );

            if (noteCopy) {
                noteCopy.textContent =
                    copy.note || '';
            }
        }

        /* ADMIN APPROVAL INLINE VISUAL START */

        /*
         * Compact, universal approval confirmation modal.
         * Uses the shared app typography by inheriting font-family.
         * Presentation only.
         */

        const applyApprovalInlineStyle = (element, declarations) => {
            if (!element) {
                return;
            }

            if (!element.dataset.adminApprovalOriginalStyleStored) {
                element.dataset.adminApprovalOriginalStyleStored = '1';
                element.dataset.adminApprovalOriginalStyle =
                    element.getAttribute('style') ?? '__NONE__';
            }

            Object.entries(declarations).forEach(([property, value]) => {
                element.style.setProperty(property, value, 'important');
            });
        };


        const restoreApprovalInlineStyle = (element) => {
            if (
                !element ||
                !element.dataset.adminApprovalOriginalStyleStored
            ) {
                return;
            }

            const original =
                element.dataset.adminApprovalOriginalStyle;

            if (original === '__NONE__') {
                element.removeAttribute('style');
            } else {
                element.setAttribute('style', original);
            }

            delete element.dataset.adminApprovalOriginalStyle;
            delete element.dataset.adminApprovalOriginalStyleStored;
        };


        const approvalNoteIcon =
            approvalNote?.querySelector('.admin-approval-note-icon');

        const approvalNoteCopy =
            approvalNote?.querySelector('.admin-approval-note-copy');

        const approvalStyledElements = [
            dialog,
            surface,
            icon,
            copyWrap,
            eyebrow,
            confirmTitle,
            confirmMessage,
            approvalNote,
            approvalNoteIcon,
            approvalNoteCopy,
            actions,
            confirmCancel,
            confirmSubmit
        ];


        if (approvalMode) {

            applyApprovalInlineStyle(dialog, {
                width: 'auto',
                'max-width': 'none',
                padding: '0',
                border: '0',
                background: 'transparent',
                overflow: 'visible'
            });

            applyApprovalInlineStyle(surface, {
                display: 'grid',
                'grid-template-columns': '46px minmax(0, 1fr)',
                'grid-template-rows': 'auto auto',
                'column-gap': '11px',
                'row-gap': '12px',
                width: 'min(500px, calc(100vw - 28px))',
                'max-width': '500px',
                'min-width': '0',
                margin: '0',
                padding: '16px 18px',
                border: '1px solid #d8e1ec',
                'border-radius': '12px',
                background: '#ffffff',
                'box-shadow': '0 18px 44px rgba(15,23,42,.18)',
                overflow: 'visible',
                'box-sizing': 'border-box',
                'font-family': 'inherit'
            });

            applyApprovalInlineStyle(icon, {
                'grid-column': '1',
                'grid-row': '1',
                display: 'grid',
                'place-items': 'center',
                width: '32px',
                height: '32px',
                'min-width': '32px',
                'max-width': '32px',
                margin: '0',
                padding: '0',
                border: '5px solid #e1efff',
                'border-radius': '50%',
                background: '#2374eb',
                color: '#ffffff',
                'font-size': '11px',
                'font-weight': '800',
                'line-height': '1',
                'box-sizing': 'content-box'
            });

            applyApprovalInlineStyle(copyWrap, {
                'grid-column': '2',
                'grid-row': '1',
                display: 'block',
                width: '100%',
                'min-width': '0',
                'max-width': 'none',
                margin: '0',
                padding: '0',
                'font-family': 'inherit'
            });

            applyApprovalInlineStyle(eyebrow, {
                display: 'block',
                width: '100%',
                margin: '0 0 4px',
                padding: '0',
                color: '#557ab3',
                'font-family': 'inherit',
                'font-size': '10px',
                'font-weight': '700',
                'line-height': '1.2',
                'letter-spacing': '.10em',
                'text-transform': 'uppercase',
                'white-space': 'normal',
                'word-break': 'normal',
                'overflow-wrap': 'normal'
            });

            applyApprovalInlineStyle(confirmTitle, {
                display: 'block',
                width: '100%',
                'min-width': '0',
                'max-width': 'none',
                margin: '0',
                padding: '0',
                color: '#0b2a56',
                'font-family': 'inherit',
                'font-size': '11px',
                'font-weight': '700',
                'line-height': '1.25',
                'white-space': 'normal',
                'word-break': 'normal',
                'overflow-wrap': 'normal'
            });

            applyApprovalInlineStyle(confirmMessage, {
                display: 'block',
                width: '100%',
                'min-width': '0',
                'max-width': 'none',
                margin: '5px 0 0',
                padding: '0',
                color: '#617492',
                'font-family': 'inherit',
                'font-size': '12px',
                'font-weight': '400',
                'line-height': '1.5',
                'white-space': 'normal',
                'word-break': 'normal',
                'overflow-wrap': 'normal'
            });

            if (approvalNote) {
                applyApprovalInlineStyle(approvalNote, {
                    display: 'grid',
                    'grid-template-columns': '22px minmax(0, 1fr)',
                    'align-items': 'start',
                    gap: '9px',
                    width: '100%',
                    'min-width': '0',
                    'max-width': '100%',
                    margin: '10px 0 0',
                    padding: '10px 12px',
                    border: '1px solid #bddafb',
                    'border-radius': '7px',
                    background: '#f3f8ff',
                    'box-sizing': 'border-box',
                    overflow: 'visible',
                    'font-family': 'inherit'
                });

                applyApprovalInlineStyle(approvalNoteIcon, {
                    display: 'grid',
                    'place-items': 'center',
                    width: '22px',
                    height: '22px',
                    'min-width': '22px',
                    'max-width': '22px',
                    margin: '0',
                    padding: '0',
                    'border-radius': '50%',
                    background: '#d6e9ff',
                    color: '#246bdc',
                    'font-size': '12px',
                    'font-weight': '600',
                    'line-height': '1'
                });

                applyApprovalInlineStyle(approvalNoteCopy, {
                    display: 'block',
                    width: 'auto',
                    'min-width': '0',
                    'max-width': 'none',
                    margin: '0',
                    padding: '0',
                    color: '#28558f',
                    'font-family': 'inherit',
                    'font-size': '11.5px',
                    'font-weight': '400',
                    'line-height': '1.55',
                    'white-space': 'normal',
                    'word-break': 'normal',
                    'overflow-wrap': 'normal',
                    hyphens: 'none'
                });
            }

            applyApprovalInlineStyle(actions, {
                'grid-column': '1 / -1',
                'grid-row': '2',
                display: 'flex',
                'align-items': 'center',
                'justify-content': 'flex-end',
                gap: '9px',
                width: '100%',
                margin: '0',
                padding: '0',
                'font-family': 'inherit'
            });

            applyApprovalInlineStyle(confirmCancel, {
                width: 'auto',
                'min-width': '86px',
                'min-height': '34px',
                padding: '0 12px',
                border: '1px solid #b9c7d8',
                'border-radius': '7px',
                background: '#ffffff',
                color: '#415673',
                'font-family': 'inherit',
                'font-size': '11.5px',
                'font-weight': '600',
                'white-space': 'nowrap',
                'box-shadow': 'none'
            });

            applyApprovalInlineStyle(confirmSubmit, {
                width: 'auto',
                'min-width': '132px',
                'min-height': '34px',
                padding: '0 14px',
                border: '1px solid #1766df',
                'border-radius': '7px',
                background: 'linear-gradient(180deg, #2d7df5 0%, #1769e0 100%)',
                color: '#ffffff',
                'font-family': 'inherit',
                'font-size': '11.5px',
                'font-weight': '700',
                'white-space': 'nowrap',
                'box-shadow': '0 6px 14px rgba(23,105,224,.16)'
            });

        } else {
            approvalStyledElements.forEach(
                restoreApprovalInlineStyle
            );
        }

/* ADMIN APPROVAL INLINE VISUAL END */

/* RETURN REVISION INLINE VISUAL START */

        /*
         * Return for Revision uses the same compact modal
         * geometry and typography as final approval, while
         * retaining an amber revision/warning semantic tone.
         */

        const revisionMode =
            decision === 'RETURNED_FOR_REVISION';

        const revisionStyledElements = [
            dialog,
            surface,
            icon,
            copyWrap,
            confirmTitle,
            confirmMessage,
            remarksWrap,
            remarksLabel,
            remarksField,
            remarksHelp,
            actions,
            confirmCancel,
            confirmSubmit
        ];


        if (revisionMode) {

            /* ---------------------------------------------
               Dialog
               --------------------------------------------- */

            applyApprovalInlineStyle(
                dialog,
                {
                    width: 'auto',
                    'max-width': 'none',
                    padding: '0',
                    border: '0',
                    background: 'transparent',
                    overflow: 'visible'
                }
            );


            /* ---------------------------------------------
               Surface
               Same medium size as Approval
               --------------------------------------------- */

            applyApprovalInlineStyle(
                surface,
                {
                    display: 'grid',

                    'grid-template-columns':
                        '46px minmax(0, 1fr)',

                    'grid-template-rows':
                        'auto auto',

                    'column-gap': '11px',
                    'row-gap': '12px',

                    width:
                        'min(500px, calc(100vw - 28px))',

                    'max-width': '500px',
                    'min-width': '0',

                    margin: '0',

                    padding: '16px 18px',

                    border:
                        '1px solid #d8e1ec',

                    'border-radius': '12px',

                    background: '#ffffff',

                    'box-shadow':
                        '0 18px 44px rgba(15,23,42,.18)',

                    overflow: 'visible',

                    'box-sizing': 'border-box',

                    'font-family': 'inherit'
                }
            );


            /* ---------------------------------------------
               Amber revision icon
               --------------------------------------------- */

            icon.textContent = '?';

            applyApprovalInlineStyle(
                icon,
                {
                    'grid-column': '1',
                    'grid-row': '1',

                    display: 'grid',
                    'place-items': 'center',

                    width: '32px',
                    height: '32px',

                    'min-width': '32px',
                    'max-width': '32px',

                    margin: '0',
                    padding: '0',

                    border:
                        '5px solid #fff3d5',

                    'border-radius': '50%',

                    background: '#f5ad28',

                    color: '#ffffff',

                    'font-family': 'inherit',
                    'font-size': '16px',
                    'font-weight': '800',
                    'line-height': '1',

                    'box-sizing': 'content-box'
                }
            );


            /* ---------------------------------------------
               Main content
               --------------------------------------------- */

            applyApprovalInlineStyle(
                copyWrap,
                {
                    'grid-column': '2',
                    'grid-row': '1',

                    display: 'block',

                    width: '100%',
                    'min-width': '0',
                    'max-width': 'none',

                    margin: '0',
                    padding: '0',

                    'font-family': 'inherit'
                }
            );


            /* ---------------------------------------------
               Title
               --------------------------------------------- */

            applyApprovalInlineStyle(
                confirmTitle,
                {
                    display: 'block',

                    width: '100%',

                    margin: '0',
                    padding: '0',

                    color: '#0b2a56',

                    'font-family': 'inherit',

                    'font-size': '16px',
                    'font-weight': '700',
                    'line-height': '1.3',

                    'white-space': 'normal',
                    'word-break': 'normal',
                    'overflow-wrap': 'normal'
                }
            );


            /* ---------------------------------------------
               Description
               --------------------------------------------- */

            applyApprovalInlineStyle(
                confirmMessage,
                {
                    display: 'block',

                    width: '100%',

                    margin: '5px 0 0',
                    padding: '0',

                    color: '#617492',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '400',
                    'line-height': '1.5',

                    'white-space': 'normal',
                    'word-break': 'normal',
                    'overflow-wrap': 'normal'
                }
            );


            /* ---------------------------------------------
               Revision remarks section
               --------------------------------------------- */

            applyApprovalInlineStyle(
                remarksWrap,
                {
                    width: '100%',
                    'min-width': '0',

                    margin: '14px 0 0',
                    padding: '0',

                    'font-family': 'inherit'
                }
            );


            applyApprovalInlineStyle(
                remarksLabel,
                {
                    display: 'block',

                    margin: '0 0 6px',

                    color: '#324966',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '600',
                    'line-height': '1.35'
                }
            );


            /* textarea */

            applyApprovalInlineStyle(
                remarksField,
                {
                    display: 'block',

                    width: '100%',
                    'min-width': '0',

                    height: '92px',
                    'min-height': '92px',
                    'max-height': '150px',

                    margin: '0',

                    padding: '10px 11px',

                    border:
                        '1px solid #b7c6d8',

                    'border-radius': '8px',

                    background: '#ffffff',

                    color: '#263d59',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '400',
                    'line-height': '1.5',

                    resize: 'vertical',

                    'box-sizing': 'border-box'
                }
            );


            /* helper text */

            applyApprovalInlineStyle(
                remarksHelp,
                {
                    display: 'block',

                    margin: '5px 0 0',

                    color: '#657a96',

                    'font-family': 'inherit',

                    'font-size': '10.5px',
                    'font-weight': '400',
                    'line-height': '1.45'
                }
            );


            /* ---------------------------------------------
               Actions
               --------------------------------------------- */

            applyApprovalInlineStyle(
                actions,
                {
                    'grid-column': '1 / -1',
                    'grid-row': '2',

                    display: 'flex',

                    'align-items': 'center',
                    'justify-content': 'flex-end',

                    gap: '8px',

                    width: '100%',

                    margin: '0',
                    padding: '0',

                    'font-family': 'inherit'
                }
            );


            /* Go Back */

            applyApprovalInlineStyle(
                confirmCancel,
                {
                    width: 'auto',

                    'min-width': '86px',
                    'min-height': '34px',

                    padding: '0 12px',

                    border:
                        '1px solid #b9c7d8',

                    'border-radius': '8px',

                    background: '#ffffff',

                    color: '#415673',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '600',

                    'white-space': 'nowrap',

                    'box-shadow': 'none'
                }
            );


            /* Return for Revision */

            applyApprovalInlineStyle(
                confirmSubmit,
                {
                    width: 'auto',

                    'min-width': '132px',
                    'min-height': '34px',

                    padding: '0 14px',

                    border:
                        '1px solid #d68b08',

                    'border-radius': '8px',

                    background:
                        'linear-gradient(180deg, #f7b733 0%, #e99a10 100%)',

                    color: '#ffffff',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '700',

                    'white-space': 'nowrap',

                    'box-shadow':
                        '0 6px 14px rgba(217,145,12,.16)'
                }
            );

        }
        else if (!approvalMode) {

            /*
             * Restore the normal shared modal for Reject /
             * Verification. Approval has its own inline styling.
             */

            revisionStyledElements.forEach(
                restoreApprovalInlineStyle
            );
        }

        /* RETURN REVISION INLINE VISUAL END */

/* REJECT REQUEST INLINE VISUAL START */

        /*
         * Reject uses the same universal modal geometry and
         * typography as Approval and Return for Revision.
         * Only the destructive semantic treatment differs.
         */

        const rejectMode =
            decision === 'REJECTED';

        const rejectStyledElements = [
            dialog,
            surface,
            icon,
            copyWrap,
            confirmTitle,
            confirmMessage,
            remarksWrap,
            remarksLabel,
            remarksField,
            remarksHelp,
            actions,
            confirmCancel,
            confirmSubmit
        ];


        if (rejectMode) {

            /* ---------------------------------------------
               Dialog
               --------------------------------------------- */

            applyApprovalInlineStyle(
                dialog,
                {
                    width: 'auto',
                    'max-width': 'none',

                    padding: '0',

                    border: '0',

                    background: 'transparent',

                    overflow: 'visible'
                }
            );


            /* ---------------------------------------------
               Universal 500px surface
               --------------------------------------------- */

            applyApprovalInlineStyle(
                surface,
                {
                    display: 'grid',

                    'grid-template-columns':
                        '46px minmax(0, 1fr)',

                    'grid-template-rows':
                        'auto auto',

                    'column-gap': '11px',
                    'row-gap': '12px',

                    width:
                        'min(500px, calc(100vw - 28px))',

                    'max-width': '500px',
                    'min-width': '0',

                    margin: '0',

                    padding: '16px 18px',

                    border:
                        '1px solid #d8e1ec',

                    'border-radius': '12px',

                    background: '#ffffff',

                    'box-shadow':
                        '0 18px 44px rgba(15,23,42,.18)',

                    overflow: 'visible',

                    'box-sizing': 'border-box',

                    'font-family': 'inherit'
                }
            );


            /* ---------------------------------------------
               RED destructive icon
               --------------------------------------------- */

            icon.textContent = '!';

            applyApprovalInlineStyle(
                icon,
                {
                    'grid-column': '1',
                    'grid-row': '1',

                    display: 'grid',
                    'place-items': 'center',

                    width: '32px',
                    height: '32px',

                    'min-width': '32px',
                    'max-width': '32px',

                    margin: '0',
                    padding: '0',

                    border:
                        '5px solid #fee2e2',

                    'border-radius': '50%',

                    background: '#dc3d3d',

                    color: '#ffffff',

                    'font-family': 'inherit',

                    'font-size': '16px',
                    'font-weight': '700',
                    'line-height': '1',

                    'box-sizing': 'content-box'
                }
            );


            /* ---------------------------------------------
               Main content
               --------------------------------------------- */

            applyApprovalInlineStyle(
                copyWrap,
                {
                    'grid-column': '2',
                    'grid-row': '1',

                    display: 'block',

                    width: '100%',
                    'min-width': '0',
                    'max-width': 'none',

                    margin: '0',
                    padding: '0',

                    'font-family': 'inherit'
                }
            );


            /* ---------------------------------------------
               Title
               --------------------------------------------- */

            applyApprovalInlineStyle(
                confirmTitle,
                {
                    display: 'block',

                    width: '100%',

                    margin: '0',
                    padding: '0',

                    color: '#0b2a56',

                    'font-family': 'inherit',

                    'font-size': '16px',
                    'font-weight': '700',
                    'line-height': '1.3',

                    'white-space': 'normal',
                    'word-break': 'normal',
                    'overflow-wrap': 'normal'
                }
            );


            /* ---------------------------------------------
               Description
               --------------------------------------------- */

            applyApprovalInlineStyle(
                confirmMessage,
                {
                    display: 'block',

                    width: '100%',

                    margin: '5px 0 0',
                    padding: '0',

                    color: '#617492',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '400',
                    'line-height': '1.5',

                    'white-space': 'normal',
                    'word-break': 'normal',
                    'overflow-wrap': 'normal'
                }
            );


            /* ---------------------------------------------
               Rejection reason section
               --------------------------------------------- */

            applyApprovalInlineStyle(
                remarksWrap,
                {
                    width: '100%',
                    'min-width': '0',

                    margin: '14px 0 0',
                    padding: '0',

                    'font-family': 'inherit'
                }
            );


            applyApprovalInlineStyle(
                remarksLabel,
                {
                    display: 'block',

                    margin: '0 0 6px',

                    color: '#324966',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '600',
                    'line-height': '1.35'
                }
            );


            /* textarea */

            applyApprovalInlineStyle(
                remarksField,
                {
                    display: 'block',

                    width: '100%',
                    'min-width': '0',

                    height: '92px',
                    'min-height': '92px',
                    'max-height': '150px',

                    margin: '0',

                    padding: '10px 11px',

                    border:
                        '1px solid #b7c6d8',

                    'border-radius': '8px',

                    background: '#ffffff',

                    color: '#263d59',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '400',
                    'line-height': '1.5',

                    resize: 'vertical',

                    'box-sizing': 'border-box'
                }
            );


            /* helper */

            applyApprovalInlineStyle(
                remarksHelp,
                {
                    display: 'block',

                    margin: '5px 0 0',

                    color: '#657a96',

                    'font-family': 'inherit',

                    'font-size': '10.5px',
                    'font-weight': '400',
                    'line-height': '1.45'
                }
            );


            /* ---------------------------------------------
               Actions
               --------------------------------------------- */

            applyApprovalInlineStyle(
                actions,
                {
                    'grid-column': '1 / -1',
                    'grid-row': '2',

                    display: 'flex',

                    'align-items': 'center',
                    'justify-content': 'flex-end',

                    gap: '8px',

                    width: '100%',

                    margin: '0',
                    padding: '0',

                    'font-family': 'inherit'
                }
            );


            /* Go Back */

            applyApprovalInlineStyle(
                confirmCancel,
                {
                    width: 'auto',

                    'min-width': '86px',
                    'min-height': '34px',

                    padding: '0 12px',

                    border:
                        '1px solid #b9c7d8',

                    'border-radius': '8px',

                    background: '#ffffff',

                    color: '#415673',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '600',

                    'white-space': 'nowrap',

                    'box-shadow': 'none'
                }
            );


            /* Reject */

            applyApprovalInlineStyle(
                confirmSubmit,
                {
                    width: 'auto',

                    'min-width': '118px',
                    'min-height': '34px',

                    padding: '0 14px',

                    border:
                        '1px solid #c92d2d',

                    'border-radius': '8px',

                    background:
                        'linear-gradient(180deg, #ee5151 0%, #d93434 100%)',

                    color: '#ffffff',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '700',

                    'white-space': 'nowrap',

                    'box-shadow':
                        '0 6px 14px rgba(217,52,52,.16)'
                }
            );
        }

        /*
         * Do not restore while Approval or Return for Revision
         * has its own universal treatment active.
         */
        else if (
            !approvalMode &&
            decision !== 'RETURNED_FOR_REVISION'
        ) {
            rejectStyledElements.forEach(
                restoreApprovalInlineStyle
            );
        }

        /* REJECT REQUEST INLINE VISUAL END */

/* ACTION OFFICER VERIFIED INLINE VISUAL START */

        const verificationMode =
            decision === 'VERIFIED';

        if (verificationMode) {

            /*
             * Reuse the universal confirmation elements already
             * created for the shared SPMU decision dialog.
             */

            if (eyebrow) {
                eyebrow.hidden = false;
                eyebrow.textContent = 'CONFIRMATION';
            }

            if (approvalNote) {
                approvalNote.hidden = false;

                const verificationNoteCopy =
                    approvalNote.querySelector(
                        '.admin-approval-note-copy'
                    );

                if (verificationNoteCopy) {
                    verificationNoteCopy.textContent =
                        copy.note || '';
                }
            }

            if (
                approvalNote &&
                copyWrap &&
                approvalNote.parentElement !== copyWrap
            ) {
                const remarksBlock =
                    copyWrap.querySelector(
                        '[data-confirm-remarks-wrap]'
                    );

                if (remarksBlock) {
                    copyWrap.insertBefore(
                        approvalNote,
                        remarksBlock
                    );
                }
                else {
                    copyWrap.appendChild(
                        approvalNote
                    );
                }
            }


            /* ---------------------------------------------
               Dialog
               --------------------------------------------- */

            applyApprovalInlineStyle(
                dialog,
                {
                    width: 'auto',
                    'max-width': 'none',
                    padding: '0',
                    border: '0',
                    background: 'transparent',
                    overflow: 'visible'
                }
            );


            /* ---------------------------------------------
               Universal 500px confirmation card
               --------------------------------------------- */

            applyApprovalInlineStyle(
                surface,
                {
                    display: 'grid',

                    'grid-template-columns':
                        '46px minmax(0, 1fr)',

                    'grid-template-rows':
                        'auto auto',

                    'column-gap': '11px',
                    'row-gap': '12px',

                    width:
                        'min(500px, calc(100vw - 28px))',

                    'max-width': '500px',
                    'min-width': '0',

                    margin: '0',

                    padding: '16px 18px',

                    border:
                        '1px solid #d8e1ec',

                    'border-radius': '12px',

                    background: '#ffffff',

                    'box-shadow':
                        '0 18px 44px rgba(15,23,42,.18)',

                    overflow: 'visible',

                    'box-sizing': 'border-box',

                    'font-family': 'inherit'
                }
            );


            /* ---------------------------------------------
               Verification check icon
               --------------------------------------------- */

            if (icon) {
                icon.textContent = '✓';
            }

            applyApprovalInlineStyle(
                icon,
                {
                    'grid-column': '1',
                    'grid-row': '1',

                    display: 'grid',
                    'place-items': 'center',

                    width: '32px',
                    height: '32px',

                    'min-width': '32px',
                    'max-width': '32px',

                    margin: '0',
                    padding: '0',

                    border:
                        '5px solid #e1efff',

                    'border-radius': '50%',

                    background: '#2374eb',

                    color: '#ffffff',

                    'font-family': 'inherit',

                    'font-size': '16px',
                    'font-weight': '800',
                    'line-height': '1',

                    'box-sizing': 'content-box'
                }
            );


            /* ---------------------------------------------
               Copy column
               --------------------------------------------- */

            applyApprovalInlineStyle(
                copyWrap,
                {
                    'grid-column': '2',
                    'grid-row': '1',

                    display: 'block',

                    width: '100%',
                    'min-width': '0',
                    'max-width': 'none',

                    margin: '0',
                    padding: '0',

                    'font-family': 'inherit'
                }
            );


            /* eyebrow */

            applyApprovalInlineStyle(
                eyebrow,
                {
                    display: 'block',

                    width: '100%',

                    margin: '0 0 4px',
                    padding: '0',

                    color: '#1769e0',

                    'font-family': 'inherit',

                    'font-size': '10px',
                    'font-weight': '700',
                    'line-height': '1.2',

                    'letter-spacing': '.08em',
                    'text-transform': 'uppercase'
                }
            );


            /* title */

            applyApprovalInlineStyle(
                confirmTitle,
                {
                    display: 'block',

                    width: '100%',

                    margin: '0',
                    padding: '0',

                    color: '#0b2a56',

                    'font-family': 'inherit',

                    'font-size': '18px',
                    'font-weight': '700',
                    'line-height': '1.25',

                    'white-space': 'normal',
                    'word-break': 'normal',
                    'overflow-wrap': 'normal'
                }
            );


            /* subtitle */

            applyApprovalInlineStyle(
                confirmMessage,
                {
                    display: 'block',

                    width: '100%',

                    margin: '5px 0 0',
                    padding: '0',

                    color: '#617492',

                    'font-family': 'inherit',

                    'font-size': '12px',
                    'font-weight': '400',
                    'line-height': '1.5',

                    'white-space': 'normal',
                    'word-break': 'normal',
                    'overflow-wrap': 'normal'
                }
            );


            /* ---------------------------------------------
               Verification information box
               --------------------------------------------- */

            if (approvalNote) {

                const verificationInfoIcon =
                    approvalNote.querySelector(
                        '.admin-approval-note-icon'
                    );

                const verificationInfoCopy =
                    approvalNote.querySelector(
                        '.admin-approval-note-copy'
                    );

                applyApprovalInlineStyle(
                    approvalNote,
                    {
                        display: 'grid',

                        'grid-template-columns':
                            '22px minmax(0, 1fr)',

                        'align-items': 'start',

                        gap: '9px',

                        width: '100%',
                        'min-width': '0',
                        'max-width': '100%',

                        margin: '10px 0 0',

                        padding: '10px 12px',

                        border:
                            '1px solid #bddafb',

                        'border-radius': '8px',

                        background: '#f3f8ff',

                        'box-sizing': 'border-box',

                        'font-family': 'inherit'
                    }
                );

                applyApprovalInlineStyle(
                    verificationInfoIcon,
                    {
                        display: 'grid',
                        'place-items': 'center',

                        width: '22px',
                        height: '22px',

                        'min-width': '22px',
                        'max-width': '22px',

                        margin: '0',
                        padding: '0',

                        'border-radius': '50%',

                        background: '#d6e9ff',

                        color: '#246bdc',

                        'font-family': 'inherit',

                        'font-size': '12px',
                        'font-weight': '800',
                        'line-height': '1'
                    }
                );

                applyApprovalInlineStyle(
                    verificationInfoCopy,
                    {
                        display: 'block',

                        width: 'auto',
                        'min-width': '0',
                        'max-width': 'none',

                        margin: '0',
                        padding: '0',

                        color: '#28558f',

                        'font-family': 'inherit',

                        'font-size': '11.5px',
                        'font-weight': '400',
                        'line-height': '1.55',

                        'white-space': 'normal',
                        'word-break': 'normal',
                        'overflow-wrap': 'normal'
                    }
                );
            }


            /* ---------------------------------------------
               Footer
               --------------------------------------------- */

            applyApprovalInlineStyle(
                actions,
                {
                    'grid-column': '1 / -1',
                    'grid-row': '2',

                    display: 'flex',

                    'align-items': 'center',
                    'justify-content': 'flex-end',

                    gap: '8px',

                    width: '100%',

                    margin: '0',
                    padding: '0',

                    'font-family': 'inherit'
                }
            );


            /* Go Back */

            applyApprovalInlineStyle(
                confirmCancel,
                {
                    width: 'auto',

                    'min-width': '86px',
                    'min-height': '34px',

                    padding: '0 12px',

                    border:
                        '1px solid #b9c7d8',

                    'border-radius': '8px',

                    background: '#ffffff',

                    color: '#415673',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '600',

                    'white-space': 'nowrap',

                    'box-shadow': 'none'
                }
            );


            /* E-sign & Mark VERIFIED */

            applyApprovalInlineStyle(
                confirmSubmit,
                {
                    width: 'auto',

                    'min-width': '150px',
                    'min-height': '34px',

                    padding: '0 14px',

                    border:
                        '1px solid #1766df',

                    'border-radius': '8px',

                    background:
                        'linear-gradient(180deg, #2d7df5 0%, #1769e0 100%)',

                    color: '#ffffff',

                    'font-family': 'inherit',

                    'font-size': '11.5px',
                    'font-weight': '700',

                    'white-space': 'nowrap',

                    'box-shadow':
                        '0 6px 14px rgba(23,105,224,.16)'
                }
            );
        }

        /* ACTION OFFICER VERIFIED INLINE VISUAL END */

        configureRemarks(decision);

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
            if (!isPositiveDecision(decision)) {
                window.setTimeout(() => remarksField.focus(), 0);
            }
            return;
        }

        if (isPositiveDecision(decision)) {
            if (window.confirm(`${copy.title}\n\n${copy.message}`)) {
                if (eSignatureInput) eSignatureInput.value = '1';
                form.submit();
            }
            return;
        }

        const fallbackRemarks = window.prompt(
            decision === 'REJECTED'
                ? 'Enter the reason for rejection:'
                : 'Enter the revision instructions:'
        );

        if (fallbackRemarks && fallbackRemarks.trim()) {
            remarksInput.value = fallbackRemarks.trim();
            form.submit();
        }
    };

    checks.forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            showInlineError('');
            updateApproveState();
        });
    });

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const decision = trigger.dataset.decisionTrigger;
            showInlineError('');

            if (isPositiveDecision(decision) && !checklistComplete()) {
                showInlineError(
                    !requiredSupportingPresent
                        ? 'A required supporting document is missing. This action is unavailable until the required document is attached.'
                        : !hasCurrentESignature
                            ? 'Register your E-signature in Account Settings before continuing.'
                            : 'Complete all checks before continuing.'
                );
                return;
            }

            openConfirmation(decision);
        });
    });

    confirmCancel?.addEventListener('click', () => {
        pendingDecision = '';
        decisionInput.value = '';
        clearRemarksError();
        dialog.close();
    });

    confirmSubmit?.addEventListener('click', () => {
        if (!pendingDecision) return;

        if (!isPositiveDecision(pendingDecision)) {
            if (eSignatureInput) eSignatureInput.value = '';
            const value = remarksField.value.trim();

            if (!value) {
                remarksError.textContent =
                    pendingDecision === 'REJECTED'
                        ? 'Enter the reason for rejection before continuing.'
                        : 'Enter revision instructions before continuing.';
                remarksError.hidden = false;
                remarksField.focus();
                return;
            }

            remarksInput.value = value;
        } else {
            remarksInput.value = '';
            if (eSignatureInput) eSignatureInput.value = '1';
        }

        confirmSubmit.disabled = true;
        // A request that never navigates away (e.g. a dropped connection)
        // would otherwise leave this button disabled forever; re-enable it
        // automatically so the user can retry. This never fires on a normal
        // submission because the page has already navigated away by then.
        window.setTimeout(() => { confirmSubmit.disabled = false; }, 20000);
        form.submit();
    });

    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        pendingDecision = '';
        decisionInput.value = '';
        clearRemarksError();
        dialog.close();
    });

    remarksField?.addEventListener('input', clearRemarksError);
    updateApproveState();
})();



</script>
@endif





















@if($isBorrower)
<style>
.request-heading-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

.borrower-request-workspace {
    display: grid;
    gap: 14px;
}

/* Borrower monitoring is always visible above the detail tabs. */
.borrower-progress-always > .request-tracker-card {
    margin-top: 0;
}

.borrower-progress-always .request-tracker__header {
    padding-bottom: 12px;
}

.borrower-progress-always .request-tracker__intro {
    display: none;
}

.borrower-progress-always .request-tracker__scroll {
    padding-top: 18px;
    padding-bottom: 8px;
}

.borrower-progress-always .request-tracker__marker {
    width: 40px;
    height: 40px;
    margin-bottom: 8px;
}

.borrower-progress-always .request-tracker__step::after {
    top: 20px;
    left: calc(50% + 20px);
    width: calc(100% - 40px);
}

.borrower-progress-always .request-tracker__copy small {
    display: none;
}

.borrower-progress-always .request-tracker__step.is-current .request-tracker__copy small,
.borrower-progress-always .request-tracker__step.is-warning .request-tracker__copy small,
.borrower-progress-always .request-tracker__step.is-stopped .request-tracker__copy small {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.borrower-progress-always .request-tracker__hint {
    display: none;
}

.borrower-document-list article {
    min-height: 64px;
}


@media (max-width: 680px) {
    .request-heading-actions {
        justify-content: flex-start;
    }

}
</style>
@endif

@if($isOperationalRequestLayout)
    </div>
@endif
<style>
/* SPMU REVIEW WORKSPACE RECOVERY START */


/* =========================================================
   EQUAL HEIGHT LEFT / RIGHT
   ========================================================= */

.spmu-verification-workspace
.spmu-review-top-row {
    align-items:stretch !important;
}

.spmu-verification-workspace
.spmu-scan-slot,

.spmu-verification-workspace
.spmu-checklist-panel {
    min-width:0 !important;
    height:auto !important;
}

.spmu-verification-workspace
.spmu-checklist-panel {
    display:flex !important;
    flex-direction:column !important;
}

.spmu-verification-workspace
.spmu-verification-form {
    display:flex !important;
    flex:1 1 auto !important;
    flex-direction:column !important;
    min-height:0 !important;
}


/* =========================================================
   DOCUMENT TABS
   ========================================================= */

.spmu-verification-workspace
.spmu-document-tabs {
    display:flex !important;
    align-items:center !important;

    width:100% !important;

    padding:0 12px !important;

    border-bottom:1px solid var(--border, #d7dee8) !important;

    background:#f8fafc !important;
}

.spmu-verification-workspace
.spmu-document-tab {
    min-height:42px !important;

    padding:0 14px !important;

    border:0 !important;
    border-bottom:2px solid transparent !important;

    background:transparent !important;

    color:var(--text-secondary, #40536f) !important;

    font:inherit !important;
    font-size:11px !important;
    font-weight:700 !important;

    cursor:pointer !important;
}

.spmu-verification-workspace
.spmu-document-tab.is-active {
    border-bottom-color:#1769e0 !important;

    color:#0b5fc7 !important;
    background:#ffffff !important;
}

.spmu-verification-workspace
.spmu-document-tab:hover:not(:disabled),
.spmu-verification-workspace
.spmu-document-tab:focus-visible:not(:disabled) {
    color:#1769e0 !important;
    background:#f6f9ff !important;
}

.spmu-verification-workspace
.spmu-document-tab:disabled {
    color:#8a99aa !important;
    cursor:not-allowed !important;
}


/* =========================================================
   CHECKLIST → READINESS
   ========================================================= */

.spmu-verification-workspace
.spmu-checklist {
    margin-bottom:0 !important;
}

.spmu-verification-workspace
.spmu-review-readiness {
    --readiness-progress:0%;

    display:grid !important;

    gap:12px !important;

    margin-top:20px !important;
    padding:16px 17px !important;

    border:1px solid var(--border, #d7dee8) !important;
    border-radius:10px !important;

    background:#f8fafc !important;

    box-shadow:none !important;
}


/* heading */

.spmu-verification-workspace
.spmu-review-readiness-heading {
    display:flex !important;
    align-items:flex-start !important;
    justify-content:space-between !important;

    gap:12px !important;
}

.spmu-verification-workspace
.spmu-review-readiness-heading .eyebrow {
    margin:0 0 3px !important;

    color:var(--text-secondary, #526782) !important;

    font-size:9px !important;
    font-weight:800 !important;
    letter-spacing:.055em !important;
}

.spmu-verification-workspace
.spmu-review-readiness-heading > div > strong {
    display:block !important;

    color:var(--heading, #102746) !important;

    font-size:12px !important;
    font-weight:750 !important;
}


/* status */

.spmu-verification-workspace
.spmu-review-readiness-status {
    display:inline-flex !important;
    align-items:center !important;

    min-height:23px !important;

    padding:4px 9px !important;

    border:1px solid #f0c66c !important;
    border-radius:999px !important;

    background:#fff8e7 !important;

    color:#9a6500 !important;

    font-size:9px !important;
    font-weight:750 !important;
}

.spmu-verification-workspace
.spmu-review-readiness.is-ready
.spmu-review-readiness-status {
    border-color:#a9ddbf !important;

    background:#eaf9f0 !important;

    color:#087b55 !important;
}


/* count */

.spmu-verification-workspace
.spmu-review-readiness-summary {
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;

    gap:12px !important;
}

.spmu-verification-workspace
.spmu-review-readiness-summary strong {
    color:var(--heading, #102746) !important;

    font-size:11px !important;
    font-weight:700 !important;
}

.spmu-verification-workspace
.spmu-review-readiness-summary span {
    color:var(--text-secondary, #526782) !important;

    font-size:10px !important;
    font-weight:700 !important;
}


/* progress */

.spmu-verification-workspace
.spmu-review-readiness-track {
    position:relative !important;

    width:100% !important;
    height:7px !important;

    overflow:hidden !important;

    border-radius:999px !important;

    background:#dce4ee !important;
}

.spmu-verification-workspace
.spmu-review-readiness-bar {
    display:block !important;

    width:var(--readiness-progress) !important;
    height:100% !important;

    border-radius:inherit !important;

    background:#1769e0 !important;

    transition:width 180ms ease !important;
}

.spmu-verification-workspace
.spmu-review-readiness.is-ready
.spmu-review-readiness-bar {
    background:#177551 !important;
}


/* message */

.spmu-verification-workspace
.spmu-review-readiness-message {
    margin:0 !important;

    color:var(--text-muted, #64748b) !important;

    font-size:10px !important;
    line-height:1.4 !important;
}

.spmu-verification-workspace
.spmu-review-readiness.is-ready
.spmu-review-readiness-message {
    color:#087b55 !important;
    font-weight:600 !important;
}


/* =========================================================
   KEEP DECISION ACTIONS LOW / BALANCED
   ========================================================= */

.spmu-verification-workspace
.spmu-decision-actions {
    margin-top:auto !important;
    padding-top:20px !important;
}


/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width:680px) {

    .spmu-verification-workspace
    .spmu-document-tabs {
        overflow-x:auto !important;
    }

    .spmu-verification-workspace
    .spmu-document-tab {
        white-space:nowrap !important;
    }

    .spmu-verification-workspace
    .spmu-review-readiness {
        margin-top:16px !important;
    }
}


/* SPMU DOCUMENT TAB CLICKABILITY FIX */

.spmu-verification-workspace
.spmu-document-tabs {
    position: relative !important;
    z-index: 30 !important;
    pointer-events: auto !important;
}

.spmu-verification-workspace
.spmu-document-tab {
    position: relative !important;
    z-index: 31 !important;
    pointer-events: auto !important;
}

.spmu-verification-workspace
.spmu-document-tab:not(:disabled) {
    cursor: pointer !important;
}

.spmu-verification-workspace
.spmu-document-tab:disabled {
    pointer-events: none !important;
}

.spmu-verification-workspace
.spmu-tab-document-frame {
    display: block;
    width: 100% !important;
    height: 70vh !important;
    min-height: 560px !important;
    border: 0 !important;
    background: #eef3f8;
}

.spmu-verification-workspace
.spmu-tab-document-image {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 0 auto;
}

/* SPMU IMAGE HAND CURSOR START */

/*
 * Uploaded image documents are interactive inspection surfaces.
 * The hand cursor makes it clear that the image can be inspected
 * while the existing - / + / Fit controls handle zoom.
 */
.spmu-verification-workspace
.scanned-document-card img:not([hidden]) {
    cursor: grab !important;
    user-select: none !important;
    -webkit-user-select: none !important;
    -webkit-user-drag: none !important;
}

.spmu-verification-workspace
.scanned-document-card img:not([hidden]):active {
    cursor: grabbing !important;
}

/*
 * Keep the hand cursor on dynamically-created tab images as well.
 */
.spmu-verification-workspace
.spmu-tab-document-image {
    cursor: grab !important;
    user-select: none !important;
    -webkit-user-select: none !important;
    -webkit-user-drag: none !important;
}

.spmu-verification-workspace
.spmu-tab-document-image:active {
    cursor: grabbing !important;
}

/* SPMU IMAGE HAND CURSOR END */

/* SPMU IMAGE-ONLY ZOOM CSS START */

/*
 * Browser/page zoom must not take over while the user is
 * inspecting an uploaded image document.
 */
.spmu-verification-workspace
.scanned-document-card img:not([hidden]) {
    cursor: grab !important;
    user-select: none !important;
    -webkit-user-select: none !important;
    -webkit-user-drag: none !important;

    /*
     * Prevent touch gestures on the image itself from zooming
     * the whole browser page.
     */
    touch-action: none !important;

    /*
     * JS controls image zoom/pan using transform only.
     */
    transform-origin: center center !important;
    will-change: transform;
}

.spmu-verification-workspace
.scanned-document-card img:not([hidden]).is-image-panning {
    cursor: grabbing !important;
}

.spmu-verification-workspace
.scanned-document-card {
    /*
     * Keep transformed image interaction inside the document viewer.
     */
    overscroll-behavior: contain;
}

/* SPMU IMAGE-ONLY ZOOM CSS END */
/* SPMU REVIEW WORKSPACE RECOVERY END */
</style>

<script>
/* SPMU REVIEW WORKSPACE RECOVERY SCRIPT START */

(() => {
    const workspace =
        document.querySelector('[data-spmu-verification-workspace]');

    if (!workspace) {
        return;
    }


    /* =====================================================
       DOCUMENT TABS
       ===================================================== */

    const tabList =
        workspace.querySelector('[data-spmu-document-tabs]');

    if (tabList) {
        const viewer =
            workspace.querySelector('.spmu-scan-slot .scanned-document-card');

        const header =
            viewer?.querySelector('.scanned-document-header');

        const frame =
            viewer?.querySelector('.scanned-pdf-frame');

        /*
         | Visually place tabs directly below:
         | "Inspect the signed Borrowing Request Letter"
         */
        if (viewer && header && tabList.parentElement !== viewer) {
            header.insertAdjacentElement('afterend', tabList);
        }

        const tabs = Array.from(
            tabList.querySelectorAll('[data-document-tab]')
        );

        /* SPMU DOCUMENT TAB IMAGE/PDF COMPATIBILITY FIX */
        let documentActivationSequence = 0;

        const detectDocumentType = async (documentUrl) => {
            try {
                const response = await fetch(documentUrl, {
                    method: 'HEAD',
                    credentials: 'same-origin',
                    cache: 'no-store'
                });

                const contentType =
                    (response.headers.get('content-type') || '')
                        .toLowerCase();

                if (contentType.startsWith('image/')) {
                    return 'image';
                }

                if (contentType.includes('pdf')) {
                    return 'pdf';
                }
            }
            catch (error) {
                // Fallback detection below handles servers that reject HEAD.
            }

            /*
             * Fallback:
             * try loading the file as an image.
             * If the browser cannot decode it as an image, use the frame.
             */
            return await new Promise((resolve) => {
                const probe = new Image();

                let settled = false;

                const finish = (type) => {
                    if (settled) return;

                    settled = true;
                    probe.onload = null;
                    probe.onerror = null;

                    resolve(type);
                };

                probe.onload = () => finish('image');
                probe.onerror = () => finish('pdf');

                probe.src = documentUrl;
            });
        };

        const activate = async (tab) => {
            const documentUrl = tab.dataset.documentUrl;

            if (
                tab.disabled ||
                !documentUrl ||
                !viewer
            ) {
                return;
            }

            const activationSequence =
                ++documentActivationSequence;

            /*
             * Update tab state immediately so the user gets
             * visual feedback as soon as the tab is clicked.
             */
            tabs.forEach((candidate) => {
                const active = candidate === tab;

                candidate.classList.toggle(
                    'is-active',
                    active
                );

                candidate.setAttribute(
                    'aria-selected',
                    active ? 'true' : 'false'
                );

                candidate.tabIndex =
                    active ? 0 : -1;
            });

            const documentType =
                await detectDocumentType(documentUrl);

            /*
             * Ignore a late response if the user already selected
             * another tab while document type detection was running.
             */
            if (
                activationSequence !==
                documentActivationSequence
            ) {
                return;
            }

            let pdfFrame =
                viewer.querySelector(
                    '.scanned-pdf-frame'
                );

            let imageElement =
                viewer.querySelector(
                    '.scanned-document-image,' +
                    '.scanned-image-preview,' +
                    '.scanned-document-preview img,' +
                    '.scanned-document-stage img,' +
                    'img[data-document-image]'
                );

            /*
             * Existing image viewers may not have a dedicated
             * image class, so use the image inside the card
             * as the final fallback.
             */
            if (!imageElement) {
                imageElement =
                    viewer.querySelector('img');
            }

            /*
             * Make image-document interaction obvious to the reviewer.
             * Zoom remains controlled by the existing - / + / Fit controls.
             */
            if (imageElement) {
                imageElement.classList.add(
                    'spmu-document-zoom-image'
                );

                imageElement.setAttribute(
                    'draggable',
                    'false'
                );

                imageElement.setAttribute(
                    'title',
                    'Use the - / + / Fit controls to zoom this document'
                );
            }

            let mediaHost =
                pdfFrame?.parentElement ||
                imageElement?.parentElement;

            /*
             * Very defensive fallback. Normally the existing
             * document viewer already provides a media wrapper.
             */
            if (!mediaHost) {
                mediaHost =
                    document.createElement('div');

                mediaHost.className =
                    'spmu-tab-media-host';

                viewer.appendChild(mediaHost);
            }

            if (documentType === 'image') {
                /*
                 * Reuse the component's original image whenever
                 * possible so its existing zoom / Fit controls
                 * continue to operate.
                 */
                if (!imageElement) {
                    imageElement =
                        document.createElement('img');

                    imageElement.className =
                        'spmu-tab-document-image spmu-document-zoom-image';

                    imageElement.dataset.documentImage = '';

                    imageElement.alt =
                        'Submitted request document';

                    imageElement.draggable = false;

                    imageElement.title =
                        'Use the - / + / Fit controls to zoom this document';

                    mediaHost.appendChild(
                        imageElement
                    );
                }

                if (pdfFrame) {
                    pdfFrame.hidden = true;
                    pdfFrame.style.display = 'none';
                }

                imageElement.hidden = false;
                imageElement.style.display = '';

                imageElement.src = documentUrl;

                return;
            }

            /*
             * PDF / non-image document.
             * If the initial viewer was an image, create the
             * PDF frame only when it is actually needed.
             */
            if (!pdfFrame) {
                pdfFrame =
                    document.createElement('iframe');

                pdfFrame.className =
                    'scanned-pdf-frame spmu-tab-document-frame';

                pdfFrame.dataset.spmuTabFrame = '';

                pdfFrame.title =
                    'Submitted request document preview';

                pdfFrame.setAttribute(
                    'loading',
                    'eager'
                );

                mediaHost.appendChild(
                    pdfFrame
                );
            }

            if (imageElement) {
                imageElement.hidden = true;
                imageElement.style.display = 'none';
            }

            pdfFrame.hidden = false;
            pdfFrame.style.display = '';

            pdfFrame.src =
                documentUrl +
                '#page=1&zoom=page-fit&toolbar=1&navpanes=0&scrollbar=1&view=Fit';
        };
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                activate(tab);
            });

            tab.addEventListener('keydown', (event) => {
                if (
                    ![
                        'ArrowLeft',
                        'ArrowRight',
                        'Home',
                        'End'
                    ].includes(event.key)
                ) {
                    return;
                }

                event.preventDefault();

                const available = tabs.filter(
                    (candidate) =>
                        !candidate.disabled &&
                        candidate.dataset.documentUrl
                );

                if (available.length === 0) {
                    return;
                }

                const current =
                    available.indexOf(tab);

                let next = current;

                if (event.key === 'ArrowLeft') {
                    next =
                        (current - 1 + available.length) %
                        available.length;
                }

                if (event.key === 'ArrowRight') {
                    next =
                        (current + 1) %
                        available.length;
                }

                if (event.key === 'Home') {
                    next = 0;
                }

                if (event.key === 'End') {
                    next = available.length - 1;
                }

                available[next].focus();
                activate(available[next]);
            });
        });
    }


    /* =====================================================
       APPROVAL / VERIFICATION READINESS
       ===================================================== */

    workspace
        .querySelectorAll('[data-verification-form]')
        .forEach((form) => {

            const readiness =
                form.querySelector('[data-review-readiness]');

            if (!readiness) {
                return;
            }

            const checks = Array.from(
                form.querySelectorAll(
                    '.spmu-checklist input[type="checkbox"][data-verification-check]'
                )
            );

            const countNode =
                readiness.querySelector(
                    '[data-review-readiness-count]'
                );

            const percentNode =
                readiness.querySelector(
                    '[data-review-readiness-percent]'
                );

            const statusNode =
                readiness.querySelector(
                    '[data-review-readiness-status]'
                );

            const messageNode =
                readiness.querySelector(
                    '[data-review-readiness-message]'
                );

            const progressNode =
                readiness.querySelector(
                    '[data-review-readiness-progress]'
                );

            const update = () => {
                const total = checks.length;

                const completed =
                    checks.filter(
                        (checkbox) => checkbox.checked
                    ).length;

                const remaining =
                    Math.max(0, total - completed);

                const percent =
                    total > 0
                        ? Math.round(
                            completed / total * 100
                        )
                        : 0;

                const ready =
                    total > 0 &&
                    completed === total;

                readiness.style.setProperty(
                    '--readiness-progress',
                    `${percent}%`
                );

                readiness.classList.toggle(
                    'is-ready',
                    ready
                );

                if (countNode) {
                    countNode.textContent =
                        `${completed} of ${total} checks completed`;
                }

                if (percentNode) {
                    percentNode.textContent =
                        `${percent}%`;
                }

                if (statusNode) {
                    statusNode.textContent =
                        ready
                            ? 'Ready'
                            : 'In progress';
                }

                if (messageNode) {
                    messageNode.textContent =
                        ready
                            ? 'All required review checks are complete.'
                            : (
                                remaining === 1
                                    ? '1 review check remaining.'
                                    : `${remaining} review checks remaining.`
                            );
                }

                if (progressNode) {
                    progressNode.setAttribute(
                        'aria-valuemax',
                        String(total)
                    );

                    progressNode.setAttribute(
                        'aria-valuenow',
                        String(completed)
                    );
                }
            };

            checks.forEach((checkbox) => {
                checkbox.addEventListener(
                    'change',
                    update
                );
            });

            update();
        });
})();


/* SPMU IMAGE-ONLY ZOOM SCRIPT START */

(() => {
    const workspace =
        document.querySelector(
            '[data-spmu-verification-workspace]'
        );

    if (!workspace) {
        return;
    }

    const viewerSelector =
        '.scanned-document-card';

    const imageSelector =
        '.scanned-document-card img:not([hidden])';

    /*
     * Keep independent zoom/pan state for every image document.
     */
    const imageStates = new WeakMap();

    const getState = (image) => {
        if (!imageStates.has(image)) {
            imageStates.set(image, {
                scale: 1,
                x: 0,
                y: 0,
                dragging: false,
                pointerId: null,
                startX: 0,
                startY: 0,
                originX: 0,
                originY: 0
            });
        }

        return imageStates.get(image);
    };

    const clamp = (value, minimum, maximum) =>
        Math.min(
            maximum,
            Math.max(minimum, value)
        );

    const applyTransform = (image, state) => {
        image.style.transform =
            `translate(${state.x}px, ${state.y}px) ` +
            `scale(${state.scale})`;
    };

    const resetImageGesture = (image) => {
        if (!image) {
            return;
        }

        const state = getState(image);

        state.scale = 1;
        state.x = 0;
        state.y = 0;
        state.dragging = false;
        state.pointerId = null;

        image.classList.remove(
            'is-image-panning'
        );

        image.style.transform = '';
    };


    /*
     * ---------------------------------------------------------
     * PINCH / CTRL + MOUSE WHEEL
     * ---------------------------------------------------------
     *
     * Chrome represents trackpad pinch as a wheel event with
     * ctrlKey=true.
     *
     * preventDefault() here stops Chrome from zooming the whole
     * SPMU-ACPMP page and applies the zoom to the image instead.
     */
    workspace.addEventListener(
        'wheel',
        (event) => {
            const target =
                event.target instanceof Element
                    ? event.target
                    : null;

            const image =
                target?.closest(imageSelector);

            if (!image) {
                return;
            }

            /*
             * Only intercept browser-zoom gestures.
             * Plain mouse-wheel scrolling remains normal.
             */
            if (!event.ctrlKey) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const state = getState(image);

            /*
             * Smooth but controlled zoom.
             * Minimum 50%, maximum 300%.
             */
            const direction =
                event.deltaY < 0 ? 1 : -1;

            const step = 0.10;

            const nextScale = clamp(
                state.scale + (direction * step),
                0.50,
                3.00
            );

            /*
             * If returning to 100%, also recenter the document.
             */
            if (
                Math.abs(nextScale - 1) < 0.001
            ) {
                state.scale = 1;
                state.x = 0;
                state.y = 0;
            }
            else {
                state.scale = nextScale;
            }

            applyTransform(
                image,
                state
            );
        },
        {
            passive: false,
            capture: true
        }
    );


    /*
     * ---------------------------------------------------------
     * CLICK + DRAG PAN
     * ---------------------------------------------------------
     *
     * Once the image has been zoomed, grab it and move around
     * inside the document viewer without moving the whole page.
     */
    workspace.addEventListener(
        'pointerdown',
        (event) => {
            if (event.button !== 0) {
                return;
            }

            const target =
                event.target instanceof Element
                    ? event.target
                    : null;

            const image =
                target?.closest(imageSelector);

            if (!image) {
                return;
            }

            const state = getState(image);

            /*
             * Pan is useful after zooming.
             * At normal/fit size, leave normal page interaction alone.
             */
            if (state.scale <= 1) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            state.dragging = true;
            state.pointerId = event.pointerId;

            state.startX = event.clientX;
            state.startY = event.clientY;

            state.originX = state.x;
            state.originY = state.y;

            image.classList.add(
                'is-image-panning'
            );

            try {
                image.setPointerCapture(
                    event.pointerId
                );
            }
            catch (error) {
                // Pointer capture is optional.
            }
        },
        true
    );


    workspace.addEventListener(
        'pointermove',
        (event) => {
            const images =
                workspace.querySelectorAll(
                    imageSelector
                );

            for (const image of images) {
                const state =
                    imageStates.get(image);

                if (
                    !state ||
                    !state.dragging ||
                    state.pointerId !==
                        event.pointerId
                ) {
                    continue;
                }

                event.preventDefault();

                state.x =
                    state.originX +
                    (
                        event.clientX -
                        state.startX
                    );

                state.y =
                    state.originY +
                    (
                        event.clientY -
                        state.startY
                    );

                applyTransform(
                    image,
                    state
                );

                break;
            }
        },
        true
    );


    const finishPan = (event) => {
        const images =
            workspace.querySelectorAll(
                imageSelector
            );

        for (const image of images) {
            const state =
                imageStates.get(image);

            if (
                !state ||
                state.pointerId !==
                    event.pointerId
            ) {
                continue;
            }

            state.dragging = false;
            state.pointerId = null;

            image.classList.remove(
                'is-image-panning'
            );

            try {
                image.releasePointerCapture(
                    event.pointerId
                );
            }
            catch (error) {
                // Safe if pointer capture was unavailable.
            }

            break;
        }
    };

    workspace.addEventListener(
        'pointerup',
        finishPan,
        true
    );

    workspace.addEventListener(
        'pointercancel',
        finishPan,
        true
    );


    /*
     * ---------------------------------------------------------
     * RESET GESTURE TRANSFORM WHEN USING EXISTING TOOLBAR
     * ---------------------------------------------------------
     *
     * Existing -, + and Fit controls continue to own their normal
     * zoom behavior. Before they run, remove gesture-only transform
     * so the two zoom systems never stack on top of each other.
     */
    workspace.addEventListener(
        'click',
        (event) => {
            const target =
                event.target instanceof Element
                    ? event.target
                    : null;

            const button =
                target?.closest('button');

            if (!button) {
                return;
            }

            const label =
                button.textContent
                    ?.trim()
                    .replace(/\s+/g, ' ') ||
                '';

            const isZoomControl =
                label === '+' ||
                label === '-' ||
                label === '−' ||
                /^fit$/i.test(label);

            if (!isZoomControl) {
                return;
            }

            const viewer =
                button.closest(viewerSelector);

            const image =
                viewer?.querySelector(
                    'img:not([hidden])'
                );

            if (image) {
                resetImageGesture(image);
            }
        },
        true
    );


    /*
     * Switching BR Letter / PTC starts the newly-selected image
     * from its normal document-viewer position.
     */
    workspace
        .querySelectorAll(
            '[data-document-tab]'
        )
        .forEach((tab) => {
            tab.addEventListener(
                'click',
                () => {
                    const viewer =
                        workspace.querySelector(
                            viewerSelector
                        );

                    viewer
                        ?.querySelectorAll('img')
                        .forEach(
                            resetImageGesture
                        );
                }
            );
        });

})();

/* SPMU IMAGE-ONLY ZOOM SCRIPT END */
/* SPMU REVIEW WORKSPACE RECOVERY SCRIPT END */
</script>

<style>
/* ADMIN REQUEST DETAILS CLEAN START */

/*
 | Clean request details presentation.
 | Keep the original request data; presentation only.
 */

.spmu-verification-workspace
.spmu-left-borrowing-info
.spmu-left-borrowing-grid-clean {
    display:grid !important;

    grid-template-columns:
        repeat(2, minmax(0, 1fr)) !important;

    column-gap:46px !important;
    row-gap:25px !important;

    width:100% !important;

    padding:22px 20px 24px !important;

    border:0 !important;
    background:transparent !important;
    box-shadow:none !important;
}


/* ---------------------------------------------------------
   Remove the previous table/cell appearance
   --------------------------------------------------------- */

.spmu-verification-workspace
.spmu-left-borrowing-grid-clean
.spmu-left-borrowing-cell {
    min-width:0 !important;

    display:block !important;

    margin:0 !important;
    padding:0 !important;

    border:0 !important;
    border-radius:0 !important;

    background:transparent !important;
    box-shadow:none !important;
}


/* ---------------------------------------------------------
   Label
   --------------------------------------------------------- */

.spmu-verification-workspace
.spmu-left-borrowing-grid-clean
.spmu-left-borrowing-cell > span {
    display:block !important;

    margin:0 0 6px !important;
    padding:0 !important;

    color:var(--text-secondary, #526782) !important;

    font-size:11.5px !important;
    font-weight:500 !important;
    line-height:1.35 !important;

    text-transform:none !important;
    letter-spacing:0 !important;
}


/* ---------------------------------------------------------
   Value
   --------------------------------------------------------- */

.spmu-verification-workspace
.spmu-left-borrowing-grid-clean
.spmu-left-borrowing-cell > strong {
    display:block !important;

    width:auto !important;
    max-width:100% !important;

    margin:0 !important;
    padding:0 !important;

    color:var(--heading, #0f2744) !important;

    font-size:13.5px !important;
    font-weight:750 !important;
    line-height:1.45 !important;

    overflow-wrap:anywhere !important;

    border:0 !important;
    background:transparent !important;
}


/* ---------------------------------------------------------
   Header remains separate and clean
   --------------------------------------------------------- */

.spmu-verification-workspace
.spmu-left-borrowing-info
.card-header {
    margin-bottom:0 !important;
}


/* ---------------------------------------------------------
   Responsive
   --------------------------------------------------------- */

@media (max-width:760px) {

    .spmu-verification-workspace
    .spmu-left-borrowing-info
    .spmu-left-borrowing-grid-clean {
        grid-template-columns:1fr !important;

        column-gap:0 !important;
        row-gap:20px !important;

        padding:20px 16px 22px !important;
    }

}

/* ADMIN REQUEST DETAILS CLEAN END */
</style>

@endsection
