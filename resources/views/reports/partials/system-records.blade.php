@php
    /*
    | The interactive records table of a report preview.
    |
    | Rendered on the server from the report's own rows (App\Reports\ReportRecordTable),
    | so search, sorting and pagination work across every record in scope, not
    | just the page in the browser. `_link` becomes the record drilldown and
    | `_tone_<key>` a status badge; neither is part of the record itself.
    |
    | Expects: $table (ReportRecordTable), $heading, $recordNoun (optional).
    */
    use App\Reports\ReportRecordTable;

    $records = $table->records;
    $recordNoun = $recordNoun ?? 'records';
    $showLinks = $table->hasRecordLinks();
    $columnCount = count($table->columns) + ($showLinks ? 1 : 0);
    $headingId = 'report-system-records-heading-'.substr(md5($heading), 0, 6);
@endphp

<section class="report-system-records-card" aria-labelledby="{{ $headingId }}" data-report-records>
    <div class="report-system-records-header">
        <div>
            <h3 id="{{ $headingId }}">{{ $heading }}</h3>
        </div>

        <form class="report-system-search" method="get" action="{{ $table->path() }}" role="search" data-report-table-nav>
            @foreach($table->state(['search' => null, 'page' => null]) as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <span class="report-system-search-icon" aria-hidden="true">⌕</span>
            <input
                type="search"
                name="search"
                value="{{ $table->search }}"
                maxlength="100"
                placeholder="Search records..."
                autocomplete="off"
                aria-label="Search report records"
            >
        </form>
    </div>

    @if($table->isSearching())
        <p class="report-system-search-note" role="status">
            {{ number_format($records->total()) }} of {{ number_format($table->scopeTotal) }} {{ $recordNoun }} match “{{ $table->search }}”.
            Search only narrows this view; print and export include all {{ number_format($table->scopeTotal) }} {{ $recordNoun }} in the report.
            <a href="{{ $table->url(['search' => null, 'page' => null]) }}" data-report-table-nav>Clear search</a>
        </p>
    @endif

    <div class="report-system-table-scroll">
        <table class="report-system-table">
            <thead>
                @foreach(App\Reports\ReportTableLayout::headers($table->columns) as $headerIndex => $headerRow)
                    <tr>
                        @foreach($headerRow as $cell)
                            @php
                                $column = $cell['column'] ?? null;
                            @endphp
                            <th scope="{{ $column ? 'col' : 'colgroup' }}" colspan="{{ $cell['colspan'] }}" rowspan="{{ $cell['rowspan'] }}" @if($column) aria-sort="{{ $table->ariaSort($column['key']) }}" @endif @class(['numeric' => ($column['align'] ?? null) === 'numeric'])>
                                @if($column)
                                    <a class="report-sort-link" aria-label="Sort by {{ $column['label'] }}" href="{{ $table->sortUrl($column['key']) }}" data-report-table-nav>
                                        <span>{{ $cell['label'] }}</span>
                                        <span class="report-sort-indicator" aria-hidden="true">{{ $table->sort === $column['key'] ? ($table->direction === 'desc' ? '▼' : '▲') : '↕' }}</span>
                                    </a>
                                @else
                                    {{ $cell['label'] }}
                                @endif
                            </th>
                        @endforeach
                        @if($showLinks && $headerIndex === 0)
                            <th rowspan="{{ count(App\Reports\ReportTableLayout::headers($table->columns)) }}" scope="col" class="report-system-action-column">Action</th>
                        @endif
                    </tr>
                @endforeach
            </thead>
            <tbody>
                @forelse($records as $row)
                    <tr>
                        @foreach($table->columns as $column)
                            @php
                                $value = (string) ($row[$column['key']] ?? '');
                                $tone = ReportRecordTable::badgeTone($row['_tone_'.$column['key']] ?? null);
                                // Reference numbers and single dates read badly when broken across lines.
                                $nowrap = (bool) preg_match('/(^|_)no$|_date$|_at$|_time$/', $column['key']);
                                $isReference = (bool) preg_match('/(^|_)(no|reference|code)$/', $column['key']);
                            @endphp
                            <td @class(['numeric' => ($column['align'] ?? null) === 'numeric', 'report-cell-nowrap' => $nowrap, 'report-cell-multiline' => str_contains($value, "\n")])>
                                @if($tone !== null && $value !== '')
                                    <span class="status-badge status-{{ $tone }}" data-status-tone="{{ $tone }}">{{ $value }}</span>
                                @elseif($isReference && $value !== '')
                                    <span class="report-reference-badge" title="{{ $value }}">{{ $value }}</span>
                                @else
                                    {{ $value }}
                                @endif
                            </td>
                        @endforeach
                        @if($showLinks)
                            <td class="report-system-action-column">
                                @if(filled($row['_link'] ?? null))
                                    <a class="report-inventory-view-action" href="{{ $row['_link'] }}" aria-label="View Record" title="View Record">
                                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2.2 12s3.6-6 9.8-6 9.8 6 9.8 6-3.6 6-9.8 6-9.8-6-9.8-6Z"/><circle cx="12" cy="12" r="2.8"/></svg>View Record</a>
                                @elseif($row['_link_restricted'] ?? false)
                                    {{-- The record exists, but its page is not open to this user at its current stage. --}}
                                    <span class="report-record-restricted" title="This record cannot be opened from your account at its current stage.">Not available</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td class="report-system-table-empty" colspan="{{ $columnCount }}">
                            No {{ $recordNoun }} match “{{ $table->search }}”.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(isset($dataset))
        @include('reports.document.table-notes')
    @endif

    <div class="report-records-footer" aria-label="Web record navigation">
        <p class="report-records-summary" role="status" aria-live="polite">
            @if($records->total() > 0)
                Showing {{ number_format($records->firstItem()) }}–{{ number_format($records->lastItem()) }} of {{ number_format($records->total()) }} {{ $records->total() === 1 ? \Illuminate\Support\Str::singular($recordNoun) : $recordNoun }}
            @else
                Showing 0 {{ $recordNoun }}
            @endif
        </p>

        <div class="report-records-controls">
            <form method="get" action="{{ $table->path() }}" class="report-per-page-control" data-report-table-nav>
                @foreach($table->state(['per_page' => null, 'page' => null]) as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                <select name="per_page" aria-label="Records per page" data-report-per-page>
                    @foreach(ReportRecordTable::PER_PAGE_OPTIONS as $size)
                        <option value="{{ $size }}" @selected($table->perPage === $size)>{{ $size }} per page</option>
                    @endforeach
                </select>
                <noscript><button class="button secondary" type="submit">Apply</button></noscript>
            </form>

            @if($records->hasPages())
                {{ $records->onEachSide(1)->links('reports.partials.report-pagination') }}
            @endif
        </div>
    </div>
</section>

@once
<script>
(() => {
    /*
     * Table navigation (search, sort, page size, pages) reloads from the
     * server. Mark the records card busy meanwhile so the operator can see
     * the request is in progress.
     */
    const markBusy = (element) => {
        const card = element.closest('[data-report-records]');
        if (!card) return;
        card.setAttribute('aria-busy', 'true');
        card.classList.add('is-loading');
    };

    document.querySelectorAll('[data-report-records]').forEach((card) => {
        card.addEventListener('submit', (event) => markBusy(event.target), true);
        card.addEventListener('click', (event) => {
            const link = event.target.closest('a[data-report-table-nav], .report-pagination a');
            if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey) markBusy(link);
        });
        card.querySelectorAll('[data-report-per-page]').forEach((select) => {
            select.addEventListener('change', () => select.form?.requestSubmit());
        });
    });
})();
</script>
@endonce
