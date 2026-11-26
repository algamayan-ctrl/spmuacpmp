@php
    $canExportStockCard = in_array(auth()->user()?->access_classification, [\App\Enums\AccessClassification::SpmuHead, \App\Enums\AccessClassification::SpmuOfficer], true);
    $printQuery = array_filter(['stock_q' => $card['search'], 'stock_movement' => $card['movement']], fn ($value) => $value !== '');
@endphp
<article class="card sao-stock-card" id="stock-card">
    <div class="sao-stock-card-heading">
        <div><h2>Stock Card</h2></div>
        @if($canExportStockCard)
            <div class="sao-stock-card-actions">
                <a class="button secondary small sao-stock-card-print-action" href="{{ route('inventory.stock-card.print', array_merge(['inventory' => $item->id], $printQuery)) }}" target="_blank" rel="noopener" aria-label="Print Stock Card"><x-icon name="printer" size="17" /> Print</a>
                <button class="button secondary small" type="button" data-stock-export-open><x-icon name="download" size="17" /> Generate</button>
            </div>
        @endif
    </div>
    @if($card['warning'])<p class="sao-stock-card-warning" role="alert">{{ $card['warning'] }}</p>@endif
    <form method="GET" action="{{ route('inventory.show', $item) }}" id="sao-stock-card-filters">
        <input type="hidden" name="tab" value="stock-card">
        <div class="sao-stock-card-filters">
            <label>Search<input type="search" name="stock_q" value="{{ $card['search'] }}" placeholder="Search reference, office, or date…" maxlength="200"></label>
            <label>Filter<select name="stock_movement" aria-label="Filter"><option value="ALL" @selected($card['movement'] === 'ALL')>All Entries</option><option value="RECEIPT" @selected($card['movement'] === 'RECEIPT')>Received Items</option><option value="ISSUE" @selected($card['movement'] === 'ISSUE')>Issued Items</option></select></label>
        </div>
        <div class="table-wrap sao-stock-card-table-wrap">
            @include('inventory.partials.stock-card-table', ['rows' => $card['page']])
        </div>
        <div class="sao-stock-card-footer">
            <div class="sao-stock-card-pagination-meta">
            <p class="meta" role="status">
                @if($card['page']->total())
                    Showing {{ $card['page']->firstItem() }}–{{ $card['page']->lastItem() }} of {{ $card['page']->total() }} entries
                @else
                    {{ $card['filtered'] ? 'No matching entries' : 'No recorded entries' }}
                @endif
            </p>
                <select name="stock_per_page" aria-label="Stock card entries per page">@foreach([7, 15, 30, 50] as $size)<option value="{{ $size }}" @selected($card['per_page'] === $size)>{{ $size }} items per page</option>@endforeach</select>
            </div>
            <div class="sao-stock-card-pagination">
                @if($card['page']->total())
                    {{ $card['page']->onEachSide(1)->links('partials.pagination') }}
                @endif
            </div>
        </div>
    </form>
</article>
@if($canExportStockCard)
    @include('inventory.partials.stock-card-export-options')
    @include('inventory.partials.stock-card-export-options-script')
@endif
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('sao-stock-card-filters');
    if (!form) return;
    let searchTimer;
    form.querySelectorAll('select').forEach(select => select.addEventListener('change', () => {
        clearTimeout(searchTimer);
        form.requestSubmit();
    }));
    form.querySelector('input[type="search"]').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => form.requestSubmit(), 500);
    });
});
</script>
