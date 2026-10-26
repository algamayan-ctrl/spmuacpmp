@foreach(App\Reports\ReportTableLayout::headers($columns) as $headerIndex => $headerRow)
    <tr>
        @foreach($headerRow as $cell)
            <th style="width: {{ array_sum(array_slice(App\Reports\ReportTableLayout::widths($columns), $cell['index'], $cell['colspan'])) }}%" colspan="{{ $cell['colspan'] }}" rowspan="{{ $cell['rowspan'] }}" scope="{{ isset($cell['column']) ? 'col' : 'colgroup' }}" @class(['numeric' => ($cell['column']['align'] ?? null) === 'numeric'])>{{ $cell['label'] }}</th>
        @endforeach
        @if(($showLinkColumn ?? false) && $headerIndex === 0)
            <th rowspan="{{ count(App\Reports\ReportTableLayout::headers($columns)) }}" scope="col" class="doc-table-action-column">Action</th>
        @endif
    </tr>
@endforeach
