{{-- This is the read-only Inventory summary dialog for authorized SPMU users. --}}
@php
    $summaryDefinitions = [
        'total' => ['Total Stock', $total, null],
        'available' => ['Available', $available, []],
        'reserved' => ['Reserved', $reserved, ['RESERVED']],
        'custody' => ['On Custody', $issued, ['CUSTODY']],
        'unavailable' => ['Unavailable', $unavailable, ['DISCREPANCY', 'LAUNDRY', 'ISSUE', 'CONDITION_HOLD', 'CONDITION']],
    ];
@endphp
@foreach($summaryDefinitions as $summaryKey => [$summaryLabel, $summaryQuantity, $sourceGroups])
    <template data-inventory-summary-template="{{ $summaryKey }}">
        <h2>{{ $summaryLabel }}</h2>
        <p class="inventory-summary-dialog-quantity">{{ $summaryQuantity + 0 }} {{ $item->unit->unit_name }}</p>
        @if(in_array($summaryKey, ['total', 'available'], true))
            <dl class="detail-list compact">
                <dt>Total Stock</dt><dd>{{ $total + 0 }}</dd>
                <dt>Available</dt><dd>{{ $available + 0 }}</dd>
                <dt>Allocated</dt><dd>{{ $reserved + 0 }}</dd>
                <dt>On Custody</dt><dd>{{ $issued + 0 }}</dd>
                <dt>Unavailable</dt><dd>{{ $unavailable + 0 }}</dd>
            </dl>
            <p class="meta">Available stock is calculated from recorded quantities, allocations and physical custody.</p>
        @endif
        @if($summaryKey !== 'available')
            @php($summarySources = $sourceGroups === null ? $currentSources : $currentSources->whereIn('group', $sourceGroups))
            @forelse($summarySources as $record)
                <article class="inventory-summary-source">
                    <strong>{{ $record['reference'] }}</strong>
                    <p>{{ $record['primary'] }}</p>
                    @if(filled($record['secondary'] ?? null))<p class="meta">{{ $record['secondary'] }}</p>@endif
                    <p>{{ (float) $record['quantity'] + 0 }} {{ $item->unit->unit_name }} · {{ $record['status'] }}</p>
                    @if(filled($record['url'] ?? null))<a class="button secondary small" href="{{ $record['url'] }}">{{ $record['action_label'] ?? 'View Record' }}</a>@endif
                </article>
            @empty
                <p class="meta">No linked {{ strtolower($summaryLabel) }} records are currently recorded.</p>
            @endforelse
        @endif
        @if($summaryKey === 'total')<button type="button" class="button secondary" data-summary-stock-card>View Stock Card</button>@endif
    </template>
@endforeach
<dialog class="inventory-summary-dialog" data-inventory-summary-dialog aria-label="Inventory summary details">
    <div class="inventory-summary-dialog-heading"><strong>Inventory details</strong><button type="button" class="button secondary small" data-summary-close>Close</button></div>
    <div data-inventory-summary-content></div>
</dialog>
