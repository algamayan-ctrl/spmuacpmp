@extends('layouts.app', ['title' => 'Analytics Records'])

@section('content')
{{-- This is the Analytics Record Details UI for authorized SPMU users. --}}
@include('reports.partials.workspace-styles')

@php
 $table = $detail['table'] ?? null;
 $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
 $columns = is_array($table['columns'] ?? null) ? $table['columns'] : [];
 $bars = is_array($detail['bars'] ?? null) ? $detail['bars'] : [];
 $structuredDemand = ! empty($detail['structured_demand']);
 $breakdownTables = $structuredDemand ? ($detail['breakdown_tables'] ?? []) : [];

 /*
 * Universal composition/distribution contract.
 * Any Analytics detail may provide:
 * label, value/count, share percentage and visual tone.
 */
 $distribution = is_array($detail['distribution'] ?? null)
 ? $detail['distribution']
 : [];
 $stats = is_array($detail['stats'] ?? null) ? $detail['stats'] : [];

 /*
 * A drilldown with an explicit empty message and no supporting rows is a
 * true zero-result state. Do not render a large "0" metric card and then
 * repeat the same fact below it; show one compact empty state instead.
 */
 $hasTableRecords = $table && $rows !== [];
 $hasBreakdown = ($structuredDemand ? $breakdownTables !== [] : $bars !== []) || $distribution !== [];
 $isEmptyResult = ! empty($detail['empty']) && ! $hasTableRecords && ! $hasBreakdown;
 $canGenerateReport = ! empty($detail['reports_url']) && ! $isEmptyResult;

 $isReturnTrendDetail =
 strcasecmp(
 trim((string) ($detail['title'] ?? '')),
 'Return Performance Trend'
 ) === 0;

 /*
 * Returned On Time is the one records table where the small bottom
 * "visible / supporting records loaded" footer is intentionally omitted.
 * Match the actual drilldown query as well as the resolved title so this
 * remains reliable even if the title wording changes.
 */
 $hideRecordsFooter =
 (
 $detailKey === 'returns'
 && strtolower((string) request()->query('state', '')) === 'on-time'
 )
 || strcasecmp((string) ($detail['title'] ?? ''), 'Returned On Time') === 0;

 $hideRecordsFooter = $hideRecordsFooter || $isReturnTrendDetail;

 /*
 * RETURN RECORDS DIRECT FOOTER CLEANUP
 * Return Performance Trend already shows the complete record set directly.
 */
 $hideRecordsFooter =
 ($hideRecordsFooter ?? false)
 || (($detail['table_title'] ?? null) === 'Return Records')
 || strcasecmp(
 trim((string) ($detail['title'] ?? '')),
 'Return Performance Trend'
 ) === 0;

 /*
 * A detail service may suppress the generic table-count footer when
 * that footer adds no information. This is reusable by every drilldown.
 */
 $hideRecordsFooter =
 $hideRecordsFooter
 || ! empty($detail['hide_table_footer']);

 $emptyIcon = match ($detailKey) {
 'requests', 'trend', 'outcomes' => 'requests',
 'currently-out', 'equipment', 'low-availability' => 'inventory',
 'returns', 'follow-up', 'overdue-aging', 'late-rate' => 'returns',
 'division', 'unit' => 'organization',
 'borrower' => 'borrower',
 default => 'file-search',
 };

 /*
 * Records tables may use plain scalar cells today or richer
 * ['primary' => ..., 'secondary' => ...] cells later. The template
 * supports both so every Analytics drilldown can share one table format.
 */
 $statusTone = static function (string $value): string {
 $normalized = str($value)->lower()->toString();

 /*
 * Check negative/warning qualifiers before generic words such as
 * "returned" or "completed".
 */
 if (str_contains($normalized, 'late')
 || str_contains($normalized, 'pending')
 || str_contains($normalized, 'pickup')
 || str_contains($normalized, 'review')
 || str_contains($normalized, 'processing')
 || str_contains($normalized, 'low')) {
 return 'is-warning';
 }

 if (str_contains($normalized, 'overdue')
 || str_contains($normalized, 'declined')
 || str_contains($normalized, 'rejected')
 || str_contains($normalized, 'incident')
 || str_contains($normalized, 'unavailable')) {
 return 'is-danger';
 }

 if (str_contains($normalized, 'on time')
 || str_contains($normalized, 'approved')
 || str_contains($normalized, 'completed')
 || str_contains($normalized, 'returned')
 || str_contains($normalized, 'healthy')
 || str_contains($normalized, 'available')) {
 return 'is-positive';
 }

 return 'is-info';
 };

 $emptyTitle = match ($detailKey) {
 'requests' => 'No requests in this period.',
 'currently-out' => 'No active custody records.',
 'follow-up' => 'No follow-up records.',
 'low-availability' => 'No low-availability records.',
 'equipment' => 'No asset records yet.',
 'returns', 'late-rate' => 'No return records yet.',
 'overdue-aging' => 'No overdue records yet.',
 'division', 'unit' => 'No organizational records yet.',
 'borrower' => 'No borrower records yet.',
 'outcomes' => 'No request outcome records yet.',
 'trend' => 'No activity in this period.',
 default => 'No records yet.',
 };
@endphp

<style>
.analytics-records-page { display: grid; gap: 16px; }
.analytics-records-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.analytics-records-heading h1 { margin: 3px 0 5px; font-size: clamp(21px, 1.65vw, 25px); color: var(--heading); }
.analytics-records-heading p { margin: 0; color: var(--text-muted); font-size: 12.5px; line-height: 1.4; }
.analytics-records-eyebrow { margin: 0 !important; color: var(--text-muted) !important; font-size: 9.5px !important; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.analytics-records-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.analytics-records-actions .button {
 display: inline-flex;
 align-items: center;
 gap: 6px;
}
.analytics-records-context { display: flex; flex-wrap: wrap; gap: 8px; }
.analytics-records-chip { display: inline-flex; align-items: center; min-height: 30px; padding: 5px 10px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface-elevated); color: var(--text-secondary); font-size: 11px; font-weight: 650; }
.analytics-records-card { overflow: hidden; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-elevated); box-shadow: var(--shadow-sm); }
.analytics-records-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); border-top: 1px solid var(--border); }
.analytics-records-stat { min-width: 0; padding: 12px 12px; border-right: 1px solid var(--border); transition: background-color var(--motion) ease; }
.analytics-records-stat:last-child { border-right: 0; }
.analytics-records-stat:hover { background: var(--surface-subtle); }
.analytics-records-stat strong { display: block; color: var(--heading); font-size: 21px; font-weight: 750; line-height: 1.1; font-variant-numeric: tabular-nums; }
.analytics-records-stat span { display: block; margin-top: 5px; color: var(--text-secondary); font-size: 11.5px; font-weight: 650; line-height: 1.4; }
.analytics-records-note { padding: 13px 12px; color: var(--text-secondary); font-size: 12px; line-height: 1.55; }
.analytics-records-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 12px 16px; border-bottom: 1px solid var(--border); }
.analytics-records-card-head h2 { margin: 0; color: var(--heading); font-size: 15px; }
.analytics-records-search { width: min(300px, 100%); min-height: 38px; }
.analytics-records-table-wrap { overflow-x: auto; }
.analytics-records-table { width: 100%; min-width: 880px; border-collapse: collapse; }
.analytics-records-table th { padding: 12px 13px; background: var(--surface-subtle); border-bottom: 1px solid var(--border); color: var(--text-secondary); font-size: 10px; font-weight: 800; letter-spacing: .05em; text-align: left; text-transform: uppercase; white-space: nowrap; }
.analytics-records-table td { padding: 12px 13px; border-bottom: 1px solid var(--border); color: var(--heading); font-size: 12px; line-height: 1.5; vertical-align: top; }
.analytics-records-table tr:last-child td { border-bottom: 0; }
.analytics-records-table tbody tr { transition: background-color var(--motion) ease; }
.analytics-records-table tbody tr:hover { background: var(--surface-subtle); }
.analytics-records-table tbody tr[hidden] { display: none; }

.analytics-records-table td:first-child {
 font-weight: 750;
}

.analytics-records-cell {
 display: grid;
 gap: 2px;
 min-width: 0;
}

.analytics-records-cell-primary {
 color: var(--heading);
 font-weight: 700;
 line-height: 1.35;
 overflow-wrap: anywhere;
}

.analytics-records-cell-secondary {
 color: var(--text-muted);
 font-size: 10.5px;
 font-weight: 500;
 line-height: 1.35;
 overflow-wrap: anywhere;
}

.analytics-records-status {
 display: inline-flex;
 align-items: center;
 width: fit-content;
 max-width: 100%;
 min-height: 24px;
 padding: 3px 9px;
 border: 1px solid var(--border);
 border-radius: 999px;
 background: var(--surface-subtle);
 color: var(--text-secondary);
 font-size: 10px;
 font-weight: 750;
 line-height: 1.25;
 white-space: normal;
}

.analytics-records-status.is-positive {
 color: var(--success);
 background: var(--success-bg);
 border-color: color-mix(in srgb, var(--success) 24%, var(--border));
}

.analytics-records-status.is-warning {
 color: var(--warning);
 background: var(--warning-bg);
 border-color: color-mix(in srgb, var(--warning) 30%, var(--border));
}

.analytics-records-status.is-danger {
 color: var(--danger);
 background: var(--danger-bg);
 border-color: color-mix(in srgb, var(--danger) 26%, var(--border));
}

.analytics-records-status.is-info {
 color: var(--interactive);
 background: var(--blue-50);
 border-color: color-mix(in srgb, var(--interactive) 24%, var(--border));
}

.analytics-records-footer { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 12px 16px; border-top: 1px solid var(--border); color: var(--text-muted); font-size: 11px; }
.analytics-records-empty {
 display: grid;
 place-items: center;
 min-height: 270px;
 padding: 34px 16px;
 border: 1px solid var(--border);
 border-radius: var(--radius);
 background: var(--surface-elevated);
 text-align: center;
}

.analytics-records-empty-inner {
 display: grid;
 justify-items: center;
 gap: 9px;
 width: min(440px, 100%);
}

.analytics-records-empty-icon {
 display: grid;
 place-items: center;
 width: 66px;
 height: 66px;
 margin-bottom: 4px;
 color: color-mix(in srgb, var(--primary-action) 56%, var(--text-muted));
}

.analytics-records-empty-icon svg {
 width: 58px;
 height: 58px;
 overflow: visible;
}

.analytics-records-empty-icon .empty-icon-soft {
 fill: color-mix(in srgb, var(--primary-action) 8%, transparent);
 stroke: none;
}

.analytics-records-empty-icon .empty-icon-line {
 fill: none;
 stroke: currentColor;
 stroke-width: 1.65;
 stroke-linecap: round;
 stroke-linejoin: round;
}

.analytics-records-empty-sparkle {
 fill: color-mix(in srgb, var(--primary-action) 26%, transparent);
 stroke: none;
}

.analytics-records-empty h2 {
 margin: 0;
 color: var(--heading);
 font-size: 15px;
 font-weight: 750;
 line-height: 1.35;
}

.analytics-records-empty p {
 margin: 0;
 color: var(--text-muted);
 font-size: 12px;
 line-height: 1.55;
}
.analytics-records-empty.is-compact {
 min-height: 270px;
 padding: 34px 16px;
}

.analytics-records-empty.is-compact .analytics-records-empty-icon {
 width: 54px;
 height: 54px;
 margin-bottom: 0;
}

.analytics-records-empty.is-compact .analytics-records-empty-icon svg {
 width: 48px;
 height: 48px;
}

.analytics-records-empty-note {
 max-width: 620px;
 color: var(--text-secondary) !important;
}

.analytics-records-empty.is-compact .analytics-records-empty-icon {
 width: 48px;
 height: 48px;
 color: var(--interactive);
 background: var(--blue-50);
 border: 1px solid color-mix(in srgb, var(--interactive) 14%, var(--border));
 border-radius: 14px;
}

.analytics-records-empty.is-compact .analytics-records-empty-icon svg {
 width: 30px;
 height: 30px;
}
.analytics-records-breakdown { width: 100%; border-collapse: collapse; }
.analytics-records-breakdown td { padding: 11px 13px; border-bottom: 1px solid var(--border); font-size: 12px; }
.analytics-records-breakdown tr:last-child td { border-bottom: 0; }
.analytics-records-breakdown td:last-child { text-align: right; color: var(--heading); font-weight: 700; }
@media (max-width: 900px) {
 .analytics-records-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
 .analytics-records-stat { border-bottom: 1px solid var(--border); }
}
@media (max-width: 700px) {
 .analytics-records-heading { display: grid; }
 .analytics-records-actions { width: 100%; }
 .analytics-records-actions .button { flex: 1; justify-content: center; }
}

.analytics-records-page.is-return-trend {
 gap: 14px;
}

.analytics-records-page.is-return-trend .analytics-records-heading {
 align-items: center;
}

.analytics-records-page.is-return-trend .analytics-records-heading h1 {
 margin-top: 2px;
 margin-bottom: 3px;
}

.analytics-records-page.is-return-trend .analytics-records-context {
 margin-top: -2px;
}

.analytics-records-page.is-return-trend .analytics-records-card-head {
 padding: 14px 18px;
}

.analytics-records-page.is-return-trend .analytics-records-summary {
 grid-template-columns: repeat(4, minmax(0, 1fr));
}

.analytics-records-page.is-return-trend .analytics-records-stat {
 padding: 16px 18px;
}

.analytics-records-page.is-return-trend .analytics-records-stat strong {
 font-size: 20px;
}

.analytics-records-page.is-return-trend .analytics-records-stat span {
 margin-top: 4px;
 font-size: 11px;
}

.analytics-records-page.is-return-trend .analytics-records-note {
 padding: 11px 18px;
 border-top: 1px solid var(--border);
 background: var(--surface-subtle);
 font-size: 11.5px;
 line-height: 1.5;
}

.analytics-records-page.is-return-trend .analytics-records-search {
 width: min(270px, 100%);
 min-height: 36px;
}

.analytics-records-page.is-return-trend .analytics-records-table {
 min-width: 760px;
}

.analytics-records-page.is-return-trend .analytics-records-table th {
 padding: 11px 14px;
}

.analytics-records-page.is-return-trend .analytics-records-table td {
 padding: 13px 14px;
 vertical-align: middle;
}

.analytics-records-page.is-return-trend .analytics-records-status {
 min-height: 23px;
 padding: 3px 10px;
}

@media (max-width: 850px) {
 .analytics-records-page.is-return-trend .analytics-records-summary {
 grid-template-columns: repeat(2, minmax(0, 1fr));
 }
}

@media (max-width: 520px) {
 .analytics-records-page.is-return-trend .analytics-records-summary {
 grid-template-columns: minmax(0, 1fr);
 }

 .analytics-records-page.is-return-trend .analytics-records-search {
 width: 100%;
 }
}

.analytics-records-page.is-return-trend .analytics-records-note,
.analytics-records-page.is-return-trend .analytics-records-footer {
 display: none !important;
}

/* Standard Analytics Records: complete metric tiles and inset record controls. */
.analytics-records-page:not(.is-return-trend) .analytics-records-summary {
 gap: 12px;
 padding: 16px;
 border-top: 0;
 grid-auto-rows: 1fr;
}
.analytics-records-page:not(.is-return-trend) .analytics-records-stat {
 padding: 16px;
 border: 1px solid var(--border);
 border-radius: 9px;
 background: var(--surface-elevated);
 overflow-wrap: anywhere;
}
.analytics-records-page:not(.is-return-trend) .analytics-records-stat:hover {
 background: var(--surface-subtle);
}
.analytics-records-page:not(.is-return-trend) .analytics-records-summary + .analytics-records-note {
 padding: 0 16px 16px;
}
.analytics-records-page:not(.is-return-trend) .analytics-records-card > .record-table-toolbar {
 margin: 0;
 padding: 16px;
}
.analytics-records-page:not(.is-return-trend) .analytics-records-card > .record-table-footer {
 margin-top: 0;
 padding: 12px 16px;
}
@media (max-width: 520px) {
 .analytics-records-page:not(.is-return-trend) .analytics-records-summary {
  grid-template-columns: minmax(0, 1fr);
 }
}

/* Revision 011-A: independently rendered Demand breakdowns. */
.analytics-demand-breakdown .analytics-records-table { min-width: 0; }
.analytics-records-source-link { color: var(--interactive); font-weight: 700; text-decoration: none; }
.analytics-records-source-link:hover, .analytics-records-source-link:focus-visible { text-decoration: underline; }
.analytics-demand-breakdown .analytics-records-table th,
.analytics-demand-breakdown .analytics-records-table td { padding-inline: 16px; }
.analytics-demand-breakdown .analytics-records-table .is-number { text-align: right; font-variant-numeric: tabular-nums; }
.analytics-demand-breakdown .demand-evidence-bar { display: block; height: 6px; width: 100%; min-width: 56px; margin-top: 5px; border-radius: 4px; background: var(--surface-subtle); }
.analytics-demand-breakdown .demand-evidence-bar > span { display: block; height: 100%; border-radius: inherit; background: var(--interactive); }
.analytics-demand-breakdown .demand-hours { display: grid; grid-template-columns: repeat(auto-fit, minmax(66px, 1fr)); gap: 8px; padding: 16px; }
.analytics-demand-breakdown .demand-hour { padding: 10px 8px; text-align: center; border: 1px solid var(--border); border-radius: 8px; }
.analytics-demand-breakdown .demand-hour.is-peak { border-color: var(--interactive); background: var(--surface-subtle); }
.analytics-demand-breakdown .demand-hour strong { display: block; color: var(--heading); font-size: 16px; }
.analytics-demand-breakdown .demand-hour span { color: var(--text-secondary); font-size: 11px; }
.analytics-demand-breakdown .demand-donut { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; padding: 16px; }
.analytics-demand-breakdown .demand-donut svg { width: 100px; height: 100px; }
.analytics-demand-breakdown .demand-donut strong { font-size: 21px; color: var(--heading); display: block; }
.analytics-demand-breakdown .demand-donut-label { font-size: 11px; color: var(--text-secondary); font-weight: 650; }

.analytics-records-distribution {
 display: grid;
 gap: 12px;
 padding: 12px 16px 16px;
}

.analytics-records-distribution-bar {
 display: flex;
 width: 100%;
 height: 12px;
 overflow: hidden;
 border-radius: 999px;
 background: var(--surface-subtle);
}

.analytics-records-distribution-segment {
 display: block;
 min-width: 0;
 height: 100%;
}

.analytics-records-distribution-segment.is-positive {
 background: var(--success);
}

.analytics-records-distribution-segment.is-warning {
 background: var(--warning);
}

.analytics-records-distribution-segment.is-danger {
 background: var(--danger);
}

.analytics-records-distribution-segment.is-info {
 background: var(--interactive);
}

.analytics-records-distribution-list {
 display: grid;
 gap: 0;
}

.analytics-records-distribution-row {
 display: grid;
 grid-template-columns: minmax(0, 1fr) auto auto;
 align-items: center;
 gap: 12px;
 min-height: 42px;
 border-bottom: 1px solid var(--border);
}

.analytics-records-distribution-row:last-child {
 border-bottom: 0;
}

.analytics-records-distribution-name {
 display: flex;
 align-items: center;
 gap: 8px;
 min-width: 0;
 color: var(--text-secondary);
 font-size: 12px;
 font-weight: 650;
}

.analytics-records-distribution-dot {
 width: 9px;
 height: 9px;
 flex: 0 0 9px;
 border-radius: 999px;
}

.analytics-records-distribution-dot.is-positive {
 background: var(--success);
}

.analytics-records-distribution-dot.is-warning {
 background: var(--warning);
}

.analytics-records-distribution-dot.is-danger {
 background: var(--danger);
}

.analytics-records-distribution-dot.is-info {
 background: var(--interactive);
}

.analytics-records-distribution-value {
 color: var(--heading);
 font-size: 13px;
 font-weight: 750;
 font-variant-numeric: tabular-nums;
}

.analytics-records-distribution-share {
 min-width: 44px;
 color: var(--text-muted);
 font-size: 11.5px;
 font-weight: 700;
 text-align: right;
 font-variant-numeric: tabular-nums;
}

</style>

<div class="reporting-workspace analytics-records-page{{ $isReturnTrendDetail ? ' is-return-trend' : '' }}">
 <section class="analytics-records-heading">
 <div>
 <p class="analytics-records-eyebrow">Analytics records</p>
 <h1>{{ $detail['title'] ?? 'Analytics Records' }}</h1>

 <p>
 {{ $isReturnTrendDetail
 ? 'Completed returns during the selected reporting period.'
 : ($detail['context'] ?? 'Records supporting the selected analytics result.') }}
 </p>
 </div>

 <div class="analytics-records-actions">
 <a class="button secondary ui-pressable" href="{{ $backToAnalyticsUrl }}">
 <x-icon name="arrow-left" size="14" />
 <span>Back</span>
 </a>

 @if($canGenerateReport)
 <a class="button secondary ui-pressable" href="{{ $detail['reports_url'] }}">
 Generate Formal Report
 </a>
 @endif
 </div>
 </section>

 <div class="analytics-records-context">
 <span class="analytics-records-chip">{{ $detail['period_label'] ?? ($from->format('d M Y').' – '.$to->format('d M Y')) }}</span>
 @if(! empty($detail['filters']))
 <span class="analytics-records-chip">{{ $detail['filters'] }}</span>
 @endif
 @if(array_key_exists('value', $detail) && $detail['value'] !== null)
 <span class="analytics-records-chip">
 @if($detailKey === 'requests' && empty($detail['value_label']))
 {{ $detail['value'] }} {{ (int) $detail['value'] === 1 ? 'request' : 'requests' }}
 @else
 {{ $detail['value'] }}{{ ! empty($detail['value_label']) ? ' - '.$detail['value_label'] : '' }}
 @endif
 </span>
 @endif
 </div>

 @if($isEmptyResult)
 <section class="analytics-records-empty is-compact" aria-labelledby="analytics-empty-title">
 <div class="analytics-records-empty-inner">
 <span class="analytics-records-empty-icon" aria-hidden="true">
 <x-icon :name="$emptyIcon" size="34" />
 </span>
 <h2 id="analytics-empty-title">{{ $emptyTitle }}</h2>
 <p>{{ $detail['empty'] }}</p>
 </div>
 </section>
 @elseif($stats !== [])
 <section class="analytics-records-card" aria-label="Analytics summary">
 <div class="analytics-records-card-head">
 <div>
 <p class="analytics-records-eyebrow">Metric summary</p>
 <h2>
 {{ $isReturnTrendDetail
 ? 'Return Summary'
 : ($detail['context'] ?? 'Analytics summary') }}
 </h2>
 </div>
 </div>

 <div class="analytics-records-summary">
 @foreach($stats as $stat)
 <div class="analytics-records-stat">
 <strong>{{ $stat['value'] ?? '—' }}</strong>
 <span>{{ $stat['label'] ?? 'Metric' }}</span>
 </div>
 @endforeach
 </div>

 @if(! empty($detail['note']))
 <div class="analytics-records-note">{{ $detail['note'] }}</div>
 @endif
 </section>
 @endif

 @if(! $isEmptyResult && $distribution !== [])
 <section class="analytics-records-card" aria-label="Analytics distribution">
 <div class="analytics-records-card-head">
 <div>
 <p class="analytics-records-eyebrow">Analytics distribution</p>
 <h2>{{ $detail['distribution_title'] ?? 'Distribution' }}</h2>
 </div>
 </div>

 <div class="analytics-records-distribution">

 <div
 class="analytics-records-distribution-bar"
 role="img"
 aria-label="{{ collect($distribution)->map(
 fn ($segment) =>
 ($segment['label'] ?? 'Segment')
 .' '.($segment['share'] ?? 0).'%'
 )->implode(', ') }}"
 >
 @foreach($distribution as $segment)
@php
 $segmentShare = max(
 0,
 min(100, (int) ($segment['share'] ?? 0))
 );

 $segmentTone = in_array(
 ($segment['tone'] ?? 'info'),
 ['positive', 'warning', 'danger', 'info'],
 true
 )
 ? $segment['tone']
 : 'info';
 @endphp

 @if($segmentShare > 0)
 <span
 class="analytics-records-distribution-segment is-{{ $segmentTone }}"
 style="width: {{ $segmentShare }}%"
 aria-hidden="true"
 ></span>
 @endif
 @endforeach
 </div>

 <div class="analytics-records-distribution-list">
 @foreach($distribution as $segment)
@php
 $segmentTone = in_array(
 ($segment['tone'] ?? 'info'),
 ['positive', 'warning', 'danger', 'info'],
 true
 )
 ? $segment['tone']
 : 'info';
 @endphp

 <div class="analytics-records-distribution-row">
 <span class="analytics-records-distribution-name">
 <span
 class="analytics-records-distribution-dot is-{{ $segmentTone }}"
 aria-hidden="true"
 ></span>

 {{ $segment['label'] ?? 'Segment' }}
 </span>

 <strong class="analytics-records-distribution-value">
 {{ $segment['value'] ?? '—' }}
 </strong>

 <span class="analytics-records-distribution-share">
 {{ (int) ($segment['share'] ?? 0) }}%
 </span>
 </div>
 @endforeach
 </div>

 </div>
 </section>
 @endif

 @if(! $isEmptyResult && $structuredDemand)
 @foreach($breakdownTables as $breakdown)
 @php
 $visual = $breakdown['visual'] ?? null;
 $barColumn = $breakdown['bar_column'] ?? null;
 $largest = $barColumn === null ? 0 : max(0, ...array_map(fn ($row) => (float) ($row[$barColumn] ?? 0), $breakdown['rows']));
 @endphp
 <section class="analytics-records-card analytics-demand-breakdown" aria-label="{{ $breakdown['title'] }}">
 <div class="analytics-records-card-head">
 <div><p class="analytics-records-eyebrow">Analytics breakdown</p><h2>{{ $breakdown['title'] }}</h2></div>
 </div>
 @if($visual === 'hours')
 <div class="demand-hours" aria-label="Hourly filing counts">
 @foreach($breakdown['rows'] as $hour)
 <div class="demand-hour{{ in_array($hour[0], $breakdown['peaks'] ?? [], true) ? ' is-peak' : '' }}">
 <strong>{{ $hour[1] }}</strong><span>{{ $hour[0] }}</span>
 </div>
 @endforeach
 </div>
 @else
 @if($visual === 'division')
 @php
 $total = array_sum(array_column($breakdown['rows'], 1));
 $offset = 0;
 $tones = ['#2563b5', '#0f978e', '#8257ca', '#94a3b8', '#c88b2c'];
 @endphp
 @if($total > 0)
 <div class="demand-donut">
 <div><span class="demand-donut-label">TOTAL FILED REQUESTS</span><strong>{{ $total }}</strong></div>
 <svg viewBox="0 0 120 120" role="img" aria-label="Distribution of {{ $total }} filed requests; exact counts are in the table below">
 <circle cx="60" cy="60" r="43" fill="none" stroke="var(--border)" stroke-width="19" />
 @foreach($breakdown['rows'] as $slice)
 @php $portion = $slice[1] / $total * 100; @endphp
 <circle cx="60" cy="60" r="43" pathLength="100" fill="none" stroke="{{ $tones[$loop->index % count($tones)] }}" stroke-width="19" stroke-dasharray="{{ $portion }} {{ 100 - $portion }}" stroke-dashoffset="{{ -$offset }}" transform="rotate(-90 60 60)" />
 @php $offset += $portion; @endphp
 @endforeach
 <text x="60" y="66" text-anchor="middle" fill="var(--heading)" font-size="20" font-weight="700">{{ $total }}</text>
 </svg>
 </div>
 @endif
 @endif
 <div class="analytics-records-table-wrap">
 <table class="analytics-records-table">
 <thead><tr>@foreach($breakdown['columns'] as $column)<th scope="col">{{ $column }}</th>@endforeach</tr></thead>
 <tbody>
 @foreach($breakdown['rows'] as $row)
 <tr>
 @foreach($row as $index => $cell)
 <td @if(is_numeric($cell)) class="is-number" @endif>{{ $cell ?? '—' }}
 @if($index === $barColumn && $largest > 0 && (float) $cell >= 0)
 <span class="demand-evidence-bar" aria-hidden="true"><span style="width: {{ min(100, (float) $cell / $largest * 100) }}%"></span></span>
 @endif
 </td>
 @endforeach
 </tr>
 @endforeach
 </tbody>
 @if(! empty($breakdown['footer']))
 <tfoot><tr>@foreach($breakdown['footer'] as $cell)<td class="{{ $loop->first ? '' : 'is-number' }}"><strong>{{ $cell }}</strong></td>@endforeach</tr></tfoot>
 @endif
 </table>
 </div>
 @endif
 @if(! empty($breakdown['note']))<div class="analytics-records-note">{{ $breakdown['note'] }}</div>@endif
 </section>
 @endforeach
 @endif

 @if($isEmptyResult)
 {{-- The dedicated compact empty state above is the complete zero-result view. --}}
 @elseif($table && $rows !== [])
 <section class="analytics-records-card">
 <div class="analytics-records-card-head">
 <div>
 <p class="analytics-records-eyebrow">
 {{ $isReturnTrendDetail ? 'Completed return records' : 'Supporting records' }}
 </p>
 <h2>{{ $detail['table_title'] ?? (($detail['title'] ?? 'Analytics').' Records') }}</h2>
 </div>


 </div>

 <div class="analytics-records-table-wrap">
 <table class="analytics-records-table" data-analytics-record-table data-record-table="Analytics records"
 @if($structuredDemand && isset($detail['table_filter_column'])) data-record-filter-column="{{ (int) $detail['table_filter_column'] }}" @endif
 @if($structuredDemand && ! empty($detail['table_filter_label'])) data-record-filter-label="{{ $detail['table_filter_label'] }}" @endif>
 <thead>
 <tr>
 @foreach($columns as $column)
 <th>{{ $column }}</th>
 @endforeach
 </tr>
 </thead>
 <tbody>
 @foreach($rows as $row)
 <tr data-analytics-record-row @if($structuredDemand && isset($detail['table_filter_values'][$loop->index])) data-record-filter="{{ $detail['table_filter_values'][$loop->index] }}" @endif>
 @foreach($row as $cellIndex => $cell)
@php
 $columnLabel = (string) ($columns[$cellIndex] ?? '');
 $isStatusColumn = in_array(
 str($columnLabel)->lower()->toString(),
 ['status', 'state', 'result'],
 true
 );

 $primary = is_array($cell)
 ? ($cell['primary'] ?? $cell['value'] ?? '—')
 : ($cell ?? '—');

 $secondary = is_array($cell)
 ? ($cell['secondary'] ?? null)
 : null;
 @endphp

 <td>
 @if($isStatusColumn)
 <span class="analytics-records-status {{ $statusTone((string) $primary) }}">
 {{ $primary }}
 </span>
 @else
 <span class="analytics-records-cell">
 @if($structuredDemand && is_array($cell) && ! empty($cell['url']))
 <a class="analytics-records-source-link" href="{{ $cell['url'] }}">{{ $primary }}</a>
 @else
 <span class="analytics-records-cell-primary">{{ $primary }}</span>
 @endif
 @if($secondary !== null && $secondary !== '')
 <span class="analytics-records-cell-secondary">{{ $secondary }}</span>
 @endif
 </span>
 @endif
 </td>
 @endforeach
 </tr>
 @endforeach
 </tbody>
 </table>
 </div>


 </section>
 @elseif($structuredDemand && $breakdownTables !== [])
 @if(! empty($detail['empty']))
 <section class="analytics-records-card analytics-records-note">{{ $detail['empty'] }}</section>
 @endif
 @elseif($bars !== [] && ! $structuredDemand)
 <section class="analytics-records-card">
 <div class="analytics-records-card-head">
 <div>
 <p class="analytics-records-eyebrow">Analytics breakdown</p>
 <h2>Supporting information for this analytics result</h2>
 </div>
 </div>

 <table class="analytics-records-breakdown">
 <tbody>
 @foreach($bars as $bar)
 <tr>
 <td>{{ $bar['label'] ?? 'Breakdown' }}</td>
 <td>{{ $bar['value'] ?? '—' }}</td>
 </tr>
 @endforeach
 </tbody>
 </table>
 </section>
 @elseif(! $isEmptyResult && $stats !== [])
 <section class="analytics-records-card analytics-records-note" aria-live="polite">
 {{ $detail['empty'] ?? 'This analytics result is summarized above and has no additional transaction-level records for the selected scope.' }}
 </section>
 @else
 <section class="analytics-records-empty" aria-labelledby="analytics-empty-title">
 <div class="analytics-records-empty-inner">

 <span class="analytics-records-empty-icon" aria-hidden="true">
 @switch($emptyIcon)

 @case('requests')
 <x-icon name="requests" size="36" />
 @break

 @case('inventory')
 <svg viewBox="0 0 64 64">
 <path class="empty-icon-soft" d="M13 22l19-11 19 11v22L32 55 13 44z"/>
 <path class="empty-icon-line" d="M12 21l20-11 20 11v22L32 54 12 43zM12 21l20 11 20-11M32 32v22M22 16l20 11"/>
 <circle class="empty-icon-line" cx="47" cy="46" r="7"/>
 <path class="empty-icon-line" d="M52 51l5 5"/>
 </svg>
 @break

 @case('returns')
 <svg viewBox="0 0 64 64">
 <path class="empty-icon-soft" d="M18 13h29v39H18z"/>
 <path class="empty-icon-line" d="M18 10h26l7 7v35H18zM44 10v8h7M24 29h20M24 36h15"/>
 <path class="empty-icon-line" d="M14 45h19M14 45l5-5M14 45l5 5"/>
 </svg>
 @break

 @case('organization')
 <svg viewBox="0 0 64 64">
 <path class="empty-icon-soft" d="M13 23h38v30H13z"/>
 <path class="empty-icon-line" d="M11 53h42M16 53V24h32v29M22 31h5M37 31h5M22 38h5M37 38h5M29 53V43h6v10M13 24l19-12 19 12"/>
 <circle class="empty-icon-line" cx="50" cy="18" r="5"/>
 </svg>
 @break

 @case('borrower')
 <svg viewBox="0 0 64 64">
 <circle class="empty-icon-soft" cx="30" cy="25" r="12"/>
 <circle class="empty-icon-line" cx="30" cy="22" r="9"/>
 <path class="empty-icon-line" d="M14 52c2-10 8-15 16-15s14 5 16 15"/>
 <circle class="empty-icon-line" cx="48" cy="43" r="7"/>
 <path class="empty-icon-line" d="M53 48l5 5"/>
 </svg>
 @break

 @default
 <svg viewBox="0 0 64 64">
 <path class="empty-icon-soft" d="M15 10h27l9 9v32H15z"/>
 <path class="empty-icon-line" d="M16 8h25l9 9v35H16zM41 8v10h9M23 27h20M23 35h17"/>
 <circle class="empty-icon-line" cx="45" cy="45" r="7"/>
 <path class="empty-icon-line" d="M50 50l5 5"/>
 </svg>
 @endswitch
 </span>

 <h2 id="analytics-empty-title">{{ $emptyTitle }}</h2>

 <p>
 {{ $detail['empty'] ?? 'Records will appear here once data becomes available.' }}
 </p>

 </div>
 </section>
 @endif

</div>


@php
    /*
     * Universal Analytics detail contract.
     *
     * Existing detail types remain compatible.
     * A detail may optionally provide:
     *
     * summary_title
     * supporting_title
     * display_period
     * status_insight
     */
    $analyticsUniversal = [
        'title' =>
            (string) ($detail['title'] ?? ''),

        'summaryTitle' =>
            $detail['summary_title'] ?? null,

        'supportingTitle' =>
            $detail['supporting_title'] ?? null,

        'structuredDemand' => $structuredDemand,

        'displayPeriod' =>
            $detail['display_period'] ?? null,

        'statusInsight' =>
            $detail['status_insight'] ?? null,
    ];
@endphp

<style>

.analytics-universal-insight {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 10px;
    align-items: start;

    margin-top: 12px;
    padding: 13px 12px;

    border-radius: 11px;
}

.analytics-universal-insight.is-positive {
    border: 1px solid #bce5d8;
    background: #edf9f5;
}

.analytics-universal-insight.is-warning {
    border: 1px solid #efd49a;
    background: #fff8e8;
}

.analytics-universal-insight-icon {
    display: grid;
    place-items: center;

    width: 27px;
    height: 27px;

    border-radius: 50%;

    font-size: 13px;
    font-weight: 900;
}

.analytics-universal-insight.is-positive
.analytics-universal-insight-icon {
    color: #047857;
    background: #d9f4ea;
}

.analytics-universal-insight.is-warning
.analytics-universal-insight-icon {
    color: #a16207;
    background: #fef3c7;
}

.analytics-universal-insight-copy strong {
    display: block;

    color: var(--heading);
    font-size: 12px;
    font-weight: 800;
}

.analytics-universal-insight-copy p {
    margin: 3px 0 0;

    color: var(--text-secondary);
    font-size: 10.5px;
    line-height: 1.45;
}

.analytics-equipment-capacity {
    display: grid;
    grid-template-columns:
        minmax(75px, 1fr)
        auto;

    gap: 8px;
    align-items: center;
}

.analytics-equipment-capacity-track {
    width: 100%;
    height: 7px;

    overflow: hidden;

    border-radius: 999px;

    background: #e8eef5;
}

.analytics-equipment-capacity-fill {
    display: block;

    height: 100%;

    border-radius: inherit;

    background: #11896f;
}

.analytics-equipment-capacity-fill.is-capacity {
    background: #d79a16;
}

.analytics-equipment-capacity-fill.is-shortage {
    background: #dc4c45;
}

.analytics-equipment-capacity-value {
    min-width: 34px;

    color: var(--text-secondary);

    font-size: 10px;
    font-weight: 800;
    text-align: right;

    font-variant-numeric:
        tabular-nums;
}

.analytics-detail-status {
    display: inline-flex;
    align-items: center;

    padding: 4px 8px;

    border-radius: 999px;

    font-size: 9px;
    font-weight: 800;

    text-transform: uppercase;
}

.analytics-detail-status.is-sufficient {
    color: #047857;

    border: 1px solid #a7dfc5;

    background: #ecfdf5;
}

.analytics-detail-status.is-capacity {
    color: #a16207;

    border: 1px solid #f1d48d;

    background: #fffbeb;
}

.analytics-detail-status.is-shortage {
    color: #b42318;

    border: 1px solid #f2b8b5;

    background: #fff1f0;
}

html[data-theme="dark"]
.analytics-equipment-capacity-track {
    background:
        rgba(
            148,
            163,
            184,
            0.18
        );
}
</style>

<script>
(() => {

    const detail =
        @json($analyticsUniversal);

    const normalize = (value) =>
        String(value || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

    const title =
        normalize(
            detail.title
        );

    const supportingNames = {

        'equipment demand & availability':
            'Equipment Coverage',

'return performance trend':
            'Supporting Records',

        'return outcome':
            'Return Distribution',

        'scheduled demand by division':
            'Demand Breakdown',

        'scheduled demand by unit':
            'Demand Breakdown',

        'expected busy period':
            'Period Distribution',

        'planning notes':
            'Planning Insights',

'current overdue exposure':
            'Overdue Cases',

        'return condition':
            'Condition Distribution',

        'accountability & return issues':
            'Issue Breakdown'
    };

    const supportingTitle =
        String(
            detail.supportingTitle
            ||
            supportingNames[title]
            ||
            'Supporting Data'
        );

    if (detail.summaryTitle) {

        Array.from(
            document.querySelectorAll(
                'p, span, h2, h3'
            )
        )
        .filter((element) => {

            if (element.children.length > 0) {
                return false;
            }

            return (
                normalize(
                    element.textContent
                )
                ===
                'metric summary'
            );
        })
        .forEach((element) => {

            element.textContent =
                String(
                    detail.summaryTitle
                ).toUpperCase();
        });
    }

    if (!detail.structuredDemand) {
    Array.from(
        document.querySelectorAll(
            'p, span, h2, h3'
        )
    )
    .filter((element) => {

        if (element.children.length > 0) {
            return false;
        }

        const value =
            normalize(
                element.textContent
            );

        return (
            value ===
                'analytics breakdown'
            ||
            value ===
                'supporting data'
        );
    })
    .forEach((element) => {

        element.textContent =
            supportingTitle.toUpperCase();
    });

    Array.from(
        document.querySelectorAll(
            'h2, h3'
        )
    )
    .filter((element) => {

        return (
            normalize(
                element.textContent
            )
            ===
            'supporting information for this analytics result'
        );
    })
    .forEach((element) => {

        element.textContent =
            supportingTitle;
    });

    }

    if (detail.displayPeriod) {

        const rangePattern =
            /^\d{2}\s+[a-z]{3}\s+\d{4}\s*[–—-]\s*\d{2}\s+[a-z]{3}\s+\d{4}$/i;

        const candidate =
            Array.from(
                document.querySelectorAll(
                    'span, p, div'
                )
            )
            .find((element) => {

                if (element.children.length > 0) {
                    return false;
                }

                return rangePattern.test(
                    String(
                        element.textContent
                        || ''
                    ).trim()
                );
            });

        if (candidate) {

            candidate.textContent =
                'Scheduled Period: '
                +
                String(
                    detail.displayPeriod
                );
        }
    }

    const fillerTexts = [
        'this analytics result is summarized above and has no additional transaction-level records for the selected scope.',
        'this result is an aggregate, not one record type'
    ];

    Array.from(
        document.querySelectorAll(
            'p, span, div'
        )
    )
    .filter((element) => {

        if (element.children.length > 0) {
            return false;
        }

        return fillerTexts.includes(
            normalize(
                element.textContent
            )
        );
    })
    .forEach((element) => {

        element.style.display =
            'none';
    });

    let supportingCard = null;

    if (
        title ===
        'equipment demand & availability'
    ) {

        const table =
            Array.from(
                document.querySelectorAll(
                    'table'
                )
            )
            .find((tableElement) => {

                const headers =
                    Array.from(
                        tableElement.querySelectorAll(
                            'th'
                        )
                    )
                    .map((header) =>
                        normalize(
                            header.textContent
                        )
                    );

                return (
                    headers.includes(
                        'equipment'
                    )
                    &&
                    headers.includes(
                        'scheduled demand'
                    )
                    &&
                    headers.includes(
                        'expected available'
                    )
                    &&
                    headers.includes(
                        'stock required'
                    )
                );
            });

        if (table) {

            supportingCard =
                table.closest(
                    'section, article'
                )
                ||
                table.parentElement;

            Array.from(
                table.querySelectorAll(
                    'tbody tr'
                )
            )
            .forEach((row) => {

                const cells =
                    row.querySelectorAll(
                        'td'
                    );

                if (cells.length < 6) {
                    return;
                }

                const requiredCell =
                    cells[3];

                const percent =
                    Math.max(
                        0,
                        Number.parseInt(
                            String(
                                requiredCell.textContent
                            )
                            .replace(
                                /[^0-9]/g,
                                ''
                            ),
                            10
                        )
                        || 0
                    );

                const statusCell =
                    cells[5];

                const originalStatus =
                    String(
                        statusCell.textContent
                        || ''
                    ).trim();

                const status =
                    normalize(
                        originalStatus
                    );

                let state =
                    'sufficient';

                if (
                    status ===
                    'shortage'
                ) {

                    state =
                        'shortage';

                } else if (
                    status ===
                    'at capacity'
                ) {

                    state =
                        'capacity';
                }

                requiredCell.innerHTML = `
                    <div class="analytics-equipment-capacity">

                        <span
                            class="analytics-equipment-capacity-track"
                        >
                            <span
                                class="analytics-equipment-capacity-fill is-${state}"
                                style="width:${Math.min(100, percent)}%"
                            ></span>
                        </span>

                        <strong
                            class="analytics-equipment-capacity-value"
                        >
                            ${percent}%
                        </strong>

                    </div>
                `;

                statusCell.innerHTML =
                    '';

                const badge =
                    document.createElement(
                        'span'
                    );

                badge.className =
                    'analytics-detail-status '
                    +
                    `is-${state}`;

                badge.textContent =
                    originalStatus;

                statusCell.appendChild(
                    badge
                );
            });
        }
    }

    const insight =
        detail.statusInsight;

    if (
        insight
        &&
        insight.title
    ) {

        const alreadyVisible =
            Array.from(
                document.querySelectorAll(
                    'strong'
                )
            )
            .some((element) => {

                return (
                    normalize(
                        element.textContent
                    )
                    ===
                    normalize(
                        insight.title
                    )
                );
            });

        if (!alreadyVisible) {

            const warning =
                normalize(
                    insight.tone
                )
                ===
                'warning';

            const box =
                document.createElement(
                    'div'
                );

            box.className =
                'analytics-universal-insight '
                +
                (
                    warning
                        ? 'is-warning'
                        : 'is-positive'
                );

            box.dataset.analyticsUniversalInsight =
                '1';

            box.innerHTML = `
                <span
                    class="analytics-universal-insight-icon"
                    aria-hidden="true"
                >
                    ${warning ? '!' : '&#10003;'}
                </span>

                <div
                    class="analytics-universal-insight-copy"
                >
                    <strong></strong>
                    <p></p>
                </div>
            `;

            box
                .querySelector(
                    'strong'
                )
                .textContent =
                    String(
                        insight.title
                    );

            box
                .querySelector(
                    'p'
                )
                .textContent =
                    String(
                        insight.message
                        || ''
                    );

            if (
                supportingCard
                &&
                supportingCard.parentNode
            ) {

                supportingCard
                    .insertAdjacentElement(
                        'afterend',
                        box
                    );

            } else {

                const tables =
                    Array.from(
                        document.querySelectorAll(
                            'table'
                        )
                    );

                const lastTable =
                    tables.length > 0
                        ? tables[
                            tables.length - 1
                        ]
                        : null;

                const tableCard =
                    lastTable
                        ? (
                            lastTable.closest(
                                'section, article'
                            )
                            ||
                            lastTable.parentElement
                        )
                        : null;

                if (
                    tableCard
                    &&
                    tableCard.parentNode
                ) {

                    tableCard
                        .insertAdjacentElement(
                            'afterend',
                            box
                        );
                }
            }
        }
    }

})();
</script>

@php
    /*
     * Generic Analytics detail visualization contract.
     *
     * Any current or future detail may use:
     *
     * visualization = time-columns
     * series[]
     * display_period
     * period_kind
     * header_chip
     * axis_label
     * insight_title
     * insight_text
     */
    $universalVisualization =
        $detail['visualization']
        ?? null;

    $universalSeries =
        collect(
            $detail['series']
            ?? []
        );

    $universalAxisLabel =
        (string) (
            $detail['axis_label']
            ?? 'Value'
        );

    $universalDisplayPeriod =
        $detail['display_period']
        ?? null;

    $universalPeriodKind =
        $detail['period_kind']
        ?? null;

    $universalHeaderChip =
        $detail['header_chip']
        ?? null;

    $universalInsightTitle =
        $detail['insight_title']
        ?? null;

    $universalInsightText =
        $detail['insight_text']
        ?? null;

    $universalContext =
        (string) (
            $detail['context']
            ?? ''
        );

    $universalSeriesMax =
        max(
            1,
            (int) (
                $universalSeries
                    ->max('value')
                ?? 0
            )
        );

    $universalSeriesMiddle =
        (int) ceil(
            $universalSeriesMax / 2
        );
@endphp

@if(
    $universalVisualization === 'time-columns'
    && $universalSeries->isNotEmpty()
)

    <section
        id="universal-time-series-detail"
        class="universal-time-series-card"
        hidden
    >

        <header class="universal-time-series-head">

            <div>
                <span class="universal-time-series-eyebrow">
                    PERIOD DISTRIBUTION
                </span>

                <h2>
                    Projected requests by week
                </h2>
            </div>

        </header>

        <div class="universal-time-series-content">

            <div class="universal-time-series-chart">

                <div class="universal-time-series-yaxis">

                    <span class="is-top">
                        {{ $universalSeriesMax }}
                    </span>

                    <span class="is-middle">
                        {{ $universalSeriesMiddle }}
                    </span>

                    <span class="is-bottom">
                        0
                    </span>

                </div>

                <div class="universal-time-series-main">

                    <div class="universal-time-series-plot">

                        <span
                            class="universal-time-series-gridline is-top"
                            aria-hidden="true"
                        ></span>

                        <span
                            class="universal-time-series-gridline is-middle"
                            aria-hidden="true"
                        ></span>

                        <span
                            class="universal-time-series-gridline is-bottom"
                            aria-hidden="true"
                        ></span>

                        <div class="universal-time-series-columns">

                            @foreach($universalSeries as $row)

@php
                                    $value =
                                        max(
                                            0,
                                            (int) (
                                                $row['value']
                                                ?? 0
                                            )
                                        );

                                    $isPeak =
                                        (bool) (
                                            $row['peak']
                                            ?? false
                                        );

                                    $height =
                                        $value > 0
                                            ? max(
                                                8,
                                                (int) round(
                                                    (
                                                        $value
                                                        / $universalSeriesMax
                                                    )
                                                    * 100
                                                )
                                            )
                                            : 0;
                                @endphp

                                <div
                                    class="
                                        universal-time-series-item
                                        {{ $isPeak ? 'is-peak' : '' }}
                                        {{ $value === 0 ? 'is-zero' : '' }}
                                    "
                                    title="
                                        {{ $row['label'] ?? 'Period' }}
                                        {{ ! empty($row['range']) ? '('.$row['range'].')' : '' }}:
                                        {{ $value }}
                                        {{ $value === 1 ? 'projected request' : 'projected requests' }}
                                    "
                                >

                                    <strong
                                        class="universal-time-series-value"
                                    >
                                        {{ $value }}
                                    </strong>

                                    <div
                                        class="universal-time-series-bar-area"
                                    >

                                        <span
                                            class="
                                                universal-time-series-bar
                                                {{ $isPeak ? 'is-peak' : '' }}
                                            "
                                            style="height: {{ $height }}%;"
                                        >

                                            @if($isPeak && $value > 0)

                                                <span
                                                    class="universal-time-series-peak"
                                                >
                                                    PEAK
                                                </span>

                                            @endif

                                        </span>

                                    </div>

                                </div>

                            @endforeach

                        </div>

                    </div>

                    <div class="universal-time-series-labels">

                        @foreach($universalSeries as $row)

                            <div
                                class="
                                    universal-time-series-label
                                    {{ ($row['peak'] ?? false) ? 'is-peak' : '' }}
                                "
                            >
                                {{ $row['label'] ?? 'Period' }}
                            </div>

                        @endforeach

                    </div>

                    <div class="universal-time-series-axis-title">
                        {{ $universalAxisLabel }}
                    </div>

                </div>

            </div>

            @if($universalInsightText)

                <div class="universal-time-series-insight">

                    <span
                        class="universal-time-series-insight-icon"
                        aria-hidden="true"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            focusable="false"
                        >
                            <path
                                d="M9 18h6"
                            ></path>

                            <path
                                d="M10 22h4"
                            ></path>

                            <path
                                d="M8.5 14.5
                                   C7.2 13.5 6.5 12 6.5 10.2
                                   C6.5 7.1 8.9 4.5 12 4.5
                                   C15.1 4.5 17.5 7.1 17.5 10.2
                                   C17.5 12 16.8 13.5 15.5 14.5
                                   C14.7 15.1 14.2 15.8 14 17
                                   H10
                                   C9.8 15.8 9.3 15.1 8.5 14.5Z"
                            ></path>
                        </svg>
                    </span>

                    <div>

                        @if($universalInsightTitle)

                            <strong>
                                {{ $universalInsightTitle }}
                            </strong>

                        @endif

                        <p>
                            {{ $universalInsightText }}
                        </p>

                    </div>

                </div>

            @endif

        </div>

    </section>

@endif

<style>

.universal-time-series-card {
    overflow: hidden;

    margin-top: 12px;

    border:
        1px solid
        var(--border);

    border-radius: 12px;

    background:
        var(
            --surface-elevated,
            #ffffff
        );
}

.universal-time-series-head {
    padding:
        12px
        16px;

    border-bottom:
        1px solid
        var(--border);
}

.universal-time-series-eyebrow {
    display: block;

    margin-bottom: 3px;

    color:
        var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: 0.08em;
}

.universal-time-series-head h2 {
    margin: 0;

    color:
        var(--heading);

    font-size: 14px;
    font-weight: 800;
}

.universal-time-series-content {
    padding:
        12px
        16px
        12px;
}

.universal-time-series-chart {
    display: grid;

    grid-template-columns:
        30px
        minmax(0, 1fr);

    gap: 10px;
}

.universal-time-series-yaxis {
    position: relative;

    height: 190px;

    color:
        var(--text-muted);

    font-size: 9px;

    font-variant-numeric:
        tabular-nums;
}

.universal-time-series-yaxis span {
    position: absolute;

    right: 0;
}

.universal-time-series-yaxis .is-top {
    top: 0;
}

.universal-time-series-yaxis .is-middle {
    top: 50%;

    transform:
        translateY(-50%);
}

.universal-time-series-yaxis .is-bottom {
    bottom: 0;
}

.universal-time-series-main {
    min-width: 0;
}

.universal-time-series-plot {
    position: relative;

    height: 190px;
}

.universal-time-series-gridline {
    position: absolute;

    left: 0;
    right: 0;

    border-top:
        1px solid
        var(--border);
}

.universal-time-series-gridline.is-top {
    top: 0;
}

.universal-time-series-gridline.is-middle {
    top: 50%;
}

.universal-time-series-gridline.is-bottom {
    bottom: 0;
}

.universal-time-series-columns {
    position: absolute;

    inset:
        0
        14px;

    display: flex;

    align-items: stretch;
    justify-content: space-around;

    gap:
        clamp(
            12px,
            4%,
            30px
        );
}

.universal-time-series-item {
    position: relative;

    flex:
        1
        1
        0;

    min-width: 52px;
    max-width: 105px;
}

.universal-time-series-value {
    position: absolute;

    left: 50%;

    z-index: 5;

    transform:
        translateX(-50%);

    color:
        var(--heading);

    font-size: 11px;
    font-weight: 850;

    font-variant-numeric:
        tabular-nums;
}

.universal-time-series-item:not(.is-zero)
.universal-time-series-value {
    top: 0;
}

.universal-time-series-item.is-zero
.universal-time-series-value {
    top: auto;
    bottom: 3px;
}

.universal-time-series-bar-area {
    position: absolute;

    inset:
        18px
        0
        0;

    display: flex;

    align-items: flex-end;
    justify-content: center;
}

.universal-time-series-bar {
    position: relative;

    display: block;

    width:
        clamp(
            30px,
            58%,
            50px
        );

    border-radius:
        6px
        6px
        0
        0;

    background:
        linear-gradient(
            180deg,
            #4182f4,
            #2f6ee8
        );
}

.universal-time-series-bar.is-peak {
    background:
        linear-gradient(
            180deg,
            #18a489,
            #11896f
        );
}

.universal-time-series-item.is-zero
.universal-time-series-bar {
    height: 0 !important;

    background:
        transparent;
}

.universal-time-series-peak {
    position: absolute;

    top: 10px;
    left: 50%;

    z-index: 6;

    transform:
        translateX(-50%);

    padding:
        3px
        6px;

    border-radius: 999px;

    color: #047857;

    background:
        rgba(
            255,
            255,
            255,
            0.94
        );

    border:
        1px solid
        rgba(
            255,
            255,
            255,
            0.78
        );

    font-size: 8px;
    font-weight: 900;

    line-height: 1;

    white-space: nowrap;
}

.universal-time-series-labels {
    display: flex;

    justify-content: space-around;

    gap:
        clamp(
            12px,
            4%,
            30px
        );

    padding:
        8px
        12px
        0;
}

.universal-time-series-label {
    flex:
        1
        1
        0;

    min-width: 52px;
    max-width: 105px;

    color:
        var(--text-secondary);

    font-size: 9.5px;
    font-weight: 700;

    text-align: center;
}

.universal-time-series-label.is-peak {
    color: #08765f;

    font-weight: 850;
}

.universal-time-series-axis-title {
    margin-top: 11px;

    color:
        var(--text-muted);

    font-size: 9.5px;

    text-align: center;
}

.universal-time-series-insight {
    display: grid;

    grid-template-columns:
        auto
        minmax(0, 1fr);

    gap: 10px;

    align-items: center;

    margin-top: 12px;

    padding:
        11px
        13px;

    border:
        1px solid
        #bce5d8;

    border-radius: 9px;

    background:
        #edf9f5;
}

.universal-time-series-insight-icon {
    display: grid;

    place-items: center;

    width: 25px;
    height: 25px;

    border-radius: 50%;

    color: #047857;

    background:
        #d9f4ea;

    border:
        1px solid
        #bce5d8;
}

.universal-time-series-insight-icon svg {
    width: 13px;
    height: 13px;

    fill: none;

    stroke:
        currentColor;

    stroke-width: 2;

    stroke-linecap: round;
    stroke-linejoin: round;
}

.universal-time-series-insight strong {
    display: block;

    color:
        var(--heading);

    font-size: 10.5px;
    font-weight: 800;
}

.universal-time-series-insight p {
    margin:
        2px
        0
        0;

    color:
        var(--text-secondary);

    font-size: 9.5px;
    line-height: 1.4;
}

@media (max-width: 680px) {

    .universal-time-series-plot,
    .universal-time-series-yaxis {
        height: 160px;
    }

    .universal-time-series-columns,
    .universal-time-series-labels {
        gap: 7px;
    }

    .universal-time-series-item,
    .universal-time-series-label {
        min-width: 38px;
    }

    .universal-time-series-label {
        font-size: 8.5px;
    }
}
</style>

<script>
(() => {

    const configuration = {
        visualization:
            @json($universalVisualization),

        displayPeriod:
            @json($universalDisplayPeriod),

        periodKind:
            @json($universalPeriodKind),

        headerChip:
            @json($universalHeaderChip),

        context:
            @json($universalContext)
    };

    const normalize = (value) =>
        String(value || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

    if (
        configuration.displayPeriod
    ) {

        const periodLabel =
            configuration.periodKind === 'scheduled'
                ? 'Scheduled Period'
                : 'Reporting Period';

        const dateRangePattern =
            /^\d{2}\s+[a-z]{3}\s+\d{4}\s*[–—-]\s*\d{2}\s+[a-z]{3}\s+\d{4}$/i;

        const currentRange =
            Array.from(
                document.querySelectorAll(
                    'span, div, p'
                )
            )
            .find((element) => {

                if (
                    element.children.length > 0
                ) {
                    return false;
                }

                return dateRangePattern.test(
                    String(
                        element.textContent
                        || ''
                    ).trim()
                );
            });

        if (currentRange) {

            currentRange.textContent =
                `${periodLabel}: ${configuration.displayPeriod}`;
        }
    }

    if (
        configuration.headerChip
    ) {

        const candidates =
            Array.from(
                document.querySelectorAll(
                    'span, div, p'
                )
            )
            .filter((element) => {

                if (
                    element.children.length > 0
                ) {
                    return false;
                }

                const text =
                    normalize(
                        element.textContent
                    );

                if (
                    text.length === 0
                    ||
                    text.length > 90
                ) {
                    return false;
                }

                return (
                    text.includes(
                        'busiest projected period'
                    )
                    ||
                    text.includes(
                        'tied busiest projected periods'
                    )
                    ||
                    /-\s*peak$/.test(
                        text
                    )
                );
            });

        if (
            candidates.length > 0
        ) {

            candidates[0].textContent =
                configuration.headerChip;
        }
    }

    if (
        configuration.context
    ) {

        const matches =
            Array.from(
                document.querySelectorAll(
                    'p, span, h2, h3, div'
                )
            )
            .filter((element) => {

                if (
                    element.children.length > 0
                ) {
                    return false;
                }

                return (
                    normalize(
                        element.textContent
                    )
                    ===
                    normalize(
                        configuration.context
                    )
                );
            });

        matches
            .slice(1)
            .forEach((element) => {

                element.style.display =
                    'none';
            });
    }

    const chart =
        document.getElementById(
            'universal-time-series-detail'
        );

    if (
        configuration.visualization === 'time-columns'
        &&
        chart
    ) {

        const metricLabel =
            Array.from(
                document.querySelectorAll(
                    'span, p, h2, h3, div'
                )
            )
            .find((element) => {

                if (
                    element.children.length > 0
                ) {
                    return false;
                }

                return (
                    normalize(
                        element.textContent
                    )
                    ===
                    'metric summary'
                );
            });

        if (metricLabel) {

            let summaryCard =
                metricLabel.closest(
                    'section, article'
                );

            if (!summaryCard) {

                summaryCard =
                    metricLabel.parentElement;

                while (
                    summaryCard
                    &&
                    summaryCard.parentElement
                    &&
                    summaryCard.getBoundingClientRect().width
                        <
                    (
                        summaryCard.parentElement
                            .getBoundingClientRect()
                            .width
                        * 0.8
                    )
                ) {

                    summaryCard =
                        summaryCard.parentElement;
                }
            }

            if (
                summaryCard
                &&
                summaryCard.parentNode
            ) {

                summaryCard.insertAdjacentElement(
                    'afterend',
                    chart
                );
            }
        }

        chart.hidden =
            false;
    }

    const positionValues = () => {

        if (!chart) {
            return;
        }

        chart
            .querySelectorAll(
                '.universal-time-series-item'
            )
            .forEach((item) => {

                const value =
                    item.querySelector(
                        '.universal-time-series-value'
                    );

                const bar =
                    item.querySelector(
                        '.universal-time-series-bar'
                    );

                if (
                    !value
                    ||
                    !bar
                ) {
                    return;
                }

                const numericValue =
                    Number.parseInt(
                        value.textContent,
                        10
                    )
                    || 0;

                if (
                    numericValue === 0
                ) {

                    value.style.top =
                        'auto';

                    value.style.bottom =
                        '3px';

                    return;
                }

                const barHeight =
                    bar.getBoundingClientRect()
                        .height;

                const itemHeight =
                    item.getBoundingClientRect()
                        .height;

                const valueHeight =
                    value.getBoundingClientRect()
                        .height;

                const bottom =
                    Math.min(
                        barHeight + 5,
                        Math.max(
                            4,
                            itemHeight
                            - valueHeight
                            - 2
                        )
                    );

                value.style.top =
                    'auto';

                value.style.bottom =
                    `${bottom}px`;
            });
    };

    requestAnimationFrame(() => {

        positionValues();

        requestAnimationFrame(
            positionValues
        );
    });

    setTimeout(
        positionValues,
        100
    );

    window.addEventListener(
        'resize',
        positionValues,
        {
            passive: true
        }
    );

})();
</script>

<script>
(() => {

    const TARGET =
        'this analytics result is summarized above and has no additional transaction-level records for the selected scope.';

    const normalize = (value) =>
        String(value || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

    const removeGenericAnalyticsFooter = () => {

        const hasSupportingContent =
            document.querySelector(
                '#universal-time-series-detail:not([hidden]), ' +
                '.universal-time-series-card:not([hidden]), ' +
                '.analytics-readiness-requirements, ' +
                '.analytics-supporting-records, ' +
                '.analytics-detail-table, ' +
                '.analytics-detail-bars'
            );

        if (!hasSupportingContent) {
            return false;
        }

        const walker =
            document.createTreeWalker(
                document.body,
                NodeFilter.SHOW_TEXT
            );

        let node = null;

        while (
            (node = walker.nextNode())
        ) {

            if (
                normalize(node.nodeValue)
                !== TARGET
            ) {
                continue;
            }

            let element =
                node.parentElement;

            if (!element) {
                continue;
            }

            const semanticCard =
                element.closest(
                    'section, article, ' +
                    '.analytics-card, ' +
                    '.analytics-record-card, ' +
                    '.analytics-detail-card, ' +
                    '.content-card, ' +
                    '.card'
                );

            if (
                semanticCard
                &&
                !semanticCard.matches(
                    'main, body, .analytics-page'
                )
            ) {

                semanticCard.remove();

                return true;
            }

            let candidate =
                element;

            while (
                candidate
                &&
                candidate.parentElement
                &&
                !candidate.matches(
                    'main, body, .analytics-page'
                )
            ) {

                const style =
                    window.getComputedStyle(
                        candidate
                    );

                const rect =
                    candidate.getBoundingClientRect();

                const parentRect =
                    candidate.parentElement
                        .getBoundingClientRect();

                const hasCardShape =
                    parseFloat(
                        style.borderTopWidth
                    ) > 0
                    ||
                    parseFloat(
                        style.borderBottomWidth
                    ) > 0
                    ||
                    parseFloat(
                        style.borderRadius
                    ) >= 6;

                const isWideEnough =
                    parentRect.width > 0
                    &&
                    rect.width
                    >=
                    parentRect.width * 0.72;

                if (
                    hasCardShape
                    &&
                    isWideEnough
                ) {

                    candidate.remove();

                    return true;
                }

                candidate =
                    candidate.parentElement;
            }

            element.remove();

            return true;
        }

        return false;
    };

    const run = () => {

        removeGenericAnalyticsFooter();

        requestAnimationFrame(
            removeGenericAnalyticsFooter
        );

        setTimeout(
            removeGenericAnalyticsFooter,
            100
        );

        setTimeout(
            removeGenericAnalyticsFooter,
            300
        );
    };

    if (
        document.readyState === 'loading'
    ) {

        document.addEventListener(
            'DOMContentLoaded',
            run,
            {
                once: true
            }
        );

    } else {

        run();
    }

    const observer =
        new MutationObserver(() => {

            if (
                removeGenericAnalyticsFooter()
            ) {

                observer.disconnect();
            }
        });

    const startObserver = () => {

        if (!document.body) {
            return;
        }

        observer.observe(
            document.body,
            {
                childList: true,
                subtree: true
            }
        );

        setTimeout(
            () => observer.disconnect(),
            3000
        );
    };

    if (
        document.readyState === 'loading'
    ) {

        document.addEventListener(
            'DOMContentLoaded',
            startObserver,
            {
                once: true
            }
        );

    } else {

        startObserver();
    }

})();
</script>

@php
    /*
     * Universal signal-list renderer.
     *
     * Expected source contract:
     *
     * visualization => signal-list
     * period_kind
     * display_period
     * stats[]
     * signals[]
     */
    $signalMode =
        ($detail['visualization'] ?? null)
        ===
        'signal-list';

    $signalStats =
        collect(
            $detail['stats']
            ?? []
        );

    $signalRows =
        collect(
            $detail['signals']
            ?? []
        );

    $signalSummaryTitle =
        (string) (
            $detail['summary_title']
            ?? 'Analytics Summary'
        );

    $signalEyebrow =
        (string) (
            $detail['supporting_eyebrow']
            ?? 'ANALYTICS SIGNALS'
        );

    $signalSectionTitle =
        (string) (
            $detail['supporting_title']
            ?? 'Supporting signals'
        );

    $signalPeriod =
        (string) (
            $detail['display_period']
            ?? ''
        );

    $signalPeriodKind =
        (string) (
            $detail['period_kind']
            ?? 'reporting'
        );

    $signalCount =
        $signalRows->count();
@endphp

@if($signalMode)

    <div
        id="analytics-signal-v3"
        class="analytics-signal-v3"
        hidden
    >

        <div class="analytics-signal-v3-chips">

            @if($signalPeriod !== '')

                <span class="analytics-signal-v3-chip">

                    {{ $signalPeriodKind === 'scheduled'
                        ? 'Scheduled Period'
                        : 'Reporting Period'
                    }}:
                    {{ $signalPeriod }}

                </span>

            @endif

            <span class="analytics-signal-v3-chip">

                {{ $signalCount }}
                -
                {{ $signalCount === 1
                    ? 'planning signal'
                    : 'planning signals'
                }}

            </span>

        </div>

        @if($signalStats->isNotEmpty())

            <section class="analytics-signal-v3-card">

                <header class="analytics-signal-v3-head">

                    <span class="analytics-signal-v3-eyebrow">
                        {{ strtoupper($signalSummaryTitle) }}
                    </span>

                </header>

                <div
                    class="analytics-signal-v3-summary"
                    style="
                        --summary-columns:
                        {{ max(
                            1,
                            min(
                                4,
                                $signalStats->count()
                            )
                        ) }};
                    "
                >

                    @foreach($signalStats as $stat)

                        <article class="analytics-signal-v3-stat">

                            <strong>
                                {{ $stat['value'] ?? '—' }}
                            </strong>

                            <span>
                                {{ $stat['label'] ?? 'Metric' }}
                            </span>

                        </article>

                    @endforeach

                </div>

            </section>

        @endif

        <section class="analytics-signal-v3-card">

            <header class="analytics-signal-v3-head">

                <span class="analytics-signal-v3-eyebrow">
                    {{ $signalEyebrow }}
                </span>

                <h2>
                    {{ $signalSectionTitle }}
                </h2>

            </header>

            <div class="analytics-signal-v3-list">

                @forelse($signalRows as $signal)

@php
                        $tone =
                            (string) (
                                $signal['tone']
                                ?? 'info'
                            );

                        $icon =
                            (string) (
                                $signal['icon']
                                ?? 'information'
                            );
                    @endphp

                    <article
                        class="
                            analytics-signal-v3-row
                            tone-{{ $tone }}
                        "
                    >

                        <span
                            class="analytics-signal-v3-icon"
                            aria-hidden="true"
                        >
                            <x-icon
                                :name="$icon"
                                size="17"
                            />
                        </span>

                        <div class="analytics-signal-v3-copy">

                            <strong>
                                {{ $signal['title'] ?? 'Signal' }}
                            </strong>

                            @if(! empty($signal['description']))

                                <p>
                                    {{ $signal['description'] }}
                                </p>

                            @endif

                        </div>

                        @if(
                            array_key_exists(
                                'value',
                                $signal
                            )
                            &&
                            $signal['value'] !== null
                            &&
                            $signal['value'] !== ''
                        )

                            <span class="analytics-signal-v3-value">
                                {{ $signal['value'] }}
                            </span>

                        @endif

                    </article>

                @empty

                    <div class="analytics-signal-v3-empty">
                        No planning signals are available for this scope.
                    </div>

                @endforelse

            </div>

        </section>

    </div>

@endif

<style>

.analytics-signal-v3 {
    display: grid;

    gap: 16px;

    margin-top: 12px;
}

.analytics-signal-v3-chips {
    display: flex;

    flex-wrap: wrap;

    gap: 8px;
}

.analytics-signal-v3-chip {
    display: inline-flex;

    align-items: center;

    min-height: 30px;

    padding:
        5px
        11px;

    border:
        1px solid
        var(--border);

    border-radius: 999px;

    color:
        var(--text-secondary);

    background:
        var(
            --surface-elevated,
            #ffffff
        );

    font-size: 10px;
    font-weight: 700;

    line-height: 1.25;
}

.analytics-signal-v3-card {
    overflow: hidden;

    border:
        1px solid
        var(--border);

    border-radius: 12px;

    background:
        var(
            --surface-elevated,
            #ffffff
        );

    box-shadow:
        0 1px 2px
        rgba(
            15,
            23,
            42,
            0.03
        );
}

.analytics-signal-v3-head {
    padding:
        12px
        16px;

    border-bottom:
        1px solid
        var(--border);
}

.analytics-signal-v3-eyebrow {
    display: block;

    color:
        var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: 0.08em;
}

.analytics-signal-v3-head h2 {
    margin:
        4px
        0
        0;

    color:
        var(--heading);

    font-size: 14px;
    font-weight: 800;

    line-height: 1.3;
}

.analytics-signal-v3-summary {
    display: grid;

    grid-template-columns:
        repeat(
            var(--summary-columns),
            minmax(0, 1fr)
        );
}

.analytics-signal-v3-stat {
    min-width: 0;

    padding:
        12px
        16px;

    border-right:
        1px solid
        var(--border);
}

.analytics-signal-v3-stat:last-child {
    border-right: 0;
}

.analytics-signal-v3-stat strong {
    display: block;

    overflow-wrap: anywhere;

    color:
        var(--heading);

    font-size: 20px;
    font-weight: 800;

    line-height: 1.15;
}

.analytics-signal-v3-stat span {
    display: block;

    margin-top: 5px;

    color:
        var(--text-secondary);

    font-size: 10.5px;
    font-weight: 650;

    line-height: 1.35;
}

.analytics-signal-v3-list {
    display: grid;
}

.analytics-signal-v3-row {
    display: grid;

    grid-template-columns:
        36px
        minmax(0, 1fr)
        auto;

    align-items: center;

    gap: 13px;

    min-height: 78px;

    padding:
        12px
        12px;

    border-bottom:
        1px solid
        var(--border);
}

.analytics-signal-v3-row:last-child {
    border-bottom: 0;
}

.analytics-signal-v3-icon {
    display: grid;

    place-items: center;

    width: 31px;
    height: 31px;

    border-radius: 9px;

    color: #1677c8;

    background: #edf6fd;
}

.analytics-signal-v3-row.tone-success
.analytics-signal-v3-icon {
    color: #087f68;

    background: #e4f7f1;
}

.analytics-signal-v3-row.tone-warning
.analytics-signal-v3-icon {
    color: #b45309;

    background: #fff5e5;
}

.analytics-signal-v3-row.tone-neutral
.analytics-signal-v3-icon {
    color: #64748b;

    background: #f1f5f9;
}

.analytics-signal-v3-copy {
    min-width: 0;
}

.analytics-signal-v3-copy strong {
    display: block;

    color:
        var(--heading);

    font-size: 12px;
    font-weight: 800;

    line-height: 1.3;
}

.analytics-signal-v3-copy p {
    margin:
        4px
        0
        0;

    color:
        var(--text-secondary);

    font-size: 10.5px;

    line-height: 1.45;
}

.analytics-signal-v3-value {
    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-height: 28px;

    max-width: 220px;

    padding:
        4px
        10px;

    border:
        1px solid
        var(--border);

    border-radius: 999px;

    background:
        var(
            --surface-subtle,
            #f6f8fb
        );

    color:
        var(--heading);

    font-size: 10px;
    font-weight: 750;

    text-align: center;
}

@media (max-width: 760px) {

    .analytics-signal-v3-summary {
        grid-template-columns:
            1fr;
    }

    .analytics-signal-v3-stat {
        border-right: 0;

        border-bottom:
            1px solid
            var(--border);
    }

    .analytics-signal-v3-stat:last-child {
        border-bottom: 0;
    }

    .analytics-signal-v3-row {
        grid-template-columns:
            34px
            minmax(0, 1fr);
    }

    .analytics-signal-v3-value {
        grid-column: 2;

        justify-self: start;
    }
}

</style>

<script>
(() => {

    const enabled =
        @json($signalMode);

    if (!enabled) {
        return;
    }

    const normalize = (value) =>
        String(value || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

    const root =
        document.getElementById(
            'analytics-signal-v3'
        );

    if (!root) {
        return;
    }

    const detailTitle =
        normalize(
            @json($detail['title'] ?? '')
        );

    const heading =
        Array.from(
            document.querySelectorAll(
                'h1, h2'
            )
        )
        .find(
            (element) =>
                normalize(
                    element.textContent
                )
                ===
                detailTitle
        );

    let main =
        heading
            ? heading.closest(
                'main, .content, .page-content, .reports-content'
            )
            : null;

    if (!main) {
        main = document.body;
    }

    const datePattern =
        /\d{2}\s+[A-Za-z]{3}\s+\d{4}\s*[–—-]\s*\d{2}\s+[A-Za-z]{3}\s+\d{4}/;

    Array.from(
        main.querySelectorAll(
            'span, div, p'
        )
    )
    .forEach((element) => {

        if (
            root.contains(element)
            ||
            element.children.length > 0
        ) {
            return;
        }

        const text =
            String(
                element.textContent
                || ''
            )
            .trim();

        const normalized =
            normalize(text);

        const isOldDateChip =
            datePattern.test(text)
            ||
            normalized.startsWith(
                'scheduled period:'
            )
            ||
            normalized.startsWith(
                'reporting period:'
            );

        const isOldSignalChip =
            normalized.includes(
                'planning signal'
            );

        if (
            isOldDateChip
            ||
            isOldSignalChip
        ) {

            const chip =
                element.closest(
                    'span, a, button'
                )
                ||
                element;

            chip.remove();
        }
    });

    Array.from(
        main.querySelectorAll(
            'section, article, div'
        )
    )
    .forEach((element) => {

        if (
            element === root
            ||
            root.contains(element)
        ) {
            return;
        }

        const text =
            normalize(
                element.textContent
            );

        const hasSummaryHeading =
            text.startsWith(
                'planning summary'
            )
            ||
            text.startsWith(
                'metric summary'
            );

        const hasKnownSignal =
            text.includes(
                'capacity coverage'
            )
            ||
            text.includes(
                'scheduled demand'
            )
            ||
            text.includes(
                'demand concentration'
            )
            ||
            text.includes(
                'scheduled demand'
            );

        if (
            hasSummaryHeading
            &&
            hasKnownSignal
        ) {

            const card =
                element.closest(
                    'section, article, .card'
                )
                ||
                element;

            if (
                card !== main
                &&
                !root.contains(card)
            ) {

                card.remove();
            }
        }
    });

    const fallbackText =
        'this analytics result is summarized above and has no additional transaction-level records for the selected scope.';

    Array.from(
        main.querySelectorAll(
            'span, p, div'
        )
    )
    .forEach((element) => {

        if (
            root.contains(element)
            ||
            element.children.length > 0
        ) {
            return;
        }

        if (
            normalize(
                element.textContent
            )
            !==
            fallbackText
        ) {
            return;
        }

        const card =
            element.closest(
                'section, article, .card'
            );

        if (card) {
            card.remove();
        }
        else if (
            element.parentElement
            &&
            element.parentElement !== main
        ) {
            element.parentElement.remove();
        }
        else {
            element.remove();
        }
    });

    if (heading) {

        let headerBlock =
            heading.parentElement;

        while (
            headerBlock
            &&
            headerBlock.parentElement
            &&
            headerBlock.parentElement !== main
        ) {

            const rect =
                headerBlock
                    .getBoundingClientRect();

            const parentRect =
                headerBlock
                    .parentElement
                    .getBoundingClientRect();

            if (
                parentRect.width > 0
                &&
                rect.width
                >=
                parentRect.width * 0.80
            ) {
                break;
            }

            headerBlock =
                headerBlock.parentElement;
        }

        if (
            headerBlock
            &&
            headerBlock.parentNode
        ) {

            headerBlock
                .insertAdjacentElement(
                    'afterend',
                    root
                );
        }
    }

    root.hidden =
        false;

})();
</script>

<style>

.analytics-signal-v3 {
    gap: 12px !important;

    margin-top: 12px !important;
}

.analytics-signal-v3-chips {
    gap: 7px !important;

    margin:
        0
        0
        2px !important;
}

.analytics-signal-v3-chip {
    min-height: 28px !important;

    padding:
        4px
        10px !important;

    font-size: 9.5px !important;
}

.analytics-signal-v3-head {
    padding:
        12px
        12px !important;
}

.analytics-signal-v3-eyebrow {
    font-size: 8.5px !important;
}

.analytics-signal-v3-head h2 {
    margin-top: 3px !important;

    font-size: 13.5px !important;
}

.analytics-signal-v3-stat {
    padding:
        12px
        12px !important;
}

.analytics-signal-v3-stat strong {
    font-size: 19px !important;

    line-height: 1.1 !important;
}

.analytics-signal-v3-stat span {
    margin-top: 4px !important;

    font-size: 10px !important;
}

.analytics-signal-v3-row {
    min-height: 68px !important;

    gap: 12px !important;

    padding:
        11px
        12px !important;
}

.analytics-signal-v3-icon {
    width: 30px !important;
    height: 30px !important;
}

.analytics-signal-v3-copy strong {
    font-size: 11.5px !important;

    line-height: 1.25 !important;
}

.analytics-signal-v3-copy p {
    margin-top: 3px !important;

    font-size: 10px !important;

    line-height: 1.4 !important;
}

.analytics-signal-v3-value {
    min-height: 26px !important;

    padding:
        3px
        9px !important;

    font-size: 9.5px !important;
}

@media (max-width: 760px) {

    .analytics-signal-v3 {
        gap: 12px !important;
    }

    .analytics-signal-v3-row {
        min-height: 64px !important;

        padding:
            10px
            13px !important;
    }

    .analytics-signal-v3-stat {
        padding:
            12px
            12px !important;
    }
}
</style>

<script>
(() => {

    const root =
        document.getElementById(
            'analytics-signal-v3'
        );

    if (!root) {
        return;
    }

    const normalize = (value) =>
        String(value || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

    const fallback =
        'this analytics result is summarized above and has no additional transaction-level records for the selected scope.';

    const removeFallback = () => {

        const walker =
            document.createTreeWalker(
                document.body,
                NodeFilter.SHOW_TEXT
            );

        let node;

        while (
            (node = walker.nextNode())
        ) {

            if (
                normalize(node.nodeValue)
                !==
                fallback
            ) {
                continue;
            }

            const leaf =
                node.parentElement;

            if (!leaf) {
                continue;
            }

            let candidate =
                leaf;

            while (
                candidate
                &&
                candidate.parentElement
                &&
                candidate !== document.body
            ) {

                const style =
                    window.getComputedStyle(
                        candidate
                    );

                const rect =
                    candidate.getBoundingClientRect();

                const parentRect =
                    candidate.parentElement
                        .getBoundingClientRect();

                const cardLike =
                    parseFloat(
                        style.borderTopWidth
                    ) > 0
                    ||
                    parseFloat(
                        style.borderBottomWidth
                    ) > 0
                    ||
                    parseFloat(
                        style.borderRadius
                    ) >= 6;

                const wide =
                    parentRect.width > 0
                    &&
                    rect.width
                    >=
                    parentRect.width * 0.70;

                if (
                    cardLike
                    &&
                    wide
                ) {

                    candidate.remove();

                    return;
                }

                candidate =
                    candidate.parentElement;
            }

            leaf.remove();

            return;
        }
    };

    removeFallback();

    requestAnimationFrame(
        removeFallback
    );

    setTimeout(
        removeFallback,
        100
    );

})();
</script>

<style>

.analytics-records-universal-subtitle {
    margin: 5px 0 0 !important;
    padding: 0 !important;

    color: #64748b !important;

    font-size: 12px !important;
    font-weight: 400 !important;
    line-height: 1.45 !important;
}

#analytics-signal-v3 {
    margin-top: 12px !important;
}

#analytics-signal-v3 .analytics-signal-v3-chips {
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}

#analytics-signal-v3 {
    gap: 12px !important;
}

#analytics-signal-v3 .analytics-signal-v3-card {
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}
</style>

<script>
(() => {

    const normalize = (value) =>
        String(value || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

    const detailTitle =
        @json($detail['title'] ?? '');

    const detailContext =
        @json($detail['context'] ?? '');

    const applyUniversalHeader = () => {

        if (!detailTitle) {
            return;
        }

        const heading =
            Array.from(
                document.querySelectorAll(
                    'h1, h2, h3'
                )
            )
            .find((element) => {

                if (
                    normalize(element.textContent)
                    !==
                    normalize(detailTitle)
                ) {
                    return false;
                }

                const rect =
                    element.getBoundingClientRect();

                const style =
                    window.getComputedStyle(element);

                return (
                    rect.width > 0
                    &&
                    rect.height > 0
                    &&
                    style.display !== 'none'
                    &&
                    style.visibility !== 'hidden'
                );
            });

        if (!heading) {
            return;
        }

        let subtitle = null;

        if (detailContext) {

            subtitle =
                Array.from(
                    heading.parentElement?.children ?? []
                )
                .find((element) =>
                    normalize(element.textContent)
                    ===
                    normalize(detailContext)
                );

            if (!subtitle) {

                subtitle =
                    document.createElement('p');

                subtitle.className =
                    'analytics-records-universal-subtitle';

                subtitle.textContent =
                    detailContext;

                heading.insertAdjacentElement(
                    'afterend',
                    subtitle
                );

            } else {

                subtitle.classList.add(
                    'analytics-records-universal-subtitle'
                );
            }
        }

        const signalRoot =
            document.getElementById(
                'analytics-signal-v3'
            );

        if (signalRoot) {

            signalRoot.style.setProperty(
                'margin-top',
                '18px',
                'important'
            );

            const chips =
                signalRoot.querySelector(
                    '.analytics-signal-v3-chips'
                );

            if (chips) {

                chips.style.setProperty(
                    'margin-top',
                    '0',
                    'important'
                );

                chips.style.setProperty(
                    'margin-bottom',
                    '0',
                    'important'
                );
            }
        }
    };

    if (
        document.readyState === 'loading'
    ) {

        document.addEventListener(
            'DOMContentLoaded',
            applyUniversalHeader,
            { once: true }
        );

    } else {

        applyUniversalHeader();
    }

    requestAnimationFrame(
        applyUniversalHeader
    );

    setTimeout(
        applyUniversalHeader,
        100
    );

    setTimeout(
        applyUniversalHeader,
        400
    );

})();
</script>

<style>

#analytics-signal-v3 {
    margin-top: 0 !important;
}

.analytics-records-universal-subtitle {
    margin-top: 4px !important;
    margin-bottom: 0 !important;
}

#analytics-signal-v3 .analytics-signal-v3-chips {
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}

#analytics-signal-v3 {
    gap: 12px !important;
}

#analytics-signal-v3 .analytics-signal-v3-card {
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}
</style>

<script>
(() => {

    const root =
        document.getElementById(
            'analytics-signal-v3'
        );

    if (!root) {
        return;
    }

    const normalizeSpacing = () => {

        root.style.setProperty(
            'margin-top',
            '0px',
            'important'
        );

        const chips =
            root.querySelector(
                '.analytics-signal-v3-chips'
            );

        if (chips) {

            chips.style.setProperty(
                'margin-top',
                '0px',
                'important'
            );

            chips.style.setProperty(
                'margin-bottom',
                '0px',
                'important'
            );
        }

        const subtitle =
            Array.from(
                document.querySelectorAll(
                    '.analytics-records-universal-subtitle'
                )
            )
            .find((element) =>
                element.offsetParent !== null
            );

        if (subtitle) {

            subtitle.style.setProperty(
                'margin-top',
                '4px',
                'important'
            );

            subtitle.style.setProperty(
                'margin-bottom',
                '0px',
                'important'
            );
        }
    };

    normalizeSpacing();

    requestAnimationFrame(
        normalizeSpacing
    );

    setTimeout(
        normalizeSpacing,
        100
    );

    setTimeout(
        normalizeSpacing,
        400
    );

})();
</script>

@endsection
