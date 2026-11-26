@php
    $stockQty = fn ($value) => $value === null ? '' : rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    $stockColumnWidths = [12, 23, 12, 12, 28, 13];
@endphp
<table class="sao-stock-card-table">
    <colgroup>@foreach($stockColumnWidths as $width)<col style="width:{{ $width }}%">@endforeach</colgroup>
    <thead>
        <tr>
            <th scope="col">Date</th>
            <th scope="col">Reference</th>
            <th scope="col">In</th>
            <th scope="col">Out</th>
            <th scope="col">Issued Office</th>
            <th scope="col" title="Consolidated balance including subsequent returns; not the historical stock on the release date.">Balance Qty.</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $row)
            <tr data-stock-card-row>
                <td>{{ $row['date'] ? \Illuminate\Support\Carbon::parse($row['date'])->format('m-d-Y') : '' }}</td>
                <td title="{{ $row['remarks'] }}">{{ $row['reference'] }}</td>
                <td class="stock-number">{{ $stockQty($row['receipt']) }}</td>
                <td class="stock-number">{{ $stockQty($row['issue']) }}</td>
                <td>{{ $row['issue'] !== null ? $row['office'] : '' }}</td>
                <td class="stock-number stock-balance">{{ $stockQty($row['balance']) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty-state">{{ ($card['filtered'] ?? false) ? 'No stock entries match these filters.' : 'No stock entries have been recorded for this item.' }}</td></tr>
        @endforelse
    </tbody>
</table>
