<?php

namespace App\Services;

use App\Support\AnalyticsDrilldown;
use App\Support\PeriodComparison;
use Carbon\CarbonInterface;
use Carbon\Carbon;
use App\Enums\RequestStatus;

/**
 * Card-level Analytics details.
 *
 * AnalyticsDetailService answers questions about a single figure that has
 * record-level backing - one unit, one item, one bucket. A card is a coarser
 * question: "what is this panel telling me?" Some panels have no record list
 * behind them at all, only a reading and the rule that produced it, and those
 * still deserve an answer rather than a dead click.
 *
 * Every descriptor here re-reads the same service method the card itself was
 * rendered from, so a drawer can never disagree with the card above it, and
 * nothing is computed that the services do not already compute. A key that is
 * not recognised still returns a valid detail, so a card can never open an
 * empty panel.
 */
class AnalyticsCardDetailService
{
 public function __construct(
 private readonly AnalyticsService $analytics,
 private readonly InventoryService $inventory,
 private readonly ReportingPeriodService $periods,
 ) {}

 /** This resolver, reading figures from the caller's already-filtered AnalyticsService. */
 public function using(AnalyticsService $analytics): self
 {
 return new self($analytics, $this->inventory, $this->periods);
 }

 /**
 * @param array<string, mixed> $scope
 * @return array<string, mixed>
 */
 public function resolve(array $scope, string $key): array
 {
 if (in_array($key, AnalyticsInventoryDetailService::KEYS, true)) {
 return (new AnalyticsInventoryDetailService($this->analytics, $this->inventory))->resolve($scope, $key);
 }
 // Full-page return evidence is attached after the legacy summary is resolved.
 if (($scope['return_records_view'] ?? false) && in_array($key, AnalyticsReturnDetailService::CARD_KEYS, true)) {
 $scope['record_limit'] = min(25, (int) ($scope['record_limit'] ?? 25));
 }
 $detail = match ($key) {
 /* Overview */
 'overview.insights' => $this->overviewInsights($scope),
 'overview.return-compliance' => $this->returnCompliance($scope),
 'overview.released' => $this->releasedItems($scope, 'Top Released Items'),
 'overview.units' => $this->unitRankings($scope),
 'overview.snapshot' => $this->snapshot($scope),

 /* Demand & Utilization */
 'demand.requested-quantity' => $this->requestedQuantity($scope),
 'demand.released-quantity' => $this->releasedQuantity($scope),
 'demand.active-units' => $this->activeUnits($scope),
 'demand.trend' => $this->trend($scope, 'Borrowing Demand Trend'),
 'demand.division' => $this->divisionDemand($scope),
 'demand.requested-items' => $this->requestedItems($scope),
 'demand.units' => $this->unitRankings($scope),
 'demand.released-items' => $this->releasedItems($scope, 'Top Released Items'),
 'demand.low-usage' => $this->lowUsage($scope),
 'demand.processing' => $this->processing($scope),
 'demand.peak' => $this->peak($scope),

 /* Borrowing & Return Performance */
 'returns.trend' => $this->returnTrend($scope),
 'returns.outcome' => $this->returnOutcome($scope),
 'returns.lifecycle' => $this->lifecycle($scope),
 'returns.followup' => $this->overdueFollowUp($scope),
 'returns.condition' => $this->returnCondition($scope),
 'returns.summary' => $this->returnSummary($scope),
 'returns.issues' => $this->returnIssues($scope),

 /* scheduled.* details were retired with the Scheduled Demand section. */
 default => $this->unknown($key),
 };

 if (($scope['return_records_view'] ?? false) && in_array($key, AnalyticsReturnDetailService::CARD_KEYS, true)) {
 return (new AnalyticsReturnDetailService($this->analytics))->card($scope, $key, $detail);
 }

 return in_array($key, [
 'demand.requested-quantity', 'demand.released-quantity', 'demand.active-units',
 'demand.trend', 'demand.division', 'demand.requested-items', 'demand.units', 'demand.peak',
 ], true) ? $this->withDemandEvidence($scope, $key, $detail) : $detail;
 }

 /** Structured drilldown data. The legacy bars are retained for existing callers. */
 private function withDemandEvidence(array $scope, string $key, array $detail): array
 {
 $args = [$scope['from'], $scope['to'], $scope['division'], $scope['unit']];
 $borrower = $scope['borrower'] ?? null;
 $breakdowns = [];
 $table = null;
 $filter = null;
 $status = static fn ($row): string => RequestStatus::tryFrom((string) $row->status)?->label() ?? (string) $row->status;
 $divisions = AnalyticsService::divisions();
 $group = static fn ($row): string => $row->affiliation_type === 'EXTERNAL' ? 'External Borrower'
 : ($divisions[$row->division_code] ?? 'Unspecified division');
 $office = static fn ($row): string => $row->affiliation_type === 'EXTERNAL'
 ? (trim((string) $row->external_company_name) ?: 'External Borrower')
 : (string) ($row->office_unit ?? '—');
 $date = static fn ($value): string => Carbon::parse($value)->format('d M Y');
 $section = static fn (string $title, array $columns, array $rows): array => compact('title', 'columns', 'rows');
 $qty = static fn ($value) => (float) $value + 0;
 $requestRef = static fn ($r): array => ['primary' => $r->request_no, 'url' => route('requests.show', $r->request_id)];
 $custodyRef = static fn ($r): array => ['primary' => $r->custody_no, 'url' => route('custody.show', $r->custody_id)];

 if (in_array($key, ['demand.requested-quantity', 'demand.requested-items'], true)) {
 $ranked = $key === 'demand.requested-items';
 if ($ranked) {
 $items = $this->analytics->demandFulfillment(...[...$args, 10, $borrower])['items'];
 $selection = $scope['demand_item'] ?? null;
 $items = array_map(fn ($item, $index) => $item + ['rank' => $index + 1], $items, array_keys($items));
 if ($selection !== null) {
 $items = array_values(array_filter($items, fn ($item) => $item['name'] === $selection['name'] && $item['unit'] === $selection['unit']));
 }
 $detail['title'] = $selection === null ? 'Equipment Demand & Actual Usage' : $selection['name'];
 $detail['value'] = count($items);
 $detail['value_label'] = 'ranked item types';
 $detail['note'] = $this->periodNote($scope).' Ranking counts distinct requests per description and unit. Fulfillment uses actual releases of those same request items within the period; percentage is capped at 100% under the existing policy. The dashboard previews five of the top ten groups.';
 $detail['empty'] = $items === [] ? 'No matching ranked equipment in this reporting period.' : null;
 if ($selection !== null) $detail['bars'] = null;
 $detail['stats'] = $selection !== null && $items !== [] ? [
 ['label' => 'Requests containing item', 'value' => $items[0]['requests']],
 ['label' => 'Requested quantity', 'value' => $items[0]['requested_quantity']],
 ['label' => 'Released quantity', 'value' => $items[0]['released_quantity']],
 ['label' => 'Fulfillment', 'value' => $items[0]['fulfillment'] === null ? '—' : $items[0]['fulfillment'].'%'],
 ] : [['label' => 'Ranked item types', 'value' => count($items)]];
 $breakdowns[] = $section('Requested and Released Equipment', ['Rank', 'Equipment', 'Unit', 'Requests', 'Requested Qty.', 'Released Qty.', 'Fulfillment'],
 array_map(fn ($item) => [$item['rank'], $item['name'], $item['unit'], $item['requests'], $item['requested_quantity'], $item['released_quantity'], $item['fulfillment'] === null ? '—' : $item['fulfillment'].'%'], $items));
 } else {
 $items = $this->analytics->requestedEquipment(...[...$args, PHP_INT_MAX, $borrower])['items'];
 usort($items, fn ($a, $b) => $b['quantity'] <=> $a['quantity']);
 $breakdowns[] = $section('Requested Quantity by Item', ['Equipment', 'Unit', 'Requests', 'Requested Qty.'],
 array_map(fn ($item) => [$item['name'], $item['unit'], $item['requests'], $item['quantity']], $items));
 }
 $rows = $this->analytics->demandItemEvidence($scope, $ranked ? $items : null);
 $table = $section($ranked ? 'Equipment Request Records' : 'Requested Item Records',
 $ranked ? ['Request No.', 'Filed', 'Borrower', 'Item', 'Unit', 'Requested Qty.', 'Released Qty.', 'Status'] : ['Request No.', 'Filed', 'Borrower', 'Item', 'Unit', 'Requested Qty.', 'Status'],
 $rows->map(fn ($r) => array_merge([$requestRef($r), $date($r->filed_at), $r->borrower, $r->name, $r->unit, $qty($r->quantity)], $ranked ? [$qty($r->released)] : [], [$status($r)]))->all());
 $filter = 3;
 } elseif ($key === 'demand.released-quantity') {
 $items = $this->analytics->equipment(...[...$args, PHP_INT_MAX, $borrower])['items'];
 $rows = $this->analytics->demandReleaseEvidence($scope);
 $breakdowns[] = $section('Released Quantity by Item', ['Equipment', 'Unit', 'Released Qty.'], array_map(fn ($r) => [$r['name'], $r['unit'], $r['released']], $items));
 $table = $section('Physical Release Records', ['Custody No.', 'Request No.', 'Released', 'Borrower', 'Item', 'Unit', 'Released Qty.'],
 $rows->map(fn ($r) => [$custodyRef($r), $r->request_no, $date($r->released_at), $r->borrower, $r->name, $r->unit, $qty($r->quantity)])->all());
 $filter = 4;
 } else {
 $rows = collect();
 if (! in_array($key, ['demand.active-units', 'demand.units'], true)) $rows = $this->analytics->demandRequestEvidence($scope);
 if ($key === 'demand.active-units' || $key === 'demand.units') {
 if ($key === 'demand.active-units') {
 // The KPI counts distinct nonempty office names, irrespective of division.
 $rows = $this->analytics->demandRequestEvidence($scope, true);
 $units = $this->analytics->requestScope(...[...$args, $borrower])
 ->whereNotNull('request_versions.office_unit')->where('request_versions.office_unit', '!=', '')
 ->select('request_versions.office_unit as name')->selectRaw('COUNT(borrowing_requests.id) AS total')
 ->groupBy('request_versions.office_unit')->orderByDesc('total')->toBase()->get();
 $detail['value'] = $units->count();
 $detail['value_label'] = 'active units';
 $highest = (float) ($units->max('total') ?: 0);
 $detail['bars'] = $units->map(fn ($r) => ['label' => $r->name, 'value' => (string) $r->total, 'share' => $this->width((float) $r->total, $highest)])->all();
 $detail['note'] = $this->periodNote($scope).' Counts every distinct nonempty recorded office/unit name, not just the ranked internal units.';
 $detail['empty'] = $units->isEmpty() ? 'No active borrowing units in this period.' : null;
 $breakdowns[] = $section('Active Borrowing Units', ['Office / College / Unit', 'Requests Filed'], $units->map(fn ($r) => [$r->name, (int) $r->total])->all());
 } else {
 $rankings = $this->analytics->unitRankings(...[...$args, 5, $borrower]);
 $units = collect($rankings['columns'])->flatMap(fn ($col) => collect($col['units'])->map(fn ($r) => $r + ['division' => $col['label'], 'code' => $col['code']]))->sortByDesc('count')->values();
 $rows = $this->analytics->demandRequestEvidence($scope, false, $units->all());
 $rank = 0; $previous = null;
 $rankRows = $units->map(function ($r, $i) use (&$rank, &$previous) {
 if ($previous !== $r['count']) $rank = $i + 1;
 $previous = $r['count'];
 return [$rank, $r['name'], $r['division'], $r['count']];
 })->all();
 $breakdowns[] = $section('Unit Ranking', ['Rank', 'Office / College / Unit', 'Division', 'Requests Filed'], $rankRows);
 $breakdowns[0]['bar_column'] = 3;
 $detail['note'] .= ' The detail lists up to five units per division; the dashboard previews the overall top five.';
 }
 $table = $section('Borrowing Requests by Unit', ['Request No.', 'Office / Unit', 'Division / Group', 'Borrower', 'Filed', 'Status'],
 $rows->map(fn ($r) => [$requestRef($r), (string) ($r->office_unit ?? '—'), $group($r), $r->borrower, $date($r->filed_at), $status($r)])->all());
 $filter = 1;
 } elseif ($key === 'demand.division') {
 $groups = $this->analytics->borrowerGroups(...[...$args, $borrower]);
 $breakdowns[] = $section('Demand by Division', ['Division / Group', 'Requests', 'Share'], $groups['groups']->map(fn ($g) => [$g['label'], $g['count'], $g['percentage'].'%'])->all());
 $breakdowns[0]['visual'] = 'division';
 $breakdowns[0]['bar_column'] = 1;
 $breakdowns[0]['footer'] = ['Total', $groups['total'], '100%'];
 $breakdowns[0]['note'] = 'Share = group requests ÷ all filed requests × 100 (rounded).';
 $detail['note'] .= ' Share = group requests divided by all filed requests, multiplied by 100. Displayed shares are rounded.';
 $table = $section('Requests by Division', ['Request No.', 'Borrower', 'Office / Organization', 'Division / Group', 'Filed', 'Status'], $rows->map(fn ($r) => [$requestRef($r), $r->borrower, $office($r), $group($r), $date($r->filed_at), $status($r)])->all());
 $filter = 3;
 } elseif ($key === 'demand.trend') {
 $trend = $this->analytics->trend(...[...$args, $scope['period'], $borrower]);
 $total = array_sum(array_column($trend['points'], 'count'));
 $breakdowns[] = $section('Borrowing Demand Trend', ['Period', 'Requests Filed', 'Share'], array_map(fn ($r) => [$r['label'], $r['count'], $total ? round($r['count'] / $total * 100, 1).'%' : '—'], $trend['points']));
 $table = $section('Filing History', ['Request No.', 'Borrower', 'Office / Unit', 'Filed Date', 'Filed Time', 'Period Bucket', 'Status'], $rows->map(fn ($r) => [$requestRef($r), $r->borrower, $office($r), $date($r->filed_at), Carbon::parse($r->filed_at)->format('g:i A'), $this->analytics->demandBucketLabel(Carbon::parse($r->filed_at), $scope['period']), $status($r)])->all());
 $filter = 5;
 } elseif ($key === 'demand.peak') {
 $peak = $this->analytics->peakBorrowing(...[...$args, $borrower]);
 if ($peak['total'] > 0) {
 $breakdowns[] = $section('Requests by Weekday', ['Weekday', 'Requests Filed'], array_map(fn ($r) => [$r['label'], $r['count']], $peak['days']));
 $breakdowns[] = $section('Requests by Filing Hour', ['Hour', 'Requests Filed'], array_map(fn ($r) => [$r['label'], $r['count']], $peak['hours']));
 $breakdowns[0]['bar_column'] = 1;
 $breakdowns[0]['note'] = $peak['available'] ? 'Busiest weekday(s): '.implode(', ', $peak['peak_days']).'.' : $peak['summary'];
 $breakdowns[1]['visual'] = 'hours';
 $breakdowns[1]['peaks'] = $peak['peak_hours'];
 if (! $peak['available']) $detail['note'] .= ' '.$peak['summary'];
 }
 $table = $section('Request Filing History', ['Request No.', 'Borrower', 'Filed Date', 'Filed Time', 'Weekday', 'Filing Hour', 'Status'], $rows->map(fn ($r) => [$requestRef($r), $r->borrower, $date($r->filed_at), Carbon::parse($r->filed_at)->format('g:i A'), Carbon::parse($r->filed_at)->format('l'), Carbon::parse($r->filed_at)->format('g A'), $status($r)])->all());
 $filter = 4;
 }
 }

 // Full-page records already authorize the entire dataset; the older drawer
 // may still use its historical record limit. Never imply a limited list is complete.
 $detail['breakdown_tables'] = array_values(array_filter($breakdowns, fn ($b) => $b['rows'] !== []));
 if ($table !== null) {
 $detail['table_title'] = $table['title'];
 $detail['table'] = ['columns' => $table['columns'], 'rows' => array_slice($table['rows'], 0, $scope['record_limit'] ?? PHP_INT_MAX)];
 $detail['table_filter_column'] = $filter;
 // Keep equipment names and units distinct when using the existing table filter.
 if (in_array($key, ['demand.requested-quantity', 'demand.requested-items', 'demand.released-quantity'], true)) {
 $itemColumn = $key === 'demand.released-quantity' ? 4 : 3;
 $detail['table_filter_label'] = 'Equipment';
 $detail['table_filter_values'] = array_map(fn ($r) => $r[$itemColumn].' ('.$r[$itemColumn + 1].')', $detail['table']['rows']);
 }
 if ($key === 'demand.peak') $detail['note'] .= ' Filter by weekday or search by filing hour (for example, 7 PM).';
 if (count($table['rows']) > ($scope['record_limit'] ?? PHP_INT_MAX)) $detail['note'] .= ' The compact view lists only the first records; open Analytics Records for the full evidence.';
 }
 if (empty($detail['stats']) && ! empty($detail['breakdown_tables'])) {
 $detail['stats'] = [['label' => ucfirst($detail['value_label'] ?? 'Result'), 'value' => $detail['value'] ?? '—']];
 }
 $detail['structured_demand'] = true;
 return $detail;
 }

 /* Shared shapes */

 /** A period-scoped reading states its window; a current one states today. */
 private function periodNote(array $scope): string
 {
 return 'Period-scoped: measured over '
 .$scope['from']->format('d M Y').' to '.$scope['to']->format('d M Y')
 .', narrowed by the borrower group and unit selected above.';
 }

 private function currentNote(): string
 {
 return 'Current state: measured as of today, not limited to the selected '
 .'reporting period, and narrowed by the borrower group and unit selected above.';
 }

 private function inventoryCurrentNote(): string
 {
 return 'Institution-wide current inventory snapshot: measured as of today. '
 .'Reporting Period, Division, Office / College / Unit, and Borrower filters do not change physical stock totals.';
 }

 /** The previous period's dates as the panel prints them. */
 private function previousWindowLabel(array $scope): string
 {
 [$from, $to] = $this->analytics->previousWindow($scope['from'], $scope['to']);

 return $from->format('d M Y').' – '.$to->format('d M Y');
 }

 /**
 * Bar width for a detail row, as a share of the largest value in the
 * rows being drawn.
 *
 * Visualisation geometry only: it exists so a bar's width is proportional
 * to the figure printed beside it, and it is never a business metric of
 * its own. A set with no positive value draws no width rather than
 * dividing by zero.
 */
 private function width(float $value, float $highest): int
 {
 if ($highest <= 0 || $value <= 0) {
 return 0;
 }

 return (int) round(min($value, $highest) / $highest * 100);
 }

 /** @return array<string, mixed> */
 private function unknown(string $key): array
 {
 return [
 'title' => 'Detail unavailable',
 'value' => null,
 'context' => 'Analytics detail',
 'empty' => 'This card does not offer a detailed breakdown yet.',
 'note' => 'Requested detail key: '.$key,
 ];
 }

 /* Overview */

 /** @return array<string, mixed> */
 private function trend(array $scope, string $title): array
 {
 $trend = $this->analytics->trend(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['period'], $scope['borrower'] ?? null
 );

 $total = array_sum(array_column($trend['points'], 'count'));

 if ($title === 'Borrowing Demand Trend' && ($trend['granularity'] ?? null) === 'month') {
 $title = 'Seasonal Borrowing Pattern';
 }

 return [
 'title' => $title,
 'value' => $total,
 'value_label' => $total === 1 ? 'request filed' : 'requests filed',
 'context' => 'Requests filed per '.$trend['granularity'],
 'note' => $this->periodNote($scope)
 .' Each bucket counts requests by the date they were filed, not the quantity '
 .'of items released. Grouping follows the selected reporting period.',
 'bars' => $trend['points'] === [] ? null : array_map(
 static fn (array $point): array => [
 'label' => $point['label'],
 'value' => (string) $point['count'],
 'share' => $point['share'],
 ],
 $trend['points']
 ),
 'empty' => $total === 0 ? 'No borrowing requests were filed during this period.' : null,
 'reports_url' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function overviewInsights(array $scope): array
 {
 $overview = $this->analytics->overview(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );
 $low = $this->analytics->lowAvailability($this->inventory);
 $returns = $this->analytics->returns(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );
 $units = $this->analytics->unitRankings(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit']
 );
 $equipment = $this->analytics->equipment(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 1, $scope['borrower'] ?? null
 );

 $rankedUnits = collect($units['columns'] ?? [])
 ->flatMap(fn (array $column): array => collect($column['units'] ?? [])
 ->map(fn (array $row): array => $row + [
 'division_label' => $column['label'] ?? '',
 ])
 ->all())
 ->sortByDesc('count')
 ->values();

 $leadUnit = $rankedUnits->first();
 $leadItem = $equipment['items'][0] ?? null;
 $signals = [];

 if (($overview['needs_follow_up'] ?? 0) > 0) {
 $signals[] = [
 'weight' => 10,
 'label' => 'Currently overdue',
 'value' => (string) $overview['needs_follow_up'],
 ];
 }

 if (($low['count'] ?? 0) > 0) {
 $signals[] = [
 'weight' => 20,
 'label' => 'Low availability',
 'value' => $low['count'].' item '.($low['count'] === 1 ? 'type' : 'types'),
 ];
 }

 if ($leadUnit) {
 $signals[] = [
 'weight' => 30,
 'label' => 'Top borrowing unit',
 'value' => $leadUnit['name'].' '.$leadUnit['count'].' '
 .($leadUnit['count'] === 1 ? 'request' : 'requests'),
 ];
 }

 if ($leadItem) {
 $signals[] = [
 'weight' => 35,
 'label' => 'Top released item',
 'value' => $leadItem['name'].' '.($leadItem['released'] + 0).' '.$leadItem['unit'],
 ];
 }

 if (($low['count'] ?? 0) === 0) {
 $signals[] = [
 'weight' => 36,
 'label' => 'Inventory availability',
 'value' => 'No item below threshold',
 ];
 }

 $signals[] = [
 'weight' => 40,
 'label' => 'Return compliance',
 'value' => $returns['on_time_rate'] === null
 ? 'Not measurable'
 : $returns['on_time'].' of '.$returns['completed'].' on time '.$returns['on_time_rate'].'%',
 ];

 usort($signals, static fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);

 return [
 'title' => 'Priority Insights',
 'value' => null,
 'context' => 'Key operational signals',
 'note' => null,
 'stats' => array_map(
 static fn (array $signal): array => [
 'label' => $signal['label'],
 'value' => $signal['value'],
 ],
 array_slice($signals, 0, 4)
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function returnCompliance(array $scope): array
 {
 $returns = $this->analytics->returns(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 $completed = (int) $returns['completed'];

 return [
 'title' => 'Return Compliance',
 'value' => $returns['on_time_rate'] === null ? null : $returns['on_time_rate'].'%',
 'value_label' => $returns['on_time_rate'] === null ? null : 'on-time return rate',
 'context' => 'Completed returns in this reporting period',
 'note' => null,
 'stats' => [
 ['label' => 'Completed returns', 'value' => $completed],
 ['label' => 'Returned on time', 'value' => (int) $returns['on_time']],
 ['label' => 'Returned late', 'value' => (int) $returns['late']],
 ],
 /* The rate itself, in percentage points against the previous period. */
 'comparison' => PeriodComparison::block(
 $this->analytics->returnComparison(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 )['on_time_rate'],
 $this->previousWindowLabel($scope)
 ),
 'empty' => $completed === 0
 ? 'No completed returns in this reporting period.'
 : null,
 'reports_url' => AnalyticsDrilldown::returns(
 $scope['period'], $scope['division'], $scope['unit'],
 ['return_status' => 'COMPLETED']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function releasedItems(array $scope, string $title = 'Top Released Items'): array
 {
 $equipment = $this->analytics->equipment(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 10, $scope['borrower'] ?? null
 );

 return [
 'title' => $title,
 'value' => count($equipment['items']),
 'value_label' => 'items released',
 'context' => 'Ranked by quantity physically released',
 'note' => $this->periodNote($scope)
 .' This counts equipment that physically left custody, summed from released '
 .'quantities. How often an item was asked for is a separate measure, reported '
 .'as requested demand.',
 'bars' => $equipment['items'] === [] ? null : array_map(
 static fn (array $row): array => [
 'label' => $row['name'],
 'value' => ($row['released'] + 0).' '.$row['unit'],
 'share' => $row['share'],
 ],
 $equipment['items']
 ),
 'empty' => $equipment['items'] === []
 ? 'No equipment was physically released during this period.'
 : null,
 'reports_url' => AnalyticsDrilldown::utilization(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function requestedItems(array $scope): array
 {
 $requested = $this->analytics->requestedEquipment(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 10, $scope['borrower'] ?? null
 );

 /*
 * The same reading as the Most Requested Items card: ranked by how
 * many requests contained the item, with the requested quantity
 * stated beside it as the secondary figure. `share` here is the
 * service's request-count share, so the bar measures the ranking
 * and never the quantity.
 */
 return [
 'title' => 'Most Requested Items',
 'value' => count($requested['items']),
 'value_label' => 'items requested',
 'context' => 'Ranked by number of requests',
 'note' => $this->periodNote($scope)
 .' A request that was never approved or released still expresses demand, so it '
 .'is counted here. Physical usage is the separate released measure.',
 'bars' => $requested['items'] === [] ? null : array_map(
 static fn (array $row): array => [
 'label' => $row['name'],
 'value' => $row['requests'].' '.($row['requests'] === 1 ? 'request' : 'requests')
 .' Requested quantity: '.(($row['quantity'] ?? 0) + 0),
 'share' => $row['share'] ?? 0,
 ],
 $requested['items']
 ),
 'empty' => $requested['items'] === []
 ? 'No equipment was requested during this period.'
 : null,
 'reports_url' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function unitRankings(array $scope): array
 {
 $units = $this->analytics->unitRankings(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 5, $scope['borrower'] ?? null
 );

 $bars = [];

 foreach ($units['columns'] as $column) {
 foreach ($column['units'] as $row) {
 $bars[] = [
 'label' => $row['name'].' '.$column['label'],
 'value' => (string) $row['count'],
 'share' => $row['share'],
 'sort' => $row['count'],
 ];
 }
 }

 usort($bars, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);

 return [
 'title' => 'Top Borrowing Units',
 'value' => count($bars),
 'value_label' => count($bars) === 1 ? 'unit with activity' : 'units with activity',
 'context' => 'Ranked by borrowing requests filed',
 'note' => $this->periodNote($scope)
 .' A unit is counted from the version of the request that is current, so a '
 .'revised request is attributed to the unit it now belongs to.',
 'bars' => $bars === [] ? null : array_map(
 static fn (array $bar): array => [
 'label' => $bar['label'],
 'value' => $bar['value'],
 'share' => $bar['share'],
 ],
 $bars
 ),
 'empty' => $bars === [] ? 'No borrowing unit activity was recorded for this period.' : null,
 'reports_url' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function snapshot(array $scope): array
 {
 $overview = $this->analytics->overview(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );
 $returns = $this->analytics->returns(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 return [
 'title' => 'Reporting Period Snapshot',
 'value' => null,
 'context' => 'Outcomes for borrowings filed in this period',
 'note' => $this->periodNote($scope)
 .' Each figure follows the borrowings filed inside the window, so it describes '
 .'what this period produced rather than what is true right now.',
 'stats' => [
 ['label' => 'Approved requests', 'value' => $overview['approved']],
 ['label' => 'Completed returns', 'value' => $returns['completed']],
 ['label' => 'Returned on time', 'value' => $returns['on_time']],
 ['label' => 'Returned late', 'value' => $returns['late']],
 ['label' => 'Open accountability', 'value' => $returns['open_cases']],
 ],
 ];
 }

 /* Demand & Utilization */

 /** @return array<string, mixed> */
 private function requestedQuantity(array $scope): array
 {
 $requested = $this->analytics->requestedEquipment(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], PHP_INT_MAX, $scope['borrower'] ?? null
 );

 /*
 * The headline figure comes from the same demandTotals() aggregate the
 * KPI card itself reads, so the detail can never disagree with the
 * number the reader just clicked. requestedEquipment() is only the
 * full item breakdown behind the bars below.
 */
 $totals = $this->analytics->demandTotals(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );
 $total = (float) ($totals['requested_quantity'] ?? 0);

 /*
 * This detail is about quantity, so its bars are drawn against the
 * largest requested quantity in the rows shown - never the service's
 * `share`, which is the request-count ranking behind Most Requested
 * Items and would make a bar disagree with the number printed on it.
 * All requested item groups are listed here
 * by quantity so the bars read in order; the request count stays a
 * secondary field.
 */
 $rows = collect($requested['items'])
 ->sortByDesc(fn (array $row): float => (float) ($row['quantity'] ?? 0))
 ->values();

 $highest = (float) ($rows->max(fn (array $row): float => (float) ($row['quantity'] ?? 0)) ?: 0);

 /* Filing-date scope in both windows, same filters; only the dates move. */
 $comparison = $this->analytics->demandComparison(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 return [
 'title' => 'Requested Quantity',
 'value' => $total + 0,
 'value_label' => 'units requested',
 'context' => 'What borrowers asked for',
 'note' => $this->periodNote($scope)
 .' Summed from request items, including requests that were never approved or '
 .'released, because an unmet request is still demand. Released quantity is the '
 .'separate measure of what physically went out.',
 'comparison' => PeriodComparison::block(
 $comparison['requested_quantity'], $this->previousWindowLabel($scope), 'units'
 ),
 'bars' => $rows->isEmpty() ? null : $rows->map(
 fn (array $row): array => [
 'label' => $row['name'],
 'value' => (($row['quantity'] ?? 0) + 0).' '.($row['unit'] ?? '')
 .' '.$row['requests'].' '.($row['requests'] === 1 ? 'request' : 'requests'),
 'share' => $this->width((float) ($row['quantity'] ?? 0), $highest),
 ]
 )->all(),
 'bars_title' => 'Requested quantity by item',
 'empty' => $total <= 0 ? 'No equipment was requested during this period.' : null,
 'reports_url' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function releasedQuantity(array $scope): array
 {
 $equipment = $this->analytics->equipment(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], PHP_INT_MAX, $scope['borrower'] ?? null
 );

 $totals = $this->analytics->demandTotals(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );
 $total = (float) ($totals['released_quantity'] ?? 0);

 /* released_at in both windows - never the filing date. */
 $comparison = $this->analytics->demandComparison(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 return [
 'title' => 'Released Quantity',
 'value' => $total + 0,
 'value_label' => 'units released',
 'context' => 'What physically left custody',
 'note' => $this->periodNote($scope)
 .' Summed from actual released quantities on custody lines, so it measures real '
 .'asset usage rather than demand.',
 'comparison' => PeriodComparison::block(
 $comparison['released_quantity'], $this->previousWindowLabel($scope), 'units'
 ),
 'bars' => $equipment['items'] === [] ? null : array_map(
 static fn (array $row): array => [
 'label' => $row['name'],
 'value' => ($row['released'] + 0).' '.$row['unit'],
 'share' => $row['share'],
 ],
 $equipment['items']
 ),
 'empty' => $total <= 0 ? 'No equipment was physically released during this period.' : null,
 'reports_url' => AnalyticsDrilldown::utilization(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function activeUnits(array $scope): array
 {
 $detail = $this->unitRankings($scope);

 return array_merge($detail, [
 'title' => 'Active Borrowing Units',
 'context' => 'Units that filed at least one request',
 ]);
 }

 /** @return array<string, mixed> */
 private function processing(array $scope): array
 {
 $processing = $this->analytics->processingPerformance(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 $duration = static function (?float $minutes): string {
 if ($minutes === null) {
 return 'No completed sample';
 }

 if ($minutes < 60) {
 return max(1, (int) round($minutes)).' min';
 }

 if ($minutes < 1440) {
 return number_format($minutes / 60, 1).' hr';
 }

 return number_format($minutes / 1440, 1).' days';
 };

 return [
 'title' => 'Operational Processing',
 'value' => null,
 'context' => 'Request turnaround and pending workload',
 'note' => $this->periodNote($scope)
 .' Approval and release averages use only requests filed in this period that reached both timestamps. '
 .'Pending approval and its age are a current count of requests still in review, whenever they were filed.',
 'stats' => [
 ['label' => 'Avg filing to approval', 'value' => $duration($processing['approval_minutes'])],
 ['label' => 'Avg approval to release', 'value' => $duration($processing['release_minutes'])],
 ['label' => 'Pending approval', 'value' => $processing['pending_count']],
 ['label' => 'Oldest pending', 'value' => $duration($processing['oldest_pending_minutes'])],
 ],
 'empty' => ! $processing['available'] ? 'No filed requests are available for processing analysis in this period, and none are awaiting approval.' : null,
 'reports_url' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function divisionDemand(array $scope): array
 {
 $groups = $this->analytics->borrowerGroups(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 return [
 'title' => 'Demand by Division',
 'value' => $groups['total'],
 'value_label' => $groups['total'] === 1 ? 'request filed' : 'requests filed',
 'context' => 'Share of filed borrowing demand',
 'note' => $this->periodNote($scope)
 .' A division with no activity is left out of the comparison rather than shown '
 .'as a zero share.',
 'bars' => $groups['groups']->isEmpty() ? null : $groups['groups']->map(
 static fn (array $group): array => [
 'label' => $group['label'],
 'value' => $group['count'].' '.$group['percentage'].'%',
 'share' => $group['percentage'],
 ]
 )->all(),
 'empty' => $groups['total'] === 0
 ? 'No borrowing requests were filed during this period.'
 : null,
 'reports_url' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function lowUsage(array $scope): array
 {
 $slow = $this->analytics->slowMovingItems(
 $scope['from'], $scope['to'], 10, $scope['division'], $scope['unit']
 );
 $rows = $slow['items'] ?? [];

 /*
 * slowMovingItems() ranks by released quantity ascending and carries
 * no share of its own. When something in the list did move, a bar is
 * drawn against the largest released quantity shown; when nothing
 * did, every bar would be a fabricated zero, so the rows are listed
 * plainly instead and the state says so.
 */
 $highest = (float) (collect($rows)->max(fn (array $row): float => (float) ($row['released'] ?? 0)) ?: 0);
 $anyMoved = $highest > 0;

 return [
 'title' => 'Low / No Usage Items',
 'value' => count($rows),
 'value_label' => 'items listed',
 'context' => 'Equipment that moved least',
 'note' => $this->periodNote($scope)
 .' The list starts from the catalogue rather than from custody records, so an '
 .'item that never moved still appears - which is the point of the question.',
 'stats' => $rows === [] ? [] : [
 ['label' => 'Items with no release', 'value' => (int) ($slow['never_moved'] ?? 0)],
 ['label' => 'Borrowable items in catalogue', 'value' => (int) ($slow['catalogue'] ?? 0)],
 ],
 'bars' => ! $anyMoved ? null : array_map(
 fn (array $row): array => [
 'label' => (string) ($row['name'] ?? ''),
 'value' => (($row['released'] ?? 0) + 0).' released '
 .(int) ($row['transactions'] ?? 0).' '
 .((int) ($row['transactions'] ?? 0) === 1 ? 'release' : 'releases'),
 'share' => $this->width((float) ($row['released'] ?? 0), $highest),
 ],
 $rows
 ),
 'bars_title' => $anyMoved ? 'Released quantity, least first' : null,
 'table' => ($rows === [] || $anyMoved) ? null : [
 'columns' => ['Item', 'Released', 'Release transactions'],
 'rows' => array_map(
 static fn (array $row): array => [
 (string) ($row['name'] ?? ''),
 'No release',
 (string) (int) ($row['transactions'] ?? 0),
 ],
 $rows
 ),
 ],
 'empty' => $rows === []
 ? 'No catalogue items are available to rank yet.'
 : ($anyMoved ? null : 'None of the listed items was released during this period.'),
 'reports_url' => AnalyticsDrilldown::utilization(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function peak(array $scope): array
 {
 $peak = $this->analytics->peakBorrowing(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 /*
 * peakBorrowing() decides availability and names the peak itself:
 * `peak_day` / `peak_hour` are null below the minimum, and `total` is
 * the number of filed requests the reading was drawn from. The detail
 * reads those keys and nothing else, so it can never show a peak the
 * card withheld or a zero the service did not measure.
 */
 $available = (bool) ($peak['available'] ?? false);
 $peakDay = $available ? ($peak['peak_day'] ?? null) : null;
 $peakHour = $available ? ($peak['peak_hour'] ?? null) : null;
 $observed = (int) ($peak['total'] ?? 0);
 $required = (int) ($peak['requirement'] ?? AnalyticsService::PEAK_MINIMUM_OBSERVATIONS);

 $stats = [
 ['label' => 'Requests observed', 'value' => $observed],
 ['label' => 'Minimum required', 'value' => $required],
 ];

 if ($peakDay !== null) {
 $stats[] = ['label' => 'Busiest weekday', 'value' => $peakDay];
 }

 if ($peakHour !== null) {
 $stats[] = ['label' => 'Busiest hour', 'value' => $peakHour];
 }

 /* The weekday spread the card draws, so the detail shows the same counts. */
 $days = $available ? collect($peak['days'] ?? []) : collect();
 $busiest = (float) ($days->max('count') ?: 0);

 return [
 'title' => 'Peak Filing Pattern',
 'value' => $peakDay,
 'value_label' => $peakDay !== null ? 'busiest weekday' : null,
 'context' => 'Weekday and hour concentration of filed requests',
 'note' => 'Peak analysis needs a minimum number of recorded requests before a busiest '
 .'period can be distinguished from ordinary variation. Below that threshold the '
 .'reading is withheld rather than estimated from too little data. '
 .$this->periodNote($scope),
 'stats' => $stats,
 'bars' => $days->isEmpty() ? null : $days->map(
 fn (array $day): array => [
 'label' => (string) $day['label'],
 'value' => (string) (int) $day['count'],
 'share' => $this->width((float) $day['count'], $busiest),
 ]
 )->all(),
 'bars_title' => $days->isEmpty() ? null : 'Requests filed by weekday',
 'empty' => $available ? null : ($peak['summary'] ?? 'Not enough activity to determine a reliable peak.'),
 'reports_url' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /* Inventory Health */

 /* Borrowing & Return Performance */

 /** @return array<string, mixed> */
 private function returnTrend(array $scope): array
 {
 $trend = $this->analytics->returnTrend(
 $scope['from'],
 $scope['to'],
 $scope['division'],
 $scope['unit'],
 $scope['period'],
 $scope['borrower'] ?? null
 );

 /*
 * Use the exact completed-return population behind the chart.
 * The drilldown therefore explains the chart with real custody
 * records instead of repeating each weekly bucket as a metric card.
 */
 $records = $this->analytics->completedReturnDetailRecords(
 $scope['from'],
 $scope['to'],
 $scope['division'],
 $scope['unit'],
 $scope['borrower'] ?? null
 );

 $completed = $records->count();

 $onTime = $records
 ->where('state', 'RETURNED_ON_TIME')
 ->count();

 $late = $records
 ->where('state', 'RETURNED_LATE')
 ->count();

 $onTimeRate = $completed > 0
 ? (int) round($onTime / $completed * 100)
 : null;

 $shown = $records
 ->sortByDesc(
 fn (array $row): int =>
 $row['returned_at']?->timestamp ?? 0
 )
 ->take((int) ($scope['record_limit'] ?? 25))
 ->values();

 return [
 'title' => 'Return Performance Trend',

 'value' => $completed,

 'value_label' => $completed === 1
 ? 'completed return'
 : 'completed returns',

 'context' => 'Completed return performance',

 'note' => null,

 'stats' => [
 [
 'label' => 'Completed Returns',
 'value' => $completed,
 ],
 [
 'label' => 'Returned On Time',
 'value' => $onTime,
 ],
 [
 'label' => 'Returned Late',
 'value' => $late,
 ],
 [
 'label' => 'On-time Return Rate',
 'value' => $onTimeRate === null
 ? 'Not measurable'
 : $onTimeRate.'%',
 ],
 ],

 'table_title' => 'Return Records',

 'record_count' => $completed,

 'table' => $shown->isEmpty()
 ? null
 : [
 'columns' => [
 'Borrower',
 'Custody No.',
 'Due',
 'Returned',
 'Status',
 ],

 'rows' => $shown
 ->map(function (array $row): array {
 $custody = $row['custody'];

 return [
 (string) (
 $custody->borrower?->full_name
 ?? '—'
 ),

 (string) (
 $custody->custody_no
 ?? '—'
 ),

 $custody->due_at
 ? $custody->due_at->format('d M Y')
 : '—',

 $row['returned_at']
 ? $row['returned_at']->format('d M Y')
 : '—',

 ($row['state'] ?? null) === 'RETURNED_LATE'
 ? 'Returned Late'
 : 'Returned On Time',
 ];
 })
 ->all(),
 ],

 'empty' => $completed === 0
 ? 'No completed returns were recorded during this reporting period.'
 : null,

 'reports_url' => AnalyticsDrilldown::returns(
 $scope['period'],
 $scope['division'],
 $scope['unit'],
 ['return_status' => 'COMPLETED']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function returnOutcome(array $scope): array
 {
 /*
 * Return Outcome uses the same canonical completed-return population
 * as Return Performance Trend. This keeps totals, percentages and
 * supporting records synchronized.
 */
 $records = $this->analytics->completedReturnDetailRecords(
 $scope['from'],
 $scope['to'],
 $scope['division'],
 $scope['unit'],
 $scope['borrower'] ?? null
 );

 $completed = $records->count();

 $onTime = $records
 ->where('state', 'RETURNED_ON_TIME')
 ->count();

 $late = $records
 ->where('state', 'RETURNED_LATE')
 ->count();

 $onTimeRate = $completed > 0
 ? (int) round(($onTime / $completed) * 100)
 : null;

 $onTimeShare = $completed > 0
 ? (int) round(($onTime / $completed) * 100)
 : 0;

 /*
 * Derive the second share from the first so the visual distribution
 * always totals exactly 100%, even when percentages are rounded.
 */
 $lateShare = $completed > 0
 ? 100 - $onTimeShare
 : 0;

 $shown = $records
 ->sortByDesc(
 fn (array $row): int =>
 $row['returned_at']?->timestamp ?? 0
 )
 ->take((int) ($scope['record_limit'] ?? 25))
 ->values();

 return [
 'title' => 'Return Outcome',

 'value' => $completed,

 'value_label' => $completed === 1
 ? 'completed return'
 : 'completed returns',

 'context' => 'Completed return results for the selected period.',

 /*
 * Universal metric-summary contract.
 * Any Analytics detail may return this same stats structure.
 */
 'stats' => $completed === 0
 ? []
 : [
 [
 'label' => 'Completed Returns',
 'value' => $completed,
 ],
 [
 'label' => 'Returned On Time',
 'value' => $onTime,
 ],
 [
 'label' => 'Returned Late',
 'value' => $late,
 ],
 [
 'label' => 'On-time Return Rate',
 'value' => $onTimeRate.'%',
 ],
 ],

 /*
 * Universal distribution contract.
 * The shared Analytics Records view can render this for outcome,
 * status, condition, availability or other composition metrics.
 */
 'distribution_title' => 'Outcome Distribution',

 'distribution' => $completed === 0
 ? []
 : [
 [
 'label' => 'On Time',
 'value' => $onTime,
 'share' => $onTimeShare,
 'tone' => 'positive',
 ],
 [
 'label' => 'Late',
 'value' => $late,
 'share' => $lateShare,
 'tone' => 'warning',
 ],
 ],

 /*
 * Universal supporting-record table contract.
 */
 'table_title' => 'Return Records',

 'record_count' => $completed,

 'table' => $shown->isEmpty()
 ? null
 : [
 'columns' => [
 'Borrower',
 'Custody No.',
 'Due',
 'Returned',
 'Result',
 ],

 'rows' => $shown
 ->map(function (array $row): array {
 $custody = $row['custody'];

 return [
 (string) (
 $custody->borrower?->full_name
 ?? '—'
 ),

 (string) (
 $custody->custody_no
 ?? '—'
 ),

 $custody->due_at
 ? $custody->due_at->format('d M Y')
 : '—',

 $row['returned_at']
 ? $row['returned_at']->format('d M Y')
 : '—',

 ($row['state'] ?? null) === 'RETURNED_LATE'
 ? 'Returned Late'
 : 'Returned On Time',
 ];
 })
 ->all(),
 ],

 /*
 * Universal presentation flag.
 * The view no longer needs to identify special pages by title.
 */
 'hide_table_footer' => true,

 'empty' => $completed === 0
 ? 'No completed returns were recorded during this reporting period.'
 : null,

 'reports_url' => AnalyticsDrilldown::returns(
 $scope['period'],
 $scope['division'],
 $scope['unit'],
 ['return_status' => 'COMPLETED']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function lifecycle(array $scope): array
 {
 $stages = $this->analytics->lifecycle(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 $highest = max(1, max(array_column($stages, 'value')));

 return [
 'title' => 'Borrowing Lifecycle',
 'value' => null,
 'context' => 'Where this period\'s borrowings stand',
 'note' => $this->periodNote($scope)
 .' Each stage is a status the workflow actually writes, counted over the same '
 .'population, so the strip follows one set of borrowings through the process.',
 'bars' => array_map(
 static fn (array $stage): array => [
 'label' => $stage['label'].' — '.$stage['note'],
 'value' => (string) $stage['value'],
 'share' => (int) round($stage['value'] / $highest * 100),
 ],
 $stages
 ),
 /*
 * Each stage's records live in a different place - awaiting/
 * preparing release are Borrowing Activity Report rows, on custody
 * belongs to Release & Custody, and returned belongs to Return &
 * Accountability - so no single report reproduces this strip. A
 * link to any one of them would look right for one stage and
 * wrong for the other three, so none is offered here.
 */
 ];
 }

 /** @return array<string, mixed> */
 private function overdueFollowUp(array $scope): array
 {
 $overdue = $this->analytics->currentOverdue($scope['division'], $scope['unit'], (int) ($scope['record_limit'] ?? 25), $scope['borrower'] ?? null);

 return [
 'title' => 'Current Overdue Follow-up',
 'value' => $overdue['total'],
 'value_label' => $overdue['total'] === 1 ? 'borrowing overdue' : 'borrowings overdue',
 'context' => 'Out past the due date right now',
 'note' => $this->currentNote()
 .' Overdue means the equipment has not come back after its effective due date. '
 .'It is a separate measure from a late return, which is equipment that did come '
 .'back, only after its due date. The two are never combined.',
 'table' => $overdue['cases'] === [] ? null : [
 'columns' => ['Borrower', 'Custody No.', 'Due', 'Open return days overdue'],
 'rows' => array_map(
 static fn (array $case): array => [
 $case['borrower'],
 $case['custody_no'],
 optional($case['due_at'])->format('d M Y') ?: '—',
 $case['days_overdue'] === null ? '—' : (string) $case['days_overdue'],
 ],
 $overdue['cases']
 ),
 ],
 'empty' => $overdue['total'] === 0 ? 'No borrowings are currently overdue.' : null,
 'reports_url' => AnalyticsDrilldown::returns(
 $scope['period'], $scope['division'], $scope['unit'],
 ['return_status' => 'CURRENTLY_OVERDUE']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function returnCondition(array $scope): array
 {
 $conditions = $this->analytics->returnConditions(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 return [
 'title' => 'Return Condition',
 'value' => $conditions['total'],
 'value_label' => 'units inspected',
 'context' => 'Recorded at return inspection',
 'note' => $this->periodNote($scope)
 .' Read from the condition the receiving officer recorded on each return line. '
 .'Only conditions that were actually recorded are listed; a condition nobody '
 .'recorded is not shown as a zero.',
 'bars' => $conditions['rows'] === [] ? null : array_map(
 static fn (array $row): array => [
 'label' => $row['label'],
 'value' => ($row['quantity'] + 0).' units',
 'share' => $row['share'],
 ],
 $conditions['rows']
 ),
 'empty' => $conditions['rows'] === []
 ? 'No return inspections were recorded during this period.'
 : null,
 /*
 * Condition is recorded on a return line, which only exists for a
 * completed physical return, so COMPLETED is exactly the record
 * population the condition breakdown above is summed from.
 */
 'reports_url' => AnalyticsDrilldown::returns(
 $scope['period'], $scope['division'], $scope['unit'],
 ['return_status' => 'COMPLETED']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function returnSummary(array $scope): array
 {
 $returns = $this->analytics->returns(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 $duration = $returns['average_duration'] ?? [];

 /*
 * Two readings against the previous period in one block: the completed
 * count (bars and rows) and the on-time rate (rows, in percentage
 * points). Both come from returnComparison(), which classifies the
 * previous window by physical completion date exactly as this period.
 */
 $comparison = $this->analytics->returnComparison(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );
 $completedBlock = PeriodComparison::block($comparison['completed'], $this->previousWindowLabel($scope), 'returns');
 $rateBlock = PeriodComparison::block($comparison['on_time_rate'], $this->previousWindowLabel($scope));

 $completedBlock['rows'] = array_merge(
 array_map(static fn (array $row): array => ['Completed returns '.$row[0], $row[1]], $completedBlock['rows']),
 array_map(static fn (array $row): array => ['On-time rate '.$row[0], $row[1]], $rateBlock['rows'])
 );

 return [
 'title' => 'Return Performance Summary',
 'value' => $returns['completed'],
 'value_label' => $returns['completed'] === 1 ? 'completed return' : 'completed returns',
 'context' => 'Rates and duration over finished returns',
 'note' => $this->periodNote($scope)
 .' A rate needs a denominator. With no completed returns the honest reading is '
 .'"not measurable", which is a different statement from 0%. No service defines a '
 .'compliance threshold, so the measured rate is reported without being graded.',
 'comparison' => $completedBlock,
 'stats' => [
 ['label' => 'Completed returns', 'value' => $returns['completed']],
 [
 'label' => 'On-time return rate',
 'value' => $returns['on_time_rate'] === null ? 'Not measurable' : $returns['on_time_rate'].'%',
 ],
 [
 'label' => 'Average borrowing duration',
 'value' => $duration['label'] ?? 'Not available',
 ],
 ],
 'reports_url' => AnalyticsDrilldown::returns(
 $scope['period'], $scope['division'], $scope['unit'],
 ['return_status' => 'COMPLETED']
 ),
 ];
 }

 /** @return array<string, mixed> */
 private function returnIssues(array $scope): array
 {
 $returns = $this->analytics->returns(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );
 $incidents = $this->analytics->incidentSummary(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 $stats = [
 ['label' => 'Open accountability cases', 'value' => $returns['open_cases']],
 ['label' => 'Incidents recorded', 'value' => $incidents['total']],
 ['label' => 'Incidents still open', 'value' => $incidents['open']],
 ];

 foreach ($incidents['types'] as $type) {
 $stats[] = [
 'label' => $type['type'],
 'value' => $type['count'].' '.($type['quantity'] + 0).' units',
 ];
 }

 $nothing = $returns['open_cases'] === 0
 && $incidents['total'] === 0;

 return [
 'title' => 'Accountability & Return Issues',
 'value' => null,
 'context' => 'Issues raised against this period\'s borrowings',
 'note' => $this->periodNote($scope)
 .' Categories come from incident types the system actually records; none is '
 .'invented to fill the list.',
 'stats' => $stats,
 'empty' => $nothing
 ? 'No accountability or return-condition issues were recorded during this period.'
 : null,
 'reports_url' => AnalyticsDrilldown::returns(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /* Forecast & Planning */

 /** @return array<string, mixed> */

 /** @return array<string, mixed> */

 /** @return array<string, mixed> */

 /** @return array<string, mixed> */

 /** @return array<string, mixed> */

}
