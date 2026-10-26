@extends('layouts.app', [
    'title' => $borrowingRequest->exists ? 'Edit Request' : 'Create Request',
    // The form renders its own notices only when a restriction is already shown,
    // so the same restriction is not repeated as a generic validation error.
    'inlinePageNotices' => !empty($borrowingBlockedReasons),
])

@section('content')

@php
    $editing = $borrowingRequest->exists;
    $isExternalBorrower = $isExternalBorrower ?? false;
    $hasCurrentESignature = auth()->user()->currentSignature()->whereHas('file')->exists();

    $selectedItems = $version->exists
        ? $version->items->keyBy('inventory_item_id')
        : collect();

    $oldItemIds = collect(old('item_ids', []))
        ->map(fn ($id) => (int) $id);

    $isReturned = $borrowingRequest->exists
        && $borrowingRequest->status === App\Enums\RequestStatus::ReturnedForRevision;

    $latestReturnForRevision = $isReturned
        ? $version->approvalSteps
            ->where('decision', 'RETURNED_FOR_REVISION')
            ->sortByDesc(
                fn ($step) => ($step->decided_at ?? $step->created_at)?->getTimestamp() ?? 0
            )
            ->first()
        : null;

    $returnRemarks = trim((string) ($latestReturnForRevision?->remarks ?? ''));
    $returnedByRole = $latestReturnForRevision
        ? ((int) $latestReturnForRevision->sequence_no === 1
            ? 'SPMU Action Officer'
            : 'SPMU Head/Admin')
        : null;
    $returnedAt = $latestReturnForRevision?->decided_at
        ?? $latestReturnForRevision?->created_at;

    $supporting = $version->exists
        ? $version->supportingDocuments->where('is_current', true)
        : collect();

    $requestLetter = $supporting->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
    );

    $ptc = $supporting->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
    );

    // Returned evidence remains previewable, but a new correction needs fresh uploads.
    $requestLetterForSubmission = $isReturned ? null : $requestLetter;
    $ptcForSubmission = $isReturned ? null : $ptc;

    $maxUploadMb = max(1, (int) ($maxUploadMb ?? 5));


    $divisionOptions = App\Support\OrganizationalStructure::divisions();

    /*
     * Canonical Office/Academic/Research Unit list comes from the
     * controller (BorrowingRequestController::officeUnitsByDivision()) so
     * this form can never drift from what the backend actually accepts.
     */
    $officeUnitsByDivision = $officeUnitsByDivision ?? [];

    $selectedDivision = old(
        'division_code',
        $version->division_code ?? ($prefillDivisionCode ?? '')
    );
    $selectedOfficeUnit = old(
        'office_unit',
        $version->office_unit ?? ($prefillOfficeUnit ?? '')
    );

    $requestingUnitOptions = $requestingUnitOptions ?? [];
    $selectedRequestingUnitId = old(
        'requesting_organizational_unit_id',
        $prefillRequestingUnitId
            ?? $borrowingRequest->accountable_unit_id
            ?? ''
    );

    $selectedRequestingUnitOption = collect($requestingUnitOptions)
        ->firstWhere('id', (int) $selectedRequestingUnitId);

    if ($selectedRequestingUnitOption) {
        $selectedDivision = $selectedRequestingUnitOption['division_code'];
        $selectedOfficeUnit = $selectedRequestingUnitOption['name'];
    }

    $oldLocations = collect(old('locations', []));
    $requestUsesOffCampus = $oldLocations->contains('OFF_CAMPUS')
        || ($oldLocations->isEmpty() && $selectedItems->contains(
            fn ($requestItem) => $requestItem?->use_location === 'OFF_CAMPUS'
        ));

    /*
     * RESUME WHERE THE BORROWER STOPPED
     * ---------------------------------
     * Continuing a saved draft must reopen the stage the borrower actually
     * stopped on, not replay stage 1. A stage counts as done when the data
     * it collects is already on the version, so the resume point is derived
     * from what was saved instead of being stored separately and drifting
     * from it. Reading through old() means a redisplayed form after a failed
     * validation pass also comes back on the stage being worked on.
     */
    $stageOneValues = [
        old('purpose_event', $version->purpose_event),
        old('location', $version->location),
        ...($isExternalBorrower
            ? [$externalCompanyName]
            : [$selectedRequestingUnitId, $selectedDivision, $selectedOfficeUnit]),
        old('schedule_date', optional($version->schedule_date ?: $version->needed_from)->format('Y-m-d')),
        old('return_date', optional($version->return_date ?: $version->return_due_at)->format('Y-m-d')),
    ];

    $stageOneComplete = collect($stageOneValues)
        ->every(fn ($value) => trim((string) $value) !== '');

    $stageTwoComplete = old('item_ids') !== null
        ? $oldItemIds->isNotEmpty()
        : $selectedItems->isNotEmpty();

    $resumeStage = match (true) {
        ! $stageOneComplete => 1,
        ! $stageTwoComplete => 2,
        default => 3,
    };
@endphp

@if(!empty($borrowingBlockedReasons))
    {{-- Retain genuine validation errors, but display each active restriction only
         once in the dedicated yellow banner below. --}}
    @php
        $otherFormErrors = collect($errors->all())->reject(function ($message) use ($borrowingBlockedReasons) {
            return collect($borrowingBlockedReasons)->contains(function ($reason) use ($message) {
                $reason = trim((string) $reason);
                return $reason !== '' && str_contains(mb_strtolower((string) $message), mb_strtolower($reason));
            });
        });
    @endphp
    @if(session('status'))
        <div class="notice success" role="status"><x-icon name="success" /><div>{{ session('status') }}</div></div>
    @endif
    @if($otherFormErrors->isNotEmpty())
        <div class="notice error" role="alert">
            <x-icon name="error" />
            <div><strong>Please correct the following:</strong><ul>
                @foreach($otherFormErrors as $error)<li>{{ $error }}</li>@endforeach
            </ul></div>
        </div>
    @endif
@endif

<style>
    .returned-for-revision-panel {
        display: block;
    }

    .returned-for-revision-panel .revision-remarks {
        margin-top: 10px;
    }

    .returned-for-revision-panel .revision-remarks-copy {
        margin: 0;
        color: var(--text);
        line-height: 1.55;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .returned-for-revision-panel .revision-return-meta {
        margin: 7px 0 0;
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .create-request-ui {
        --cr-navy: #102a43;
        --cr-blue: #1769e0;
        --cr-ink: #18324a;
        --cr-muted: #68798a;
        --cr-line: #d9e2ec;
        --cr-soft: #f6f8fb;
        --cr-soft-blue: #f4f8ff;
        --cr-gold: #c99a2e;
        --cr-danger: #a62f2f;
        --cr-success: #237a45;
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 16px;
        width: 100%;
    }

    .create-request-ui > * { min-width: 0; }

    .create-request-heading {
        align-items: flex-start;
        margin-bottom: 18px;
    }

    .create-request-heading h1 {
        margin-bottom: 6px;
    }

    .create-request-heading .heading-copy {
        max-width: 820px;
        color: var(--text-muted);
        line-height: 1.55;
    }

    .create-request-ui .request-card {
        overflow: hidden;
        border: 1px solid var(--cr-line);
        border-radius: 14px;
        background: var(--surface, #fff);
        box-shadow: 0 6px 18px rgba(16, 42, 67, .04);
    }

    .create-request-ui .request-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 17px 20px 14px;
        border-bottom: 1px solid var(--cr-line);
        background: linear-gradient(180deg, #fff 0%, #fbfcfe 100%);
    }

    .create-request-ui .request-card-header .eyebrow {
        margin-bottom: 4px;
    }

    .create-request-ui .request-card-header h2 {
        margin: 0;
        color: var(--cr-ink);
        font-size: 1rem;
    }

    .create-request-ui .request-card-header .meta {
        margin: 5px 0 0;
        color: var(--cr-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .create-request-ui .request-card-body {
        padding: 20px;
    }

    .create-request-ui .field-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 15px;
    }

    .create-request-ui .full-span {
        grid-column: 1 / -1;
    }

    .create-request-ui label > input,
    .create-request-ui label > select,
    .create-request-ui label > textarea {
        margin-top: 7px;
    }

    .create-request-ui textarea {
        min-height: 112px;
        resize: vertical;
    }

    .student-activity-panel {
        padding: 15px;
        border: 1px solid var(--cr-line);
        border-radius: 12px;
        background: var(--cr-soft);
    }

    .student-activity-panel .checkbox {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 10px;
        width: 100%;
        margin: 0;
        text-align: left;
    }

    .student-activity-panel .checkbox input[type="checkbox"] {
        flex: 0 0 auto;
        width: 18px;
        height: 18px;
        margin: 0;
    }


    .inventory-date-context {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 30px;
        padding: 5px 10px;
        border: 1px solid #dce7f8;
        border-radius: 999px;
        background: var(--cr-soft-blue);
        color: #31547b;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }

    .inventory-search-shell {
        position: relative;
        margin-bottom: 16px;
    }

    .inventory-search-label {
        display: block;
        min-width: 0;
    }

    .catalog-shell {
        position: absolute;
        z-index: 30;
        top: calc(100% + 8px);
        left: 0;
        right: 0;
        max-height: 430px;
        overflow: auto;
        border: 1px solid var(--cr-line);
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 16px 38px rgba(16, 42, 67, .16);
    }

    .catalog-shell[hidden] {
        display: none !important;
    }

    .catalog-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 10px 14px;
        border-bottom: 1px solid var(--cr-line);
        background: #fbfcfe;
        color: var(--cr-muted);
        font-size: 12px;
    }

    .catalog-list {
        display: grid;
    }

    .catalog-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 16px;
        min-height: 76px;
        padding: 13px 14px;
        border-bottom: 1px solid var(--cr-line);
    }

    .catalog-item:last-child {
        border-bottom: 0;
    }

    .catalog-item[hidden] {
        display: none !important;
    }

    .catalog-item-main {
        min-width: 0;
    }

    .catalog-item-title {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .catalog-item-title strong {
        color: var(--cr-ink);
        font-size: 14px;
    }

    .catalog-item-meta {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        margin-top: 4px;
        color: var(--cr-muted);
        font-size: 11px;
    }

    .catalog-item-description {
        margin: 5px 0 0;
        color: var(--cr-muted);
        font-size: 12px;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }

    .catalog-item-side {
        display: grid;
        justify-items: end;
        gap: 7px;
        min-width: 138px;
    }

    .catalog-availability {
        color: var(--cr-muted);
        font-size: 11px;
        font-weight: 700;
        text-align: right;
    }

    .catalog-availability.is-available {
        color: var(--cr-success);
    }

    .catalog-availability.is-unavailable {
        color: var(--cr-danger);
    }

    .catalog-add-button {
        min-width: 92px;
        justify-content: center;
    }

    .catalog-add-button.is-added {
        border-color: #cfe0d6;
        background: #f0f8f3;
        color: var(--cr-success);
    }


    .catalog-empty {
        padding: 28px 18px;
        text-align: center;
        color: var(--cr-muted);
    }

    .selected-items-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: 20px;
        margin-bottom: 10px;
    }

    .selected-items-header h3 {
        margin: 0;
        color: var(--cr-ink);
        font-size: 14px;
    }

    .selected-count {
        display: inline-flex;
        align-items: center;
        min-height: 29px;
        padding: 5px 9px;
        border: 1px solid var(--cr-line);
        border-radius: 999px;
        color: var(--cr-muted);
        font-size: 11px;
        font-weight: 800;
    }

    .selected-items-table table {
        min-width: 860px;
    }

    .selected-items-table thead th {
        background: var(--cr-navy);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
    }

    .selected-item-row[hidden] {
        display: none !important;
    }

    .selected-item-name {
        display: grid;
        gap: 3px;
    }

    .selected-item-name strong {
        color: var(--cr-ink);
        font-size: 13px;
    }

    .selected-item-name small {
        color: var(--cr-muted);
        font-size: 11px;
    }

    .selected-availability {
        display: grid;
        gap: 2px;
    }

    .selected-availability strong {
        color: var(--cr-ink);
    }

    .selected-availability small {
        color: var(--cr-muted);
        font-size: 11px;
    }

    .selected-availability small.is-error {
        color: var(--cr-danger);
        font-weight: 700;
    }

    .selected-quantity {
        min-width: 88px;
        max-width: 108px;
    }

    .request-premises-panel {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 18px;
        padding: 14px 16px;
        border: 1px solid #d8e3ee;
        border-radius: 12px;
        background: #f8fafc;
    }

    .request-premises-copy {
        display: grid;
        gap: 3px;
        color: var(--cr-muted);
        font-size: 11px;
        line-height: 1.45;
    }

    .request-premises-copy strong {
        color: var(--cr-ink);
        font-size: 12px;
    }

    .request-premises-toggle {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        min-height: 38px;
        margin: 0;
        padding: 8px 11px;
        border: 1px solid #cbd8e5;
        border-radius: 10px;
        background: #fff;
        color: var(--cr-ink);
        font-weight: 800;
        white-space: nowrap;
        cursor: pointer;
    }

    .request-premises-toggle input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin: 0;
    }

    @media (max-width: 760px) {
        .request-premises-panel {
            flex-direction: column;
        }
    }

    .selected-empty {
        padding: 22px 18px;
        border: 1px dashed #cdd7e2;
        border-radius: 12px;
        background: #fbfcfe;
        color: var(--cr-muted);
        text-align: center;
        font-size: 12px;
    }

    .selected-empty[hidden] {
        display: none !important;
    }

    .picker-warning {
        margin-top: 12px;
    }

    .item-code-badge {
        display: inline-flex;
        align-items: center;
        min-height: 22px;
        padding: 2px 7px;
        border: 1px solid #cfdae6;
        border-radius: 7px;
        background: #f7f9fc;
        color: #274560;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .025em;
        white-space: nowrap;
    }

    .review-summary {
        margin-bottom: 18px;
        padding: 17px;
        border: 1px solid var(--cr-line);
        border-radius: 14px;
        background: #fbfcfe;
    }

    .document-current {
        margin: 0 0 10px;
        color: var(--cr-muted);
        font-size: 12px;
    }

    .sticky-actions {
        position: sticky;
        bottom: 12px;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 12px;
        border: 1px solid var(--cr-line);
        border-radius: 14px;
        background: rgba(255, 255, 255, .97);
        box-shadow: 0 10px 28px rgba(16, 42, 67, .10);
        backdrop-filter: blur(8px);
    }

    .sticky-actions .meta {
        margin: 0;
        color: var(--cr-muted);
        font-size: 11px;
        line-height: 1.4;
    }

    .sticky-actions .actions {
        display: flex;
        gap: 9px;
        flex: 0 0 auto;
    }

    #request-submit-button:disabled {
        cursor: not-allowed;
        opacity: .55;
    }

    .request-stepper {
        display: grid;
        grid-template-columns: auto minmax(56px, 1fr) auto minmax(56px, 1fr) auto;
        align-items: center;
        gap: 14px;
        padding: 19px 22px;
        border-top: 1px solid var(--cr-line);
        border-bottom: 1px solid var(--cr-line);
        background: #f8fafc;
    }

    .request-step {
        appearance: none;
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 0;
        border: 0;
        background: transparent;
        color: #8a99aa;
        font: inherit;
        font-size: 13px;
        font-weight: 800;
        white-space: nowrap;
        cursor: pointer;
    }

    .request-step:disabled {
        cursor: default;
    }

    .request-step-number {
        display: grid;
        place-items: center;
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        border-radius: 50%;
        background: #e5edf4;
        color: #8595a6;
        font-size: 13px;
        font-weight: 800;
    }

    .request-step.is-active {
        color: #1769e0;
    }

    .request-step.is-active .request-step-number,
    .request-step.is-complete .request-step-number {
        background: #1769e0;
        color: #fff;
    }

    .request-step.is-complete {
        color: #496982;
    }

    .request-step-line {
        height: 1px;
        background: #d4dee8;
    }

    [data-stage-panel][hidden] {
        display: none !important;
    }

    .stage-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 9px;
        padding: 12px;
        border: 1px solid var(--cr-line);
        border-radius: 14px;
        background: #fff;
    }

    .field-help {
        display: block;
        margin-top: 6px;
        color: var(--cr-muted);
        font-size: 11px;
        line-height: 1.4;
    }

    @media (max-width: 800px) {
        .catalog-item {
            align-items: start;
        }
    }

    @media (max-width: 700px) {
        .request-stepper {
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .request-step-line {
            display: none;
        }

        .stage-actions {
            flex-direction: column-reverse;
            align-items: stretch;
        }

        .stage-actions .button {
            width: 100%;
            justify-content: center;
        }

        .create-request-ui .field-grid,
        .student-fields,
        .review-summary-grid {
            grid-template-columns: 1fr;
        }

        .create-request-ui .full-span {
            grid-column: auto;
        }

        .create-request-ui .request-card-header,
        .create-request-ui .request-card-body {
            padding-left: 15px;
            padding-right: 15px;
        }

        .catalog-shell {
            position: static;
            max-height: 360px;
            margin-top: 8px;
            box-shadow: none;
        }

        .catalog-item {
            grid-template-columns: 1fr;
        }

        .catalog-item-side {
            grid-template-columns: 1fr auto;
            align-items: center;
            justify-items: stretch;
            min-width: 0;
        }

        .catalog-availability {
            text-align: left;
        }


        .sticky-actions {
            position: static;
            align-items: stretch;
            flex-direction: column;
        }

        .sticky-actions .actions {
            flex-direction: column;
        }

        .sticky-actions .button {
            width: 100%;
            justify-content: center;
        }
    }


    /* Final Stage 3 confirmation */
    .final-confirmation {
        display: flex;
        align-items: flex-start;
        justify-content: flex-start;
        gap: 10px;
        margin-top: 20px;
        padding: 16px 18px;
        border: 1px solid var(--cr-line);
        border-radius: 12px;
        background: #f8fafc;
        text-align: left;
        font-weight: 700;
    }

    .final-confirmation input[type="checkbox"] {
        flex: 0 0 18px;
        width: 18px;
        height: 18px;
        margin: 2px 0 0;
    }

    .final-confirmation > span {
        display: grid;
        gap: 5px;
    }

    .final-confirmation > span > strong {
        color: var(--cr-ink);
        font-size: 12px;
    }

    .final-confirmation > span > small {
        color: var(--cr-muted);
        font-size: 11px;
        font-weight: 500;
        line-height: 1.55;
    }


    /* Create Request final action must always receive pointer input when enabled. */
    #request-submit-button:not(:disabled) {
        pointer-events: auto !important;
        cursor: pointer;
        position: relative;
        z-index: 2;
    }
</style>

@include('requests.partials.create-styles')
@include('requests.partials.create-review-styles')
@include('requests.partials.create-validation-styles')

{{-- Scoped presentation refinements; no request, validation, or stage behavior changes. --}}
<style>
    .create-request-heading { margin-bottom: 12px; }
    .create-request-heading h1 { margin-bottom: 3px; font-size: clamp(21px, 1.65vw, 25px); line-height: 1.2; }
    .create-request-heading .heading-copy { font-size: 12.5px; line-height: 1.4; }
    /* Match the existing 1120px request-form shell so the restriction notice,
       stepper, and form cards share the same left/right edges. */
    .create-request-restriction {
        width: 100%;
        max-width: 1120px;
        margin-bottom: 16px;
    }
    .create-request-restriction .request-restriction-alert {
        width: 100%;
        box-sizing: border-box;
    }
    .create-request-restriction .request-restriction-alert {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        align-items: center;
        gap: 10px 22px;
        padding: 14px 18px;
        border-radius: 10px;
    }
    .request-restriction-alert .eyebrow { margin: 0 0 3px; font-size: 10px; letter-spacing: .045em; }
    .request-restriction-alert h2 { margin: 0 0 3px; font-size: 15px; line-height: 1.4; }
    .request-restriction-alert p:not(.eyebrow) { margin: 0; font-size: 12px; line-height: 1.45; }
    .request-restriction-alert .restriction-reasons {
        display: grid; gap: 3px; min-width: 0;
        padding: 0 0 0 18px; margin: 0;
        border-left: 1px solid rgba(122, 82, 15, .20);
        font-size: 12px; line-height: 1.45;
        overflow-wrap: anywhere;
    }
    .request-restriction-alert .restriction-reasons p { margin: 0; }
    .request-restriction-alert .restriction-obligations-link { color: #075ac2; font-weight: 600; text-decoration: none; }
    .request-restriction-alert .restriction-obligations-link:hover,
    .request-restriction-alert .restriction-obligations-link:focus-visible { color: #06479a; text-decoration: underline; }
    .request-restriction-alert .restriction-obligations-link:focus-visible { outline: 2px solid #075ac2; outline-offset: 2px; border-radius: 2px; }

    /* Same universal interactive surface as the other borrower forms.
       Only ENABLED controls respond to hover; disabled stages stay locked
       until the existing client-side validation enables them. */
    #request-form .create-request-ui .stage-actions :is(a.button, button.button):not(:disabled):is(:hover, :focus-visible),
    #request-form .create-request-ui .request-card :is(a.button, button.button):not(:disabled):is(:hover, :focus-visible) {
        background: #065df0;
        border-color: #065df0;
        color: #fff;
        text-decoration: none;
        cursor: pointer;
    }
    /* Match the existing Borrowing Schedule heading; retain its blue exactly. */
    #request-form .create-request-ui .request-details-card .request-card-header #request-details-heading {
        color: #065df0;
        font-size: 12px;
        font-weight: 750;
        letter-spacing: 0;
        line-height: 1.4;
        text-transform: uppercase;
    }

    /* Documents & Review uses request-review-actions (not stage-actions).
       Keep default outlined buttons; apply the universal blue on enabled hover/focus. */
    #request-form .create-request-ui .request-review-actions :is(a.button, button.button):not(:disabled):is(:hover, :focus-visible) {
        background-color: #065df0;
        border-color: #065df0;
        color: #fff;
        text-decoration: none;
        cursor: pointer;
    }
    #request-form .create-request-ui .request-review-actions :is(a.button, button.button):not(:disabled):is(:hover, :focus-visible) svg {
        color: inherit;
        stroke: currentColor;
    }
    #request-form .create-request-ui .request-review-actions :is(a.button, button.button):not(:disabled):focus-visible {
        outline: 2px solid #065df0;
        outline-offset: 3px;
    }
    #request-form .create-request-ui .stage-actions :is(a.button, button.button):not(:disabled):focus-visible,
    #request-form .create-request-ui .request-card :is(a.button, button.button):not(:disabled):focus-visible {
        outline: 2px solid #065df0;
        outline-offset: 3px;
    }
    #request-form .create-request-ui .stage-actions button.button:disabled {
        cursor: not-allowed;
        opacity: .58;
    }
    #request-form .create-request-ui .request-step:not(:disabled):is(:hover, :focus-visible) {
        color: #065df0;
        cursor: pointer;
    }
    #request-form .create-request-ui .request-step:not(:disabled):is(:hover, :focus-visible) .request-step-number {
        background: #065df0;
        color: #fff;
    }
    #request-form .create-request-ui .request-step:not(:disabled):focus-visible {
        outline: 2px solid #065df0; outline-offset: 4px;
        border-radius: 4px;
    }
    .request-form-shell { margin-top: 0; }
    #request-form .create-request-ui { gap: 14px; }
    #request-form .create-request-ui .request-stepper {
        gap: 12px;
        padding: 11px 16px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--surface-elevated);
    }
    #request-form .create-request-ui .request-step { font-size: 12px; font-weight: 700; }
    #request-form .create-request-ui .request-step-number {
        width: 28px; height: 28px; flex-basis: 28px; font-size: 12px;
    }
    #request-form .create-request-ui .request-details-card .request-card-header,
    #request-form .create-request-ui .request-picker-card .request-card-header { padding: 15px 20px 12px; }
    #request-form .create-request-ui .request-details-card .request-information-fields { padding: 0 20px 18px; }
    #request-form .create-request-ui .request-details-card .field-grid { gap: 15px 22px; }
    #request-form .create-request-ui .request-details-card .field-grid > label {
        font-size: 13px; font-weight: 650; line-height: 1.4;
    }
    #request-form .create-request-ui .request-details-card .field-grid input,
    #request-form .create-request-ui .request-details-card .field-grid select,
    #request-form .create-request-ui .request-details-card .field-grid .request-static-field {
        height: 43px; min-height: 43px; margin-top: 6px; padding: 9px 12px;
        font-size: 13px;
    }
    #request-form .create-request-ui .request-schedule-fields { padding: 17px 20px 19px; }
    #request-form .create-request-ui .request-schedule-heading { gap: 4px; margin-bottom: 12px; }
    #request-form .create-request-ui .request-schedule-note { font-size: 12px; line-height: 1.45; }
    #request-form .create-request-ui .request-details-card .student-activity-panel { padding: 15px 20px; }
    #request-form .create-request-ui .student-activity-panel .checkbox { gap: 11px; }
    #request-form .create-request-ui .student-activity-panel .checkbox input[type="checkbox"] {
        width: 18px; height: 18px; min-height: 18px;
    }
    #request-form .create-request-ui .request-details-actions { padding: 13px 20px; }
    @media (max-width: 760px) {
        .create-request-restriction .request-restriction-alert { grid-template-columns: 1fr; gap: 8px; }
        .request-restriction-alert .restriction-reasons { padding: 8px 0 0; border-left: 0; border-top: 1px solid rgba(122, 82, 15, .20); }
        #request-form .create-request-ui .request-stepper { gap: 8px; padding: 10px 12px; }
        #request-form .create-request-ui .request-details-card .field-grid { gap: 13px; }
    }
</style>

<section class="page-heading create-request-heading">
    <div data-esign-modal-note>
        <p class="eyebrow">Borrowing request</p>

        <h1>
            {{ $isReturned
                ? 'Revise request'
                : ($editing ? 'Edit request' : 'Create request')
            }}
        </h1>

        <p class="heading-copy">
            Choose the event details, borrowing dates, and items you need.
        </p>
    </div>
</section>

@if(!empty($borrowingBlockedReasons))
<section class="content-area create-request-restriction">
    <div class="action-panel action-warning request-restriction-alert" role="alert">
        <div>
            <p class="eyebrow">Borrowing restricted</p>
            <h2>{{ request()->routeIs('requests.edit')
    ? 'You cannot resubmit this borrowing request right now'
    : 'You cannot submit a new borrowing request right now' }}</h2>
            <p><a class="restriction-obligations-link" href="{{ route('accountability.index') }}">View My Obligations <span aria-hidden="true">→</span></a></p>
        </div>

        <div class="restriction-reasons" aria-label="Active restriction details">
            @foreach($borrowingBlockedReasons as $reason)
                <p>{{ $reason }}</p>
            @endforeach
        </div>
    </div>
</section>
@endif

@if($isReturned)
<section class="content-area">
    <div class="action-panel action-warning returned-for-revision-panel" role="status" aria-labelledby="returned-for-revision-heading">
        <div>
            <p class="eyebrow">Action required</p>
            <h2 id="returned-for-revision-heading">Returned for Revision</h2>
            <p>Please update the request based on the review remarks below.</p>
        </div>

        <section class="revision-remarks" aria-labelledby="review-remarks-heading">
            <p class="eyebrow" id="review-remarks-heading">Review remarks</p>
            <p class="revision-remarks-copy">{{ $returnRemarks !== ''
                ? $returnRemarks
                : 'No review remarks were recorded. Please contact SPMU for clarification.' }}</p>
        </section>

        @if($returnedByRole && $returnedAt)
            <p class="revision-return-meta">Returned by: {{ $returnedByRole }} · <time datetime="{{ $returnedAt->toAtomString() }}">{{ $returnedAt->timezone(config('app.timezone'))->format('d M Y, g:i A') }}</time></p>
        @endif
    </div>
</section>
@endif

<section class="content-area request-form-shell">
<form
    method="post"
    enctype="multipart/form-data"
    action="{{ $editing
        ? route('requests.update', $borrowingRequest)
        : route('requests.store')
    }}"
    id="request-form"
>
    @csrf

    @if($editing)
        @method('PUT')
    @endif

    <div class="create-request-ui">

        <nav class="request-stepper" aria-label="Create request progress">
            <button type="button" class="request-step is-active" data-stage-target="1" aria-current="step">
                <span class="request-step-number">1</span>
                <span>Request details</span>
            </button>

            <span class="request-step-line" aria-hidden="true"></span>

            <button type="button" class="request-step" data-stage-target="2" disabled>
                <span class="request-step-number">2</span>
                <span>Select items</span>
            </button>

            <span class="request-step-line" aria-hidden="true"></span>

            <button type="button" class="request-step" data-stage-target="3" disabled>
                <span class="request-step-number">3</span>
                <span>Documents &amp; Review</span>
            </button>
        </nav>


        @include('requests.partials.create-details')

        <section class="request-card request-picker-card" data-stage-panel="2" aria-labelledby="item-picker-heading">
            <div class="request-card-header">
                <div>
                    <p class="eyebrow">Select items</p>
                    <h2 id="item-picker-heading">Select inventory items</h2>
                    <p class="meta" id="availability-message">
                        Select Schedule Date and Expected Return Date to load availability.
                    </p>
                </div>
            </div>

            <div class="request-card-body">
                @error('item_ids')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                @error('items')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                @error('quantities')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                @error('locations')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                @include('requests.partials.create-premises')

                <div class="inventory-search-shell">
                    <label for="inventory-search" class="inventory-search-label">
                        Search inventory
                        <span class="search-input-shell">
                            <span class="search-input-icon" aria-hidden="true"><x-icon name="search" /></span>
                            <input
                                id="inventory-search"
                                type="search"
                                placeholder="Start typing an item name, category, description, or unit..."
                                autocomplete="off"
                                aria-autocomplete="list"
                                aria-controls="catalog-list"
                            >
                        </span>
                    </label>

                    <div class="catalog-shell" id="catalog-shell" hidden>
                        <div class="catalog-summary">
                            <span id="catalog-result-label">Recommended matches</span>
                        </div>

                    <div id="catalog-list" class="catalog-list">
                        @foreach($items as $item)
                            @php
                                $categoryName = $item->category?->category_name ?: 'Uncategorized';
                                $itemUiId = 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT);

                                $searchText = strtolower(
                                    $item->unique_description.' '.
                                    ($item->specification ?? '').' '.
                                    $categoryName.' '.
                                    ($item->unit?->unit_name ?? '')
                                );

                                $requestItem = $selectedItems->get($item->id);

                                $requestedQuantity = old(
                                    'quantities.'.$item->id,
                                    $requestItem?->requested_quantity ?? 0
                                );

                                $wasSelected = $oldItemIds->isNotEmpty()
                                    ? $oldItemIds->contains((int) $item->id)
                                    : ((float) $requestedQuantity > 0);

                                $selectedLocation = old(
                                    'locations.'.$item->id,
                                    $requestItem?->use_location ?? 'ON_CAMPUS'
                                );
                            @endphp

                            <article
                                class="catalog-item"
                                data-catalog-item
                                data-item-id="{{ $item->id }}"
                                data-search="{{ $searchText }}"
                                data-category="{{ strtolower($categoryName) }}"
                                data-off-campus-allowed="{{ $item->off_campus_allowed ? '1' : '0' }}"
                            >
                                <div class="catalog-item-main">
                                    <div class="catalog-item-title">
                                        <strong>{{ $item->unique_description }}</strong>

                                        @if($item->laundry_required)
                                            <span class="badge">Laundry required</span>
                                        @endif
                                    </div>

                                    <div class="catalog-item-meta">
                                        <span>{{ $categoryName }}</span>
                                        <span>&middot;</span>
                                        <span>{{ $item->unit?->unit_name }}</span>
                                    </div>

                                    @if($item->specification)
                                        <p class="catalog-item-description">
                                            {{ $item->specification }}
                                        </p>
                                    @endif
                                </div>

                                <div class="catalog-item-side">
                                    <span
                                        class="catalog-availability"
                                        data-catalog-availability="{{ $item->id }}"
                                    >
                                        Select dates
                                    </span>

                                    <button
                                        type="button"
                                        class="button secondary small ui-pressable catalog-add-button"
                                        data-add-item="{{ $item->id }}"
                                        @disabled(!$wasSelected)
                                    >
                                        {{ $wasSelected ? 'Added' : '+ Add' }}
                                    </button>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div
                        id="catalog-empty"
                        class="catalog-empty"
                        hidden
                    >
                        No matching eligible item found. Try another item name or keyword.
                    </div>

                    </div>
                </div>

                <div class="selected-items-header">
                    <div>
                        <h3>Selected Items</h3>
                    </div>

                    <span class="selected-count">
                        <span id="selected-item-count">0</span>&nbsp;added
                    </span>
                </div>

                <div
                    id="selected-empty"
                    class="selected-empty"
                >
                    No items selected yet.
                </div>

                <div class="table-wrap selected-items-table" @if(!$stageTwoComplete) hidden @endif>
                    <table id="selected-items-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Available</th>
                                <th>Quantity</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach($items as $item)
                                @php
                                    $categoryName = $item->category?->category_name ?: 'Uncategorized';
                                    $itemUiId = 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT);
                                    $requestItem = $selectedItems->get($item->id);

                                    $requestedQuantity = old(
                                        'quantities.'.$item->id,
                                        $requestItem?->requested_quantity ?? 0
                                    );

                                    $wasSelected = $oldItemIds->isNotEmpty()
                                        ? $oldItemIds->contains((int) $item->id)
                                        : ((float) $requestedQuantity > 0);

                                    $selectedLocation = old(
                                        'locations.'.$item->id,
                                        $requestItem?->use_location ?? 'ON_CAMPUS'
                                    );
                                @endphp

                                <tr
                                    class="selected-item-row"
                                    data-selected-item="{{ $item->id }}"
                                    data-item-code="{{ $itemUiId }}"
                                    data-item-name="{{ $item->unique_description }}"
                                    data-item-category="{{ $categoryName }}"
                                    data-item-unit="{{ $item->unit?->unit_name ?? '—' }}"
                                    data-off-campus-allowed="{{ $item->off_campus_allowed ? '1' : '0' }}"
                                    @if(!$wasSelected) hidden @endif
                                >
                                    <td data-label="Item">
                                        <input
                                            type="checkbox"
                                            name="item_ids[]"
                                            value="{{ $item->id }}"
                                            data-selected-checkbox="{{ $item->id }}"
                                            @checked($wasSelected)
                                            hidden
                                        >

                                        <div class="selected-item-name">
                                            <div>
                                                <span class="item-code-badge">{{ $itemUiId }}</span>
                                                <strong>{{ $item->unique_description }}</strong>
                                            </div>
                                            <small>
                                                {{ $categoryName }}
                                                &middot;
                                                {{ $item->unit?->unit_name }}
                                            </small>
                                        </div>
                                    </td>

                                    <td data-label="Available">
                                        <div class="selected-availability">
                                            <strong
                                                data-selected-availability="{{ $item->id }}"
                                            >
                                                -
                                            </strong>

                                            <small
                                                data-selected-availability-note="{{ $item->id }}"
                                            >
                                                Select dates
                                            </small>
                                        </div>
                                    </td>

                                    <td data-label="Quantity">
                                        <input
                                            class="selected-quantity"
                                            type="number"
                                            min="0"
                                            step="1"
                                            inputmode="numeric"
                                            name="quantities[{{ $item->id }}]"
                                            value="{{ $wasSelected ? max(1, (int) round((float) $requestedQuantity)) : 0 }}"
                                            data-selected-quantity="{{ $item->id }}"
                                            aria-label="Requested quantity for {{ $item->unique_description }}"
                                        >
                                    </td>
                                    @php
                                        $normalizedSelectedLocation = $item->off_campus_allowed
                                            && $selectedLocation === 'OFF_CAMPUS'
                                                ? 'OFF_CAMPUS'
                                                : 'ON_CAMPUS';
                                    @endphp

                                    <td data-label="Action">
                                        <input
                                            type="hidden"
                                            name="locations[{{ $item->id }}]"
                                            value="{{ $normalizedSelectedLocation }}"
                                            data-selected-location="{{ $item->id }}"
                                        >

                                        <button
                                            type="button"
                                            class="button secondary small ui-pressable request-remove-button"
                                            data-remove-item="{{ $item->id }}"
                                        >
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div
                    id="campus-mode-conflict"
                    class="callout danger picker-warning"
                    hidden
                >
                    <strong>Review the Off-campus selection.</strong>
                    <p>Off-campus borrowing allows one Barricade item only. Remove incompatible selected items or switch back to On-campus.</p>
                </div>

                <div
                    id="availability-conflict"
                    class="callout danger picker-warning"
                    hidden
                >
                    <strong>Review the selected item quantities.</strong>
                    <p>
                        One or more requested quantities exceed the availability for the
                        currently selected dates. Reduce the quantity or remove the item
                        before saving.
                    </p>
                </div>
            </div>
        </section>

        <div class="stage-actions request-picker-actions" data-stage-panel="2">
            <button type="button" class="button secondary ui-pressable" data-stage-back="1">Back</button>
            <button type="button" class="button primary ui-pressable" data-stage-next="3" disabled>
                Confirm Items &amp; Continue
                <svg class="ui-icon request-continue-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6" /></svg>
            </button>
        </div>

        <section class="request-card review-card" data-stage-panel="3" aria-labelledby="review-summary-heading">
            <p class="request-section-label">Review Summary</p>
            <p class="request-section-copy" id="review-summary-heading">Please review the details of your request before submitting.</p>

            <hr class="request-section-rule">

            <div class="review-summary-grid">
                <div class="review-summary-field">
                    <span>Event</span>
                    <strong id="summary-purpose">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>Event Location</span>
                    <strong id="summary-location">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>{{ $isExternalBorrower ? 'Borrower type' : 'Division' }}</span>
                    <strong id="summary-division">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>{{ $isExternalBorrower ? 'Company / Agency / Institution' : 'Office / Academic Unit / Research Unit' }}</span>
                    <strong id="summary-office">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>Items Needed From</span>
                    <strong id="summary-from">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>Expected Return Date</span>
                    <strong id="summary-return">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>Premises</span>
                    <strong id="summary-premises" class="review-summary-pill is-positive">On-campus</strong>
                </div>
                <div class="review-summary-field">
                    <span>Student Activity</span>
                    <strong id="summary-student-activity" class="review-summary-pill is-neutral">No</strong>
                </div>
            </div>

            <hr class="request-section-rule">

            <div class="review-items-header">
                <h3>Selected Items</h3>
                <span class="review-items-count" id="summary-items-count">0 items</span>
            </div>

            <div class="table-wrap review-items-table">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col">Category</th>
                            <th scope="col">Unit</th>
                            <th scope="col">Quantity</th>
                        </tr>
                    </thead>
                    <tbody id="summary-items-body">
                        <tr>
                            <td colspan="4" class="review-items-empty">No selected items.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="request-card documents-card" data-stage-panel="3" aria-labelledby="documents-heading">
            <p class="request-section-label">Required Documents</p>
            <p class="request-section-copy" id="documents-heading">Upload the fully signed documents required for this request.</p>

            <div class="document-rows">
                <div class="document-row">
                    <div class="document-row-info">
                        <strong>
                            Borrowing Request Letter
                            <span class="document-required">Required</span>
                        </strong>

                        <p>{{ $isReturned ? 'Upload the corrected signed borrowing request letter for the new version.' : 'Upload the signed borrowing request letter.' }}</p>

                        @if($requestLetter)
                            <p class="document-current">
                                {{ $isReturned ? 'Previous version:' : 'Current file:' }}
                                <a href="{{ route('files.preview', $requestLetter->file) }}">
                                    Preview
                                </a>
                            </p>
                        @endif

                        @error('approved_request_letter')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </div>

                    <label class="document-dropzone" data-dropzone>
                        <input
                            type="file"
                            name="approved_request_letter"
                            accept="application/pdf,image/png,image/jpeg,image/webp"
                        >
                        <span class="visually-hidden">Fully signed Borrowing Request Letter</span>
                        <span class="document-dropzone-icon" aria-hidden="true">
                            <svg class="ui-icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 18a4.5 4.5 0 0 1-.6-8.96 6 6 0 0 1 11.7-1.36A4.25 4.25 0 0 1 18 18h-1.5" /><path d="M12 12v8M9 15l3-3 3 3" /></svg>
                            <strong>Upload file</strong>
                        </span>
                        <span class="document-dropzone-hint">or drag and drop</span>
                        <small class="document-dropzone-formats">PDF, JPG, PNG (Max {{ $maxUploadMb }}MB)</small>
                        <span class="document-dropzone-file" data-dropzone-file hidden></span>
                    </label>
                </div>

                <div class="document-row" id="ptc-document-box">
                    <div class="document-row-info">
                        <strong>
                            Permission to Conduct Letter
                            <span class="document-required">Required</span>
                        </strong>

                        <p>{{ $isReturned ? 'Upload the corrected signed Permission to Conduct Letter for the new version.' : 'Upload the signed Permission to Conduct Letter.' }}</p>

                        @if($ptc)
                            <p class="document-current">
                                {{ $isReturned ? 'Previous version:' : 'Current file:' }}
                                <a href="{{ route('files.preview', $ptc->file) }}">
                                    Preview
                                </a>
                            </p>
                        @endif

                        @error('permission_to_conduct_letter')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </div>

                    <label class="document-dropzone" data-dropzone>
                        <input
                            type="file"
                            name="permission_to_conduct_letter"
                            accept="application/pdf,image/png,image/jpeg,image/webp"
                        >
                        <span class="visually-hidden">Permission to Conduct Letter</span>
                        <span class="document-dropzone-icon" aria-hidden="true">
                            <svg class="ui-icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 18a4.5 4.5 0 0 1-.6-8.96 6 6 0 0 1 11.7-1.36A4.25 4.25 0 0 1 18 18h-1.5" /><path d="M12 12v8M9 15l3-3 3 3" /></svg>
                            <strong>Upload file</strong>
                        </span>
                        <span class="document-dropzone-hint">or drag and drop</span>
                        <small class="document-dropzone-formats">PDF, JPG, PNG (Max {{ $maxUploadMb }}MB)</small>
                        <span class="document-dropzone-file" data-dropzone-file hidden></span>
                    </label>
                </div>
            </div>
        </section>

        <section class="request-card confirmation-card" data-stage-panel="3" aria-labelledby="acknowledgement-heading">
            <p class="request-section-label" id="acknowledgement-heading">Acknowledgement</p>

            <div class="final-confirmation acknowledgement-confirmation">
                <input type="hidden" name="borrower_acknowledgement" value="0">
                <input
                    id="final-confirmation"
                    type="checkbox"
                    name="borrower_acknowledgement"
                    value="1"
                    @checked(old('borrower_acknowledgement'))
                    @disabled(!$hasCurrentESignature)
                    required
                >
                <input
                    id="e-signature-confirmation"
                    type="hidden"
                    name="confirm_e_signature"
                    value="{{ old('borrower_acknowledgement') ? '1' : '0' }}"
                >
                <div class="acknowledgement-copy">
                    <label for="final-confirmation">
                        <strong>
                            I hereby certify that the information, supporting documents, selected items, quantities, premises, and borrowing period provided in this request are true and correct. I authorize the use of my registered E-signature for this submission and acknowledge that I have read and agree to comply with the applicable SPMU
                            <a href="#request-terms-dialog" class="request-terms-link" data-open-request-terms>Terms and Conditions</a>.
                        </strong>
                    </label>
                </div>
            </div>

            @error('borrower_acknowledgement')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <p class="field-error" id="final-confirmation-error" hidden>
                Read and accept the acknowledgement and Terms and Conditions before submitting to SPMU.
            </p>

            @error('confirm_e_signature')<p class="field-error">{{ $message }}</p>@enderror
            @error('signature')<p class="field-error">{{ $message }}</p>@enderror

            @unless($hasCurrentESignature)
                <div class="esignature-notice" role="status">
                    <x-icon name="information" size="18" />
                    <div>
                        <strong>E-signature is required before submission.</strong>
                        <p>Register your E-signature in <a href="{{ route('profile.show') }}">Account Settings</a>.</p>
                    </div>
                </div>
            @endunless
        </section>

        <div class="sticky-actions request-review-actions" data-stage-panel="3">
            <button type="button" class="button secondary ui-pressable" data-stage-back="2">
                Back
            </button>

            <div class="actions">
                <button
                    id="request-save-draft-button"
                    class="button secondary ui-pressable"
                    type="submit"
                    name="intent"
                    value="draft"
                >
                    {{ $editing ? 'Save Draft Changes' : 'Save Draft' }}
                </button>

                <button
                    id="request-submit-button"
                    class="button primary ui-pressable request-submit-button"
                    type="button">
                    <x-icon name="requests" size="17" />
                    E-sign &amp; Submit to SPMU
                </button>

                <button
                    id="request-submit-trigger"
                    type="submit"
                    name="intent"
                    value="submit"
                    hidden
                    tabindex="-1"
                    aria-hidden="true"
                ></button>
            </div>
        </div>

        <div class="request-dialog-backdrop" id="request-terms-dialog" data-request-terms-dialog role="dialog" aria-modal="true" aria-labelledby="request-terms-title" hidden>
            <div class="request-dialog request-terms-dialog" role="document">
                <div class="request-dialog-header">
                    <div>
                        <p class="request-section-label">SPMU Borrowing</p>
                        <h2 id="request-terms-title">Terms and Conditions</h2>
                    </div>
                    <button type="button" class="request-dialog-close" data-close-request-terms aria-label="Close Terms and Conditions">&times;</button>
                </div>
                <div class="request-dialog-body">
                    <p>By submitting this request, the borrower acknowledges the following conditions for this borrowing transaction:</p>
                    <ol class="request-terms-list">
                        <li>The information and supporting documents you submit must be complete, accurate, and authentic.</li>
                        <li>Submitting a request does not guarantee approval or item availability. Approved items may only be used for the approved purpose, location, and borrowing period.</li>
                        <li>Approved items must be picked up within the assigned schedule and released in full; partial issuance is not allowed. If you miss the pickup schedule, you may request a reschedule or cancel while another valid pickup schedule is still available. The system may automatically cancel an unreleased request if no valid pickup schedule remains or if the allowed response period has passed.</li>
                        <li>You are responsible for the borrowed items from the time they are released until they are properly returned and received. For linen, the return is recorded through the Laundry Form.</li>
                        <li>All items must be returned in full on or before the Expected Return Date. Partial returns are not allowed, but early returns are allowed.</li>
                        <li>Late returns may result in a Late Return Notice, billing, accountability processing, or other applicable sanctions.</li>
                        <li>Damaged, lost, missing, or otherwise problematic items may be subject to accountability processing. Confirmed property accountability may require RSLDDP or other required external procedures before the case is resolved.</li>
                        <li>Off-campus borrowing is limited to eligible Barricade items and requires the applicable Gate Pass process.</li>
                        <li>Your registered E-signature will be attached to the submitted request and kept as part of the official record.</li>
                    </ol>
                </div>
                <div class="request-dialog-actions">
                    <button type="button" class="button primary ui-pressable" data-close-request-terms>Close</button>
                </div>
            </div>
        </div>

        <div class="request-dialog-backdrop" id="request-submit-confirmation-dialog" data-request-submit-dialog role="dialog" aria-modal="true" aria-labelledby="request-submit-confirmation-title" hidden>
            <div class="request-dialog request-submit-dialog" role="document">
                <div class="request-dialog-header">
                    <div class="request-submit-identity">
                        <span class="request-submit-icon" aria-hidden="true"><x-icon name="approval" size="20" /></span>
                        <div>
                            <p class="request-section-label">Confirmation</p>
                            <h2 id="request-submit-confirmation-title" data-esign-modal-title>Confirm E-signature and Submission</h2>
                        </div>
                    </div>
                    <button type="button" class="request-dialog-close" data-cancel-request-submit aria-label="Close confirmation">&times;</button>
                </div>
                <div class="request-dialog-body">
                    <p data-esign-modal-intro>Your registered e-signature will be applied to this request before it is submitted to SPMU.</p>
                    <p class="request-submit-note">
                        By continuing, you confirm that you have reviewed the request and agree to the SPMU
                        <a href="#request-terms-dialog" class="request-terms-link" data-open-request-terms>Terms and Conditions</a>.
                    </p>
                </div>
                <div class="request-dialog-actions">
                    <button type="button" class="button secondary ui-pressable" data-cancel-request-submit data-esign-cancel>Cancel</button>
                    <button type="button" class="button primary ui-pressable" data-confirm-request-submit>Confirm E-sign &amp; Submit</button>
                </div>
            </div>
        </div>
    </div>
</form>

{{-- Unsaved-changes guard: kept outside the form so its buttons are never submitted. --}}
<div class="request-dialog-backdrop" id="request-leave-dialog" data-request-leave-dialog role="dialog" aria-modal="true" aria-labelledby="request-leave-title" aria-describedby="request-leave-message" hidden>
    <div class="request-dialog request-submit-dialog" role="document">
        <div class="request-dialog-header">
            <div>
                <p class="request-section-label">Unsaved changes</p>
                <h2 id="request-leave-title">Leave Create Request?</h2>
            </div>
            <button type="button" class="request-dialog-close" data-request-leave-stay aria-label="Stay on page">&times;</button>
        </div>
        <div class="request-dialog-body">
            <p id="request-leave-message">You have unsaved changes. If you leave now, your latest changes will not be saved.</p>
        </div>
        <div class="request-dialog-actions">
            <button type="button" class="button secondary ui-pressable" data-request-leave-stay>Stay on Page</button>
            <button type="button" class="button primary ui-pressable" data-request-leave-confirm>Leave Without Saving</button>
        </div>
    </div>
</div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('request-form');
    const submitButton = document.getElementById('request-submit-button');
    const submitTrigger = document.getElementById('request-submit-trigger');
    const saveDraftButton = document.getElementById('request-save-draft-button');
    const finalConfirmation = document.getElementById('final-confirmation');
    const finalConfirmationError = document.getElementById('final-confirmation-error');
    const eSignatureConfirmation = document.getElementById('e-signature-confirmation');
    const termsDialog = document.querySelector('[data-request-terms-dialog]');
    const submitDialog = document.querySelector('[data-request-submit-dialog]');
    const confirmSubmitButton = document.querySelector('[data-confirm-request-submit]');
    const cancelSubmitButtons = document.querySelectorAll('[data-cancel-request-submit]');
    const signatureReady = {{ $hasCurrentESignature ? 'true' : 'false' }};
    let submissionConfirmed = false;

    const scheduleDate = document.getElementById('schedule_date');
    const returnDate = document.getElementById('return_date');
    const dateContext = document.getElementById('inventory-date-context');
    const availabilityMessage = document.getElementById('availability-message');

    const divisionSelect = document.getElementById('division_code');
    const divisionDisplay = document.getElementById('division-display');
    const officeInput = document.getElementById('office_unit') || document.getElementById('external-company-name');
    const externalBorrower = @json($isExternalBorrower);
    const requestingUnitSelect = document.getElementById('requesting_organizational_unit_id');
    const officeDatalist = document.getElementById('office-unit-options');

    const summaryPurpose = document.getElementById('summary-purpose');
    const summaryLocation = document.getElementById('summary-location');
    const summaryDivision = document.getElementById('summary-division');
    const summaryOffice = document.getElementById('summary-office');
    const summaryFrom = document.getElementById('summary-from');
    const summaryReturn = document.getElementById('summary-return');
    const summaryPremises = document.getElementById('summary-premises');
    const summaryStudentActivity = document.getElementById('summary-student-activity');
    const summaryItemsBody = document.getElementById('summary-items-body');

    const officeUnitsByDivision = @json($officeUnitsByDivision);
    const divisionLabels = @json($divisionOptions);

    const studentToggle = document.getElementById('student-activity-toggle');
    const ptcDocumentBox = document.getElementById('ptc-document-box');
    const summaryItemsCount = document.getElementById('summary-items-count');

    const searchInput = document.getElementById('inventory-search');
    const catalogItems = Array.from(
        document.querySelectorAll('[data-catalog-item]')
    );

    const catalogShell = document.getElementById('catalog-shell');
    const resultLabel = document.getElementById('catalog-result-label');
    const catalogEmpty = document.getElementById('catalog-empty');

    const selectedEmpty = document.getElementById('selected-empty');
    const selectedCount = document.getElementById('selected-item-count');
    const availabilityConflict = document.getElementById('availability-conflict');
    const requestOffCampusToggle = document.getElementById('request-off-campus-toggle');
    const requestOnCampusToggle = document.getElementById('request-on-campus-toggle');
    const premisesHelp = document.getElementById('request-premises-help');
    const campusModeConflict = document.getElementById('campus-mode-conflict');
    const stageOneNextButton = document.querySelector('[data-stage-next="2"]');
    const stageTwoNextButton = document.querySelector('[data-stage-next="3"]');
    const requestLetterInput = form?.querySelector('[name="approved_request_letter"]');
    const ptcInput = form?.querySelector('[name="permission_to_conduct_letter"]');
    const requestLetterAlreadyUploaded = {{ $requestLetterForSubmission ? 'true' : 'false' }};
    const ptcAlreadyUploaded = {{ $ptcForSubmission ? 'true' : 'false' }};
    const selectedItemsTableWrap = document.getElementById('selected-items-table')?.closest('.selected-items-table');

    const stagePanels = Array.from(
        document.querySelectorAll('[data-stage-panel]')
    );
    const stageButtons = Array.from(
        document.querySelectorAll('[data-stage-target]')
    );

    /*
     * Stage the borrower stopped on, derived from the saved draft in the
     * Blade template above. Stages up to it stay unlocked in the stepper
     * because showStage() raises furthestStage to the stage it opens.
     */
    const resumeStage = {{ $resumeStage }};

    let activeStage = 1;
    let furthestStage = 1;
    let availability = {};
    let availabilityLoaded = false;
    let availabilityTimer = null;
    let operationalDateValidationId = 0;

    function setSummaryText(element, value, fallback = '—') {
        if (!element) {
            return;
        }

        const normalized = String(value ?? '').trim();
        element.textContent = normalized || fallback;
    }

    function updateReviewSummary() {
        const purposeField = form?.querySelector('[name="purpose_event"]');
        const locationField = form?.querySelector('[name="location"]');

        setSummaryText(summaryPurpose, purposeField?.value);
        setSummaryText(summaryLocation, locationField?.value);
        setSummaryText(
            summaryDivision,
            externalBorrower ? 'External Borrower' : (divisionLabels[divisionSelect?.value || ''] || divisionDisplay?.textContent)
        );
        setSummaryText(summaryOffice, officeInput?.value);
        setSummaryText(summaryFrom, formatDateLabel(scheduleDate?.value));
        setSummaryText(summaryReturn, formatDateLabel(returnDate?.value));
        const isOffCampus = Boolean(requestOffCampusToggle?.checked);
        setSummaryText(summaryPremises, isOffCampus ? 'Off-campus' : 'On-campus');
        summaryPremises?.classList.toggle('is-positive', !isOffCampus);
        summaryPremises?.classList.toggle('is-elevated', isOffCampus);

        const isStudentActivity = Boolean(studentToggle?.checked);
        setSummaryText(
            summaryStudentActivity,
            isStudentActivity ? 'Yes' : 'No',
            'No'
        );
        summaryStudentActivity?.classList.toggle('is-positive', isStudentActivity);
        summaryStudentActivity?.classList.toggle('is-neutral', !isStudentActivity);

        if (summaryItemsBody) {
            const rows = getSelectedRows();
            summaryItemsBody.innerHTML = '';

            if (rows.length === 0) {
                const emptyRow = document.createElement('tr');
                const emptyCell = document.createElement('td');
                emptyCell.colSpan = 4;
                emptyCell.className = 'review-items-empty';
                emptyCell.textContent = 'No selected items.';
                emptyRow.appendChild(emptyCell);
                summaryItemsBody.appendChild(emptyRow);
            } else {
                rows.forEach((row) => {
                    const itemId = row.dataset.selectedItem;
                    const quantity = document.querySelector(
                        `[data-selected-quantity="${itemId}"]`
                    )?.value || '0';
                    const tr = document.createElement('tr');

                    // The item code rides along inside the Item cell.
                    const itemCell = document.createElement('td');
                    itemCell.className = 'review-item-cell';

                    const badge = document.createElement('span');
                    badge.className = 'item-code-badge';
                    badge.textContent = row.dataset.itemCode
                        || `INV-${String(itemId).padStart(4, '0')}`;
                    itemCell.appendChild(badge);

                    const name = document.createElement('span');
                    name.className = 'review-item-name';
                    name.textContent = row.dataset.itemName || 'Item';
                    itemCell.appendChild(name);

                    tr.appendChild(itemCell);

                    [
                        row.dataset.itemCategory || '—',
                        row.dataset.itemUnit || '—',
                        String(Math.max(0, Math.trunc(Number(quantity) || 0))),
                    ].forEach((value) => {
                        const td = document.createElement('td');
                        td.textContent = value;
                        tr.appendChild(td);
                    });

                    summaryItemsBody.appendChild(tr);
                });
            }

            if (summaryItemsCount) {
                summaryItemsCount.textContent = rows.length === 1
                    ? '1 item'
                    : `${rows.length} items`;
            }
        }
    }

    document.querySelectorAll('[data-dropzone]').forEach((zone) => {
        const input = zone.querySelector('input[type="file"]');
        const readout = zone.querySelector('[data-dropzone-file]');

        if (!input || !readout) {
            return;
        }

        const showSelection = () => {
            const name = input.files?.[0]?.name || '';
            readout.textContent = name;
            readout.hidden = name === '';
            zone.classList.toggle('has-file', name !== '');
        };

        input.addEventListener('change', () => {
            showSelection();
            syncForwardActionStates();
        });

        ['dragenter', 'dragover'].forEach((event) => {
            zone.addEventListener(event, (dragEvent) => {
                dragEvent.preventDefault();
                zone.classList.add('is-dragging');
            });
        });

        ['dragleave', 'dragend', 'drop'].forEach((event) => {
            zone.addEventListener(event, () => zone.classList.remove('is-dragging'));
        });

        zone.addEventListener('drop', (dropEvent) => {
            dropEvent.preventDefault();

            const dropped = dropEvent.dataTransfer?.files?.[0];

            if (!dropped) {
                return;
            }

            const transfer = new DataTransfer();
            transfer.items.add(dropped);
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        showSelection();
    });

    function showStage(stage, scroll = true) {
        activeStage = Math.max(1, Math.min(3, Number(stage) || 1));
        furthestStage = Math.max(furthestStage, activeStage);

        stagePanels.forEach((panel) => {
            panel.hidden = Number(panel.dataset.stagePanel) !== activeStage;
        });

        stageButtons.forEach((button) => {
            const target = Number(button.dataset.stageTarget);
            button.disabled = target > furthestStage;
            button.classList.toggle('is-active', target === activeStage);
            button.classList.toggle('is-complete', target < activeStage);

            if (target === activeStage) {
                button.setAttribute('aria-current', 'step');
            } else {
                button.removeAttribute('aria-current');
            }
        });

        if (activeStage === 3) {
            updateReviewSummary();
        }

        if (scroll) {
            document.querySelector('.request-stepper')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    }

    function validateStageOne() {
        const fields = Array.from(
            document.querySelectorAll(
                '[data-stage-panel="1"] input[required], ' +
                '[data-stage-panel="1"] select[required], ' +
                '[data-stage-panel="1"] textarea[required]'
            )
        ).filter((field) => !field.disabled);

        for (const field of fields) {
            if (!field.checkValidity()) {
                field.reportValidity();
                field.focus();
                return false;
            }
        }

        if (scheduleDate?.value && returnDate?.value) {
            const from = new Date(`${scheduleDate.value}T00:00:00`);
            const to = new Date(`${returnDate.value}T00:00:00`);

            if (to <= from) {
                returnDate.setCustomValidity('Expected Return Date must be after Items Needed From.');
                returnDate.reportValidity();
                returnDate.setCustomValidity('');
                return false;
            }
        }

        return true;
    }

    function validateStageTwo() {
        normalizeAllQuantities();
        syncSelectedItems();

        const count = getSelectedRows().length;

        if (count === 0) {
            document.getElementById('item-picker-heading')?.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            return false;
        }

        if (campusModeConflict && !campusModeConflict.hidden) {
            campusModeConflict.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }

        if (availabilityLoaded && availabilityConflict && !availabilityConflict.hidden) {
            availabilityConflict.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }

        return true;
    }

    function isStageOneReady() {
        const fields = Array.from(
            document.querySelectorAll(
                '[data-stage-panel="1"] input[required], ' +
                '[data-stage-panel="1"] select[required], ' +
                '[data-stage-panel="1"] textarea[required]'
            )
        ).filter((field) => !field.disabled);

        const allRequiredFieldsReady = fields.every((field) => {
            const value = String(field.value ?? '').trim();
            return value !== '' && field.checkValidity();
        });

        if (!allRequiredFieldsReady) {
            return false;
        }

        if (scheduleDate?.value && returnDate?.value) {
            const from = new Date(`${scheduleDate.value}T00:00:00`);
            const to = new Date(`${returnDate.value}T00:00:00`);
            if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime()) || to <= from) {
                return false;
            }
        }

        return true;
    }

    function isStageTwoReady() {
        const rows = getSelectedRows();
        if (rows.length === 0 || !availabilityLoaded) {
            return false;
        }

        if (isOffCampusMode()) {
            if (rows.length !== 1 || rows[0]?.dataset.offCampusAllowed !== '1') {
                return false;
            }
        }

        return rows.every((row) => {
            const itemId = row.dataset.selectedItem;
            const quantity = document.querySelector(`[data-selected-quantity="${itemId}"]`);
            const requested = Math.trunc(Number(quantity?.value || 0));
            const available = Math.max(0, Math.floor(Number(availability[itemId]?.available) || 0));
            return requested > 0 && available > 0 && requested <= available;
        });
    }

    function areRequiredDocumentsReady() {
        const requestLetterReady = Boolean(
            requestLetterAlreadyUploaded || requestLetterInput?.files?.length
        );
        const ptcRequired = Boolean(studentToggle?.checked);
        const ptcReady = Boolean(
            !ptcRequired || ptcAlreadyUploaded || ptcInput?.files?.length
        );

        return requestLetterReady && ptcReady;
    }

    function syncForwardActionStates() {
        if (stageOneNextButton) {
            stageOneNextButton.disabled = !isStageOneReady();
        }

        if (stageTwoNextButton) {
            stageTwoNextButton.disabled = !isStageTwoReady();
        }

        /*
         * FINAL E-SIGN / SUBMIT READINESS
         *
         * E-sign & Submit to SPMU stays DISABLED until all required
         * submission requirements are complete.
         *
         * Borrowing Request Letter = always required.
         * PTC = required only for Student Activity.
         * Save Draft is NOT affected by this rule.
         */

        const requestLetterReady = Boolean(
            requestLetterAlreadyUploaded ||
            requestLetterInput?.files?.length
        );

        const ptcRequired = Boolean(studentToggle?.checked);

        const ptcReady = Boolean(
            !ptcRequired ||
            ptcAlreadyUploaded ||
            ptcInput?.files?.length
        );

        const detailsReady = isStageOneReady();
        const itemsReady = isStageTwoReady();
        const acknowledgementReady = Boolean(finalConfirmation?.checked);

        const canSubmit =
            detailsReady &&
            itemsReady &&
            requestLetterReady &&
            ptcReady &&
            signatureReady &&
            acknowledgementReady;

        if (submitButton) {
            submitButton.disabled = !canSubmit;

            /*
             * Helpful explanation for why submission is unavailable.
             */
            if (!detailsReady) {
                submitButton.title =
                    'Complete all required Request Details first.';
            } else if (!itemsReady) {
                submitButton.title =
                    'Complete the item selection and valid quantities first.';
            } else if (!requestLetterReady) {
                submitButton.title =
                    'Upload the fully signed Borrowing Request Letter before submitting.';
            } else if (!ptcReady) {
                submitButton.title =
                    'Upload the Permission to Conduct Letter for this Student Activity before submitting.';
            } else if (!signatureReady) {
                submitButton.title =
                    'Register your E-signature in Account Settings before submitting.';
            } else if (!acknowledgementReady) {
                submitButton.title =
                    'Check the Acknowledgement before submitting.';
            } else {
                submitButton.title = '';
            }
        }
    }

    function syncRequestingUnitProfile() {
        if (!requestingUnitSelect || !divisionSelect || !officeInput) {
            return;
        }

        const option = requestingUnitSelect.tagName === 'SELECT'
            ? requestingUnitSelect.selectedOptions?.[0]
            : null;

        if (option) {
            const divisionCode = option.dataset.divisionCode || '';
            const divisionLabel = option.dataset.divisionLabel || divisionLabels[divisionCode] || '';
            const unitName = option.dataset.unitName || option.textContent?.trim() || '';

            divisionSelect.value = divisionCode;
            officeInput.value = unitName;

            if (divisionDisplay) {
                divisionDisplay.textContent = divisionLabel || 'No division assigned';
            }
        }

        updateReviewSummary();
        syncForwardActionStates();
    }

    function syncOfficeOptions() {
        /*
         * Division / office are no longer borrower-editable. Kept as a small
         * compatibility hook so older event calls do not break this page.
         */
        syncRequestingUnitProfile();
    }

    function formatDateLabel(value) {
        if (!value) {
            return '';
        }

        const date = new Date(`${value}T00:00:00`);
        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat('en-PH', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        }).format(date);
    }

    function syncDateContext() {
        const from = scheduleDate?.value || '';
        const to = returnDate?.value || '';

        if (!dateContext) {
            return;
        }

        dateContext.textContent =
            from && to
                ? `${formatDateLabel(from)} - ${formatDateLabel(to)}`
                : 'Select dates';
    }

    async function validateOperationalDates() {
        const from = scheduleDate?.value || '';
        const to = returnDate?.value || '';
        const validationId = ++operationalDateValidationId;

        // Field validity is owned by the date-validation block
        // (SPMU_CREATE_REQUEST_DATE_VALIDATION); do not clear it here.

        if (!from || !to) {
            return true;
        }

        try {
            const response = await fetch(
                `{{ route('requests.operational-date-availability') }}?schedule_date=${encodeURIComponent(from)}&return_date=${encodeURIComponent(to)}`,
                { headers: { Accept: 'application/json' } }
            );

            if (!response.ok) {
                throw new Error('Operational date validation failed.');
            }

            const dates = await response.json();

            if (validationId !== operationalDateValidationId) {
                return false;
            }

            const invalidMessages = [];

            [
                [scheduleDate, dates.schedule_date],
                [returnDate, dates.return_date],
            ].forEach(([field, result]) => {
                const message = result?.open ? '' : (result?.message || 'SPMU is closed on this date. Please choose another date.');
                spmuDateServerResult(field, result);

                if (message) {
                    invalidMessages.push(message);
                }
            });

            if (invalidMessages.length && availabilityMessage) {
                availabilityMessage.textContent = invalidMessages.join(' ');
            }

            return invalidMessages.length === 0;
        } catch (error) {
            if (validationId === operationalDateValidationId && availabilityMessage) {
                availabilityMessage.textContent = "We couldn't check your dates right now. They will be checked again when you save or submit.";
            }

            return true;
        }
    }

    function syncStudentFields() {
        const active = Boolean(studentToggle?.checked);

        if (ptcDocumentBox) {
            ptcDocumentBox.hidden = !active;
        }
    }

    function getFilteredCatalog() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const offCampusMode = Boolean(requestOffCampusToggle?.checked);

        if (!query) {
            return [];
        }

        return catalogItems
            .filter((item) => (item.dataset.search || '').includes(query))
            .filter((item) => !offCampusMode || item.dataset.offCampusAllowed === '1')
            .slice(0, 8);
    }

    function renderCatalog() {
        const query = (searchInput?.value || '').trim();
        const filtered = getFilteredCatalog();

        catalogItems.forEach((item) => { item.hidden = true; });

        if (!query) {
            if (catalogShell) catalogShell.hidden = true;
            if (catalogEmpty) catalogEmpty.hidden = true;
            if (resultLabel) resultLabel.textContent = 'Recommended matches';
            syncCatalogButtons();
            return;
        }

        filtered.forEach((item) => { item.hidden = false; });

        if (catalogShell) catalogShell.hidden = false;
        if (catalogEmpty) catalogEmpty.hidden = filtered.length !== 0;

        if (resultLabel) {
            resultLabel.textContent = filtered.length
                ? `Recommended matches for “${query}”`
                : `No match for “${query}”`;
        }

        syncCatalogButtons();
    }

    function isSelected(itemId) {
        return Boolean(
            document.querySelector(`[data-selected-checkbox="${itemId}"]`)?.checked
        );
    }

    function getSelectedRows() {
        return Array.from(document.querySelectorAll('[data-selected-item]'))
            .filter((row) => {
                const itemId = row.dataset.selectedItem;
                return isSelected(itemId);
            });
    }

    function isOffCampusMode() {
        return Boolean(requestOffCampusToggle?.checked);
    }

    function syncRequestPremises() {
        const offCampusMode = isOffCampusMode();

        if (requestOnCampusToggle) requestOnCampusToggle.checked = !offCampusMode;

        document.querySelectorAll('[data-selected-location]').forEach((location) => {
            location.value = 'ON_CAMPUS';
        });

        if (offCampusMode) {
            getSelectedRows().forEach((row) => {
                const itemId = row.dataset.selectedItem;
                const location = document.querySelector(`[data-selected-location="${itemId}"]`);
                if (location) {
                    location.value = 'OFF_CAMPUS';
                }
            });
        }
        if (searchInput) {
            searchInput.placeholder = offCampusMode
                ? 'Search Barricade...'
                : 'Start typing an item name, category, description, or unit...';
        }
    }

    function syncCatalogButtons() {
        const offCampusMode = isOffCampusMode();
        const selectedCountNow = getSelectedRows().length;

        document.querySelectorAll('[data-add-item]').forEach((button) => {
            const itemId = button.dataset.addItem;
            const added = isSelected(itemId);
            const available = Math.floor(Number(availability[itemId]?.available ?? 0));
            const catalogItem = document.querySelector(`[data-catalog-item][data-item-id="${itemId}"]`);
            const offCampusAllowed = catalogItem?.dataset.offCampusAllowed === '1';

            if (added) {
                button.textContent = 'Added';
                button.classList.add('is-added');
                button.disabled = true;
                button.title = '';
                return;
            }

            button.textContent = '+ Add';
            button.classList.remove('is-added');

            const modeBlocked = offCampusMode && (!offCampusAllowed || selectedCountNow >= 1);
            button.disabled =
                !availabilityLoaded
                || !scheduleDate?.value
                || !returnDate?.value
                || available <= 0
                || modeBlocked;

            button.title = modeBlocked
                ? 'Off-campus borrowing allows one Barricade item only.'
                : '';
        });
    }

    function updateCatalogAvailability() {
        document.querySelectorAll('[data-catalog-availability]').forEach((node) => {
            const itemId = node.dataset.catalogAvailability;
            const balance = availability[itemId];

            node.classList.remove('is-available', 'is-unavailable');

            if (!availabilityLoaded || !balance) {
                node.textContent = 'Select dates';
                return;
            }

            const available = Math.max(0, Math.floor(Number(balance.available) || 0));
            node.textContent = available > 0
                ? `${available} available`
                : 'Unavailable for selected dates';
            node.classList.add(available > 0 ? 'is-available' : 'is-unavailable');
        });

        syncCatalogButtons();
    }

    function normalizeQuantity(input) {
        if (!input) {
            return;
        }

        const raw = String(input.value ?? '').trim();
        if (raw === '') {
            return;
        }

        let value = Number(raw);
        if (!Number.isFinite(value)) {
            input.value = '0';
            return;
        }

        value = Math.max(0, Math.trunc(value));
        const max = Math.floor(Number(input.max));

        if (Number.isFinite(max) && max >= 0) {
            value = Math.min(value, max);
        }

        input.value = String(value);
    }

    function normalizeAllQuantities() {
        document.querySelectorAll('[data-selected-quantity]').forEach(normalizeQuantity);
    }

    function syncSelectedItems() {
        if (eSignatureConfirmation) {
            eSignatureConfirmation.value = finalConfirmation?.checked ? '1' : '0';
        }

        let count = 0;
        let conflict = false;

        document.querySelectorAll('[data-selected-item]').forEach((row) => {
            const itemId = row.dataset.selectedItem;
            const checkbox = document.querySelector(`[data-selected-checkbox="${itemId}"]`);
            const quantity = document.querySelector(`[data-selected-quantity="${itemId}"]`);
            const location = document.querySelector(`[data-selected-location="${itemId}"]`);
            const availabilityValue = document.querySelector(`[data-selected-availability="${itemId}"]`);
            const availabilityNote = document.querySelector(`[data-selected-availability-note="${itemId}"]`);
            const selected = Boolean(checkbox?.checked);

            row.hidden = !selected;
            if (!selected) {
                return;
            }

            count++;

            const balance = availability[itemId];
            if (!availabilityLoaded || !balance) {
                if (availabilityValue) availabilityValue.textContent = '-';
                if (availabilityNote) {
                    availabilityNote.textContent = 'Select dates';
                    availabilityNote.classList.remove('is-error');
                }
                return;
            }

            const available = Math.max(0, Math.floor(Number(balance.available) || 0));
            const requested = Math.trunc(Number(quantity?.value || 0));

            if (availabilityValue) {
                availabilityValue.textContent = String(available);
            }

            const invalid = available <= 0 || requested <= 0 || requested > available;

            if (availabilityNote) {
                availabilityNote.classList.toggle('is-error', invalid);
                if (available <= 0) {
                    availabilityNote.textContent = 'Unavailable for these dates';
                } else if (requested > available) {
                    availabilityNote.textContent = `Requested ${requested}; only ${available} available`;
                } else if (requested <= 0) {
                    availabilityNote.textContent = 'Enter a whole-number quantity greater than 0';
                } else {
                    availabilityNote.textContent = 'Available for selected dates';
                }
            }

            if (quantity && available > 0) {
                quantity.max = String(available);
            }

            if (invalid) {
                conflict = true;
            }
        });

        syncRequestPremises();

        const offCampusMode = isOffCampusMode();
        const selectedRows = getSelectedRows();
        const offCampusConflict = offCampusMode && (
            selectedRows.length > 1
            || selectedRows.some((row) => row.dataset.offCampusAllowed !== '1')
        );

        if (selectedEmpty) selectedEmpty.hidden = count !== 0;
        if (selectedItemsTableWrap) selectedItemsTableWrap.hidden = count === 0;
        if (selectedCount) selectedCount.textContent = String(count);
        if (campusModeConflict) campusModeConflict.hidden = !offCampusConflict;
        if (availabilityConflict) {
            availabilityConflict.hidden = !availabilityLoaded || !conflict;
        }

        syncForwardActionStates();
        syncCatalogButtons();

        if (activeStage === 3) {
            updateReviewSummary();
        }
    }

    function addItem(itemId) {
        const offCampusMode = isOffCampusMode();
        const catalogItem = document.querySelector(`[data-catalog-item][data-item-id="${itemId}"]`);
        const offCampusAllowed = catalogItem?.dataset.offCampusAllowed === '1';

        if (offCampusMode && (!offCampusAllowed || getSelectedRows().length >= 1)) {
            return;
        }

        const checkbox = document.querySelector(`[data-selected-checkbox="${itemId}"]`);
        const quantity = document.querySelector(`[data-selected-quantity="${itemId}"]`);

        if (!checkbox) {
            return;
        }

        const available = Math.floor(Number(availability[itemId]?.available ?? 0));
        if (!availabilityLoaded || available <= 0) {
            return;
        }

        checkbox.checked = true;

        const location = document.querySelector(`[data-selected-location="${itemId}"]`);
        if (location) {
            location.value = offCampusMode ? 'OFF_CAMPUS' : 'ON_CAMPUS';
        }

        if (quantity && Number(quantity.value || 0) <= 0) {
            quantity.value = '1';
        }

        syncSelectedItems();

        if (searchInput) {
            searchInput.value = '';
            renderCatalog();
            searchInput.focus();
        }
    }

    function removeItem(itemId) {
        const checkbox = document.querySelector(`[data-selected-checkbox="${itemId}"]`);
        const quantity = document.querySelector(`[data-selected-quantity="${itemId}"]`);
        const location = document.querySelector(`[data-selected-location="${itemId}"]`);

        if (checkbox) checkbox.checked = false;
        if (quantity) quantity.value = '0';
        if (location) location.value = 'ON_CAMPUS';

        syncSelectedItems();
    }

    async function refreshAvailability() {
        const from = scheduleDate?.value || '';
        const to = returnDate?.value || '';

        syncDateContext();

        if (!from || !to) {
            availability = {};
            availabilityLoaded = false;
            if (availabilityMessage) {
                availabilityMessage.textContent =
                    'Select Items Needed From and Expected Return Date to load availability.';
            }
            updateCatalogAvailability();
            syncSelectedItems();
            return;
        }

        if (!await validateOperationalDates()) {
            availability = {};
            availabilityLoaded = false;
            updateCatalogAvailability();
            syncSelectedItems();
            return;
        }

        clearTimeout(availabilityTimer);
        availabilityTimer = setTimeout(async () => {
            if (availabilityMessage) {
                availabilityMessage.textContent = 'Checking availability for the selected dates...';
            }

            try {
                const response = await fetch(
                    `{{ route('inventory.availability') }}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
                    { headers: { Accept: 'application/json' } }
                );

                if (!response.ok) {
                    throw new Error('Availability request failed.');
                }

                availability = await response.json();
                availabilityLoaded = true;

                if (availabilityMessage) {
                    availabilityMessage.textContent =
                        'Availability is based on the complete selected borrowing period.';
                }

                updateCatalogAvailability();
                syncSelectedItems();
            } catch (error) {
                availability = {};
                availabilityLoaded = false;
                if (availabilityMessage) {
                    availabilityMessage.textContent =
                        'Availability could not be loaded. Check the borrowing dates and try again.';
                }
                updateCatalogAvailability();
                syncSelectedItems();
            }
        }, 250);
    }

    document.querySelectorAll('[data-stage-next]').forEach((button) => {
        button.addEventListener('click', () => {
            const next = Number(button.dataset.stageNext);

            if (activeStage === 1 && !validateStageOne()) {
                return;
            }

            if (activeStage === 2 && !validateStageTwo()) {
                return;
            }

            showStage(next);
        });
    });

    document.querySelectorAll('[data-stage-back]').forEach((button) => {
        button.addEventListener('click', () => {
            showStage(Number(button.dataset.stageBack));
        });
    });

    stageButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const target = Number(button.dataset.stageTarget);
            if (target <= furthestStage) {
                showStage(target);
            }
        });
    });

    requestingUnitSelect?.addEventListener('change', syncRequestingUnitProfile);
    studentToggle?.addEventListener('change', () => {
        syncStudentFields();
        syncForwardActionStates();
    });

    form?.addEventListener('input', () => {
        syncForwardActionStates();
        if (activeStage === 3) {
            updateReviewSummary();
        }
    });

    form?.addEventListener('change', () => {
        syncForwardActionStates();
        if (activeStage === 3) {
            updateReviewSummary();
        }
    });
    // SPMU_CREATE_REQUEST_DATE_VALIDATION_START
    /*
     * Items Needed From / Expected Return Date checks, using the native date
     * picker. Mirrors the server rules exactly (the server stays authoritative
     * on Save Draft and Submit):
     * - Items Needed From is after today (the input's min attribute);
     * - Expected Return Date is after Items Needed From;
     * - both fall on a day SPMU is open in the Operational Calendar,
     *   including special openings and excluding closures/holidays.
     * One message line per field; never auto-picks a date for the borrower.
     */
    var spmuDateStates = null;
    var spmuDefaultReturnMin = '';

    const spmuDateCheckUrl = @json(route('requests.operational-date-availability'));

    function spmuDateLabel(value) {
        const date = new Date(`${value}T12:00:00`);

        return Number.isNaN(date.getTime())
            ? value
            : date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    }

    function spmuNextDay(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return '';

        const [year, month, day] = value.split('-').map(Number);
        const date = new Date(year, month - 1, day + 1);

        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0'),
        ].join('-');
    }

    function spmuDateEmptyMessage(field) {
        return field === scheduleDate
            ? 'Please select Items Needed From.'
            : 'Please select Expected Return Date.';
    }

    function spmuDateRender(field) {
        const state = spmuDateStates.get(field);
        const showMessage = state.message && !state.suppressMessage;

        if (state.node) {
            state.node.textContent = showMessage ? state.message : '';
            state.node.style.display = showMessage ? 'block' : 'none';
        }

        // One message per field: once the live message speaks, hide the
        // server-rendered error under the same input.
        if (showMessage && state.serverError) {
            state.serverError.hidden = true;
        }

        const blocking = {
            pending: 'Checking this date. Please wait a moment.',
            invalid: state.message || 'Please choose another date.',
            error: state.message,
        }[state.status] || '';

        field.setCustomValidity(blocking);
        field.toggleAttribute('aria-invalid', Boolean(showMessage));
    }

    function spmuSyncReturnMin() {
        const afterFrom = spmuNextDay(scheduleDate.value);

        returnDate.min = afterFrom && afterFrom > spmuDefaultReturnMin
            ? afterFrom
            : spmuDefaultReturnMin;
    }

    function spmuDatesReady() {
        return spmuDateStates.get(scheduleDate).status === 'valid'
            && spmuDateStates.get(returnDate).status === 'valid'
            && returnDate.value > scheduleDate.value;
    }

    /*
     * Local rules first; only a locally valid date is sent to the
     * Operational Calendar. Bumping the serial makes any older in-flight
     * check for this field irrelevant (it can never overwrite this result).
     */
    function spmuDateEvaluate(field, { userChange, emptyMessage = null }) {
        const state = spmuDateStates.get(field);
        const value = field.value;

        state.serial++;

        if (!value) {
            state.status = 'empty';
            state.message = emptyMessage ?? (state.touched ? spmuDateEmptyMessage(field) : '');
            spmuDateRender(field);
            return;
        }

        let problem = '';

        if (field === scheduleDate && scheduleDate.min && value < scheduleDate.min) {
            problem = `Items Needed From must be ${spmuDateLabel(scheduleDate.min)} or later.`;
        } else if (field === returnDate && scheduleDate.value && value <= scheduleDate.value) {
            problem = `Please choose a return date after ${spmuDateLabel(scheduleDate.value)}.`;
        } else if (field === returnDate && returnDate.min && value < returnDate.min) {
            problem = `Expected Return Date must be ${spmuDateLabel(returnDate.min)} or later.`;
        }

        if (problem) {
            state.status = 'invalid';
            state.message = problem;
            spmuDateRender(field);
            return;
        }

        void spmuDateCheckCalendar(field, state.serial, userChange);
    }

    async function spmuDateCheckCalendar(field, serial, userChange) {
        const state = spmuDateStates.get(field);
        const picked = field.value;
        const isCurrent = () => serial === state.serial && field.value === picked;

        state.status = 'pending';
        state.message = '';
        spmuDateRender(field);
        spmuDateAfterChange();

        let result;

        try {
            // The endpoint checks two dates independently; sending the picked
            // date as both lets one field be checked before the other is set.
            const query = new URLSearchParams({ schedule_date: picked, return_date: picked });
            const response = await fetch(`${spmuDateCheckUrl}?${query}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) throw new Error('Calendar lookup failed.');

            result = (await response.json())?.[field.id];

            if (typeof result?.open !== 'boolean') throw new Error('Unexpected calendar response.');
        } catch (error) {
            if (!isCurrent()) return;

            state.status = 'error';
            state.message = "We couldn't check this date. Please select it again.";
            spmuDateRender(field);
            spmuDateAfterChange();
            return;
        }

        if (!isCurrent()) return;

        if (result.open) {
            state.status = 'valid';
            state.message = '';
        } else {
            state.message = result.message
                || `SPMU is closed on ${spmuDateLabel(picked)}. Please choose another date.`;

            // The borrower's selection is never changed or cleared for them: the
            // date stays visible, marked invalid, with the calendar's explanation
            // beside it. They cannot continue until they choose another date.
            state.status = 'invalid';
        }

        spmuDateRender(field);
        spmuDateAfterChange();
    }

    function spmuDateAfterChange() {
        const schedule = spmuDateStates.get(scheduleDate).status;
        const ret = spmuDateStates.get(returnDate).status;

        if (spmuDatesReady() || schedule === 'empty' || ret === 'empty') {
            // Existing availability flow (also resets it when a date is empty).
            void refreshAvailability();
        } else {
            syncDateContext();
            availability = {};
            availabilityLoaded = false;
            updateCatalogAvailability();
            syncSelectedItems();
        }

        syncForwardActionStates();
    }

    function spmuDateOnUserChange(field) {
        const state = spmuDateStates.get(field);

        // Chrome fires both input and change for one pick; handle a value once,
        // except to retry after a failed check.
        if (field.value === state.lastValue && state.status !== 'error') return;

        state.lastValue = field.value;
        state.touched = true;
        state.suppressMessage = false;

        if (state.serverError) state.serverError.hidden = true;

        spmuDateEvaluate(field, { userChange: true });

        if (field === scheduleDate) {
            spmuSyncReturnMin();

            const returnState = spmuDateStates.get(returnDate);

            if (returnDate.value && scheduleDate.value && returnDate.value <= scheduleDate.value) {
                // The earlier return date is no longer valid: clear only it and
                // let the borrower choose a new one.
                returnDate.value = '';
                returnState.lastValue = '';
                returnState.touched = true;
                returnState.suppressMessage = false;

                if (returnState.serverError) returnState.serverError.hidden = true;

                spmuDateEvaluate(returnDate, {
                    userChange: true,
                    emptyMessage: `Please choose a new return date after ${spmuDateLabel(scheduleDate.value)}.`,
                });
            } else if (returnDate.value && returnState.status === 'invalid') {
                // An order problem may now be fixed by the new Items Needed From.
                spmuDateEvaluate(returnDate, { userChange: true });
            }
        }

        spmuDateAfterChange();
    }

    /*
     * Called by validateOperationalDates() when the availability refresh
     * re-checks both dates on the server. Only a closure for the value still
     * selected is applied; it never overrides a newer selection.
     */
    function spmuDateServerResult(field, result) {
        if (!spmuDateStates || !field || !result || result.open) return;

        const state = spmuDateStates.get(field);

        if (state.status !== 'valid' || state.lastValue !== field.value) return;

        state.status = 'invalid';
        state.message = result.message || `SPMU is closed on ${spmuDateLabel(field.value)}. Please choose another date.`;
        spmuDateRender(field);
    }

    if (scheduleDate && returnDate) {
        spmuDefaultReturnMin = returnDate.min;

        spmuDateStates = new Map([scheduleDate, returnDate].map((field) => {
            const serverError = Array.from(field.closest('label')?.querySelectorAll('.field-error') || [])
                .find((node) => !node.id?.startsWith('spmu-')) || null;

            return [field, {
                node: document.getElementById(field === scheduleDate ? 'spmu-schedule-live-error' : 'spmu-return-live-error'),
                serverError,
                // Do not repeat a server error already shown under this input.
                suppressMessage: Boolean(serverError),
                status: 'empty',
                message: '',
                touched: Boolean(field.value),
                lastValue: field.value,
                serial: 0,
            }];
        }));

        spmuSyncReturnMin();

        // Saved draft / redisplayed values are checked but never cleared.
        // Deferred until the page script has finished initializing.
        queueMicrotask(() => {
            [scheduleDate, returnDate].forEach((field) => spmuDateEvaluate(field, { userChange: false }));
        });

        ['input', 'change'].forEach((eventName) => {
            scheduleDate.addEventListener(eventName, () => spmuDateOnUserChange(scheduleDate));
            returnDate.addEventListener(eventName, () => spmuDateOnUserChange(returnDate));
        });

        /*
         * Step 2 cannot be reached (Continue or the step indicator) while a
         * date is missing, invalid, or still being checked.
         */
        document.addEventListener('click', (event) => {
            const button = event.target instanceof Element
                ? event.target.closest('[data-stage-next], [data-stage-target]')
                : null;

            if (!button || activeStage !== 1) return;

            const target = Number(button.dataset.stageNext || button.dataset.stageTarget);

            if (target < 2 || spmuDatesReady()) return;

            event.preventDefault();
            event.stopImmediatePropagation();

            [scheduleDate, returnDate].forEach((field) => {
                const state = spmuDateStates.get(field);

                state.touched = true;
                state.suppressMessage = false;

                if (state.status === 'empty' && !state.message) {
                    state.message = spmuDateEmptyMessage(field);
                }

                spmuDateRender(field);
            });

            ([scheduleDate, returnDate].find((field) => spmuDateStates.get(field).status !== 'valid') || returnDate)
                .focus();
        }, true);
    }
    // SPMU_CREATE_REQUEST_DATE_VALIDATION_END

    searchInput?.addEventListener('input', renderCatalog);

    searchInput?.addEventListener('focus', () => {
        if ((searchInput.value || '').trim()) {
            renderCatalog();
        }
    });

    document.querySelectorAll('[data-add-item]').forEach((button) => {
        button.addEventListener('click', () => addItem(button.dataset.addItem));
    });

    document.querySelectorAll('[data-remove-item]').forEach((button) => {
        button.addEventListener('click', () => removeItem(button.dataset.removeItem));
    });

    document.querySelectorAll('[data-selected-quantity]').forEach((input) => {
        input.addEventListener('keydown', (event) => {
            if (['.', ',', 'e', 'E', '+', '-'].includes(event.key)) {
                event.preventDefault();
            }
        });

        input.addEventListener('input', syncSelectedItems);
        input.addEventListener('change', () => {
            normalizeQuantity(input);
            syncSelectedItems();
        });
        input.addEventListener('blur', () => {
            normalizeQuantity(input);
            syncSelectedItems();
        });
    });

    function handlePremisesChange() {
        if (requestOffCampusToggle.checked) {
            const selectedRows = getSelectedRows();
            const needsReset = selectedRows.length > 1
                || selectedRows.some((row) => row.dataset.offCampusAllowed !== '1');

            if (needsReset) {
                const proceed = window.confirm(
                    'Switch to Off-campus borrowing? Current selected items will be removed because only Barricade may be borrowed off-campus.'
                );

                if (!proceed) {
                    requestOffCampusToggle.checked = false;
                    syncRequestPremises();
                    syncSelectedItems();
                    renderCatalog();
                    return;
                }

                getSelectedRows().forEach((row) => {
                    const itemId = row.dataset.selectedItem;
                    const checkbox = document.querySelector(`[data-selected-checkbox="${itemId}"]`);
                    const quantity = document.querySelector(`[data-selected-quantity="${itemId}"]`);
                    const location = document.querySelector(`[data-selected-location="${itemId}"]`);

                    if (checkbox) checkbox.checked = false;
                    if (quantity) quantity.value = '0';
                    if (location) location.value = 'ON_CAMPUS';
                });
            }
        }

        syncRequestPremises();
        syncSelectedItems();
        renderCatalog();
    }

    requestOffCampusToggle?.addEventListener('change', handlePremisesChange);
    requestOnCampusToggle?.addEventListener('change', handlePremisesChange);
    /*
     * PRE-CONFIRMATION CLICK
     * ----------------------
     * The visible E-sign button is intentionally type="button".
     * We run the existing submit validation path with the hidden submit trigger,
     * but temporarily opt this preflight submit out of the global duplicate-submit
     * lock. This lets the local handler validate the request and open the
     * confirmation modal WITHOUT changing the visible button to Processing...
     * and WITHOUT locking the form before the borrower confirms.
     */
    submitButton?.addEventListener('click', () => {
        if (!form || !submitTrigger || submitButton.disabled) return;

        submissionConfirmed = false;

        form.dataset.allowRepeatedSubmit = 'true';
        try {
            form.requestSubmit(submitTrigger);
        } finally {
            delete form.dataset.allowRepeatedSubmit;
        }
    });

    form?.addEventListener('submit', (event) => {
        if (activeStage !== 3) {
            event.preventDefault();
            return;
        }

        normalizeAllQuantities();
        syncSelectedItems();

        const intent = event.submitter?.value || 'draft';
        const blockedButton = intent === 'submit' ? submitButton : saveDraftButton;

        if (blockedButton?.disabled) {
            event.preventDefault();
            (campusModeConflict && !campusModeConflict.hidden
                ? campusModeConflict
                : availabilityConflict
            )?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        if (intent !== 'submit') {
            return;
        }

        const requestLetterInput = form.querySelector('[name="approved_request_letter"]');
        const ptcInput = form.querySelector('[name="permission_to_conduct_letter"]');
        const requestLetterReady = Boolean(
            requestLetterInput?.files?.length || {{ $requestLetterForSubmission ? 'true' : 'false' }}
        );
        const ptcRequired = Boolean(studentToggle?.checked);
        const ptcReady = Boolean(
            !ptcRequired || ptcInput?.files?.length || {{ $ptcForSubmission ? 'true' : 'false' }}
        );

        if (!requestLetterReady) {
            event.preventDefault();
            requestLetterInput?.setCustomValidity(
                'Upload the fully signed Borrowing Request Letter before submitting.'
            );
            requestLetterInput?.reportValidity();
            requestLetterInput?.setCustomValidity('');
            requestLetterInput?.focus();
            return;
        }

        if (!ptcReady) {
            event.preventDefault();
            ptcInput?.setCustomValidity(
                'Upload the Permission to Conduct Letter for this student activity before submitting.'
            );
            ptcInput?.reportValidity();
            ptcInput?.setCustomValidity('');
            ptcInput?.focus();
            return;
        }

        if (!signatureReady) {
            event.preventDefault();
            const signatureNotice = form.querySelector('.esignature-notice');
            signatureNotice?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            signatureNotice?.querySelector('a')?.focus();
            return;
        }

        if (!finalConfirmation?.checked) {
            event.preventDefault();
            if (finalConfirmationError) {
                finalConfirmationError.hidden = false;
            }
            finalConfirmation?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            finalConfirmation?.focus();
            return;
        }

        if (finalConfirmationError) {
            finalConfirmationError.hidden = true;
        }

        if (!submissionConfirmed) {
            event.preventDefault();
            if (eSignatureConfirmation) eSignatureConfirmation.value = '1';
            if (submitDialog) {
                if (confirmSubmitButton) confirmSubmitButton.disabled = false;
                submitDialog.hidden = false;
                document.body.classList.add('request-dialog-open');
                confirmSubmitButton?.focus();
            }
        }
    });

    function closeDialog(dialog) {
        if (!dialog) return;
        dialog.hidden = true;
        if (termsDialog?.hidden !== false && submitDialog?.hidden !== false) {
            document.body.classList.remove('request-dialog-open');
        }
    }

    document.querySelectorAll('[data-open-request-terms]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (termsDialog) {
                termsDialog.hidden = false;
                document.body.classList.add('request-dialog-open');
                termsDialog.querySelector('[data-close-request-terms]')?.focus();
            }
        });
    });

    document.querySelectorAll('[data-close-request-terms]').forEach((button) => {
        button.addEventListener('click', () => closeDialog(termsDialog));
    });
    termsDialog?.addEventListener('click', (event) => {
        if (event.target === termsDialog) closeDialog(termsDialog);
    });
    cancelSubmitButtons.forEach((button) => {
        button.addEventListener('click', () => {
            submissionConfirmed = false;
            closeDialog(submitDialog);
            submitButton?.focus();
        });
    });
    confirmSubmitButton?.addEventListener('click', () => {
        if (!form || !finalConfirmation?.checked) return;

        submissionConfirmed = true;
        if (eSignatureConfirmation) eSignatureConfirmation.value = '1';
        if (confirmSubmitButton) confirmSubmitButton.disabled = true;

        /*
         * Re-enter the same submit path using the visible submitter so the
         * submit intent stays "submit" and all existing backend validation,
         * E-sign snapshot handling, and duplicate-submission protection remain intact.
         *
         * submitButton must stay enabled until AFTER requestSubmit() dispatches
         * the form's submit event: that listener reads submitButton.disabled to
         * decide whether the "submit" intent is blocked, so disabling it first
         * caused this confirmed re-submission to be cancelled every time.
         */
        if (typeof form.requestSubmit === 'function' && submitTrigger) {
            form.requestSubmit(submitTrigger);
        } else {
            // Older-browser fallback. Backend validation remains authoritative.
            const fallbackIntent = document.createElement('input');
            fallbackIntent.type = 'hidden';
            fallbackIntent.name = 'intent';
            fallbackIntent.value = 'submit';
            form.appendChild(fallbackIntent);
            form.submit();
        }

        if (submitButton) {
            // Keep the original borrower-facing action label visible.
            // Disabling the button is enough to prevent a second confirmation
            // while the request is being submitted.
            submitButton.disabled = true;
        }

        // A request that never navigates away (e.g. a dropped connection)
        // would otherwise leave these buttons disabled forever; re-enable
        // them automatically so the borrower can retry. This never fires on
        // a normal submission because the page has already navigated away.
        window.setTimeout(() => {
            if (confirmSubmitButton) confirmSubmitButton.disabled = false;
            if (submitButton) submitButton.disabled = false;
        }, 20000);
    });
    submitDialog?.addEventListener('click', (event) => {
        if (event.target === submitDialog) {
            submissionConfirmed = false;
            closeDialog(submitDialog);
            submitButton?.focus();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (termsDialog && !termsDialog.hidden) {
            closeDialog(termsDialog);
            return;
        }
        if (submitDialog && !submitDialog.hidden) {
            submissionConfirmed = false;
            closeDialog(submitDialog);
            submitButton?.focus();
        }
    });

    finalConfirmation?.addEventListener('change', () => {
        if (finalConfirmationError) {
            finalConfirmationError.hidden = Boolean(finalConfirmation.checked);
        }
        if (eSignatureConfirmation) {
            eSignatureConfirmation.value = finalConfirmation.checked ? '1' : '0';
        }
        submissionConfirmed = false;
        syncSelectedItems();
    });

    syncRequestingUnitProfile();
    syncStudentFields();
    syncRequestPremises();
    syncDateContext();
    renderCatalog();
    syncSelectedItems();
    refreshAvailability();
    showStage(resumeStage, false);
    syncForwardActionStates();
});
</script>

<script>
// SPMU_CREATE_REQUEST_UNSAVED_GUARD_START
// Warns before leaving Create/Edit Request with unsaved changes. It never
// submits, saves, or deletes anything, so an existing saved draft is untouched.
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('request-form');
    const dialog = document.querySelector('[data-request-leave-dialog]');

    if (!form || !dialog) return;

    const stayButton = dialog.querySelector('.request-dialog-actions [data-request-leave-stay]');
    const confirmButton = dialog.querySelector('[data-request-leave-confirm]');

    // Values written by scripts or the submit flow, not entered by the borrower.
    const ignoredNames = new Set(['_token', '_method', '_action_token', 'intent', 'confirm_e_signature']);

    // A redisplayed form after failed validation holds input that was NOT saved.
    const startsDirty = @json($errors->any());

    let baseline = null;
    let submitting = false;
    let leaving = false;
    let submitResetTimer = null;
    let pendingHref = null;
    let returnFocus = null;

    const snapshot = () => JSON.stringify(
        Array.from(form.elements)
            .filter((field) => {
                const type = (field.type || '').toLowerCase();

                return field.name
                    && !field.disabled
                    && !ignoredNames.has(field.name)
                    && !['submit', 'button', 'reset', 'image'].includes(type);
            })
            .map((field) => {
                const type = (field.type || '').toLowerCase();

                if (type === 'checkbox' || type === 'radio') {
                    return [field.name, field.value, field.checked];
                }

                if (type === 'file') {
                    return [field.name, Array.from(field.files || [])
                        .map((file) => `${file.name}:${file.size}:${file.lastModified}`)];
                }

                return [field.name, field.value];
            })
    );

    /*
     * The baseline is taken at the borrower's first real interaction, after
     * the page's own initialization has populated derived values (division,
     * office, normalized quantities, E-sign flag). Script-driven changes made
     * before that point are therefore never reported as unsaved changes.
     */
    const captureBaseline = (event) => {
        if (baseline === null && event.isTrusted) {
            baseline = snapshot();
        }
    };

    ['pointerdown', 'keydown', 'dragenter', 'drop', 'input', 'change'].forEach((eventName) => {
        document.addEventListener(eventName, captureBaseline, true);
    });

    const isDirty = () => startsDirty || (baseline !== null && snapshot() !== baseline);

    const markSubmitting = () => {
        submitting = true;
        window.clearTimeout(submitResetTimer);

        // Matches the existing 20-second retry window: if the submission never
        // navigates away (e.g. dropped connection), the guard becomes active again.
        submitResetTimer = window.setTimeout(() => {
            submitting = false;
        }, 20000);
    };

    /*
     * Window bubble phase runs after every form/document submit handler, so a
     * blocked or invalid submission (defaultPrevented) is never treated as an
     * accepted Save Draft or E-sign & Submit.
     */
    window.addEventListener('submit', (event) => {
        if (event.target === form && !event.defaultPrevented) {
            markSubmitting();
        }
    });

    // Older-browser fallback in the existing confirm flow uses form.submit(),
    // which fires no submit event.
    document.querySelector('[data-confirm-request-submit]')?.addEventListener('click', () => {
        if (typeof form.requestSubmit !== 'function') {
            markSubmitting();
        }
    });

    const openLeaveDialog = (href, trigger) => {
        pendingHref = href;
        returnFocus = trigger;
        dialog.hidden = false;
        document.body.classList.add('request-dialog-open');
        stayButton?.focus();
    };

    const closeLeaveDialog = () => {
        dialog.hidden = true;
        pendingHref = null;

        if (!document.querySelector('.request-dialog-backdrop:not([hidden])')) {
            document.body.classList.remove('request-dialog-open');
        }

        returnFocus?.focus?.();
        returnFocus = null;
    };

    /*
     * Same-window links only (Cancel, sidebar, top bar, in-page links to other
     * modules). Runs in the window bubble phase so links already handled by
     * other scripts (defaultPrevented) are left alone. The intended
     * destination is kept and used after confirmation.
     */
    window.addEventListener('click', (event) => {
        if (
            leaving
            || event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
        ) {
            return;
        }

        const link = event.target instanceof Element
            ? event.target.closest('a[href]')
            : null;

        if (!link || dialog.contains(link) || link.hasAttribute('download')) return;

        const target = (link.getAttribute('target') || '').toLowerCase();
        if (target && target !== '_self') return;

        let url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (error) {
            return;
        }

        if (!['http:', 'https:'].includes(url.protocol)) return;

        // In-page anchors (e.g. Terms and Conditions) do not leave the page.
        if (
            url.origin === window.location.origin
            && url.pathname === window.location.pathname
            && url.search === window.location.search
        ) {
            return;
        }

        if (!isDirty()) return;

        event.preventDefault();
        openLeaveDialog(url.href, link);
    });

    dialog.querySelectorAll('[data-request-leave-stay]').forEach((button) => {
        button.addEventListener('click', closeLeaveDialog);
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeLeaveDialog();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !dialog.hidden) closeLeaveDialog();
    });

    confirmButton?.addEventListener('click', () => {
        if (!pendingHref) return;

        leaving = true;
        window.location.assign(pendingHref);
    });

    /*
     * Refresh, browser Back/Forward to another page, tab/window close, and
     * other forms (e.g. Logout). The browser shows its own native text.
     * Switching tabs does not unload the page, so it is never affected.
     */
    window.addEventListener('beforeunload', (event) => {
        if (
            leaving
            || submitting
            // The layout already ended an idle session; nothing can be saved.
            || document.documentElement.classList.contains('spmu-session-ended')
            || !isDirty()
        ) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });

    // Returning via the back/forward cache after leaving or submitting.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;

        if (submitting) {
            baseline = snapshot();
        }

        submitting = false;
        leaving = false;
    });
});
// SPMU_CREATE_REQUEST_UNSAVED_GUARD_END
</script>

<style>
/* E-SIGN CONFIRM MODAL ENHANCEMENTS START */

/* Title */
[data-esign-modal-title] {
    margin:0 !important;
    color:var(--heading, #0f2d57) !important;
    font-size:2rem !important;
    font-weight:800 !important;
    line-height:1.2 !important;
}

/* Intro */
[data-esign-modal-intro] {
    margin:0 !important;
    color:var(--text-secondary, #4a6284) !important;
    font-size:1rem !important;
    line-height:1.65 !important;
}

/* Notice / confirmation box */
[data-esign-modal-note] {
    margin-top:14px !important;
    padding:16px 18px !important;

    border:1px solid #b9d6f4 !important;
    border-radius:10px !important;

    background:#f4f8ff !important;
    color:var(--text-secondary, #4a6284) !important;

    font-size:.97rem !important;
    line-height:1.65 !important;
}

[data-esign-modal-note] a {
    color:#1769e0 !important;
    font-weight:700 !important;
    text-decoration:underline !important;
}

/* Secondary CTA */
[data-esign-cancel] {
    min-width:132px !important;
    min-height:44px !important;

    border:1px solid #b7c5d9 !important;
    border-radius:10px !important;

    background:#fff !important;
    color:#3d526f !important;

    font-weight:700 !important;

    box-shadow:none !important;
}

/* Primary CTA */
[data-esign-confirm] {
    min-width:180px !important;
    min-height:44px !important;

    border:1px solid #1d63d8 !important;
    border-radius:10px !important;

    background:linear-gradient(180deg, #2d7df6 0%, #1769e0 100%) !important;
    color:#fff !important;

    font-weight:800 !important;

    box-shadow:
        0 8px 18px rgba(23, 105, 224, .18) !important;
}

[data-esign-confirm]:hover {
    filter:brightness(.98) !important;
}

[data-esign-confirm]:focus,
[data-esign-cancel]:focus {
    outline:none !important;
    box-shadow:0 0 0 3px rgba(23, 105, 224, .14) !important;
}

/* Slightly calmer spacing on narrow screens */
@media (max-width:640px) {
    [data-esign-modal-title] {
        font-size:1.45rem !important;
    }

    [data-esign-confirm],
    [data-esign-cancel] {
        min-width:0 !important;
        width:100% !important;
    }
}

/* E-SIGN CONFIRM MODAL ENHANCEMENTS END */
</style>



@endsection

<!-- SPMU-QUANTITY-WHOLE-NUMBER-FIX:START -->

<style>
    /*
     * Quantity fields:
     * - whole numbers only
     * - remove browser number spinner arrows
     */

    input[type="number"][name^="quantities"]::-webkit-inner-spin-button,
    input[type="number"][name^="quantities"]::-webkit-outer-spin-button {
        -webkit-appearance: none !important;
        margin: 0 !important;
    }

    input[type="number"][name^="quantities"] {
        -moz-appearance: textfield !important;
        appearance: textfield !important;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const quantitySelector =
        'input[type="number"][name^="quantities"]';

    const isQuantityInput = (element) =>
        element instanceof HTMLInputElement &&
        element.matches(quantitySelector);

    /*
     * Make every current/future quantity input integer-only.
     *
     * Do NOT set min="1" here: unselected rows keep their template default
     * value of 0 while hidden, and forcing min="1" makes that 0 natively
     * invalid on a field the browser cannot focus (it's inside a hidden
     * row) — the browser then silently blocks the whole form submission
     * (native constraint validation fails before the JS submit handler
     * ever runs) any time not every catalog item is selected. Whole-number
     * / minimum-1 enforcement for an actually selected quantity is already
     * handled by the input/paste/change handlers below plus backend
     * validation, so no native min constraint is needed here.
     */
    const configureQuantityInput = (input) => {
        if (!isQuantityInput(input)) {
            return;
        }

        input.setAttribute('step', '1');
        input.setAttribute('inputmode', 'numeric');
    };

    document
        .querySelectorAll(quantitySelector)
        .forEach(configureQuantityInput);

    /*
     * Create Request can dynamically add selected-item rows,
     * so configure quantity fields added later as well.
     */
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }

                if (isQuantityInput(node)) {
                    configureQuantityInput(node);
                }

                node
                    .querySelectorAll?.(quantitySelector)
                    .forEach(configureQuantityInput);
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    /*
     * Prevent mouse wheel / touchpad scrolling from changing
     * the focused number input.
     */
    document.addEventListener(
        'wheel',
        (event) => {
            const input = event.target;

            if (!isQuantityInput(input)) {
                return;
            }

            event.preventDefault();
            input.blur();
        },
        {
            passive: false,
            capture: true
        }
    );

    /*
     * Prevent decimal, scientific notation, plus and minus.
     */
    document.addEventListener(
        'keydown',
        (event) => {
            const input = event.target;

            if (!isQuantityInput(input)) {
                return;
            }

            if (
                ['.', ',', 'e', 'E', '+', '-'].includes(event.key)
            ) {
                event.preventDefault();
            }
        },
        true
    );

    /*
     * Handle pasted values.
     *
     * Examples:
     *  5     -> 5
     *  3.7   -> 3
     *  -4    -> rejected / minimum 1
     *  abc   -> rejected
     */
    document.addEventListener(
        'paste',
        (event) => {
            const input = event.target;

            if (!isQuantityInput(input)) {
                return;
            }

            const pasted =
                event.clipboardData?.getData('text')?.trim() ?? '';

            if (pasted === '') {
                return;
            }

            const parsed = Number.parseInt(pasted, 10);

            event.preventDefault();

            if (!Number.isFinite(parsed) || parsed < 1) {
                input.value = '';
            } else {
                input.value = String(parsed);
            }

            input.dispatchEvent(
                new Event('input', {
                    bubbles: true
                })
            );

            input.dispatchEvent(
                new Event('change', {
                    bubbles: true
                })
            );
        },
        true
    );

    /*
     * Final safety normalization.
     */
    document.addEventListener(
        'change',
        (event) => {
            const input = event.target;

            if (!isQuantityInput(input)) {
                return;
            }

            if (input.value === '') {
                return;
            }

            const value = Number(input.value);

            if (!Number.isFinite(value) || value < 1) {
                input.value = '';
                return;
            }

            input.value = String(Math.trunc(value));
        },
        true
    );
});
</script>

<!-- SPMU-QUANTITY-WHOLE-NUMBER-FIX:END -->

<!-- SPMU-QUANTITY-SCROLL-LOCK:START -->

<style>
    /*
     * Hide the browser's own number-spinner controls.
     * Keep the custom minus / plus buttons unchanged.
     */
    input[type="number"][name^="quantities"]::-webkit-inner-spin-button,
    input[type="number"][name^="quantities"]::-webkit-outer-spin-button {
        -webkit-appearance: none !important;
        appearance: none !important;
        margin: 0 !important;
    }

    input[type="number"][name^="quantities"] {
        -moz-appearance: textfield !important;
    }
</style>

<script>
(() => {
    /*
     * Targets only the center quantity field.
     *
     * Custom - and + buttons are NOT affected.
     */
    const isQuantityField = (element) => {
        return element instanceof HTMLInputElement
            && element.matches(
                'input[name^="quantities"], ' +
                'input[data-quantity-input], ' +
                '.quantity-input'
            );
    };

    /*
     * BLOCK mouse-wheel / touchpad scrolling from modifying
     * the number while the center quantity field is focused.
     */
    document.addEventListener(
        'wheel',
        (event) => {
            if (!isQuantityField(event.target)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
        },
        {
            capture: true,
            passive: false
        }
    );

    /*
     * BLOCK keyboard number stepping.
     *
     * ArrowUp / ArrowDown normally increment/decrement
     * <input type="number"> values.
     */
    document.addEventListener(
        'keydown',
        (event) => {
            if (!isQuantityField(event.target)) {
                return;
            }

            if (
                event.key === 'ArrowUp' ||
                event.key === 'ArrowDown'
            ) {
                event.preventDefault();
                return;
            }

            /*
             * Whole-number quantity only.
             */
            if (
                event.key === '.' ||
                event.key === ',' ||
                event.key === 'e' ||
                event.key === 'E' ||
                event.key === '+' ||
                event.key === '-'
            ) {
                event.preventDefault();
            }
        },
        true
    );

    /*
     * Normalize manually entered / pasted values.
     */
    document.addEventListener(
        'input',
        (event) => {
            const input = event.target;

            if (!isQuantityField(input)) {
                return;
            }

            if (input.value === '') {
                return;
            }

            /*
             * Strip anything except digits.
             */
            const clean = input.value.replace(/[^0-9]/g, '');

            if (input.value !== clean) {
                input.value = clean;
            }
        },
        true
    );
})();
</script>

<!-- SPMU-QUANTITY-SCROLL-LOCK:END -->
