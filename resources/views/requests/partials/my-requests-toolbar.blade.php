@php
    // Search, status, and sort run on the server (see BorrowingRequestController::index).
    $borrowerRequestFilters = $borrowerRequestFilters ?? ['search' => '', 'status' => 'all', 'sort' => 'newest', 'active' => false];
    $borrowerRequestStatusFilters = $borrowerRequestStatusFilters ?? [];
@endphp

{{-- GET form: values stay in the URL, and changing them starts again at page 1. --}}
<form
    class="record-browser-toolbar mr-toolbar"
    id="request-filters"
    method="GET"
    action="{{ route('requests.index') }}"
    role="search"
    aria-label="Borrowing request controls"
>
    <label class="record-browser-search">
        Search

        <span class="search-input-shell">
            <span class="search-input-icon" aria-hidden="true">
                <x-icon name="search" size="17" />
            </span>

            <input
                id="request-search"
                name="search"
                type="search"
                value="{{ $borrowerRequestFilters['search'] }}"
                maxlength="100"
                placeholder="Search request no., purpose, or item..."
                autocomplete="off"
            >
        </span>
    </label>

    <label>
        Status

        <select id="request-status-filter" name="status">
            <option value="all">All ongoing statuses</option>
            @foreach($borrowerRequestStatusFilters as $statusKey => $statusLabel)
                <option value="{{ $statusKey }}" @selected($borrowerRequestFilters['status'] === $statusKey)>{{ $statusLabel }}</option>
            @endforeach
        </select>
    </label>

    <label>
        Sort

        <select id="request-sort" name="sort" aria-label="Sort requests">
            <option value="newest" @selected($borrowerRequestFilters['sort'] === 'newest')>Newest first</option>
            <option value="oldest" @selected($borrowerRequestFilters['sort'] === 'oldest')>Oldest first</option>
        </select>
    </label>

    <noscript><button class="button secondary" type="submit">Apply</button></noscript>
</form>
