@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Requests' : 'Request Records'])

@section('content')
<style>.record-browser-toolbar + .table-wrap tbody td { padding-block: 10px; }</style>
@php
    $isBorrower = session('active_workspace') === 'BORROWER';
    $classification = auth()->user()?->access_classification?->value;
    $isActionOfficer = ! $isBorrower && $classification === 'SPMU_OFFICER';
    $requestRecordStatus = static fn ($request): array => \App\Support\RequestRecordStatus::forRequest($request);
    $requestFilterStatuses = collect(\App\Support\RequestRecordStatus::filters())
        ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values();
@endphp

<section class="page-heading">
    <div>
        @if($isBorrower)
            <p class="eyebrow">Ongoing requests</p>
        @endif
        <h1>{{ $isBorrower ? 'My Requests' : 'Request Records' }}</h1>

        @if($isBorrower)
            <p>Track your ongoing requests and drafts. View completed, cancelled, and rejected records in My Borrowings.</p>
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
