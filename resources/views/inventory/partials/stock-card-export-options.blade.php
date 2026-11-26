@php
    $exportClass = \App\Reports\ReportExportOptions::class;
    $scopeLabel = match($card['movement']) { 'RECEIPT' => 'Received Items', 'ISSUE' => 'Issued Items', default => 'All Entries' };
@endphp
<dialog class="stock-export-dialog" id="stock-export-options" aria-labelledby="stock-export-title">
    <form method="get" action="{{ route('inventory.stock-card.export', $item) }}" data-stock-export-form>
        @foreach($printQuery as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <header class="stock-export-header">
            <div>
                <h2 id="stock-export-title">Export / Print Stock Card</h2>
                <p>{{ $item->unique_description }} · {{ $scopeLabel }} · {{ number_format($card['page']->total()) }} matching {{ $card['page']->total() === 1 ? 'record' : 'records' }}@if($card['search'] !== '') · Search: {{ $card['search'] }}@endif</p>
            </div>
            <button class="icon-button" type="button" aria-label="Close export options" data-stock-export-close><x-icon name="close" size="18" /></button>
        </header>
        <div class="stock-export-body">
            <h3>Generation options</h3>
            <div class="stock-export-grid">
                <label>Format<select name="format" data-stock-export-format>@foreach($exportClass::FORMATS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label data-stock-page-setting>Page Size<select name="page_size">@foreach($exportClass::PAGE_SIZES as $value => $label)<option value="{{ $value }}">{{ match($value) { 'LETTER' => 'Letter', 'LEGAL' => 'Legal', default => 'A4' } }}</option>@endforeach</select></label>
                <label data-stock-page-setting>Orientation<select name="orientation">@foreach($exportClass::ORIENTATIONS as $value => $label)<option value="{{ $value }}" @selected($value === 'landscape')>{{ $label }}</option>@endforeach</select></label>
            </div>
            <p class="stock-export-note"><x-icon name="information" size="17" /> All matching Stock Card records are included.</p>
            <details class="stock-export-advanced" data-stock-page-setting>
                <summary><x-icon name="settings" size="18" /> Advanced Print Settings</summary>
                <div class="stock-export-advanced-grid">
                    <label>Margins<select name="margins" data-stock-export-margins>@foreach($exportClass::MARGIN_PRESETS as $value => $preset)<option value="{{ $value }}">{{ $preset['label'] }}</option>@endforeach</select></label>
                    <label>Table Font Size<select name="font_size"><option value="">Automatic (Recommended)</option>@foreach([8,9,10,11,12] as $size)<option value="{{ $size }}">{{ $size }} pt</option>@endforeach</select></label>
                    <div class="stock-export-custom" data-stock-custom-margins hidden>
                        @foreach(['top'=>'Top','right'=>'Right','bottom'=>'Bottom','left'=>'Left'] as $edge => $label)
                            <label>{{ $label }} (mm)<input type="number" name="margin_{{ $edge }}" value="20" min="5" max="60" step="1"></label>
                        @endforeach
                    </div>
                </div>
            </details>
        </div>
        <footer class="stock-export-footer">
            <button class="button secondary" type="button" data-stock-export-close>Cancel</button>
            <button class="button primary" type="submit" data-stock-export-submit>Generate PDF</button>
        </footer>
    </form>
</dialog>
