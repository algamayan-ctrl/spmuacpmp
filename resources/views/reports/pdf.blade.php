@php
    /*
    | The PDF copy.
    |
    | dompdf understands table layout far better than grid or flex, so the
    | institutional header is a table here while the screen uses CSS grid. The
    | content, wording and order are identical to the preview; only the layout
    | mechanism differs, because the renderer demands it.
    */
    $meta = $dataset->meta;
    $appliedFilters = $meta['applied_filters'] ?? [];
    $scopeSummary = implode(' · ', array_column(App\Reports\ReportScopeFormatter::rows($appliedFilters), 'value'));
    $margins = $exportOptions->marginsInPoints();
    $periodMode = App\Reports\ReportCatalogue::periodMode($dataset->reportKey);
    $fontSize = $exportOptions->fontSize ?: App\Reports\ReportTableLayout::fontSize($dataset->columns);
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $dataset->label }}</title>
<style>
    @page {
        margin: {{ $margins['top'] }}pt {{ $margins['right'] }}pt {{ max(58, $margins['bottom']) }}pt {{ $margins['left'] }}pt;
    }

    body {
        margin: 0;
        font-family: Helvetica, Arial, sans-serif;
        font-size: {{ $fontSize }}pt;
        line-height: 1.35;
        color: #111;
    }

    .doc-header { width: 100%; border-collapse: collapse; border-bottom: 1px solid #222; }
    .doc-header td { padding: 0 0 6pt; vertical-align: middle; }
    .doc-header-seal { width: 46pt; }
    .doc-header-seal img { width: 44pt; height: 44pt; }
    .doc-header-republic, .doc-header-address { font-size: 8pt; line-height: 1.15; }
    .doc-header-institution { font-size: 11pt; font-weight: bold; line-height: 1.15; }

    .doc-title { margin: 14pt 0 0; text-align: center; font-size: 11pt; font-weight: bold; letter-spacing: 0.4pt; }
    .doc-period { margin: 2pt 0 14pt; text-align: center; font-size: 8pt; }

    .doc-meta { margin: 0 0 10pt; }
    .doc-meta div { margin: 0 0 1pt; font-size: 8pt; }
    .doc-meta span { display: inline-block; width: 96pt; }

    table.doc-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    @if(! ($options['repeat_headers'] ?? true))
    table.doc-table thead { display: table-row-group; }
    @endif
    table.doc-table th {
        padding: 4pt 2pt;
        background: #f2f2f2;
        border: 0.6pt solid #999;
        font-size: {{ max(6, $fontSize - 0.9) }}pt;
        font-weight: bold;
        text-align: center;
        vertical-align: middle;
        text-transform: uppercase;
        overflow-wrap: break-word;
        word-wrap: break-word;
    }
    table.doc-table td { padding: 3.5pt 3pt; border: 0.6pt solid #bbb; vertical-align: top; white-space: pre-line; overflow-wrap: break-word; }
    table.doc-table td.numeric, table.doc-table th.numeric { text-align: right; }
    .doc-table-notes { margin-top: 8pt; font-size: 7pt; }
    .doc-table-notes p { margin: 2pt 0; }
    .doc-table-empty { text-align: center; padding: 14pt 4pt; color: #555; }

    .doc-summary { margin: 8pt 0 10pt; }
    .doc-summary-heading { font-size: 8.5pt; font-weight: bold; text-transform: uppercase; margin: 0 0 4pt; }
    table.doc-summary-table { width: 300pt; border-collapse: collapse; font-size: 8pt; }
    table.doc-summary-table td { padding: 2.4pt 0; border-bottom: 0.45pt solid #dddddd; vertical-align: top; }
    table.doc-summary-table td:first-child { width: 205pt; padding-right: 10pt; }
    table.doc-summary-table td:last-child { width: 95pt; text-align: right; font-weight: bold; }

    .doc-footer { margin-top: 16pt; padding-top: 5pt; border-top: 0.6pt solid #999; }
    .doc-footer div { font-size: 7pt; color: #333; }
</style>
</head>
<body>

<table class="doc-header">
    <tr>
        <td class="doc-header-seal">
            @if($sealSrc)
                <img src="{{ $sealSrc }}" alt="Camarines Sur Polytechnic Colleges">
            @endif
        </td>
        <td>
            <div class="doc-header-republic">Republic of the Philippines</div>
            <div class="doc-header-institution">CAMARINES SUR POLYTECHNIC COLLEGES</div>
            <div class="doc-header-address">Nabua, Camarines Sur</div>
        </td>
    </tr>
</table>

<div class="doc-title">{{ mb_strtoupper($dataset->label) }}</div>

<div class="doc-period">
    @if($periodMode === 'as_of')
        As of {{ $meta['as_of_long'] ?? '' }}
    @else
        For the period {{ $meta['period_long'] ?? '' }}
    @endif
</div>

<div class="doc-meta">
    @if(($options['generated_by'] ?? true) && ! empty($meta['generated_by']))
        <div><span>Prepared by</span>: {{ $meta['generated_by'] }}</div>
    @endif

    <div><span>Date Generated</span>: {{ $meta['generated_long'] ?? '' }}</div>

    @if(($options['filters'] ?? true) && $scopeSummary !== '')
        <div><span>Report Scope</span>: {{ $scopeSummary }}</div>
    @endif
</div>

@include('reports.document.table')
@include('reports.document.table-notes')

@if(($options['summary'] ?? true) && ! empty($dataset->summary))
    <div class="doc-summary">
        <div class="doc-summary-heading">Report Summary</div>
        <table class="doc-summary-table" aria-label="Report summary">
            <tbody>
                @foreach($dataset->summary as $label => $value)
                    <tr>
                        <td>{{ $label }}</td>
                        <td>{{ App\Reports\ReportSummaryFormatter::display((string) $label, $value) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if($options['footer'] ?? true)
    <div class="doc-footer">
        <div>Prepared from official SPMU-ACPMP operational records.</div>
        <div>This is a system-generated report. No signature is required.</div>
    </div>
@endif

</body>
</html>
