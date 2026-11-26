@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Borrowings' : (auth()->user()?->access_classification?->value === 'SPMU_HEAD' ? 'Release & Return Oversight' : (($spmuMode ?? null) === 'return' ? 'Return' : 'Release'))])

@section('content')

@php
    $isBorrower = session('active_workspace') === 'BORROWER';
    $isHead = auth()->user()?->access_classification?->value === 'SPMU_HEAD';
    $mode = $spmuMode ?? null;

    $borrowerKpiFilterLabels = [
        'active_borrowings' => 'Active Borrowings',
        'upcoming_pickup' => 'Upcoming Pickup',
        'returns_due' => 'Returns Due',
    ];
    $borrowerKpiFilterLabel = $borrowerKpiFilterLabels[$borrowerKpiFilter ?? ''] ?? null;

    $pageTitle = $isBorrower
        ? 'My Borrowings'
        : ($isHead
            ? 'Release & Return Oversight'
            : ($mode === 'return' ? 'Return' : 'Release'));

    $pageCopy = match (true) {
        $isBorrower => 'View all submitted requests, ongoing transactions, and completed records.',
        $mode === 'release' => 'Confirm item preparation, print the required documents, and record the handover.',
        $mode === 'return' => 'Inspect physically returned items, record full-quantity accounting, monitor linen/laundry return, and complete reconciliation.',
        $isHead => 'Monitor item release, active custody, returns, and overdue transactions.',
        default => null,
    };

    $isOversightView = ! $isBorrower && $isHead && $mode === null;

    $groupForCustody = fn ($custody): string => $custody->workflowStatus()['group'];

    $priorityForCustody = fn ($custody): int => match ($groupForCustody($custody)) {
        'attention' => 1,
        'return' => 2,
        'custody' => 3,
        'release' => 4,
        'active' => 5,
        'cancelled' => 8,
        'completed' => 9,
        default => 8,
    };


    // My Borrowings search/status/sort run on the server (see CustodyController::index).
    $borrowerFilters = $borrowerFilters ?? ['search' => '', 'status' => 'all', 'sort' => 'newest', 'per_page' => 7, 'active' => false];
    $borrowerStatusFilters = $borrowerStatusFilters ?? [];
    $borrowerPerPageOptions = $borrowerPerPageOptions ?? [7, 15, 30, 50];

    $oversightTabs = [
        'all' => 'All',
        'active' => 'Active',
        'attention' => 'Needs Attention',
        'release' => 'Release Queue',
        'custody' => 'On Custody',
        'return' => 'Return Processing',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    $oversightTabIcons = [
        'all' => 'dashboard',
        'active' => 'check-circle',
        'attention' => 'warning',
        'release' => 'clock',
        'custody' => 'box',
        'return' => 'cycle',
        'completed' => 'check-circle',
        'cancelled' => 'close',
    ];

    $oversightCounts = $isOversightView
        ? [
            'active' => $custodies->filter(fn ($custody) => ! in_array($groupForCustody($custody), ['completed', 'cancelled'], true))->count(),
            'attention' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'attention')->count(),
            'release' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'release')->count(),
            'custody' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'custody')->count(),
            'return' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'return')->count(),
            'completed' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'completed')->count(),
            'cancelled' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'cancelled')->count(),
            'all' => $custodies->count(),
        ]
        : [];


    /*
     * The Return workspace is for the physical handover only. Financial,
     * accountability, gate-pass, and laundry work have their own areas, so
     * they must not turn this filter into a second accountability queue.
     */
    $returnOperationalStatusForCustody = static function ($custody): array {
        if ((string) $custody->status === 'OVERDUE') {
            return ['key' => 'OVERDUE', 'label' => 'Overdue'];
        }

        if (in_array((string) $custody->status, ['RETURN_PROCESSING', 'PARTIALLY_RETURNED', 'EARLY_RETURN'], true)) {
            return ['key' => 'RETURN_INSPECTION', 'label' => 'Return Processing'];
        }

        return ['key' => 'ON_CUSTODY', 'label' => 'On Custody'];
    };

    /*
     * Keep each operational filter stable even when its queue is empty. The
     * options below are stable even when a queue is empty, so an Action
     * Officer can always filter by a valid physical-return stage.
     */
    $releaseFilterStatuses = collect([
        ['key' => 'FOR_PREPARATION', 'label' => 'For Preparation'],
        ['key' => 'PICKUP_SCHEDULED', 'label' => 'Pickup Scheduled'],
        ['key' => 'READY_FOR_RELEASE', 'label' => 'Ready for Release'],
        ['key' => 'PICKUP_EXPIRED', 'label' => 'Pickup Missed'],
    ]);

    $returnFilterStatuses = collect([
        ['key' => 'ON_CUSTODY', 'label' => 'On Custody'],
        ['key' => 'RETURN_INSPECTION', 'label' => 'Return Processing'],
        ['key' => 'OVERDUE', 'label' => 'Overdue'],
    ]);

    $operationalFilterStatuses = match ($mode) {
        'release' => $releaseFilterStatuses,
        'return' => $returnFilterStatuses,
        default => collect(),
    };
@endphp

@if($isOversightView)
    @include('custody.partials.oversight-styles')
@elseif($isBorrower)
    @include('custody.partials.borrowings-styles')
@endif

<section class="page-heading">
    <div>
        <p class="eyebrow">
            {{ $mode === 'return' ? 'Physical return and reconciliation' : ($mode === 'release' ? 'Pickup and physical issuance' : ($isBorrower ? 'All transaction records' : 'Pickup, issuance and return')) }}
        </p>

        <h1>{{ $pageTitle }}</h1>

        @if($pageCopy)
            <p>{{ $pageCopy }}</p>
        @endif
    </div>
</section>

@if($isOversightView)
    <div class="content-area custody-oversight" data-custody-oversight>
        @if(($headKpiFilter ?? null) === 'active_custodies')
            <x-filter-context-banner label="Active Custodies" :count="$custodies->count()" :clear-url="route('custody.index')" />
        @endif

        @include('custody.partials.oversight-toolbar')

        @if($custodies->isEmpty())
            @include('custody.partials.oversight-empty', [
                'emptyId' => null,
                'emptyHidden' => false,
                'emptyTitle' => 'No transactions to display.',
                'emptyMessage' => 'Release and return transactions will appear here when available.',
            ])
        @else
            <div class="custody-oversight-section-head">
                <h2>Transactions</h2>

                <span
                    class="custody-oversight-record-count"
                    id="custody-oversight-result-summary"
                    role="status"
                    aria-live="polite"
                >
                    {{ $custodies->count() }} {{ $custodies->count() === 1 ? 'record' : 'records' }}
                </span>
            </div>

            <div
                id="custody-oversight-list"
                class="custody-oversight-list"
                aria-label="Release and return oversight records"
            >
                @foreach($custodies as $custody)
                    @include('custody.partials.oversight-row')
                @endforeach
            </div>

            @include('custody.partials.oversight-empty', [
                'emptyId' => 'custody-oversight-no-results',
                'emptyHidden' => true,
                'emptyTitle' => 'No matching release or return transaction.',
                'emptyMessage' => 'Try another status tab, clear the search, or adjust the date range.',
            ])

            <div class="custody-oversight-footer" id="custody-oversight-footer">
                <label class="custody-oversight-page-size">
                    Show
                    <select id="custody-oversight-page-size" aria-label="Transactions per page">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    per page
                </label>

                <div
                    class="custody-oversight-pagination"
                    id="custody-oversight-pagination"
                    role="group"
                    aria-label="Release and return oversight pages"
                ></div>
            </div>
        @endif
    </div>

    @include('custody.partials.oversight-interactions')
@elseif($isBorrower)
    <div class="content-area my-borrowings" data-my-borrowings>
        @if($borrowerKpiFilterLabel)
            <x-filter-context-banner :label="$borrowerKpiFilterLabel" :count="$borrowerRecords->total()" :clear-url="route('custody.index')" />
        @endif

        {{-- GET form: values stay in the URL, and changing them starts again at page 1. --}}
        <form class="borrowings-toolbar" id="borrowings-filters" method="GET" action="{{ route('custody.index') }}" role="search" aria-label="Filter my borrowings">
            @if($borrowerKpiFilter ?? null)
                <input type="hidden" name="kpi" value="{{ $borrowerKpiFilter }}">
            @endif

            <label>
                Search
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                    <input
                        id="borrowings-search"
                        name="search"
                        type="search"
                        value="{{ $borrowerFilters['search'] }}"
                        maxlength="100"
                        placeholder="Search request no., purpose, or item..."
                        autocomplete="off"
                    >
                </span>
            </label>

            <label>
                Status
                <select id="borrowings-status" name="status">
                    <option value="all">All statuses</option>
                    @foreach($borrowerStatusFilters as $statusKey => $statusLabel)
                        <option value="{{ $statusKey }}" @selected($borrowerFilters['status'] === $statusKey)>{{ $statusLabel }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                Sort
                <select id="borrowings-sort" name="sort">
                    <option value="newest" @selected($borrowerFilters['sort'] === 'newest')>Newest first</option>
                    <option value="oldest" @selected($borrowerFilters['sort'] === 'oldest')>Oldest first</option>
                </select>
            </label>

            <noscript><button class="button secondary" type="submit">Apply</button></noscript>
        </form>

        @if($borrowerRecords->total() === 0)
            <div class="my-borrowings-card">
                @if($borrowerFilters['active'])
                    @include('custody.partials.borrowings-empty', [
                        'emptyHidden' => false,
                        'emptyTitle' => 'No matching borrowings.',
                        'emptyMessage' => 'Try another search term or status.',
                        'emptyActionUrl' => route('custody.index', ($borrowerKpiFilter ?? null) ? ['kpi' => $borrowerKpiFilter] : []),
                        'emptyActionLabel' => 'Clear filters',
                    ])
                @else
                    @include('custody.partials.borrowings-empty', [
                        'emptyHidden' => false,
                        'emptyTitle' => 'No borrowing records yet.',
                        'emptyMessage' => 'Submitted requests will appear here, including ongoing, completed, cancelled, and rejected records.',
                    ])
                @endif
            </div>
        @else
            <div class="borrowings-results-head">
                <strong id="borrowings-result-summary" role="status" aria-live="polite">
                    {{ $borrowerRecords->total() }} {{ $borrowerRecords->total() === 1 ? 'borrowing record' : 'borrowing records' }}
                </strong>
            </div>

            <div class="operational-record-list borrowings-list" id="borrowings-list">
                @foreach($borrowerRecords as $record)
                    @include('custody.partials.borrowings-row', ['record' => $record])
                @endforeach
            </div>

            @if($borrowerRecords->isEmpty())
                {{-- Page number past the last page (e.g. an old link). --}}
                <div class="my-borrowings-card">
                    @include('custody.partials.borrowings-empty', [
                        'emptyHidden' => false,
                        'emptyTitle' => 'This page has no borrowings.',
                        'emptyMessage' => 'Go back to the first page to see your records.',
                        'emptyActionUrl' => $borrowerRecords->url(1),
                        'emptyActionLabel' => 'Go to first page',
                    ])
                </div>
            @endif

            <div class="borrowings-footer">
                <p class="borrowings-page-summary" role="status">Showing {{ $borrowerRecords->firstItem() ?? 0 }} to {{ $borrowerRecords->lastItem() ?? 0 }} of {{ $borrowerRecords->total() }} {{ $borrowerRecords->total() === 1 ? 'borrowing' : 'borrowings' }}</p>
                <div class="borrowings-pagination-controls">
                    <select
                        id="borrowings-per-page"
                        name="per_page"
                        form="borrowings-filters"
                        aria-label="Borrowings per page"
                    >
                        @foreach($borrowerPerPageOptions as $size)
                            <option value="{{ $size }}" @selected($borrowerFilters['per_page'] === $size)>{{ $size }} entries</option>
                        @endforeach
                    </select>
                    {{ $borrowerRecords->onEachSide(1)->links('custody.partials.borrowings-pagination') }}
                </div>
            </div>
        @endif
    </div>

    @include('custody.partials.borrowings-interactions')
@elseif($mode === 'release' && auth()->user()?->access_classification?->value === 'SPMU_OFFICER')
    @include('custody.partials.sao-release-list')
@elseif($mode === 'return')
    {{-- Return uses the existing Release list presentation and pagination components. --}}
    <section class="content-area sao-release-browser" data-sao-release-browser>
        <div class="sao-release-toolbar">
            <label>Search
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                    <input type="search" id="operational-search" aria-label="Search return records" placeholder="Search borrower, request no., custody no., or event…" autocomplete="off">
                </span>
            </label>
            <label>Status
                <select id="operational-status" aria-label="Return status">
                    <option value="all">All statuses</option>
                    @foreach($returnFilterStatuses as $filterStatus)
                        <option value="{{ $filterStatus['key'] }}">{{ $filterStatus['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label>Sort
                <select id="operational-sort" aria-label="Sort return records">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                </select>
            </label>
        </div>
        <article class="card sao-release-records">
            <div class="table-wrap sao-release-table-wrap" data-release-table-wrap @if($custodies->isEmpty()) hidden @endif>
                <table class="sao-release-table">
                    <colgroup><col style="width:27%"><col style="width:19%"><col style="width:17%"><col style="width:11%"><col style="width:14%"><col style="width:12%"></colgroup>
                    <thead>
                        <tr>
                            <th scope="col">Borrower / Request</th>
                            <th scope="col">Borrowing Dates</th>
                            <th scope="col">Issued</th>
                            <th scope="col">On Custody</th>
                            <th scope="col">Status</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody id="operational-filter-list">
                        @foreach($custodies as $custody)
                            @php
                                $version = $custody->request?->currentVersion;
                                $scheduleDate = $version?->schedule_date ?: $version?->needed_from;
                                $originalReturnDate = $custody->original_due_at
                                    ?: $version?->return_date
                                    ?: $version?->return_due_at
                                    ?: $custody->due_at;
                                $returnDate = $custody->due_at ?: $originalReturnDate;
                                $returnDateAdjusted = $originalReturnDate && $returnDate
                                    && ! $originalReturnDate->isSameDay($returnDate);
                                $outstanding = $custody->lines->sum(
                                    fn ($line) => max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity)
                                );
                                $physicalReturnStatus = $returnOperationalStatusForCustody($custody);
                                $linenQueueLabel = App\Services\LateReturnService::linenStateLabel(
                                    app(App\Services\LateReturnService::class)->linenReturnState($custody)
                                );
                            @endphp
                            <tr data-operational-record
                                data-status="{{ $physicalReturnStatus['key'] }}"
                                data-created="{{ $custody->updated_at?->timestamp ?? 0 }}"
                                data-id="{{ $custody->id }}"
                                data-search="{{ trim(($custody->borrower?->full_name ?? '').' '.($custody->request?->request_no ?? '').' '.($custody->custody_no ?? '').' '.($version?->purpose_event ?? '')) }}">
                                <td>
                                    <strong>{{ $custody->borrower?->full_name ?: 'Not recorded' }}</strong>
                                    <small>{{ $custody->request?->request_no ?: $custody->custody_no }}</small>
                                </td>
                                <td>
                                    <span>{{ $scheduleDate?->format('d M Y') ?: '—' }}</span>
                                    <small>{{ $returnDateAdjusted ? 'Effective Return' : 'Return' }}: {{ $returnDate?->format('d M Y') ?: '—' }}</small>
                                    @if($returnDateAdjusted)
                                        <small>Adjusted from {{ $originalReturnDate->format('d M Y') }}</small>
                                    @endif
                                    @if($linenQueueLabel)
                                        <small data-linen-return-state><span class="status-badge status-warning">{{ $linenQueueLabel }}</span></small>
                                    @endif
                                </td>
                                <td>{{ $custody->released_at?->format('d M Y, g:i A') ?: '—' }}</td>
                                <td>{{ $outstanding + 0 }}</td>
                                <td><x-status-badge :status="$physicalReturnStatus['key']" :label="$physicalReturnStatus['label']" /></td>
                                <td><a class="button secondary small ui-pressable" href="{{ route('custody.return.show', $custody) }}">View Record</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="sao-release-empty" id="operational-filter-empty" @if($custodies->isNotEmpty()) hidden @endif>
                <span class="sao-release-empty-icon" aria-hidden="true"><x-icon name="custody" size="30" /></span>
                <h2 data-release-empty-title>{{ $custodies->isEmpty() ? 'No released transactions to return.' : 'No matching return records.' }}</h2>
                <p data-release-empty-copy>{{ $custodies->isEmpty() ? 'After physical release, the transaction moves here for return tracking and reconciliation.' : 'Try another search term or status.' }}</p>
            </div>
            <footer class="sao-release-footer" data-release-pagination @if($custodies->isEmpty()) hidden @endif>
                <div class="sao-release-pagination-meta">
                    <p class="meta" data-release-range role="status" aria-live="polite">{{ $custodies->count() }} {{ \Illuminate\Support\Str::plural('record', $custodies->count()) }}</p>
                    <select data-release-page-size aria-label="Return records per page">
                        @foreach([7, 15, 30, 50] as $size)
                            <option value="{{ $size }}">{{ $size }} items per page</option>
                        @endforeach
                    </select>
                </div>
                <div class="sao-release-pagination">
                    <nav class="app-pagination" aria-label="Return record pages">
                        <button type="button" class="app-pagination-link" data-release-previous aria-label="Previous page" disabled><x-icon name="arrow-left" size="14" /></button>
                        <span data-release-pages></span>
                        <button type="button" class="app-pagination-link" data-release-next aria-label="Next page" disabled><x-icon name="arrow-right" size="14" /></button>
                    </nav>
                </div>
            </footer>
        </article>
    </section>
    @include('custody.partials.sao-release-list-styles')
    @include('custody.partials.sao-release-list-interactions')
@else
    <section class="content-area operational-content-area {{ $mode === 'return' ? 'operational-content-area--return' : '' }}">
        @if(in_array($mode, ['release','return'], true))
        <div class="operational-browser-toolbar">
            <label>Search
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" /></span>
                    <input type="search" id="operational-search" placeholder="Search borrower, request no., custody no., or event..." autocomplete="off">
                </span>
            </label>
            <label>Status
                <select id="operational-status">
                    <option value="all">All statuses</option>
                    @foreach($operationalFilterStatuses as $filterStatus)
                        <option value="{{ $filterStatus['key'] }}">{{ $filterStatus['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label>Sort
                <select id="operational-sort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select>
            </label>
        </div>
        @endif
        <div class="operational-record-list" id="operational-filter-list">
            @forelse($custodies as $custody)
                @php
                    $outstanding = $custody->lines->sum(
                        fn ($line) => max(
                            0,
                            (float) $line->actual_released_quantity - (float) $line->returned_quantity
                        )
                    );

                    $scheduleDate = $custody->request->currentVersion?->schedule_date
                        ?: $custody->request->currentVersion?->needed_from;

                    $originalReturnDate = $custody->original_due_at
                        ?: $custody->request->currentVersion?->return_date
                        ?: $custody->request->currentVersion?->return_due_at
                        ?: $custody->due_at;
                    $returnDate = $custody->due_at ?: $originalReturnDate;
                    $returnDateAdjusted = $originalReturnDate && $returnDate
                        && ! $originalReturnDate->isSameDay($returnDate);

                    $hasActivePickupSchedule = (bool) $custody->scheduled_release_at
                        && (bool) $custody->pickup_expires_at
                        && (bool) $custody->pickup_scheduled_at
                        && ! $custody->pickup_expired_at;

                    $workflowStatus = $custody->workflowStatus();
                    if ($mode === 'release') {
                        $workflowStatus = \App\Support\ActionOfficerStatusPresentation::releaseStatus($workflowStatus);
                    }
                    $physicalReturnStatus = $mode === 'return'
                        ? $returnOperationalStatusForCustody($custody)
                        : null;
                    $operationalLabel = $physicalReturnStatus['label'] ?? $workflowStatus['label'];
                    $operationalStatusKey = $physicalReturnStatus['key'] ?? $workflowStatus['key'];
                    $operationalFilterStatus = ['key' => $operationalStatusKey, 'label' => $operationalLabel];
                    $isCompleted = $workflowStatus['group'] === 'completed';
                    $isCancelled = $workflowStatus['group'] === 'cancelled';
                    $isFullyComplete = $operationalStatusKey === 'COMPLETED';

                    $detailRoute = match ($mode) {
                        'release' => route('custody.release.show', $custody),
                        'return' => route('custody.return.show', $custody),
                        default => route('custody.show', $custody),
                    };
                @endphp

                <a class="operational-record ui-pressable" href="{{ $detailRoute }}"
                    @if(in_array($mode, ['release','return'], true))
                    data-operational-record
                    data-created="{{ optional($custody->updated_at)->timestamp ?? 0 }}"
                    data-priority="0"
                    data-status="{{ $operationalFilterStatus['key'] }}"
                    data-search="{{ strtolower(trim(($custody->borrower?->full_name ?? '').' '.($custody->request?->request_no ?? '').' '.($custody->custody_no ?? '').' '.($custody->request?->currentVersion?->purpose_event ?? ''))) }}"
                    @endif
                >
                    <span class="operational-record-primary">
                        <strong>{{ $isBorrower ? $custody->custody_no : $custody->borrower->full_name }}</strong>
                        <span>Request {{ $custody->request->request_no }}</span>
                        <small>Schedule {{ optional($scheduleDate)->format('d M Y') }} · {{ $returnDateAdjusted ? 'Effective Return' : 'Return' }} {{ optional($returnDate)->format('d M Y') }}</small>
                        @if($returnDateAdjusted)
                            <small>Adjusted from {{ optional($originalReturnDate)->format('d M Y') }}</small>
                        @endif
                        @if($mode === 'return' && ($linenQueueLabel = App\Services\LateReturnService::linenStateLabel(app(App\Services\LateReturnService::class)->linenReturnState($custody))))
                            <small data-linen-return-state><span class="status-badge status-warning">{{ $linenQueueLabel }}</span></small>
                        @endif
                    </span>

                    <span class="operational-record-facts">
                        @if($mode === 'release')
                            <span><small>{{ $custody->pickup_scheduled_at ? 'Pickup Scheduled' : 'Schedule Exception' }}</small><strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Unavailable' }}</strong></span>
                            <span><small>Preparation</small><strong>{{ $custody->prepared_at ? 'Confirmed' : 'Pending' }}</strong></span>
                            <span><small>Issued</small><strong>Not yet</strong></span>
                        @elseif($mode === 'return')
                            <span><small>Issued</small><strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: '—' }}</strong></span>
                            <span><small>{{ $returnDateAdjusted ? 'Effective Return' : 'Return Due' }}</small><strong>{{ optional($returnDate)->format('d M Y') ?: '—' }}</strong></span>
                            <span><small>{{ $custody->status === 'OVERDUE' ? 'Overdue' : 'On Custody' }}</small><strong>{{ $outstanding + 0 }}</strong></span>
                        @else
                            <span><small>Pickup</small><strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong></span>
                            <span><small>Issued</small><strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: 'Not yet' }}</strong></span>
                            @if($isCancelled)
                                <span><small>Cancelled</small><strong>{{ optional($custody->closed_at)->format('d M Y, g:i A') ?: 'Cancelled' }}</strong></span>
                            @elseif($isCompleted)
                                <span><small>{{ $operationalLabel }}</small><strong>{{ optional($custody->closed_at)->format('d M Y, g:i A') ?: $operationalLabel }}</strong></span>
                            @else
                                <span><small>{{ $custody->status === 'OVERDUE' ? 'Overdue' : 'On Custody' }}</small><strong>{{ $outstanding + 0 }}</strong></span>
                            @endif
                        @endif
                    </span>

                    <span class="operational-record-action">
                        <x-status-badge
                            :status="$operationalStatusKey"
                            :label="$operationalLabel"
                        />
                        <strong>View Record</strong>
                    </span>
                </a>
            @empty
                @if($mode === 'release')
                    {{-- Universal operational empty state: same treatment as Verification, with a release-specific package icon. --}}
                    <section class="operational-empty-card">
                        <div class="operational-empty-card-content">
                            <span class="operational-empty-card-icon" aria-hidden="true"><x-icon name="box" size="30" /></span>
                            <h2>No transactions waiting for release.</h2>
                            <p>Approved transactions appear here until physical release is confirmed.</p>
                        </div>
                    </section>
                @elseif($mode === 'return')
                    <section class="operational-empty-card">
                        <div class="operational-empty-card-content">
                            <span class="operational-empty-card-icon" aria-hidden="true"><x-icon name="custody" size="30" /></span>
                            <h2>No released transactions to return.</h2>
                            <p>After physical release, the transaction moves here for return tracking and reconciliation.</p>
                        </div>
                    </section>
                @else
                    <div class="empty-state">
                        <strong>No custody/pickup records.</strong>
                        <span>A record appears after the SPMU Head verifies and approves a request and the approved quantities are allocated/held for pickup.</span>
                    </div>
                @endif
            @endforelse
        </div>
        @if(in_array($mode, ['release','return'], true))
            <div class="empty-state top-gap" id="operational-filter-empty" hidden><strong>No matching records.</strong><span>Try another search term or status.</span></div>
        @endif
        @if($mode === 'return' && $custodies->isNotEmpty())
            <footer class="operational-pagination" data-operational-pagination>
                <div class="operational-pagination-meta">
                    <p class="meta" data-operational-range role="status" aria-live="polite"></p>
                    <label>
                        <span class="sr-only">Return records per page</span>
                        <select data-operational-page-size aria-label="Return records per page">
                            @foreach([7, 15, 30, 50] as $size)
                                <option value="{{ $size }}">{{ $size }} items per page</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="operational-pagination-controls">
                    <nav class="app-pagination" aria-label="Return record pages">
                        <button type="button" class="app-pagination-link" data-operational-previous aria-label="Previous page" disabled><x-icon name="arrow-left" size="14" /></button>
                        <span data-operational-pages></span>
                        <button type="button" class="app-pagination-link" data-operational-next aria-label="Next page" disabled><x-icon name="arrow-right" size="14" /></button>
                    </nav>
                </div>
            </footer>
        @endif
    </section>
    @if(in_array($mode, ['release','return'], true))
    <style>
    .operational-browser-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) minmax(190px,230px) minmax(150px,190px);gap:12px;align-items:end;margin-bottom:14px;padding:14px;background:var(--surface-elevated);border:1px solid var(--border);border-radius:var(--radius)}
    .operational-browser-toolbar label{display:grid;gap:6px;font-size:12px;font-weight:800;color:var(--text-muted)}
    .operational-browser-toolbar input,.operational-browser-toolbar select{min-height:42px;width:100%}
    .operational-record-action{align-content:center}
    {{-- /* Release/Return is a page-level operational workspace. When it has no
       transactions, let the result panel fill the remaining screen just like
       the other queue/list pages. The message itself stays constrained so the
       text never stretches awkwardly across the wide card. */ --}}
    .operational-content-area{display:flex;flex-direction:column;width:100%;min-width:0}
    .operational-content-area > .operational-record-list{flex:1 1 auto;display:flex;flex-direction:column;width:100%;min-width:0;min-height:0}
    .operational-content-area--return > .operational-record-list{flex:0 0 auto}
    .operational-pagination{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-top:14px;padding:16px 20px;background:var(--surface-elevated);border:1px solid var(--border);border-radius:var(--radius)}
    .operational-content-area--return .operational-pagination{margin-top:12px}
    .operational-pagination p{margin:0}
    .operational-pagination-meta{display:flex;align-items:center;flex-wrap:wrap;gap:10px}
    .operational-pagination-controls,.operational-pagination nav{display:flex;align-items:center;flex-wrap:wrap;gap:6px}
    .operational-pagination .app-pagination{margin:0}
    .operational-pagination select{width:auto;min-height:32px;margin:0;padding:4px 28px 4px 9px;font-size:11px}
    .operational-record-list > .operational-empty-card{flex:1 1 auto;width:100%}
    .operational-empty-card{--operational-empty-accent:#0865df;display:flex;min-height:300px;align-items:center;justify-content:center;padding:40px 22px;background:var(--surface-elevated);border:1px solid var(--border);border-radius:9px;text-align:center}
    .operational-empty-card-content{display:flex;flex-direction:column;align-items:center;max-width:400px}
    .operational-empty-card-icon{display:grid;place-items:center;width:72px;height:72px;margin-bottom:18px;border-radius:50%;background:var(--info-bg);color:var(--operational-empty-accent)}
    .operational-empty-card h2{margin:0 0 8px;font-size:16px;font-weight:750;line-height:1.4}
    .operational-empty-card p{margin:0;color:var(--text-secondary);font-size:13px;line-height:1.65}
    html[data-theme="dark"] .operational-empty-card{--operational-empty-accent:#72b7f4}
    @media(max-width:760px){.operational-browser-toolbar{grid-template-columns:1fr}.operational-empty-card{min-height:260px;padding:30px 16px}.operational-pagination{padding:14px}}
    </style>
    <script>
    (() => {
        const list = document.getElementById('operational-filter-list');
        const rows = [...document.querySelectorAll('[data-operational-record]')];
        const search = document.getElementById('operational-search');
        const status = document.getElementById('operational-status');
        const sort = document.getElementById('operational-sort');
        const empty = document.getElementById('operational-filter-empty');
        const footer = document.querySelector('[data-operational-pagination]');
        const pageSize = document.querySelector('[data-operational-page-size]');
        const previous = document.querySelector('[data-operational-previous]');
        const next = document.querySelector('[data-operational-next]');
        const range = document.querySelector('[data-operational-range]');
        const pages = document.querySelector('[data-operational-pages]');

        if (!list || !rows.length || !search || !status || !sort) return;

        let currentPage = 1;
        const pageNumbers = (totalPages) => {
            const candidates = [1, currentPage - 1, currentPage, currentPage + 1, totalPages]
                .filter((page) => page >= 1 && page <= totalPages)
                .sort((left, right) => left - right);
            const result = [];
            candidates.forEach((page) => {
                if (result.length && page - result[result.length - 1] > 1) result.push('…');
                if (!result.includes(page)) result.push(page);
            });
            return result;
        };
        const renderPageNumbers = (totalPages) => {
            if (!pages) return;
            pages.replaceChildren();
            pageNumbers(totalPages).forEach((page) => {
                if (page === '…') {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'app-pagination-ellipsis';
                    ellipsis.setAttribute('aria-hidden', 'true');
                    ellipsis.textContent = '…';
                    pages.appendChild(ellipsis);
                    return;
                }
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'app-pagination-link';
                button.textContent = String(page);
                button.setAttribute('aria-label', `Page ${page}`);
                if (page === currentPage) {
                    button.classList.add('is-active');
                    button.setAttribute('aria-current', 'page');
                }
                button.addEventListener('click', () => { currentPage = page; render(); });
                pages.appendChild(button);
            });
        };
        const perPage = () => [7, 15, 30, 50].includes(Number(pageSize?.value))
            ? Number(pageSize.value)
            : Number.MAX_SAFE_INTEGER;

        const render = () => {
            const query = search.value.trim().toLowerCase();
            const selectedStatus = status.value;
            const ordered = [...rows].sort((left, right) => {
                const priority = Number(right.dataset.priority || 0) - Number(left.dataset.priority || 0);
                if (priority !== 0) return priority;

                const difference = Number(right.dataset.created) - Number(left.dataset.created);
                return sort.value === 'newest' ? difference : -difference;
            });

            const matches = ordered.filter((row) => (
                (!query || row.dataset.search.includes(query))
                && (selectedStatus === 'all' || row.dataset.status === selectedStatus)
            ));

            const totalPages = Math.max(1, Math.ceil(matches.length / perPage()));
            currentPage = Math.min(Math.max(1, currentPage), totalPages);
            const firstIndex = (currentPage - 1) * perPage();
            const lastIndex = Math.min(firstIndex + perPage(), matches.length);

            rows.forEach((row) => { row.hidden = true; });
            matches.forEach((row, index) => {
                list.appendChild(row);
                row.hidden = index < firstIndex || index >= lastIndex;
            });

            if (empty) empty.hidden = matches.length > 0;
            if (footer) footer.hidden = matches.length === 0;

            if (range && matches.length) {
                range.textContent = `Showing ${firstIndex + 1}–${lastIndex} of ${matches.length} ${matches.length === 1 ? 'record' : 'records'}`;
            }
            renderPageNumbers(totalPages);
            if (previous) previous.disabled = currentPage === 1;
            if (next) next.disabled = currentPage === totalPages;
        };

        search.addEventListener('input', () => { currentPage = 1; render(); });
        status.addEventListener('change', () => { currentPage = 1; render(); });
        sort.addEventListener('change', () => { currentPage = 1; render(); });
        pageSize?.addEventListener('change', () => { currentPage = 1; render(); });
        previous?.addEventListener('click', () => { if (currentPage > 1) { currentPage--; render(); } });
        next?.addEventListener('click', () => { currentPage++; render(); });
        render();
    })();
    </script>
    @endif
@endif

@endsection
