<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stock Card — {{ $item->unique_description }}</title>
    <style>
        @page { size: {{ $options->pageSize }} {{ $options->orientation }}; margin: {{ $options->marginsMm['top'] }}mm {{ $options->marginsMm['right'] }}mm {{ $options->marginsMm['bottom'] }}mm {{ $options->marginsMm['left'] }}mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #172b44; background: #edf1f6; font: 14px Arial, sans-serif; }
        .print-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 20px; max-width: 1120px; margin: 20px auto; padding: 0 16px; }
        .print-toolbar p { margin: 0; font-size: 13px; }
        .print-toolbar button, .print-toolbar a { display: inline-block; padding: 10px 16px; border: 1px solid #b9c9dc; border-radius: 6px; background: white; color: #175c9d; font: inherit; font-weight: bold; text-decoration: none; cursor: pointer; }
        .print-toolbar button { background: #1569b0; color: white; border-color: #1569b0; }
        .stock-sheet { max-width: 1120px; margin: 0 auto 28px; padding: 30px; background: white; }
        h1 { margin: 0 0 20px; text-align: center; font-size: 22px; }
        .sao-stock-card-table { width: 100%; border-collapse: collapse; table-layout: fixed; color: #111; font-size: {{ $options->fontSize ?? 9 }}pt; }
        .sao-stock-card-table th, .sao-stock-card-table td { padding: 7px 6px; border: 1px solid #555; vertical-align: top; overflow-wrap: anywhere; word-wrap: break-word; }
        .sao-stock-card-table th { text-align: center; background: white; }
        .sao-stock-card-table td:first-child, .sao-stock-card-table td:nth-child(5) { text-align: center; }
        .stock-number { text-align: center; font-variant-numeric: tabular-nums; }
        .stock-balance { font-weight: bold; }
        .stock-note { margin: 13px 0 0; font-size: 10px; line-height: 1.5; }
        .stock-warning { padding: 12px; border: 1px solid #ad6500; color: #754600; font-size: 12px; }
        @media print {
            body { background: white; color: black; }
            .print-toolbar { display: none; }
            .stock-sheet { max-width: none; margin: 0; padding: 0; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
        }
        .stock-institution { text-align: center; font-size: 12px; line-height: 1.5; margin-bottom: 12px; }
        .stock-print-meta { margin: 0 0 14px; font-size: 11px; line-height: 1.5; }
        @if($pdfMode)
            body { background: white; color: black; font-family: DejaVu Sans, sans-serif; }
            .stock-sheet { max-width: none; margin: 0; padding: 0; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
        @endif
    </style>
</head>
<body>
    @unless($pdfMode)
    <div class="print-toolbar">
        <p>Print the stock entries shown in this copy.</p>
        <div><a href="{{ route('inventory.show', ['inventory' => $item->id, 'tab' => 'stock-card']) }}">Back to Inventory</a> <button type="button" id="print-stock-card">Print</button></div>
    </div>
    @endunless
    <main class="stock-sheet">
        <div class="stock-institution">REPUBLIC OF THE PHILIPPINES<br><strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong><br>Supply and Property Management Unit</div>
        <h1>STOCK CARD</h1>
        <div class="stock-print-meta"><strong>Item:</strong> {{ $item->unique_description }}<br><strong>Inventory Reference:</strong> {{ 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT) }}<br><strong>Unit:</strong> {{ $item->unit?->unit_name }}<br><strong>Filter:</strong> {{ match($card['movement']) { 'RECEIPT' => 'Received Items', 'ISSUE' => 'Issued Items', default => 'All Entries' } }}</div>
        @if($card['warning'])<p class="stock-warning">{{ $card['warning'] }}</p>@endif
        @if($card['filtered'])<p class="stock-note"><strong>Selected entries:</strong> {{ $card['search'] ? 'Search: '.$card['search'].' · ' : '' }}{{ match($card['movement']) { 'RECEIPT' => 'Received Items', 'ISSUE' => 'Issued Items', default => 'All Entries' } }}. Balances retain the full consolidated sequence before filtering.</p>@endif
        @include('inventory.partials.stock-card-table', ['rows' => $card['rows']])
        <p class="stock-note">{{ $card['balance_note'] }} Balances start from the existing inventory figures. This copy includes all {{ $card['rows']->count() }} matching entries.</p>
        <p class="stock-note">Generated {{ now()->format('m-d-Y, g:i A') }}</p>
    </main>
    @unless($pdfMode)
    <script>document.getElementById('print-stock-card').addEventListener('click', () => window.print());</script>
    @endunless
</body>
</html>
