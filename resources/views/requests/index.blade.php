@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Requests' : 'Request Records'])

@section('content')
<style>.record-browser-toolbar + .table-wrap tbody td { padding-block: 10px; }</style>
@php
    $isBorrower = session('active_workspace') === 'BORROWER';
    $classification = auth()->user()?->access_classification?->value;
    $isActionOfficer = ! $isBorrower && $classification === 'SPMU_OFFICER';
    $isSpmuHead = ! $isBorrower && $classification === 'SPMU_HEAD';
    $returnMetrics = app(\App\Services\ReturnMetricsService::class);

    /*
     * Request Records uses one short, role-specific label for each current
     * borrowing stage. Accountability sub-stages and retired workflow names
     * are grouped into the few statuses that are useful in this record list.
     */
    $requestRecordStatus = static function ($request) use ($isBorrower, $isActionOfficer, $isSpmuHead, $returnMetrics): array {
        if ($isActionOfficer) {
            return \App\Support\ActionOfficerStatusPresentation::forRequest($request);
        }

        if ($request->custody) {
            $custody = $request->custody;
            $workflow = $custody->workflowStatus();
            $workflowKey = $workflow['key'];
            $accountabilityKeys = [
                'INCIDENT_OPEN', 'OBLIGATION_OPEN', 'FOR_BILLING',
                'BILLING_PENDING', 'BILLING_OPEN', 'BILLING_ISSUED',
                'PAYMENT_VERIFICATION', 'RECEIPT_SUBMITTED',
                'COMPLIANCE_REQUIRED', 'COMPLIANCE_RSLDDP_PENDING',
                'F2F_PROCESSING', 'RSLDDP_AWAITING_UPLOAD',
                'RSLDDP_FOR_ACCOUNTING_PROCESSING', 'RSLDDP_PAYMENT_REQUIRED',
                'RSLDDP_DISPOSITION_PENDING', 'RSLDDP_COMPLIANCE_VERIFICATION',
                'RSLDDP_FOR_RESOLUTION', 'BORROWING_RESTRICTED', 'LATE_RETURN',
            ];
            $slldpKeys = [
                'F2F_PROCESSING', 'COMPLIANCE_RSLDDP_PENDING',
                'RSLDDP_AWAITING_UPLOAD', 'RSLDDP_FOR_ACCOUNTING_PROCESSING',
                'RSLDDP_PAYMENT_REQUIRED', 'RSLDDP_DISPOSITION_PENDING',
                'RSLDDP_COMPLIANCE_VERIFICATION', 'RSLDDP_FOR_RESOLUTION',
            ];

            if (in_array($workflowKey, $accountabilityKeys, true)) {
                if ($isBorrower) {
                    // The borrowing/return transaction is done; its separate
                    // Accountability case remains available in My Obligations.
                    return ['key' => 'COMPLETED', 'label' => 'Completed', 'group' => 'completed'];
                }

                if ($isActionOfficer || in_array($workflowKey, $slldpKeys, true)) {
                    return ['key' => 'SLDDP', 'label' => 'SLDDP', 'group' => 'attention'];
                }

                return ['key' => 'ACCOUNTABILITY_REVIEW', 'label' => 'Accountability Review', 'group' => 'attention'];
            }

            if (($isActionOfficer || $isSpmuHead)
                && $returnMetrics->state($custody) === \App\Services\ReturnMetricsService::RETURNED_LATE) {
                return ['key' => 'RETURNED_LATE', 'label' => 'Returned Late', 'group' => 'attention'];
            }

            return match ($workflowKey) {
                'PICKUP_EXPIRED' => ['key' => 'MISSED_PICKUP', 'label' => 'Missed Pickup', 'group' => 'attention'],
                'PICKUP_SCHEDULING', 'ITEM_PREPARATION', 'PREPARING_RELEASE' => [
                    'key' => 'FOR_ISSUANCE',
                    'label' => 'For Issuance',
                    'group' => 'release',
                ],
                'READY_FOR_RELEASE' => ['key' => 'READY_FOR_PICKUP', 'label' => 'Ready for Pickup', 'group' => 'release'],
                'BORROWED' => [
                    'key' => 'ON_CUSTODY',
                    'label' => 'On Custody',
                    'group' => 'custody',
                ],
                'RETURN_PROCESSING', 'LINEN_PENDING', 'GATE_PASS_PENDING' => $isActionOfficer
                    ? ['key' => 'RETURN_INSPECTION', 'label' => 'Return Inspection', 'group' => 'return']
                    : ['key' => 'ON_CUSTODY', 'label' => 'On Custody', 'group' => 'custody'],
                'BORROWER_CLEARED' => ['key' => 'COMPLETED', 'label' => 'Completed', 'group' => 'completed'],
                default => $workflow,
            };
        }

        $status = $request->status;
        $rawKey = strtoupper($status instanceof BackedEnum ? $status->value : (string) $status);

        return match ($rawKey) {
            'DRAFT' => ['key' => 'DRAFT', 'label' => 'Draft', 'group' => 'request'],
            'SIGNED', 'SUBMITTED' => [
                'key' => $isBorrower ? 'FOR_REVIEW' : ($isActionOfficer ? 'FOR_VERIFICATION' : 'FOR_APPROVAL'),
                'label' => $isBorrower ? 'For Review' : ($isActionOfficer ? 'For Verification' : 'For Approval'),
                'group' => 'request',
            ],
            'UNDER_SPMU', 'UNDER_GSU', 'UNDER_VPAF' => (function () use ($request, $isBorrower, $isActionOfficer, $isSpmuHead): array {
                if ($isBorrower) {
                    return [
                        'key' => 'FOR_REVIEW',
                        'label' => 'For Review',
                        'group' => 'request',
                    ];
                }

                if ($isActionOfficer) {
                    $steps = $request->currentVersion?->approvalSteps ?? collect();
                    $verificationPending = $steps->contains(
                        fn ($step) => (int) $step->sequence_no === 1
                            && strtoupper((string) ($step->stage_code instanceof BackedEnum ? $step->stage_code->value : $step->stage_code)) === 'SPMU'
                            && in_array((string) $step->decision, ['PENDING', 'RECEIVED'], true)
                    );

                    if ($verificationPending) {
                        return [
                            'key' => 'FOR_VERIFICATION',
                            'label' => 'For Verification',
                            'group' => 'request',
                        ];
                    }
                }

                return [
                    'key' => 'FOR_APPROVAL',
                    'label' => $isSpmuHead ? 'For Approval' : 'For Approval',
                    'group' => 'request',
                ];
            })(),
            'RETURNED_FOR_REVISION' => [
                'key' => 'NEEDS_REVISION',
                'label' => 'Needs Revision',
                'group' => 'request',
            ],
            'FINAL_APPROVED_AWAITING_DOWNLOAD', 'APPROVED_READY_FOR_RELEASE' => [
                'key' => 'FOR_ISSUANCE',
                'label' => 'For Issuance',
                'group' => 'request',
            ],
            'REJECTED' => ['key' => 'REJECTED', 'label' => 'Rejected', 'group' => 'request'],
            'CANCELLED' => ['key' => 'CANCELLED', 'label' => 'Cancelled', 'group' => 'request'],
            'EXPIRED' => ['key' => 'CANCELLED', 'label' => 'Cancelled', 'group' => 'request'],
            default => [
                'key' => $rawKey,
                'label' => str($rawKey)->replace('_', ' ')->lower()->title()->toString(),
                'group' => 'request',
            ],
        };
    };

    $requestStatusOrder = [
        'DRAFT' => 10,
        'FOR_REVIEW' => 20,
        'FOR_VERIFICATION' => 20,
        'FOR_APPROVAL' => 20,
        'NEEDS_REVISION' => 30,
        'PICKUP_SCHEDULED' => 40,
        'FOR_ISSUANCE' => 50,
        'READY_FOR_PICKUP' => 60,
        'MISSED_PICKUP' => 70,
        'ON_CUSTODY' => 80,
        'RETURN_INSPECTION' => 90,
        'OVERDUE' => 100,
        'RETURNED_LATE' => 110,
        'SLDDP' => 120,
        'ACCOUNTABILITY_REVIEW' => 130,
        'COMPLETED' => 140,
        'CANCELLED' => 150,
        'REJECTED' => 160,
    ];

    /*
     * Keep current record wording and add supported stages with no rows.
     * Retired GSU/VPAF stages remain normalized for historical records.
     */
    $requestRecordsForFilters = method_exists($requests, 'items')
        ? collect($requests->items())
        : collect($requests);

    // Keep only the concise statuses supported by this role, even when no
    // current row is in one of those stages. Internal and legacy keys never
    // become filter options.
    $supportedStatusLabels = [
        ...($isBorrower ? ['DRAFT' => 'Draft', 'FOR_REVIEW' => 'For Review'] : []),
        ...($isActionOfficer ? ['FOR_VERIFICATION' => 'For Verification'] : []),
        ...($isSpmuHead ? ['FOR_APPROVAL' => 'For Approval'] : []),
        'NEEDS_REVISION' => 'Needs Revision',
        'PICKUP_SCHEDULED' => 'Pickup Scheduled',
        'FOR_ISSUANCE' => 'For Issuance',
        'READY_FOR_PICKUP' => 'Ready for Pickup',
        'MISSED_PICKUP' => 'Missed Pickup',
        'ON_CUSTODY' => 'On Custody',
        ...($isActionOfficer ? ['RETURN_INSPECTION' => 'Return Inspection'] : []),
        'OVERDUE' => 'Overdue',
        ...(($isActionOfficer || $isSpmuHead) ? ['RETURNED_LATE' => 'Returned Late', 'SLDDP' => 'SLDDP'] : []),
        ...($isSpmuHead ? ['ACCOUNTABILITY_REVIEW' => 'Accountability Review'] : []),
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled',
        'REJECTED' => 'Rejected',
    ];
    $requestFilterStatuses = $isActionOfficer
        ? collect(\App\Support\ActionOfficerStatusPresentation::filters())
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
            ->values()
        : $requestRecordsForFilters->map($requestRecordStatus)
        ->concat(collect($supportedStatusLabels)->map(fn ($label, $key) => ['key' => $key, 'label' => $label]))
        ->unique('key')
        ->sortBy(fn (array $status): int => $requestStatusOrder[$status['key']] ?? 999)
        ->values();
@endphp

<section class="page-heading">
    <div>
        @if($isBorrower)
            <p class="eyebrow">Request tracking</p>
        @endif
        <h1>{{ $isBorrower ? 'My Requests' : 'Request Records' }}</h1>

        @if($isBorrower)
            <p>Review your borrowing requests, current status, required actions, and release progress.</p>
        @else
            <p>View borrowing requests and their transaction history.</p>
        @endif
    </div>

    @if($isBorrower)
        <a class="button primary ui-pressable" href="{{ route('requests.create') }}">
            <x-icon name="plus" size="17" />
            Create new request
        </a>
    @endif
</section>

<section class="content-area{{ $isActionOfficer ? ' sao-records-workspace' : '' }}">
@if($activeCustodiesFilter ?? false)
    <x-filter-context-banner label="Active Custodies" :count="$requests->count()" :clear-url="route('requests.index')" />
@endif
@if($isBorrower)

<div class="my-requests">
    @if($requests->total() === 0 && ($borrowerRequestFilters['active'] ?? false))
        @include('requests.partials.my-requests-toolbar')

        <section class="mr-empty mr-filter-empty" aria-live="polite">
            @include('requests.partials.my-requests-empty-filtered')
        </section>
    @elseif($requests->total() === 0)
        @include('requests.partials.my-requests-toolbar')

        @include('requests.partials.my-requests-empty')
    @else
        @include('requests.partials.my-requests-toolbar')

        @include('requests.partials.my-requests-results')
    @endif
</div>

@else

    <div class="record-browser-toolbar" data-record-browser-toolbar>
        <label class="record-browser-search">Search
            <span class="search-input-shell">
                <span class="search-input-icon" aria-hidden="true"><x-icon name="search" /></span>
                <input type="search" data-record-search placeholder="Search request no., borrower, event, or item..." autocomplete="off">
            </span>
        </label>
        <label>Status
            <select data-record-status-filter>
                <option value="all">All statuses</option>
                @if($isActionOfficer)
                    <option value="NEEDS_ATTENTION">Needs Attention</option>
                @endif
                @foreach($requestFilterStatuses as $filterStatus)
                    <option value="{{ $filterStatus['key'] }}">{{ $filterStatus['label'] }}</option>
                @endforeach
            </select>
        </label>
        <label>Sort
            <select data-record-sort><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select>
        </label>
    </div>

    @if($isActionOfficer)
        <div class="card sao-record-results">
    @endif
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Request</th>
                    <th>Borrower</th>
                    <th>Purpose and Borrowing Period</th>
                    <th>Item Types</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
            @forelse($requests as $request)
                @php
                    $recordWorkflowStatus = $requestRecordStatus($request);
                    $recordStatus = $recordWorkflowStatus['key'];
                    $recordStatusLabel = $recordWorkflowStatus['label'];
                    $recordFilterKey = $recordWorkflowStatus['filter'] ?? $recordStatus;
                    $recordNotes = $recordWorkflowStatus['notes'] ?? [];
                    $recordNeededFrom = $request->currentVersion?->needed_from;
                    $recordFromFormat = $recordNeededFrom?->format('H:i:s') === '00:00:00'
                        ? 'd M Y'
                        : 'd M Y, g:i A';
                    $recordSearch = strtolower(trim(
                        $request->request_no.' '.
                        ($request->borrower?->full_name ?? '').' '.
                        ($request->currentVersion?->purpose_event ?? '').' '.
                        $request->currentVersion?->items?->map(fn($ri) => $ri->inventoryItem?->unique_description)->filter()->implode(' ').' '.
                        $recordStatusLabel.' '.implode(' ', $recordNotes)
                    ));
                @endphp
                <tr data-request-record data-search="{{ $recordSearch }}" data-status="{{ $recordFilterKey }}" data-status-key="{{ $recordStatus }}" data-attention="{{ !empty($recordWorkflowStatus['attention']) ? '1' : '0' }}" data-created="{{ optional($request->created_at)->timestamp ?? 0 }}">
                    <td>
                        <strong>{{ $request->request_no }}</strong>
                        <small>Version {{ $request->current_version_no }}</small>
                    </td>

                    <td>{{ $request->borrower->full_name }}</td>

                    <td>
                        {{ $request->currentVersion?->purpose_event }}

                        <small>
                            {{ $recordNeededFrom?->format($recordFromFormat) }}
                            &ndash;
                            {{ optional($request->currentVersion?->return_due_at)->format('d M Y') }}
                        </small>
                    </td>

                    <td>
                        {{ $request->currentVersion?->items->count() ?? 0 }}
                    </td>

                    <td>
                        <x-status-badge
                            :status="$recordStatus"
                            :label="$recordStatusLabel"
                        />
                        @foreach($recordNotes as $recordNote)
                            <small data-record-status-detail>
                                @if($recordNote === 'Overdue')
                                    <x-status-badge status="OVERDUE" :label="$recordNote" />
                                @else
                                    {{ $recordNote }}
                                @endif
                            </small>
                        @endforeach
                    </td>

                    <td>
                        <a class="table-action" href="{{ route('requests.show', $request) }}">
                            <span>View Details</span>
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="empty-state">
                        No borrowing requests found.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="empty-state top-gap" data-record-empty hidden><strong>No matching request records.</strong><span>Try another search term or status.</span></div>
    @unless($isBorrower)
        <div class="sao-record-pagination" data-sao-record-pagination hidden>
            <div class="sao-record-pagination-meta">
                <span class="meta" data-sao-record-range aria-live="polite"></span>
                <label class="sr-only" for="sao-record-page-size">Request records per page</label>
                <select id="sao-record-page-size" data-sao-record-page-size aria-label="Request records per page">
                    @foreach([7, 15, 30, 50] as $size)<option value="{{ $size }}" @selected($size === 7)>{{ $size }} entries</option>@endforeach
                </select>
            </div>
            <nav class="app-pagination" aria-label="Request Records pages">
                <button class="app-pagination-link" type="button" data-sao-record-previous aria-label="Previous page"><x-icon name="arrow-left" size="14" /></button>
                <span data-sao-record-pages></span>
                <button class="app-pagination-link" type="button" data-sao-record-next aria-label="Next page"><x-icon name="arrow-right" size="14" /></button>
            </nav>
        </div>
    @endunless
    @if($isActionOfficer)
        </div>
    @endif

@endif
</section>

@unless($isBorrower)
<style>
.record-browser-toolbar{display:grid;grid-template-columns:minmax(260px,1fr) minmax(170px,220px) minmax(150px,190px);gap:12px;align-items:end;margin-bottom:14px;padding:14px;background:var(--surface-elevated);border:1px solid var(--border);border-radius:var(--radius)}
.record-browser-toolbar label{display:grid;gap:6px;font-size:12px;font-weight:800;color:var(--text-muted)}
.record-browser-toolbar input,.record-browser-toolbar select{width:100%;min-height:42px}
@media(max-width:760px){.record-browser-toolbar{grid-template-columns:1fr}}
.sao-record-pagination{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:16px}.sao-record-pagination .app-pagination{margin:0}
.sao-record-pagination-meta{display:flex;align-items:center;flex-wrap:wrap;gap:10px}.sao-record-pagination-meta select{min-height:32px;padding:4px 28px 4px 9px;font-size:11px}
.sao-record-pagination nav{display:flex;align-items:center;gap:12px}
.sao-record-pagination[hidden]{display:none}
.sao-records-workspace{display:flex;flex-direction:column;gap:14px;min-width:0}
.app-shell .app-main .sao-records-workspace > .record-browser-toolbar{flex:0 0 auto;margin-bottom:0 !important}
.app-shell .app-main .sao-records-workspace > .sao-record-results{display:flex;flex-direction:column;flex:1 1 auto;min-height:320px;padding:0 !important;overflow:hidden}
.sao-record-results > .table-wrap{flex:0 0 auto;width:100%;border:0;border-radius:0;box-shadow:none;overflow-x:auto}
.sao-record-results table th{font-size:11px;padding:10px 14px}
.sao-record-results table td{font-size:12.5px;padding:10px 14px}
.sao-record-results .table-wrap tbody tr[data-request-record] > td{border-bottom:1px solid var(--border)}
.sao-record-results > .sao-record-pagination{flex:0 0 auto;margin-top:auto;padding:14px 16px;border-top:1px solid var(--border)}
.sao-record-results > [data-record-empty]{flex:1 1 auto;margin:0;padding:24px;display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center}
.sao-record-results > [data-record-empty][hidden]{display:none}
@media(max-width:600px){.sao-record-results > .sao-record-pagination{justify-content:center;padding:12px;gap:8px}.sao-record-pagination nav{gap:8px}}
</style>
@include('requests.partials.sao-record-pagination')
@endunless

@if($isBorrower)
@include('requests.partials.my-requests-styles')
@include('requests.partials.my-requests-scripts')
@endif

@endsection
