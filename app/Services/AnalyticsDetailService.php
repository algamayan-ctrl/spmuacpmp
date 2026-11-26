<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Support\AnalyticsDrilldown;
use App\Support\OrganizationalStructure;
use App\Support\PeriodComparison;
use App\Support\RequestOutcomes;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The internal Analytics detail behind a figure.
 *
 * Clicking a number in Analytics is a question about that number, not a
 * request to leave for the records module. This service answers the question
 * in place: what the metric means, what it is made of, and what the records
 * behind it look like. Reports stays one deliberate step further on, offered
 * as a secondary action once the reader has understood the figure.
 *
 * Every detail returns the same shape, so a single partial renders all of
 * them and there is no second detail system to maintain. Calculations are
 * reused from AnalyticsService rather than restated here; only the
 * record-level listings, which no chart needed before, are queried here.
 */
class AnalyticsDetailService
{
 /** Records listed inside a detail before it stops being a summary. */
 public const RECORD_LIMIT = 25;

 /** Detail keys the panel can resolve. */
 public const TYPES = [
 'requests',
 'currently-out',
 'follow-up',
 'low-availability',
 'equipment',
 'division',
 'unit',
 'returns',
 'borrower',
 'coverage',
 'trend',
 /* Request Outcomes cohort, whole or one outcome group. */
 'outcomes',
 /* Current overdue backlog by age, whole or one aging band. */
 'overdue-aging',
 /* Late-return share of completed returns by division or unit, whole or one segment. */
 'late-rate',
 /* Card-level detail: one panel rather than one figure. */
 'card',
 ];

 public function __construct(
 private readonly AnalyticsService $analytics,
 private readonly InventoryService $inventory,
 private readonly AnalyticsCardDetailService $cards,
 private readonly ReturnMetricsService $returnMetrics,
 ) {}

 /** This resolver, reading figures from the caller's already-filtered AnalyticsService. */
 public function using(AnalyticsService $analytics): self
 {
 return new self($analytics, $this->inventory, $this->cards->using($analytics), $this->returnMetrics);
 }

 /**
 * Resolve one detail, or null when the key is not offered.
 *
 * @return array<string, mixed>|null
 */
 public function resolve(
 string $type,
 Request $request,
 CarbonInterface $from,
 CarbonInterface $to,
 ?string $division,
 ?string $unit,
 string $periodSelection,
 ?int $borrower = null,
 ?int $recordLimit = null
 ): ?array {
 if (! in_array($type, self::TYPES, true) || ($type === 'card' && str_starts_with((string) $request->input('for', ''), 'forecast.'))) {
 return null;
 }

 $scope = [
 'from' => $from,
 'to' => $to,
 'division' => $division,
 'unit' => $unit,
 'period' => $periodSelection,
 'borrower' => $borrower,
 'record_limit' => $recordLimit ?? self::RECORD_LIMIT,
 'demand_view' => $request->input('section') === 'demand',
 ];

 if ($type === 'card' && $request->input('for') === 'demand.requested-items'
 && is_string($request->input('item_name')) && is_string($request->input('item_unit'))) {
 $scope['demand_item'] = ['name' => $request->input('item_name'), 'unit' => $request->input('item_unit')];
 }

 $detail = match ($type) {
 'requests' => $this->requests($scope),
 'currently-out' => $this->currentlyOut($scope),
 'follow-up' => $this->followUp($scope),
 'low-availability' => $this->lowAvailability($scope),
 'equipment' => $this->equipment($scope, (string) $request->input('item', '')),
 'division' => $this->division($scope, (string) $request->input('for', '')),
 'unit' => $this->unit($scope, (string) $request->input('for', '')),
 'returns' => $this->returns($scope, (string) $request->input('state', 'on-time')),
 'borrower' => $this->borrower($scope, (string) $request->input('for', '')),
 'coverage' => $this->coverage($scope, (string) $request->input('item', '')),
 'trend' => $this->trend($scope, (int) $request->input('bucket', 0)),
 'outcomes' => $this->outcomes($scope, (string) $request->input('outcome', '')),
 'overdue-aging' => $this->overdueAging($scope, (string) $request->input('bucket', '')),
 'late-rate' => $this->lateRate(
 $scope,
 (string) $request->input('level', 'division'),
 (string) $request->input('for', ''),
 (string) $request->input('segment', '')
 ),
 'card' => $this->cards->resolve($scope, (string) $request->input('for', '')),
 };

 if ($detail === null) {
 return null;
 }

 if ($type === 'outcomes' && $request->input('section') === 'demand') {
 $outcomes = $this->analytics->requestOutcomes($from, $to, $division, $unit, $borrower);
 $detail['structured_demand'] = true;
 $detail['breakdown_tables'] = $outcomes['total'] > 0 ? [[
 'title' => 'Request Outcomes Breakdown',
 'columns' => ['Outcome', 'Requests', 'Share'],
 'rows' => array_map(fn ($row) => [$row['label'], $row['count'], $row['share'] === null ? '—' : $row['share'].'%'], $outcomes['groups']),
 ]] : [];
 $detail['table_title'] = 'Request Outcome Records';
 $detail['table_filter_column'] = 4;
 }

 return array_merge(
 [
 'type' => $type,
 'period_label' => $from->format('d M Y').' – '.$to->format('d M Y'),
 'filters' => $this->filterLabel($division, $unit, $borrower),
 'stats' => [],
 'table' => null,
 'bars' => null,
 /* Period-over-period block; only period-scoped figures set it. */
 'comparison' => null,
 'note' => null,
 'empty' => null,
 'reports_url' => null,
 ],
 $detail
 );
 }

 /** The previous period's dates as the panel prints them. */
 private function previousWindowLabel(array $scope): string
 {
 [$from, $to] = $this->analytics->previousWindow($scope['from'], $scope['to']);

 return $from->format('d M Y').' – '.$to->format('d M Y');
 }

 /* Overview figures */

 /** @param array<string, mixed> $scope */
 private function requests(array $scope): array
 {
 $overview = $this->analytics->overview(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower']
 );

 $counts = $this->analytics
 ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'])
 ->select('borrowing_requests.status')
 ->selectRaw('COUNT(borrowing_requests.id) AS total')
 ->groupBy('borrowing_requests.status')
 ->pluck('total', 'status');

 $stats = [['label' => 'Filed activity', 'value' => $overview['total']]];

 foreach ($counts as $status => $total) {
 $case = $status instanceof RequestStatus
 ? $status
 : RequestStatus::tryFrom((string) $status);

 $stats[] = [
 'label' => $case?->label() ?? (string) $status,
 'value' => (int) $total,
 ];
 }

 $groups = $this->analytics->borrowerGroups(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit']
 );

 /*
 * Records drilldown uses the exact same filed-request population as
 * the KPI. Select the request model explicitly because requestScope()
 * joins the current version table for filtering.
 */
 $requestRows = $this->analytics
 ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'])
 ->select('borrowing_requests.*')
 ->with(['borrower', 'currentVersion'])
 ->orderByRaw('COALESCE(request_versions.submitted_at, borrowing_requests.created_at) DESC')
 ->limit((int) $scope['record_limit'])
 ->get();

 /* Same filing-date scope and filters, over the equal-length period before. */
 $comparison = $this->analytics->demandComparison(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower']
 );

 return [
 'title' => 'Requests',
 'value' => $overview['total'],
 'context' => 'Filed borrowing demand and review workload',
 'note' => 'Filed requests in the selected period. Drafts, cancelled and expired requests are excluded.',
 'stats' => $stats,
 'comparison' => PeriodComparison::block(
 $comparison['requests'], $this->previousWindowLabel($scope), 'requests'
 ),
 'bars' => $groups['total'] > 0
 ? $groups['groups']->map(fn (array $group): array => [
 'label' => $group['label'],
 'value' => $group['count'].' - '.$group['percentage'].'%',
 'share' => $group['percentage'],
 ])->all()
 : null,
 'bars_title' => 'By division',
 'table' => $requestRows->isEmpty() ? null : [
 'columns' => ['Request No.', 'Borrower', 'Office / College / Unit', 'Filed', 'Schedule', 'Return', 'Status'],
 'rows' => $requestRows->map(function (BorrowingRequest $request): array {
 $version = $request->currentVersion;
 $status = $request->status instanceof RequestStatus
 ? $request->status->label()
 : (RequestStatus::tryFrom((string) $request->status)?->label()
 ?? str((string) $request->status)->replace('_', ' ')->title()->toString());

 return [
 (string) $request->request_no,
 (string) ($request->borrower?->full_name ?? ''),
 $this->affiliationLabel($version),
 $this->date($version?->submitted_at ?? $request->created_at),
 $this->date($version?->schedule_date ?? $version?->needed_from),
 $this->date($version?->return_date ?? $version?->return_due_at),
 $status,
 ];
 })->all(),
 ],
 'empty' => $overview['total'] === 0
 ? 'No borrowing requests were filed during this reporting period.'
 : null,
 'reports_url' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /**
 * The Currently Out KPI's own population, listed.
 *
 * Read from AnalyticsService::currentlyOutRecords() so the panel can
 * never disagree with the card: physical custody decides membership, not
 * the administrative status a custody is left in after a return.
 *
 * The Release & Custody Report has no filter for "physically outstanding
 * across custody states", so the source-record view opens that report in
 * the same organisational scope only; the note says so rather than
 * passing a status filter that would silently list a different set.
 *
 * @param array<string, mixed> $scope
 */
 private function currentlyOut(array $scope): array
 {
 $records = $this->analytics->currentlyOutRecords(
 $scope['division'], $scope['unit'], $scope['borrower'], (int) $scope['record_limit']
 );

 $rows = $records['rows'];
 $total = $records['total'];

 return [
 'title' => 'Currently Out',
 'value' => $total,
 'context' => 'Current physical custody, as of today',
 'note' => 'Released property with quantity still physically outstanding, as of today, whatever '
 .'administrative state the custody is in. Source records open the custody report within the same '
 .'organizational scope; the Analytics Currently Out total uses physical outstanding status across '
 .'custody states, which the report cannot filter on.',
 'table' => $rows->isEmpty() ? null : [
 'columns' => ['Borrower', 'Custody No.', 'Released', 'Quantity', 'Due', 'Status'],
 'rows' => $rows->map(fn (CustodyTransaction $custody): array => [
 (string) ($custody->borrower?->full_name ?? ''),
 (string) $custody->custody_no,
 $this->date($custody->released_at),
 $this->quantity($custody),
 $this->date($custody->due_at),
 $this->custodyLabel($custody->status),
 ])->all(),
 ],
 'empty' => $total === 0 ? 'No equipment is currently out on custody.' : null,
 'reports_url' => AnalyticsDrilldown::custody(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /**
 * The Currently Overdue KPI's own population, listed.
 *
 * Read from AnalyticsService::currentlyOverdueRecords(), the same query
 * behind the KPI, the follow-up card and Overdue Aging.
 *
 * @param array<string, mixed> $scope
 */
 private function followUp(array $scope): array
 {
 $records = $this->analytics->currentlyOverdueRecords(
 $scope['division'], $scope['unit'], $scope['borrower'], (int) $scope['record_limit']
 );

 $rows = $records['rows'];
 $total = $records['total'];

 return [
 'title' => 'Currently Overdue',
 'value' => $total,
 'context' => 'Released and still out past the due date',
 'note' => 'Late returns are already returned and are counted separately.',
 'table' => $rows->isEmpty() ? null : [
 'columns' => ['Borrower', 'Custody No.', 'Due', 'Open return days overdue', 'Issue', 'State'],
 'rows' => $rows->map(function (CustodyTransaction $custody): array {
 $days = $this->analytics->daysOverdue($custody);

 return [
 (string) ($custody->borrower?->full_name ?? ''),
 (string) $custody->custody_no,
 $this->date($custody->due_at),
 $days === null ? '—' : (string) $days,
 'Not yet returned',
 $this->custodyLabel($custody->status),
 ];
 })->all(),
 ],
 'empty' => $total === 0
 ? 'No borrowing is currently overdue.'
 : null,
 'reports_url' => AnalyticsDrilldown::returns(
 $scope['period'], $scope['division'], $scope['unit'],
 ['return_status' => 'CURRENTLY_OVERDUE']
 ),
 ];
 }

 /** @param array<string, mixed> $scope */
 private function lowAvailability(array $scope): array
 {
 $low = $this->analytics->lowAvailability($this->inventory, (int) $scope['record_limit']);

 return [
 'title' => 'Low Availability',
 'value' => $low['count'],
 'context' => 'Current inventory status, as of today',
 'note' => 'Item types with usable stock at or below '
 .((int) ($low['threshold'] * 100)).'% of serviceable stock.',
 'table' => $low['items'] === [] ? null : [
 'columns' => ['Item', 'Serviceable Total', 'Currently Available', 'Availability', 'Status'],
 'rows' => collect($low['items'])->map(fn (array $row): array => [
 $row['name'],
 (string) $row['stock'],
 (string) $row['available'],
 $row['share'].'%',
 $row['status'],
 ])->all(),
 ],
 'empty' => $low['count'] === 0 ? $low['summary'] : null,
 /*
 * lowAvailability() counts every borrowable item at or below the
 * threshold share, not only the ones at zero - FULLY_COMMITTED
 * only covers items with nothing available at all, which under-
 * counted this population against the Inventory Status Report.
 */
 'reports_url' => AnalyticsDrilldown::inventory(
 $scope['period'], ['availability_status' => 'LOW_AVAILABILITY']
 ),
 ];
 }

 /* Equipment */

 /** @param array<string, mixed> $scope */
 private function equipment(array $scope, string $itemId): ?array
 {
 $item = InventoryItem::query()->find($itemId);

 if (! $item) {
 return null;
 }

 $requested = (float) DB::table('request_items')
 ->whereIn(
 'request_items.request_version_id',
 $this->analytics
 ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'])
 ->select('request_versions.id')
 )
 ->where('request_items.inventory_item_id', $item->id)
 ->sum('request_items.requested_quantity');

 $appearances = DB::table('request_items')
 ->whereIn(
 'request_items.request_version_id',
 $this->analytics
 ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'])
 ->select('request_versions.id')
 )
 ->where('request_items.inventory_item_id', $item->id)
 ->count();

 $releasedQuery = DB::table('custody_lines')
 ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
 ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
 ->whereNotNull('custody_transactions.released_at')
 ->whereBetween('custody_transactions.released_at', [$scope['from'], $scope['to']])
 ->where('request_items.inventory_item_id', $item->id);

 if ($versionIds = $this->analytics->filteredVersionIds($scope['division'], $scope['unit'])) {
 $releasedQuery->whereIn('custody_transactions.request_version_id', $versionIds);
 }

 if ($scope['borrower'] !== null) {
 $releasedQuery->where('custody_transactions.borrower_user_id', $scope['borrower']);
 }

 $released = (float) (clone $releasedQuery)->sum('custody_lines.actual_released_quantity');

 /*
 * The item drilldown must be able to explain its own released figure.
 * Keep the aggregate above for reconciliation, then list the exact
 * release transactions from the same period, item and organisation
 * scope. This prevents a non-zero metric from opening an empty record
 * page and gives the reader an auditable path to the source activity.
 */
 $releaseRowsQuery = DB::table('custody_lines')
 ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
 ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
 ->leftJoin('borrowing_requests', 'borrowing_requests.id', '=', 'custody_transactions.request_id')
 ->leftJoin('request_versions', 'request_versions.id', '=', 'custody_transactions.request_version_id')
 ->leftJoin('users', 'users.id', '=', 'custody_transactions.borrower_user_id')
 ->whereNotNull('custody_transactions.released_at')
 ->whereBetween('custody_transactions.released_at', [$scope['from'], $scope['to']])
 ->where('request_items.inventory_item_id', $item->id);

 $this->analytics->constrainAffiliation($releaseRowsQuery, 'request_versions.affiliation_type', $scope['division'], $scope['unit']);

 if ($scope['division'] !== null) {
 $releaseRowsQuery->where('request_versions.division_code', $scope['division']);
 }

 if ($scope['unit'] !== null) {
 $releaseRowsQuery->where('request_versions.office_unit', $scope['unit']);
 }

 if ($scope['borrower'] !== null) {
 $releaseRowsQuery->where('custody_transactions.borrower_user_id', $scope['borrower']);
 }

 $releaseRows = $releaseRowsQuery
 ->orderByDesc('custody_transactions.released_at')
 ->limit((int) $scope['record_limit'])
 ->get([
 'borrowing_requests.request_no',
 'custody_transactions.custody_no',
 'custody_transactions.released_at',
 'custody_transactions.due_at',
 'custody_transactions.status',
 'custody_lines.actual_released_quantity',
 'users.full_name as borrower_name',
 'request_versions.office_unit',
 'request_versions.division_code',
 'request_versions.affiliation_type',
 'request_versions.external_company_name',
 ]);

 $balance = $this->inventory->portfolio(
 collect([$item]), now()->startOfDay(), now()->endOfDay()
 )[$item->id] ?? [];

 $available = (float) ($balance['borrower_available'] ?? $balance['current_available'] ?? 0);
 $stock = (float) ($balance['serviceable_total'] ?? $balance['stock'] ?? 0);
 $availability = $stock > 0 ? (int) round(($available / $stock) * 100) : 0;

 return [
 'title' => $item->unique_description,
 'value' => $released + 0,
 'value_label' => 'units released',
 'context' => 'Current stock position and activity during the selected period',
 'note' => 'Current availability is a live stock snapshot. Requested and released quantities describe activity only within the selected reporting period.',
 'stats' => [
 ['label' => 'Currently available', 'value' => $available + 0],
 ['label' => 'Serviceable stock', 'value' => $stock + 0],
 ['label' => 'Availability', 'value' => $availability.'%'],
 ['label' => 'Requested quantity', 'value' => $requested + 0],
 ['label' => 'Request appearances', 'value' => $appearances],
 ['label' => 'Units released', 'value' => $released + 0],
 ],
 'table' => $releaseRows->isEmpty() ? null : [
 'columns' => ['Released', 'Request No.', 'Custody No.', 'Borrower', 'Office / College / Unit', 'Qty Released', 'Due', 'Status'],
 'rows' => $releaseRows->map(fn ($row): array => [
 $this->date($row->released_at),
 (string) ($row->request_no ?? '—'),
 (string) ($row->custody_no ?? '—'),
 (string) ($row->borrower_name ?? '—'),
 $this->affiliationLabel($row),
 (string) ((float) $row->actual_released_quantity + 0),
 $this->date($row->due_at),
 $this->custodyLabel((string) $row->status),
 ])->all(),
 ],
 'empty' => ($requested <= 0 && $released <= 0)
 ? 'This item was neither requested nor released during this reporting period.'
 : ($releaseRows->isEmpty()
 ? 'No release transaction rows match this item in the selected reporting period.'
 : null),
 'reports_url' => AnalyticsDrilldown::utilization(
 $scope['period'], $scope['division'], $scope['unit'], ['equipment' => $item->id]
 ),
 ];
 }

 /** @param array<string, mixed> $scope */

 /* Organisation */

 /** @param array<string, mixed> $scope */
 private function division(array $scope, string $code): ?array
 {
 if (! array_key_exists($code, OrganizationalStructure::divisions())) {
 return null;
 }

 return $this->organisation(
 $scope,
 OrganizationalStructure::label($code),
 'Division detail',
 $code,
 ! empty($scope['demand_view']) ? $scope['unit'] : null
 );
 }

 /** @param array<string, mixed> $scope */
 private function unit(array $scope, string $name): ?array
 {
 if (trim($name) === '') {
 return null;
 }

 return $this->organisation($scope, $name, 'Unit detail', $scope['division'], $name);
 }

 /** @param array<string, mixed> $scope */
 private function organisation(
 array $scope,
 string $title,
 string $context,
 ?string $division,
 ?string $unit
 ): array {
 $overview = $this->analytics->overview($scope['from'], $scope['to'], $division, $unit, $scope['borrower']);
 $requested = $this->analytics->requestedEquipment($scope['from'], $scope['to'], $division, $unit, 5, $scope['borrower']);
 $released = $this->analytics->equipment($scope['from'], $scope['to'], $division, $unit, 5, $scope['borrower']);
 $returns = $this->analytics->returns($scope['from'], $scope['to'], $division, $unit, $scope['borrower']);

 $detail = [
 'title' => $title,
 'value' => $overview['total'],
 'value_label' => 'requests filed',
 'context' => $context,
 'stats' => [
 ['label' => 'Currently out', 'value' => $overview['on_custody']],
 ['label' => 'Returned on time', 'value' => $returns['on_time']],
 ['label' => 'Returned late', 'value' => $returns['late']],
 ['label' => 'Currently overdue', 'value' => $returns['overdue']],
 ],
 'bars' => $released['items'] === [] ? null : collect($released['items'])
 ->map(fn (array $row): array => [
 'label' => $row['name'],
 'value' => $row['released'].' '.$row['unit'],
 'share' => $row['share'],
 ])->all(),
 'bars_title' => 'Most actually used (released quantity)',
 'table' => $requested['items'] === [] ? null : [
 'columns' => ['Most requested item', 'Requests', 'Requested quantity'],
 'rows' => collect($requested['items'])->map(fn (array $row): array => [
 $row['name'],
 (string) $row['requests'],
 (string) $row['quantity'],
 ])->all(),
 ],
 'empty' => $overview['total'] === 0
 ? 'No borrowing requests were filed here during this reporting period.'
 : null,
 'reports_url' => AnalyticsDrilldown::borrowing($scope['period'], $division, $unit),
 ];
 if (! empty($scope['demand_view'])) {
 $evidence = $this->analytics->demandRequestEvidence(array_merge($scope, ['division' => $division, 'unit' => $unit]));
 $detail['structured_demand'] = true;
 $detail['note'] = 'Filed requests for the recorded organizational scope and period. Current custody and overdue figures remain current-state metrics. Equipment summaries below retain their existing top-five scope.';
 $detail['breakdown_tables'] = array_values(array_filter([
 ['title' => 'Most Requested Items', 'columns' => ['Equipment', 'Requests', 'Requested Qty.'], 'rows' => $detail['table']['rows'] ?? []],
 ['title' => 'Most Actually Used Items', 'columns' => ['Equipment', 'Unit', 'Released Qty.'], 'rows' => array_map(fn ($row) => [$row['name'], $row['unit'], $row['released']], $released['items'])],
 ], fn ($table) => $table['rows'] !== []));
 $detail['table_title'] = 'Filed Request Records';
 $detail['table_filter_column'] = 2;
 $detail['table'] = ['columns' => ['Request No.', 'Borrower', 'Office / Unit', 'Filed', 'Status'],
 'rows' => $evidence->take((int) $scope['record_limit'])->map(fn ($row) => [$row->request_no, $row->borrower, $this->affiliationLabel($row), $this->date($row->filed_at), RequestStatus::tryFrom((string) $row->status)?->label() ?? (string) $row->status])->all()];
 if ($evidence->count() > $scope['record_limit']) $detail['note'] .= ' Compact view: first records only; use Analytics Records for the complete list.';
 }
 return $detail;

 }

 /** @param array<string, mixed> $scope */
 private function borrower(array $scope, string $name): ?array
 {
 if (trim($name) === '') {
 return null;
 }

 $requests = $this->analytics
 ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'])
 ->join('users', 'users.id', '=', 'borrowing_requests.borrower_user_id')
 ->where('users.full_name', $name)
 ->with(['currentVersion'])
 ->orderByDesc('borrowing_requests.created_at')
 ->limit((int) $scope['record_limit'])
 ->get(['borrowing_requests.*']);

 return [
 'title' => $name,
 'value' => $requests->count(),
 'value_label' => 'requests filed',
 'context' => 'Borrower detail',
 'note' => 'Requests filed by this borrower in the selected period.',
 'table' => $requests->isEmpty() ? null : [
 'columns' => ['Request No.', 'Purpose', 'Office / College / Unit', 'Filed', 'Status'],
 'rows' => $requests->map(fn (BorrowingRequest $row): array => [
 (string) $row->request_no,
 (string) ($row->currentVersion?->purpose_event ?? ''),
 (string) ($row->currentVersion?->office_unit ?? ''),
 $this->date($row->created_at),
 $row->status->label(),
 ])->all(),
 ],
 'empty' => $requests->isEmpty()
 ? 'No borrowing requests were filed by this borrower during this reporting period.'
 : null,
 'reports_url' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit']
 ),
 ];
 }

 /* Returns */

 /** @param array<string, mixed> $scope */
 private function returns(array $scope, string $state): array
 {
 $returns = $this->analytics->returns(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower']
 );

 return match ($state) {
 'late' => $this->closedReturns($scope, $returns, true),
 'overdue' => array_merge($this->followUp($scope), [
 'title' => 'Currently Overdue',
 'value' => $returns['overdue'],
 ]),
 'accountability' => $this->openAccountability($scope, $returns),
 default => $this->closedReturns($scope, $returns, false),
 };
 }

 /**
 * @param array<string, mixed> $scope
 * @param array<string, mixed> $returns
 */
 private function closedReturns(array $scope, array $returns, bool $late): array
 {
 $query = CustodyTransaction::query()
 ->with(['borrower', 'lines.requestItem.inventoryItem', 'returns', 'laundryJob'])
 ->whereNotNull('released_at')
 ->where(function ($events) use ($scope): void {
 $events->whereBetween('closed_at', [$scope['from'], $scope['to']])
 ->orWhereHas('returns', fn ($rows) => $rows->whereBetween('received_at', [$scope['from'], $scope['to']]))
 ->orWhereHas('laundryJob', fn ($job) => $job->whereBetween('worker_received_at', [$scope['from'], $scope['to']]));
 });

 if ($versionIds = $this->analytics->filteredVersionIds($scope['division'], $scope['unit'])) {
 $query->whereIn('request_version_id', $versionIds);
 }

 if ($scope['borrower'] !== null) {
 $query->where('borrower_user_id', $scope['borrower']);
 }

 $target = $late
 ? ReturnMetricsService::RETURNED_LATE
 : ReturnMetricsService::RETURNED_ON_TIME;

 /* Physical completion date in both windows, classified the same way. */
 $comparison = $this->analytics->returnComparison(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower']
 );

 $rows = $query->get()
 ->filter(fn (CustodyTransaction $custody): bool =>
 $this->returnMetrics->completedInPeriod($custody, $scope['from'], $scope['to'])
 && $this->returnMetrics->state($custody) === $target
 )
 ->sortByDesc(fn (CustodyTransaction $custody) =>
 $this->returnMetrics->completionMoment($custody)?->timestamp ?? 0
 )
 ->take((int) $scope['record_limit'])
 ->values();

 return [
 'title' => $late ? 'Returned Late' : 'Returned On Time',
 'value' => $late ? $returns['late'] : $returns['on_time'],
 'context' => 'Completed returns in this reporting period',
 'note' => $late
 ? 'Completed returns received after the due date. Currently overdue borrowings are separate.'
 : 'Completed returns received on or before the due date.',
 'comparison' => PeriodComparison::block(
 $comparison[$late ? 'late' : 'on_time'], $this->previousWindowLabel($scope), 'returns'
 ),
 'table' => $rows->isEmpty() ? null : [
 'columns' => ['Borrower', 'Custody No.', 'Due', 'Returned', $late ? 'Days late' : 'Result'],
 'rows' => $rows->map(function (CustodyTransaction $custody) use ($late): array {
 $due = $this->returnMetrics->expectedReturnDate($custody);
 $returnedAt = $this->returnMetrics->completionMoment($custody);
 $days = $due && $returnedAt
 ? $this->analytics->lateDaysForReturn($custody, $returnedAt)
 : null;

 return [
 (string) ($custody->borrower?->full_name ?? ''),
 (string) $custody->custody_no,
 $this->date($due),
 $this->date($returnedAt),
 $late ? (string) max(0, $days ?? 0) : 'On time',
 ];
 })->all(),
 ],
 'empty' => ($late ? $returns['late'] : $returns['on_time']) === 0
 ? ($late
 ? 'No late returns were recorded for this reporting period.'
 : 'No on-time returns were recorded for this reporting period.')
 : null,
 'reports_url' => AnalyticsDrilldown::returns(
 $scope['period'], $scope['division'], $scope['unit'],
 ['return_status' => $late ? 'RETURNED_LATE' : 'RETURNED_ON_TIME']
 ),
 ];
 }

 /**
 * @param array<string, mixed> $scope
 * @param array<string, mixed> $returns
 */
 private function openAccountability(array $scope, array $returns): array
 {
 $records = $this->analytics->openAccountabilityRecords(
 $scope['from'],
 $scope['to'],
 $scope['division'],
 $scope['unit'],
 $scope['borrower']
 );

 $listed = $records->take((int) $scope['record_limit']);
 $hasStandaloneLegacy = $records->contains(
 fn (array $row): bool => in_array($row['kind'], ['billing', 'restriction'], true)
 );

 return [
 'title' => 'Open Accountability',
 'value' => $returns['open_cases'],
 'context' => 'Unresolved accountability opened in this reporting period',
 'note' => $hasStandaloneLegacy
 ? 'The total includes a standalone legacy billing or restriction record that is not linked to a separate open Incident or Late Return case.'
 : 'Each property Incident and each Late Return is counted as its own accountability case, even when both belong to the same custody transaction.',
 'table' => $listed->isEmpty() ? null : [
 'columns' => ['Borrower', 'Reference', 'Custody No.', 'Request No.', 'Accountability', 'Status'],
 'rows' => $listed->map(fn (array $row): array => [
 $row['borrower'],
 $row['reference'],
 $row['custody_no'],
 $row['request_no'],
 $row['accountability'],
 $row['status'],
 ])->all(),
 ],
 'empty' => $returns['open_cases'] === 0
 ? 'No accountability case is open for this reporting period.'
 : null,
 'reports_url' => $hasStandaloneLegacy
 ? null
 : AnalyticsDrilldown::report(
 'accountability-cases',
 $scope['period'],
 $scope['division'],
 $scope['unit'],
 ['accountability_status' => 'OPEN_CURRENT']
 ),
 ];
 }

 /* Predictive */

 /** @param array<string, mixed> $scope */

 /** @param array<string, mixed> $scope */

 /** @param array<string, mixed> $scope */

 /** @param array<string, mixed> $scope */
 private function trend(array $scope, int $bucket): ?array
 {
 $trend = $this->analytics->trend(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['period'], $scope['borrower']
 );

 $point = $trend['points'][$bucket] ?? null;

 if ($point === null) {
 return null;
 }

 [$bucketFrom, $bucketTo] = $this->trendBucketWindow($scope, $bucket);

 /*
 * A chart point is only useful as a drilldown when the records page
 * can show the same request population that produced the point.
 * Reuse requestScope() with the exact bucket dates so the list, count
 * and formal report all reconcile with the selected chart value.
 */
 $requestRows = $this->analytics
 ->requestScope($bucketFrom, $bucketTo, $scope['division'], $scope['unit'], $scope['borrower'])
 ->select('borrowing_requests.*')
 ->with(['borrower', 'currentVersion'])
 ->orderByRaw('COALESCE(request_versions.submitted_at, borrowing_requests.created_at) DESC')
 ->limit((int) $scope['record_limit'])
 ->get();

 return [
 'title' => $point['label'],
 'value' => $point['count'],
 'value_label' => 'requests filed',
 'context' => 'Borrowing activity, grouped by '.$trend['granularity'],
 'period_label' => $bucketFrom->format('d M Y').' – '.$bucketTo->format('d M Y'),
 'note' => 'Requests filed in this '.$trend['granularity'].' bucket.',
 'table' => $requestRows->isEmpty() ? null : [
 'columns' => ['Request No.', 'Borrower', 'Office / College / Unit', 'Filed', 'Schedule', 'Expected Return', 'Status'],
 'rows' => $requestRows->map(function (BorrowingRequest $request): array {
 $version = $request->currentVersion;
 $status = $request->status instanceof RequestStatus
 ? $request->status->label()
 : (RequestStatus::tryFrom((string) $request->status)?->label()
 ?? str((string) $request->status)->replace('_', ' ')->title()->toString());

 return [
 (string) $request->request_no,
 (string) ($request->borrower?->full_name ?? ''),
 $this->affiliationLabel($version),
 $this->date($version?->submitted_at ?? $request->created_at),
 $this->date($version?->schedule_date),
 $this->date($version?->return_date ?? $version?->return_due_at),
 $status,
 ];
 })->all(),
 ],
 'empty' => $point['count'] === 0
 ? 'No borrowing requests were filed in this '.$trend['granularity'].'.'
 : ($requestRows->isEmpty()
 ? 'No request rows match this analytics bucket.'
 : null),
 'reports_url' => AnalyticsDrilldown::borrowing(
 'custom',
 $scope['division'],
 $scope['unit'],
 [
 'start_date' => $bucketFrom->toDateString(),
 'end_date' => $bucketTo->toDateString(),
 ]
 ),
 ];
 }

 /**
 * Exact date window represented by a trend chart point.
 *
 * The Analytics trend groups a week by day, a month by fixed day-of-month
 * weeks (1-7, 8-14, ...), and longer selections by calendar month. The
 * window is always clipped to the parent reporting period.
 *
 * @param array<string, mixed> $scope
 * @return array{0:CarbonInterface,1:CarbonInterface}
 */
 private function trendBucketWindow(array $scope, int $bucket): array
 {
 $from = Carbon::parse($scope['from'])->startOfDay();
 $to = Carbon::parse($scope['to'])->endOfDay();

 if ($scope['period'] === 'week') {
 $start = $from->copy()->addDays($bucket)->startOfDay();
 $end = $start->copy()->endOfDay();
 } elseif ($scope['period'] === 'month') {
 $month = $from->copy()->startOfMonth();
 $start = $month->copy()->addDays($bucket * 7)->startOfDay();
 $end = $start->copy()->addDays(6)->endOfDay();
 } else {
 $month = $from->copy()->startOfMonth()->addMonths($bucket);
 $start = $month->copy()->startOfMonth()->startOfDay();
 $end = $month->copy()->endOfMonth()->endOfDay();
 }

 if ($start->lessThan($from)) {
 $start = $from->copy();
 }

 if ($end->greaterThan($to)) {
 $end = $to->copy();
 }

 return [$start, $end];
 }

 /* Request outcomes */

 /**
 * The Request Outcomes cohort, whole or narrowed to one outcome group.
 *
 * The figures are requestOutcomes() re-read, so the panel cannot disagree
 * with the card. Only the record list is queried here.
 *
 * REPORTS
 * -------
 * The Borrowing Activity Report filters on operational status, where
 * custody wins once it exists. Cancelled and expired filed requests are
 * explicitly supported there when those status filters are selected. An
 * approved request already released still appears under its custody state,
 * so only outcome groups with an exact report equivalent carry a status
 * filter into Reports.
 *
 * @param array<string, mixed> $scope
 */
 private function outcomes(array $scope, string $group): ?array
 {
 if ($group !== '' && ! array_key_exists($group, RequestOutcomes::GROUPS)) {
 return null;
 }

 $outcomes = $this->analytics->requestOutcomes(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower']
 );

 $selected = $group === ''
 ? null
 : collect($outcomes['groups'])->firstWhere('key', $group);

 $records = $this->analytics
 ->requestOutcomeScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'])
 ->when($group !== '', fn ($query) => $query->whereIn(
 'borrowing_requests.status',
 array_map(
 static fn (RequestStatus $status): string => $status->value,
 RequestOutcomes::statusesFor($group)
 )
 ))
 ->with(['borrower', 'currentVersion'])
 ->orderByRaw('COALESCE(request_versions.submitted_at, borrowing_requests.created_at) DESC')
 ->limit((int) $scope['record_limit'])
 ->get(['borrowing_requests.*']);

 $stats = [['label' => 'Filed requests', 'value' => $outcomes['total']]];

 if ($selected !== null) {
 $stats[] = ['label' => $selected['label'], 'value' => $selected['count']];
 $stats[] = [
 'label' => 'Share of filed requests',
 'value' => $selected['share'] === null ? '—' : $selected['share'].'%',
 ];
 } elseif ($outcomes['closed_after_filing'] > 0) {
 $stats[] = ['label' => 'Cancelled or expired after filing', 'value' => $outcomes['closed_after_filing']];
 }

 $reportsUrl = match ($group) {
 'cancelled' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit'], ['status' => RequestStatus::Cancelled->value]
 ),
 'expired' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit'], ['status' => RequestStatus::Expired->value]
 ),
 'rejected' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit'], ['status' => RequestStatus::Rejected->value]
 ),
 'revision' => AnalyticsDrilldown::borrowing(
 $scope['period'], $scope['division'], $scope['unit'], ['status' => RequestStatus::ReturnedForRevision->value]
 ),
 default => AnalyticsDrilldown::borrowing($scope['period'], $scope['division'], $scope['unit']),
 };

 $reportsNote = match ($group) {
 'approved', 'in_review', '' => 'Reports show each request by its operational status: an approved request '
 .'that has already been released appears there under its custody state.',
 default => null,
 };

 return [
 'title' => $selected === null ? 'Request Outcomes' : $selected['label'].' requests',
 'value' => $selected === null ? $outcomes['total'] : $selected['count'],
 'value_label' => $selected === null
 ? 'filed requests'
 : ($selected['count'] === 1 ? 'request' : 'requests')
 .($selected['share'] === null ? '' : ' - '.$selected['share'].'% of filed requests'),
 'context' => 'Current workflow outcome of requests filed in the selected period',
 'note' => trim('A cohort reading: each request filed in the period is shown in the workflow state it holds '
 .'now, so a request filed here and decided later still counts here. Drafts are never included; '
 .'requests filed and later cancelled or expired are. '
 .($selected !== null && $selected['statuses'] !== []
 ? 'This group covers: '.implode(', ', $selected['statuses']).'. '
 : '')
 .($reportsNote ?? '')),
 'stats' => $stats,
 'bars' => $outcomes['total'] === 0 ? null : array_map(
 static fn (array $row): array => [
 'label' => $row['label'],
 'value' => $row['count'].' - '.$row['share'].'%',
 'share' => (int) round((float) $row['share']),
 ],
 $outcomes['groups']
 ),
 'bars_title' => 'Share of filed requests',
 'table' => $records->isEmpty() ? null : [
 'columns' => ['Request No.', 'Borrower', 'Office / College / Unit', 'Filed', 'Current status'],
 'rows' => $records->map(fn (BorrowingRequest $row): array => [
 (string) $row->request_no,
 (string) ($row->borrower?->full_name ?? ''),
 $this->affiliationLabel($row->currentVersion),
 $this->date($row->currentVersion?->submitted_at ?? $row->created_at),
 $row->status->label(),
 ])->all(),
 ],
 'empty' => $outcomes['total'] === 0
 ? 'No filed requests are available for outcome analysis in this period.'
 : ($selected !== null && $selected['count'] === 0
 ? 'No filed request in this period is currently '.$selected['phrase'].'.'
 : null),
 'reports_url' => $reportsUrl,
 ];
 }

 /* Overdue aging */

 /**
 * The current overdue backlog by age, whole or one aging band.
 *
 * Current state only: the population is the Currently Overdue KPI's own
 * query under the same division, unit and borrower scope, and the
 * reporting period plays no part. Records that came back late are
 * completed returns, not overdue, and are never listed here.
 *
 * REPORTS
 * -------
 * Return & Accountability lists the currently overdue population, so
 * the whole-card link opens exactly that set. Reports has no
 * days-overdue filter, so a band's link opens the same complete set and
 * the note says so rather than pretending the report is narrowed.
 *
 * @param array<string, mixed> $scope
 */
 private function overdueAging(array $scope, string $bucket): ?array
 {
 $bands = collect(AnalyticsService::OVERDUE_AGING_BANDS)->keyBy('key');

 if ($bucket !== '' && ! $bands->has($bucket)) {
 return null;
 }

 $aging = $this->analytics->overdueAging($scope['division'], $scope['unit'], $scope['borrower']);
 $selected = $bucket === '' ? null : collect($aging['groups'])->firstWhere('key', $bucket);

 $records = $this->analytics->overdueAgingRecords(
 $scope['division'], $scope['unit'], $scope['borrower'], $bucket === '' ? null : $bucket, (int) $scope['record_limit']
 );

 $stats = [['label' => 'Currently overdue', 'value' => $aging['total']]];

 if ($selected !== null) {
 $stats[] = ['label' => $selected['label'].' past due', 'value' => $selected['count']];
 $stats[] = [
 'label' => 'Share of currently overdue',
 'value' => $selected['share'] === null ? '—' : $selected['share'].'%',
 ];
 } elseif ($aging['unbanded'] > 0) {
 $stats[] = ['label' => 'Flagged overdue, under a full day past due', 'value' => $aging['unbanded']];
 }

 $reportsNote = $selected === null
 ? ''
 : ' Reports has no days-overdue filter, so the source-record view opens every currently overdue record, not only this band.';

 return [
 'title' => $selected === null ? 'Overdue Aging' : 'Overdue '.$selected['label'],
 'value' => $selected === null ? $aging['total'] : $selected['count'],
 'value_label' => $selected === null
 ? 'currently overdue'
 : ($selected['count'] === 1 ? 'borrowing' : 'borrowings')
 .($selected['share'] === null ? '' : ' - '.$selected['share'].'% of currently overdue'),
 'context' => 'Current state as of today, not limited to the reporting period',
 'note' => 'Physically unreturned custody past its effective due date, grouped by open return days after that '
 .'date. A borrowing that has come back late is a completed return and is counted elsewhere, never '
 .'here, even while its accountability remains open. Days follow the Admin Transaction Schedule, including special-date changes.'.$reportsNote,
 'stats' => $stats,
 'bars' => $aging['total'] === 0 ? null : array_map(
 static fn (array $row): array => [
 'label' => $row['label'],
 'value' => $row['count'].' - '.$row['share'].'%',
 'share' => (int) round((float) $row['share']),
 ],
 $aging['groups']
 ),
 'bars_title' => 'Share of currently overdue',
 'table' => $records['rows']->isEmpty() ? null : [
 'columns' => ['Custody No.', 'Request No.', 'Borrower', 'Office / College / Unit', 'Due', 'Open return days overdue', 'Custody status'],
 'rows' => $records['rows']->map(function (CustodyTransaction $custody): array {
 $days = $this->analytics->daysOverdue($custody);
 $version = $custody->requestVersion;

 return [
 (string) $custody->custody_no,
 (string) ($custody->request?->request_no ?? ''),
 (string) ($custody->borrower?->full_name ?? ''),
 $this->affiliationLabel($version),
 $this->date($custody->due_at),
 $days === null ? '—' : (string) $days,
 $this->custodyLabel($custody->status),
 ];
 })->all(),
 ],
 'empty' => $aging['total'] === 0
 ? 'No borrowings are currently overdue.'
 : ($selected !== null && $selected['count'] === 0
 ? 'No currently overdue borrowing is '.$selected['label'].' past due.'
 : null),
 'reports_url' => AnalyticsDrilldown::returns(
 $scope['period'], $scope['division'], $scope['unit'],
 ['return_status' => 'CURRENTLY_OVERDUE']
 ),
 ];
 }

 /* Late-return rate by organisation */

 /**
 * Late-return share of completed returns, by division or unit, whole or
 * narrowed to one segment.
 *
 * The figures are lateReturnRates() re-read, so the panel cannot
 * disagree with the card. The table lists the completed returns the
 * segment was measured against - late first - and the Reports link
 * opens the late subset: Return & Accountability represents
 * RETURNED_LATE with the same period, division, unit and borrower scope
 * and the same classification.
 *
 * @param array<string, mixed> $scope
 */
 private function lateRate(array $scope, string $level, string $for, string $segment): ?array
 {
 if (! in_array($level, ['division', 'unit'], true)) {
 return null;
 }

 $rates = $this->analytics->lateReturnRates(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'], $level
 );

 $selected = null;

 if ($for !== '') {
 $key = $level === 'unit' ? $for.'|'.$segment : $for;
 $selected = collect($rates['groups'])->firstWhere('key', $key);

 if ($selected === null) {
 return null;
 }
 }

 $records = $this->analytics->lateReturnRateRecords(
 $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'],
 $selected !== null ? ($selected['code'] ?? 'unspecified') : null,
 $selected !== null && $level === 'unit' ? $selected['unit'] : null,
 (int) $scope['record_limit']
 );

 $noun = $level === 'unit' ? 'unit' : 'division';

 $stats = $selected === null
 ? [
 ['label' => 'Completed returns', 'value' => $rates['completed']],
 ['label' => 'Returned late', 'value' => $rates['late']],
 ['label' => 'Late return rate', 'value' => $rates['late_rate'] === null ? 'Not measurable' : $rates['late_rate'].'%'],
 ['label' => ucfirst($noun).'s with completed returns', 'value' => count($rates['groups'])],
 ]
 : [
 ['label' => 'Completed returns', 'value' => $selected['completed']],
 ['label' => 'Returned on time', 'value' => $selected['on_time']],
 ['label' => 'Returned late', 'value' => $selected['late']],
 ['label' => 'Late return rate', 'value' => $selected['late_rate'] === null ? 'Not measurable' : $selected['late_rate'].'%'],
 ];

 /* Reports can only follow a real division code, not the unspecified bucket. */
 $reportDivision = $selected !== null ? $selected['code'] : $scope['division'];
 $reportUnit = $selected !== null && $level === 'unit' ? $selected['unit'] : $scope['unit'];
 $reportsUrl = ($selected !== null && $selected['code'] === null)
 ? null
 : AnalyticsDrilldown::returns(
 $scope['period'], $reportDivision, $reportUnit, ['return_status' => 'RETURNED_LATE']
 );

 return [
 'title' => $selected === null
 ? 'Late Return Rate by '.($level === 'unit' ? 'Unit' : 'Division')
 : $selected['label'].($level === 'unit' ? ' - '.$selected['division_label'] : ''),
 'value' => ($selected ?? $rates)['late_rate'] === null ? null : ($selected ?? $rates)['late_rate'].'%',
 'value_label' => ($selected ?? $rates)['late_rate'] === null
 ? null
 : 'late return rate - '.($selected ?? $rates)['late'].' late of '.($selected ?? $rates)['completed'].' completed',
 'context' => 'Late-return share among completed returns in the selected period',
 'note' => 'Completed physical returns only, by their authoritative completion date, attributed to the '
 .'organisation recorded on the request at borrowing time. A borrowing still out is not a completed '
 .'return and is never in a denominator; a late return whose accountability is still open is. '
 .'A rate is shown with its counts - '.($selected !== null ? 'this segment' : 'each segment')
 .' is as large as its completed-return count says, no larger. '
 .($reportsUrl !== null
 ? 'The source-record view opens only the late returns of this scope, the numerator, not every completed return; the table below lists every completed return the rate was measured against.'
 : 'Reports cannot represent returns with no recorded division, so no source-record view is offered.'),
 'stats' => $stats,
 'bars' => $rates['groups'] === [] ? null : array_map(
 static fn (array $row): array => [
 'label' => $row['label'].($level === 'unit' ? ' - '.$row['division_label'] : ''),
 'value' => ($row['late_rate'] ?? 0).'% - '.$row['late'].' late of '.$row['completed'].' completed',
 /* A rate is already a share of 100, so the bar is the rate itself. */
 'share' => (int) round((float) ($row['late_rate'] ?? 0)),
 ],
 $rates['groups']
 ),
 'bars_title' => 'Late return rate by '.$noun,
 'table' => $records['rows']->isEmpty() ? null : [
 'columns' => ['Custody No.', 'Request No.', 'Borrower', 'Office / College / Unit', 'Due', 'Returned', 'Outcome', 'Days late'],
 'rows' => $records['rows']->map(function (array $row): array {
 /** @var CustodyTransaction $custody */
 $custody = $row['custody'];
 $due = $this->returnMetrics->expectedReturnDate($custody);
 $late = $row['state'] === ReturnMetricsService::RETURNED_LATE;
 $days = $late && $due
 ? $this->analytics->lateDaysForReturn($custody, $row['returned_at'])
 : null;

 return [
 (string) $custody->custody_no,
 (string) ($custody->request?->request_no ?? ''),
 (string) ($custody->borrower?->full_name ?? ''),
 $this->affiliationLabel($custody->requestVersion),
 $this->date($due),
 $this->date($row['returned_at']),
 $late ? 'Returned late' : 'Returned on time',
 $days === null ? '—' : (string) $days,
 ];
 })->all(),
 ],
 'empty' => $rates['completed'] === 0
 ? 'No completed returns are available for late-return rate analysis in this period.'
 : null,
 'reports_url' => $reportsUrl,
 ];
 }

 /* Helpers */

 /** @param list<array<string, mixed>> $history */

 private function quantity(CustodyTransaction $custody): string
 {
 $released = (float) $custody->lines->sum(
 fn ($line): float => (float) $line->actual_released_quantity
 );

 return rtrim(rtrim(number_format($released, 2, '.', ''), '0'), '.') ?: '0';
 }

 private function custodyLabel(?string $status): string
 {
 return match ($status) {
 'PREPARING_RELEASE' => 'Preparing Release',
 'ACTIVE' => 'On Custody',
 'RETURN_PROCESSING' => 'Return Processing',
 'PARTIALLY_RETURNED' => 'Return Processing',
 'OVERDUE' => 'Overdue',
 'INCIDENT_OPEN' => 'Incident Open',
 'OBLIGATION_OPEN' => 'Obligation Open',
 'CLOSED' => 'Completed',
 default => str((string) $status)->replace('_', ' ')->title()->toString(),
 };
 }

 private function filterLabel(?string $division, ?string $unit, ?int $borrower = null): ?string
 {
 $parts = array_filter([
 $division ? OrganizationalStructure::label($division) : null,
 $unit,
 $borrower !== null
 ? (\App\Models\User::query()->find($borrower)?->full_name ?? 'Selected borrower')
 : null,
 ]);

 return $parts === [] ? null : implode(' - ', $parts);
 }

 private function date(mixed $value): string
 {
 return $value ? Carbon::parse($value)->format('d M Y') : '—';
 }

 /**
 * The "Office / Unit" cell of a record row, from the saved request
 * version: the recorded company for an External Borrower, otherwise the
 * CSPC office (or its division when no office was recorded).
 */
 private function affiliationLabel(?object $version): string
 {
 $type = $version?->affiliation_type;
 $type = $type instanceof \App\Enums\RegistrationType ? $type->value : $type;

 if ($type === \App\Enums\RegistrationType::External->value) {
 $company = trim((string) ($version->external_company_name ?? ''));

 return $company !== '' ? $company : \App\Enums\RegistrationType::External->label();
 }

 return trim((string) ($version?->office_unit ?? '')) !== ''
 ? (string) $version->office_unit
 : OrganizationalStructure::label($version?->division_code);
 }

 // Pulled-main descriptive stock/release history; no projected values.
 private function coverage(array $scope, string $itemId): ?array
 {
 $coverage = $this->analytics->stockCoverage(
 $this->inventory, $scope['from'], $scope['to'], 200, $scope['division'], $scope['unit'], $scope['borrower'] ?? null
 );

 $row = collect($coverage['items'])->firstWhere('item_id', (int) $itemId);

 if (! $row) {
 return null;
 }

 /*
 * Recorded facts only: what is usable now and what the custody records
 * show went out in the period. No usage rate, projected days of stock
 * or risk band is derived from them.
 */
 return [
 'title' => $row['name'],
 'value' => $row['available'],
 'value_label' => 'units available now',
 'context' => 'Available stock and release history',
 'note' => 'Available stock is the current count. Released quantity and release '
 .'transactions are those recorded in the selected reporting period.',
 'stats' => [
 ['label' => 'Current usable stock', 'value' => $row['available']],
 ['label' => 'Serviceable stock', 'value' => $row['stock']],
 ['label' => 'Released in this period', 'value' => $row['released']],
 ['label' => 'Release transactions', 'value' => $row['releases']],
 ['label' => 'Reporting period', 'value' => $coverage['window_days'].' days'],
 ],
 'reports_url' => AnalyticsDrilldown::utilization(
 $scope['period'], null, null, ['equipment' => $row['item_id']]
 ),
 ];
 }
}
