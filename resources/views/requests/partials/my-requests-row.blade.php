@php
    /*
    |--------------------------------------------------------------------------
    | Borrower "My Requests" row
    |--------------------------------------------------------------------------
    |
    | This row intentionally uses the same operational-record pattern used by
    | My Borrowings and SPMU transaction lists so request records feel like one
    | system instead of a separate card design.
    |
    */
    $version = $request->currentVersion;
    $custody = $request->custody;

    $requestStatus = $request->status;
    $custodyWorkflow = $custody?->workflowStatus();

    // Shared with the server-side status filter so the badge and filter agree.
    // Uses the same Admin status classification as both staff record lists.
    ['key' => $statusKey, 'label' => $statusLabel] = $request->borrowerRecordStatus();

    $requiresAction = (
        ! $custody
        && in_array(
            $requestStatus,
            [
                App\Enums\RequestStatus::Draft,
                App\Enums\RequestStatus::ReturnedForRevision,
            ],
            true
        )
    ) || (($custodyWorkflow['key'] ?? null) === 'PICKUP_EXPIRED');

    $statusGroup = match (true) {
        $statusKey === 'COMPLETED' => 'completed',
        in_array($statusKey, ['CANCELLED', 'REJECTED'], true) => 'closed',
        in_array($statusKey, ['MISSED_PICKUP', 'OVERDUE', 'RETURNED_LATE', 'SLDDP', 'ACCOUNTABILITY_REVIEW'], true) => 'attention',
        $requiresAction => 'action',
        $statusKey === 'ON_CUSTODY' => 'custody',
        in_array($statusKey, ['PICKUP_SCHEDULED', 'FOR_ISSUANCE', 'READY_FOR_PICKUP'], true) => 'approved',
        default => 'review',
    };

    $context = $version?->isExternalAffiliation()
        ? $version->affiliationLabel()
        : ($version?->student_organization ?: ($version?->office_unit ?: $version?->location));

    $submittedAt = $version?->submitted_at ?: $request->created_at;
    $borrowingDate = $version?->schedule_date ?: $version?->needed_from;

    $itemTypes = $version?->items->count() ?? 0;
    $pieces = (int) ($version?->items->sum(
        fn ($item) => $item->approved_quantity ?? $item->requested_quantity
    ) ?? 0);

    /*
     * A DRAFT has not entered the workflow, so its card opens the editor
     * rather than a detail page with nothing to report. requests.show
     * redirects drafts to the same place, so a stale link still lands right.
     */
    $isDraft = $request->status === \App\Enums\RequestStatus::Draft;

    $rowToneClass = match (true) {
        $statusKey === 'OVERDUE' => 'is-danger',
        $statusKey === 'MISSED_PICKUP' || $requiresAction => 'is-warning',
        default => '',
    };
@endphp

<a
    class="operational-record ui-pressable {{ $rowToneClass }}"
    href="{{ $isDraft ? route('requests.edit', $request) : route('requests.show', $request) }}"
    data-request-card
    data-status="{{ $statusKey }}"
    data-status-group="{{ $statusGroup }}"
>
    <span class="operational-record-primary">
        <span class="mr-request-identity-line">
            <strong>{{ $request->request_no }}</strong>

            <x-status-badge
                :status="$statusKey"
                :label="$statusLabel"
            />
        </span>

        <span>{{ $version?->purpose_event ?: 'Borrowing request' }}</span>
        <small>{{ $context ?: 'No additional details recorded' }}</small>
    </span>

    <span class="operational-record-facts">
        <span>
            <small>Submitted</small>
            <strong>{{ $submittedAt ? $submittedAt->format('d M Y, h:i A') : 'Not yet submitted' }}</strong>
        </span>

        <span>
            <small>Borrowing date</small>
            <strong>{{ $borrowingDate ? $borrowingDate->format('d M Y') : 'Schedule pending' }}</strong>
        </span>

        <span>
            <small>Items</small>
            <strong>
                {{ $itemTypes }} {{ $itemTypes === 1 ? 'item type' : 'item types' }}
                · {{ number_format($pieces) }} {{ $pieces === 1 ? 'piece' : 'pieces' }}
            </strong>
        </span>
    </span>

    <span class="operational-record-action">
        <strong class="mr-request-action-label">
            @if($isDraft)
                <x-icon name="edit" size="15" />
                <span>Resume Draft</span>
            @else
                <span>View Request</span>
                <x-icon name="arrow-right" size="14" />
            @endif
        </strong>
    </span>
</a>
