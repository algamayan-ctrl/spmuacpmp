@extends('layouts.app', ['title' => 'Gate Pass'])
@section('content')
{{-- This is the Gate Pass Records UI for SPMU Action Officer. --}}
@php
    $gatePasses->loadMissing([
        'custody.borrower.organizationalUnit',
        'custody.request',
        'custody.lines.requestItem.inventoryItem',
        'custody.laundryJob.latestEvidence.file',
        'custody.returns',
    ]);

@endphp

@include('gate-passes.partials.index-styles')

<div class="gate-pass-browser" data-gate-pass-browser data-page-size="7">
    <section class="page-heading gate-pass-heading">
        <div>
            <p class="eyebrow">SPMU Action Officer</p>
            <h1>Gate Pass</h1>
            <p>Review accomplished Gate Passes uploaded and recorded by the SPMU Action Officer after return.</p>
        </div>
    </section>

    <section id="gate-pass-filters" class="card record-browser-toolbar gate-pass-toolbar record-table-toolbar" aria-label="Gate Pass filters">
        <label class="record-browser-search" for="gate-pass-search">Search
            <span class="search-input-shell">
                <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                <input id="gate-pass-search" type="search" placeholder="Request, borrower, custody, destination..." data-gate-pass-search autocomplete="off">
            </span>
        </label>
        <label>Date from<input type="date" data-gate-pass-from></label>
        <label>Date to<input type="date" data-gate-pass-to></label>
        <label>Show entries
            <select data-gate-pass-size>
                @foreach([7, 15, 30, 50] as $size)
                    <option value="{{ $size }}">{{ $size }} entries</option>
                @endforeach
            </select>
        </label>
        <label for="gate-pass-sort">Sort
            <select id="gate-pass-sort" data-gate-pass-sort>
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
            </select>
        </label>
        <button class="button secondary" type="button" data-gate-pass-clear>Clear</button>
    </section>

    @if($gatePasses->isEmpty())
        <section class="card gate-pass-empty-card" aria-labelledby="gate-pass-empty-title">
            @include('gate-passes.partials.empty-illustration')
            <h2 id="gate-pass-empty-title">No recorded Gate Passes yet.</h2>
            <p>Accomplished Gate Passes will appear after their copies have been uploaded and recorded by SPMU.</p>
        </section>
    @else

        <section class="card gate-pass-records-card" aria-labelledby="gate-pass-records-title">
            <div class="gate-pass-records-heading">
                <h2 id="gate-pass-records-title">Recorded Gate Passes</h2>
                <p data-gate-pass-count role="status" aria-live="polite">Showing 1 to {{ min(7, $gatePasses->count()) }} of {{ $gatePasses->count() }} records</p>
            </div>
            <div class="gate-pass-table-wrap">
                <table class="gate-pass-table" aria-labelledby="gate-pass-records-title">
                    <thead>
                        <tr>
                            <th scope="col">Request No.</th>
                            <th scope="col">Borrower</th>
                            <th scope="col">Event / Use Location</th>
                            <th scope="col">Recorded Date</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody data-gate-pass-list>
                        @foreach($gatePasses as $gatePass)
                            @include('gate-passes.partials.record-row')
                        @endforeach
                        <tr class="gate-pass-filter-empty" data-gate-pass-empty hidden>
                            <td colspan="6">
                                <strong>No recorded Gate Passes match your filters.</strong>
                                <p>Try another search term or date range.</p>
                                <button class="button secondary small link-button" type="button" data-gate-pass-reset>Clear filters</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="gate-pass-footer">
                <nav class="gate-pass-pagination" aria-label="Gate Pass records pagination" data-gate-pass-pagination hidden>
                    <button class="icon-button gate-pass-page" type="button" data-gate-pass-page="previous" aria-label="Previous page" disabled><x-icon name="arrow-left" size="16" /></button>
                    <div class="gate-pass-page-numbers" data-gate-pass-page-numbers></div>
                    <button class="icon-button gate-pass-page" type="button" data-gate-pass-page="next" aria-label="Next page" disabled><x-icon name="arrow-right" size="16" /></button>
                </nav>
            </div>
        </section>
    @endif
</div>

@include('gate-passes.partials.index-interactions')

@endsection
