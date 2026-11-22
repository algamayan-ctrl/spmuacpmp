<form method="get" action="{{ route('policies.index') }}" class="record-table-toolbar">
    <input type="hidden" name="section" value="special-dates">
    <input type="hidden" name="exception_sort" value="{{ request('exception_sort', 'date') }}">
    <input type="hidden" name="exception_direction" value="{{ request('exception_direction', 'asc') }}">
    <label class="record-table-search">Search
        <input id="special-dates-search" type="search" name="exception_q" value="{{ request('exception_q') }}" maxlength="200" placeholder="Date, reason or transaction…">
    </label>
    <label>Status
        <select name="exception_status">
            @foreach(['ALL' => 'All statuses', 'OPEN' => 'Open', 'CLOSED' => 'Closed'] as $value => $label)
                <option value="{{ $value }}" @selected(request('exception_status', 'ALL') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label>Date from<input type="date" name="exception_from" value="{{ request('exception_from') }}"></label>
    <label>Date to<input type="date" name="exception_to" value="{{ request('exception_to') }}"></label>
    <x-record-page-size name="exception_per_page" :value="$dateExceptions->perPage()" :entries-inside="true" />
    <div class="record-table-actions">
        <button class="button primary" type="submit">Apply</button>
        <a class="button secondary" href="{{ route('policies.index', ['section' => 'special-dates']) }}">Clear</a>
    </div>
</form>
@foreach(['exception_from', 'exception_to'] as $field)
    @error($field)<p class="field-error">{{ $message }}</p>@enderror
@endforeach
