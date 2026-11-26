<section id="my-requests-results" class="mr-results" aria-label="Borrowing request results">
    <div class="mr-results-head">
        <strong class="mr-result-count" data-request-count role="status" aria-live="polite">
            {{ $requests->total() }} {{ $requests->total() === 1 ? 'request found' : 'requests found' }}
        </strong>
    </div>

    <div class="operational-record-list mr-list" id="borrower-request-list" aria-label="My borrowing requests">
        @foreach($requests as $request)
            @include('requests.partials.my-requests-row', ['request' => $request])
        @endforeach
    </div>

    @if($requests->isEmpty())
        {{-- Page number past the last page (e.g. an old link). --}}
        <section class="mr-empty mr-filter-empty">
            @include('requests.partials.my-requests-empty-filtered', [
                'emptyTitle' => 'This page has no requests.',
                'emptyMessage' => 'Go back to the first page to see your requests.',
                'emptyActionUrl' => $requests->url(1),
                'emptyActionLabel' => 'Go to first page',
            ])
        </section>
    @endif

    <div class="mr-footer">
        <div class="mr-pagination-meta">
            <p role="status">Showing {{ $requests->firstItem() ?? 0 }} to {{ $requests->lastItem() ?? 0 }} of {{ $requests->total() }} {{ $requests->total() === 1 ? 'request' : 'requests' }}</p>
            <form method="get" action="{{ route('requests.index') }}">
                @foreach(request()->except(['page', 'per_page']) as $name => $value)
                    @if(is_scalar($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
                @endforeach
                <label class="sr-only" for="my-requests-per-page">Requests per page</label>
                <select id="my-requests-per-page" name="per_page" aria-label="Requests per page" onchange="this.form.submit()">
                    @foreach([7, 15, 30, 50] as $size)
                        <option value="{{ $size }}" @selected($borrowerRequestFilters['per_page'] === $size)>{{ $size }} entries</option>
                    @endforeach
                </select>
            </form>
        </div>
        {{ $requests->onEachSide(1)->links('requests.partials.my-requests-pagination') }}
    </div>
</section>
