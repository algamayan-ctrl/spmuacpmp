@php
 /*
 | Demand & Utilization: what was asked for, and what actually went out.
 |
 | The two are deliberately never merged. A request that was filed but never
 | released is real demand and belongs in Most Requested; only a physical
 | release belongs in Top Released. Every card states which of the two it
 | counts, and no card adds one to the other.
 |
 | Layout only. Every figure is computed in AnalyticsService and handed here
 | by AnalyticsController; nothing below is calculated from records, and a
 | reading that does not exist is stated as absent rather than filled in.
 |
 | A card's primary click opens an Analytics detail for that figure, not the
 | record module: clicking a number is a question about the number.
 */
 use App\Services\AnalyticsService;
 use App\Support\AnalyticsDetailLink;

 $division = $selectedDivision === 'all' ? null : $selectedDivision;
 $unit = $selectedUnit === 'all' ? null : $selectedUnit;

 /* Quantities are stored as decimals; whole units should not read as 9.00. */
 $qty = static fn (float $value): string => rtrim(rtrim(number_format($value, 2), '0'), '.');

 /*
 | How the trend should be drawn, on the same rule Overview uses: a period
 | is only plotted once at least one bucket carries a request, so a filter
 | with no activity does not render as a flat line along the floor.
 */
 $trendFilled = array_sum(array_column($trend['points'], 'count')) > 0;

 $trendMode = match (true) {
 $trend['points'] === [] || ! $trendFilled => 'empty',
 count($trend['points']) === 1 => 'single',
 default => 'line',
 };

 /*
 | Division legend.
 |
 | borrowerGroups() drops divisions with no activity, which is right for a
 | ranking but wrong for a composition: a reader comparing parts of a whole
 | needs to see the division that contributed nothing. The counts already
 | fetched are re-read against the full division list, so a zero division is
 | restored without a second query.
 */
 $groupCounts = collect($groups['groups'])->keyBy(fn (array $row): string => $row['code'] ?? '__unspecified');

 $divisionLegend = collect(AnalyticsService::divisions())
 ->map(fn (string $label, string $code): array => [
 'code' => $code,
 'label' => $label,
 'count' => (int) ($groupCounts[$code]['count'] ?? 0),
 'percentage' => (int) ($groupCounts[$code]['percentage'] ?? 0),
 'tone' => strtolower(explode('_', $code)[0]),
 ])
 ->values();

 if ($groupCounts->has('__unspecified')) {
 $unspecified = $groupCounts['__unspecified'];
 $divisionLegend->push([
 'code' => null,
 'label' => $unspecified['label'],
 'count' => (int) $unspecified['count'],
 'percentage' => (int) $unspecified['percentage'],
 'tone' => 'unspecified',
 ]);
 }

 if ($groupCounts->has('EXTERNAL')) {
 $externalGroup = $groupCounts['EXTERNAL'];
 $divisionLegend->push([
 'code' => null,
 'label' => $externalGroup['label'],
 'count' => (int) $externalGroup['count'],
 'percentage' => (int) $externalGroup['percentage'],
 'tone' => 'external',
 ]);
 }

 /*
 | Borrower Type = External Borrower: CSPC divisions do not apply, so the
 | same card names the External demand and lists only that row. The data,
 | total and period scope are the ones computed above.
 */
 $externalDemandOnly = ($selectedBorrowerType ?? 'all') === 'EXTERNAL';

 if ($externalDemandOnly) {
 $divisionLegend = $divisionLegend->where('tone', 'external')->values();
 }

 $divisionCardTitle = $externalDemandOnly ? 'External Borrower Demand' : 'Demand by Division';

 /*
 | Donut geometry. The radius is chosen so the circumference is exactly 100
 | and a dash length is a percentage directly. Arc lengths use the unrounded
 | share so the ring closes; the printed percentage stays the service's
 | rounded one, so what is drawn and what is read never disagree by a step.
 */
 $donutTotal = (int) $groups['total'];
 $donutOffset = 0.0;
 $donutSegments = [];

 if ($donutTotal > 0) {
 foreach ($divisionLegend as $slice) {
 if ($slice['count'] <= 0) {
 continue;
 }

 $length = $slice['count'] / $donutTotal * 100;

 $donutSegments[] = [
 'tone' => $slice['tone'],
 'label' => $slice['label'],
 /* Carried onto the arc so it can state what it draws. */
 'count' => $slice['count'],
 'percentage' => $slice['percentage'],
 'length' => round($length, 3),
 'offset' => round($donutOffset, 3),
 ];

 $donutOffset += $length;
 }
 }

 /*
 | Top borrowing units across every division, rather than one block per
 | division. unitRankings() already returns each division's top five, so
 | ranking them against each other is a re-read of rows already fetched and
 | the combined top five is complete. Division becomes secondary metadata.
 */
 $rankedUnits = collect($units['columns'])
 ->flatMap(fn (array $column): array => collect($column['units'])
 ->map(fn (array $row): array => $row + [
 'division_code' => $column['code'],
 'division_label' => $column['label'],
 'division_tone' => strtolower(explode('_', $column['code'])[0]),
 ])
 ->all())
 ->sortByDesc('count')
 ->take(5)
 ->values();

 $unitLeaderCount = (int) ($rankedUnits->max('count') ?: 0);

 $topRequested = collect(array_slice($fulfillment['items'] ?? [], 0, 5))
 ->map(fn (array $row): array => $row + [
 'quantity' => $row['requested_quantity'],
 'share' => ($fulfillment['items'][0]['requests'] ?? 0) > 0
 ? (int) round($row['requests'] / $fulfillment['items'][0]['requests'] * 100) : 0,
 ])->all();

 $topReleased = array_slice($released['items'], 0, 5);

 $duration = static function (?float $minutes): string {
 if ($minutes === null) {
 return 'No sample';
 }

 if ($minutes < 60) {
 return max(1, (int) round($minutes)).' min';
 }

 if ($minutes < 1440) {
 return number_format($minutes / 60, 1).' hr';
 }

 return number_format($minutes / 1440, 1).' days';
 };

 /*
 | How Most Requested should be drawn.
 |
 | The bar answers "how does this item compare to the one above it", so it
 | is only worth drawing when there is a comparison to make. When every
 | shown item appeared in the same number of requests the bars would all
 | be full width, which reads as a ranking that the data does not support;
 | the rows are listed plainly instead and the tie is stated once. A single
 | item has nothing to be compared against, so it is listed the same way,
 | but without a sentence about items agreeing with each other.
 */
 $requestedMode = match (true) {
 $topRequested === [] => 'empty',
 count($topRequested) === 1 => 'single',
 count(array_unique(array_column($topRequested, 'requests'))) === 1 => 'tied',
 default => 'ranked',
 };

 /*
 | Low / no usage: catalogue items with nothing released in the period.
 | slowMovingItems() sorts ascending by released quantity, so the zero rows
 | sit at the front; only those are shown, and only the first five.
 */
 $noUsage = collect($slowMoving['items'])
 ->filter(fn (array $row): bool => $row['released'] <= 0)
 ->take(5)
 ->values();

 $noUsageTotal = (int) $slowMoving['never_moved'];

 /* Peak: the weekday and hour requests were filed on, beside the main trend. */
 $peakHighest = $peak['available'] ? (int) collect($peak['days'])->max('count') : 0;

 /*
 | A semester/year view is already monthly, so the existing trend is titled
 | as the monthly view instead of adding a second duplicate chart. It shows
 | recorded filings only; nothing is projected.
 */
 $seasonalTrend = ($trend['granularity'] ?? null) === 'month';
 $trendTitle = $seasonalTrend ? 'Monthly Borrowing Trend' : 'Borrowing Demand Trend';
 $trendSubtitle = $seasonalTrend
 ? 'Borrowing requests filed per month within the selected reporting period.'
 : 'Number of borrowing requests filed per '.($trend['granularity'] ?? 'period').'.';
@endphp

<div class="analytics-kpis analytics-kpis-demand">
 {{--
 Requests Filed opens the record-backed Requests detail. The three
 beside it open card-level details that restate the same aggregate the
 card was rendered from, so every card here is a real link.
 --}}
 <a
 class="analytics-kpi-card tone-units"
 href="{{ AnalyticsDetailLink::to('requests', 'demand', $periodSelection, $division, $unit) }}"
 aria-label="View the borrowing requests filed in this period"
 >
 <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="requests" size="19" /></span>
 <span class="analytics-kpi-card-label">Requests Filed</span>
 <strong class="analytics-kpi-card-value">{{ $totals['requests'] }}</strong>
 <span class="analytics-kpi-card-note">Filed; excludes drafts, cancelled and expired</span>
 @include('analytics.partials.period-delta', ['comparison' => $totalsComparison['requests'], 'deltaClass' => 'analytics-kpi-card-meta'])
 </a>

 <a
 class="analytics-kpi-card tone-quantity"
 href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.requested-quantity']) }}"
 >
 <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="inventory" size="19" /></span>
 <span class="analytics-kpi-card-label">Requested Quantity</span>
 <strong class="analytics-kpi-card-value">{{ $qty($totals['requested_quantity']) }}</strong>
 <span class="analytics-kpi-card-note">Total quantity requested</span>
 @include('analytics.partials.period-delta', ['comparison' => $totalsComparison['requested_quantity'], 'deltaClass' => 'analytics-kpi-card-meta'])
 </a>

 <a
 class="analytics-kpi-card tone-released"
 href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.released-quantity']) }}"
 >
 <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="custody" size="19" /></span>
 <span class="analytics-kpi-card-label">Released Quantity</span>
 <strong class="analytics-kpi-card-value">{{ $qty($totals['released_quantity']) }}</strong>
 <span class="analytics-kpi-card-note">Quantity physically released</span>
 @include('analytics.partials.period-delta', ['comparison' => $totalsComparison['released_quantity'], 'deltaClass' => 'analytics-kpi-card-meta'])
 </a>

 <a
 class="analytics-kpi-card tone-custody"
 href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.active-units']) }}"
 >
 <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="users" size="19" /></span>
 <span class="analytics-kpi-card-label">Active Borrowing Units</span>
 <strong class="analytics-kpi-card-value">{{ $totals['active_units'] }}</strong>
 <span class="analytics-kpi-card-note">Units that filed at least one request</span>
 </a>
</div>

@if($detail)
 @include('analytics.partials.detail-panel')
@endif

<div class="analytics-demand-main">
 <section id="borrowing-demand-trend"
 data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.trend']) }}"
 class="analytics-card">
 <header class="analytics-card-head">
 <span class="analytics-card-mark" aria-hidden="true"><x-icon name="analytics" size="15" /></span>
 <div>
 <h2>{{ $trendTitle }}</h2>
 <p>{{ $trendSubtitle }}</p>
 </div>
 <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.trend']) }}" aria-label="View {{ $trendTitle }} details"><x-icon name="chevron-right" size="15" /></a>
 </header>

 @if($trendMode === 'empty')
 <div class="analytics-card-body">
 <p class="analytics-blank is-visualization-empty">
 <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="analytics" size="19" /></span>
 No borrowing requests were filed during this period.
 </p>
 </div>
 @else
 @if($trendMode === 'single')
@php $onlyPoint = $trend['points'][0]; @endphp

 <div class="analytics-card-body analytics-trend-single">
 <a
 class="analytics-trend-single-figure"
 href="{{ AnalyticsDetailLink::to('trend', 'demand', $periodSelection, $division, $unit, ['bucket' => 0]) }}"
 aria-label="View Details for {{ $onlyPoint['label'] }}: {{ $onlyPoint['count'] }} {{ $onlyPoint['count'] === 1 ? 'request' : 'requests' }}"
 >
 <span class="analytics-trend-single-value">
 <strong>{{ $onlyPoint['count'] }}</strong>
 {{ $onlyPoint['count'] === 1 ? 'request' : 'requests' }}
 </span>
 <span class="analytics-trend-single-bar" aria-hidden="true"><span></span></span>
 <span class="analytics-trend-single-label">{{ $onlyPoint['label'] }}</span>
 </a>
 </div>
 @else
 <div class="analytics-card-body is-plot">
 @include('analytics.partials.trend-line', ['trend' => $trend, 'lineSection' => 'demand'])
 </div>
 @endif

 <p class="analytics-insight-strip">
 <x-icon name="lightbulb" size="14" aria-hidden="true" />
 <span>{{ $trend['summary'] }}</span>
 </p>
 @endif
 </section>

 <section id="demand-by-division"
 data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.division']) }}"
 class="analytics-card">
 <header class="analytics-card-head">
 <span class="analytics-card-mark" aria-hidden="true"><x-icon name="users" size="15" /></span>
 <div>
 <h2>{{ $divisionCardTitle }}</h2>
 <p>{{ $externalDemandOnly ? 'Borrowing requests filed by External Borrowers.' : 'Share of borrowing requests filed by each division.' }}</p>
 </div>
 <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.division']) }}" aria-label="View {{ $divisionCardTitle }} details"><x-icon name="chevron-right" size="15" /></a>
 </header>

 @if($donutTotal === 0)
 <div class="analytics-card-body">
 <p class="analytics-blank is-visualization-empty">
 <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="users" size="19" /></span>
 {{ $externalDemandOnly ? 'No External Borrower requests during this period.' : 'No borrowing activity by division during this period.' }}
 </p>
 </div>
 @else
 <div class="analytics-card-body analytics-donut-body">
 <div class="analytics-donut" role="img" aria-label="{{ $externalDemandOnly ? 'External Borrower requests' : 'Borrowing requests by division' }}: {{ $donutTotal }} in total">
 <svg viewBox="0 0 42 42" aria-hidden="true" focusable="false">
 <circle class="analytics-donut-rail" cx="21" cy="21" r="15.9155" />
 @foreach($donutSegments as $segment)
 {{--
 Dash length is the slice, the gap is the rest of
 the ring. The offset counts backwards from the
 12 o'clock start, which the -90deg rotation sets.
 --}}
 <circle
 class="analytics-donut-arc is-{{ $segment['tone'] }}"
 cx="21"
 cy="21"
 r="15.9155"
 tabindex="0"
 role="img"
 data-chart-tip
 data-tip-title="{{ $segment['label'] }}"
 data-tip-rows="{{ json_encode([
 [$segment['count'] === 1 ? 'Request' : 'Requests', (string) $segment['count']],
 ['Share of requests', $segment['percentage'].'%'],
 ]) }}"
 aria-label="{{ $segment['label'] }}: {{ $segment['count'] }} {{ $segment['count'] === 1 ? 'request' : 'requests' }}, {{ $segment['percentage'] }} percent of requests"
 stroke-dasharray="{{ $segment['length'] }} {{ round(100 - $segment['length'], 3) }}"
 stroke-dashoffset="{{ round(100 - $segment['offset'], 3) }}"
 />
 @endforeach
 </svg>

 <span class="analytics-donut-centre">
 <strong>{{ $donutTotal }}</strong>
 <small>Total {{ $donutTotal === 1 ? 'Request' : 'Requests' }}</small>
 </span>
 </div>

 <ul class="analytics-donut-legend">
 @foreach($divisionLegend as $slice)
 <li>
 @if($slice['code'] && $slice['count'] > 0)
 <a href="{{ AnalyticsDetailLink::to('division', 'demand', $periodSelection, $slice['code'], $unit, ['for' => $slice['code']]) }}">
 <span class="analytics-donut-key is-{{ $slice['tone'] }}" aria-hidden="true"></span>
 <span class="analytics-donut-name">{{ $slice['label'] }}</span>
 <span class="analytics-donut-figure">{{ $slice['count'] }} <small> - {{ $slice['percentage'] }}%</small></span>
 </a>
 @else
 <div>
 <span class="analytics-donut-key is-{{ $slice['tone'] }}" aria-hidden="true"></span>
 <span class="analytics-donut-name">{{ $slice['label'] }}</span>
 <span class="analytics-donut-figure">{{ $slice['count'] }} <small> - {{ $slice['percentage'] }}%</small></span>
 </div>
 @endif
 </li>
 @endforeach
 </ul>
 </div>

 <p class="analytics-insight-strip">
 <x-icon name="information" size="14" aria-hidden="true" />
 <span>{{ $groups['summary'] }}</span>
 </p>
 @endif
 </section>
</div>

@php
 /*
 | Request Outcomes is a cohort: requests FILED in the period, in the
 | workflow state each holds now. It has its own population - unlike
 | Requests Filed it keeps requests that were filed and later cancelled or
 | expired - so its total is stated beside the bar with that difference.
 | Every count and share comes from AnalyticsService::requestOutcomes();
 | nothing here recalculates a business figure.
 */
 $outcomeLink = static fn (?string $group = null): string => AnalyticsDetailLink::to(
 'outcomes', 'demand', $periodSelection, $division, $unit, $group ? ['outcome' => $group] : []
 );

 /* Only groups with a count are drawn; a zero segment has no width to draw. */
 $drawnOutcomes = collect($outcomes['groups'])->filter(fn (array $row): bool => $row['count'] > 0)->values();
@endphp
<section
 data-card-detail="{{ $outcomeLink() }}"
 class="analytics-card analytics-outcomes">
 <header class="analytics-card-head">
 <span class="analytics-card-mark" aria-hidden="true"><x-icon name="clipboard-check" size="15" /></span>
 <div>
 <h2>Request Outcomes</h2>
 <p>Shows the current status of requests filed in this period, including those later cancelled or expired.</p>
 </div>
 <a class="analytics-card-open" href="{{ $outcomeLink() }}" aria-label="View Request Outcomes details"><x-icon name="chevron-right" size="15" /></a>
 </header>

 @if(! $outcomes['available'])
 <div class="analytics-card-body">
 <p class="analytics-blank is-visualization-empty">
 <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="clipboard-check" size="19" /></span>
 No filed requests are available for outcome analysis in this period.
 </p>
 </div>
 @else
 <div class="analytics-card-body analytics-outcomes-body">
 <div class="analytics-outcomes-composition">
 <p class="analytics-dist-total">
 <strong>{{ $outcomes['total'] }}</strong>
 <span>{{ $outcomes['total'] === 1 ? 'request in the outcome cohort' : 'requests in the outcome cohort' }}</span>
 </p>

 {{--
 A 100% bar: each segment's width is its share of filed
 requests. Segments are links, so a tap reveals the figure
 and a second tap opens the group; keyboard users reach each
 segment in turn and hear its label, count and share.
 --}}
 <div class="analytics-outcome-bar" role="list" aria-label="Request outcomes as a share of filed requests">
 @foreach($drawnOutcomes as $row)
 <a
 class="analytics-outcome-seg is-{{ $row['key'] }}"
 style="width: {{ $row['share'] }}%"
 role="listitem"
 href="{{ $outcomeLink($row['key']) }}"
 data-chart-tip
 data-tip-title="{{ $row['label'] }}"
 data-tip-rows="{{ json_encode([
 [$row['count'] === 1 ? 'Request' : 'Requests', (string) $row['count']],
 ['Share of filed requests', $row['share'].'%'],
 ]) }}"
 aria-label="View details for {{ $row['label'] }}: {{ $row['count'] }} {{ $row['count'] === 1 ? 'request' : 'requests' }}, {{ $row['share'] }} percent of filed requests"
 ><span class="visually-hidden">{{ $row['label'] }}</span></a>
 @endforeach
 </div>
 </div>

 {{-- Every group is listed, including the ones at zero, so the set reads as complete. --}}
 <ol class="analytics-outcome-legend">
 @foreach($outcomes['groups'] as $row)
 <li class="{{ $row['count'] > 0 ? '' : 'is-none' }}">
 @if($row['count'] > 0)
 <a
 href="{{ $outcomeLink($row['key']) }}"
 data-chart-tip
 data-tip-title="{{ $row['label'] }}"
 data-tip-rows="{{ json_encode([
 [$row['count'] === 1 ? 'Request' : 'Requests', (string) $row['count']],
 ['Share of filed requests', $row['share'].'%'],
 ]) }}"
 aria-label="View details for {{ $row['label'] }}"
 >
 <span class="analytics-outcome-key is-{{ $row['key'] }}" aria-hidden="true"></span>
 <span class="analytics-outcome-name">{{ $row['label'] }}</span>
 <span class="analytics-outcome-figure">{{ $row['count'] }} <small> - {{ $row['share'] }}%</small></span>
 </a>
 @else
 <div>
 <span class="analytics-outcome-key is-{{ $row['key'] }}" aria-hidden="true"></span>
 <span class="analytics-outcome-name">{{ $row['label'] }}</span>
 <span class="analytics-outcome-figure">0 <small> - 0%</small></span>
 </div>
 @endif
 </li>
 @endforeach
 </ol>
 </div>

 @if($outcomes['summary'])
 <p class="analytics-insight-strip">
 <x-icon name="information" size="14" aria-hidden="true" />
 <span>{{ $outcomes['summary'] }}</span>
 </p>
 @endif
 @endif
</section>

<div class="analytics-demand-pair">
 <section id="equipment-demand-usage"
 data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.requested-items']) }}"
 class="analytics-card">
 <header class="analytics-card-head">
 <span class="analytics-card-mark" aria-hidden="true"><x-icon name="box" size="15" /></span>
 <div>
 <h2>Equipment Demand &amp; Actual Usage</h2>
 <p>Shows the most requested items and how much was actually released.</p>
 </div>
 <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.requested-items']) }}" aria-label="View Equipment Demand and Actual Usage details"><x-icon name="chevron-right" size="15" /></a>
 </header>

 <div class="analytics-card-body">
 @if($requestedMode === 'empty')
 <p class="analytics-blank is-visualization-empty">
 <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="box" size="19" /></span>
 No requested items were recorded during this period.
 </p>
 @else
 {{--
 The bar measures how many requests contained the item, never
 the quantity: one request for 150 chairs must not outrank an
 item that two separate requests asked for. Quantity is stated
 beside it as the secondary reading.

 Where counts vary the name sits beside its bar, so the bars
 form one column that can be compared down the card. Where
 every item ties the track is dropped rather than drawn at
 full width for all of them - see $requestedMode above.
 --}}
 <ol class="analytics-rank {{ $requestedMode === 'ranked' ? 'analytics-rank-split' : 'analytics-rank-flat' }}">
 @foreach($topRequested as $index => $row)
 <li>
 <a
 class="analytics-rank-row"
 href="{{ AnalyticsDetailLink::to('card', 'demand', $periodSelection, $division, $unit, ['for' => 'demand.requested-items', 'item_name' => $row['name'], 'item_unit' => $row['unit']]) }}"
 data-chart-tip
 data-tip-title="{{ $row['name'] }}"
 data-tip-rows="{{ json_encode([
 [$row['requests'] === 1 ? 'Request' : 'Requests', (string) $row['requests']],
 ['Requested quantity', $qty($row['quantity'])],
 ['Released quantity', $qty($row['released_quantity'])],
 ['Released vs requested', $row['fulfillment'] === null ? 'Not available' : $row['fulfillment'].'%'],
 ]) }}"
 aria-label="View Details for {{ $row['name'] }}"
 >
 <span class="analytics-rank-no">{{ $index + 1 }}</span>
 <span class="analytics-rank-main">
 <span class="analytics-rank-name">{{ $row['name'] }}</span>
 @if($requestedMode === 'ranked')
 <span class="analytics-rank-track">
 <span class="analytics-rank-fill" style="width: {{ max(3, $row['share']) }}%"></span>
 </span>
 @endif
 </span>
 <span class="analytics-rank-value">
 {{ $row['requests'] }} {{ $row['requests'] === 1 ? 'request' : 'requests' }}
 <small>{{ $qty($row['quantity']) }} requested - {{ $qty($row['released_quantity']) }} released</small>
 </span>
 </a>
 </li>
 @endforeach
 </ol>
 @endif
 </div>

 {{-- Sits flush at the card's bottom edge, like every other strip here. --}}
 @if($requestedMode === 'tied')
 <p class="analytics-insight-strip">
 <x-icon name="information" size="14" aria-hidden="true" />
 <span>All top items appeared in the same number of requests.</span>
 </p>
 @endif
 </section>

 <section id="top-borrowing-units"
 data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.units']) }}"
 class="analytics-card">
 <header class="analytics-card-head">
 <span class="analytics-card-mark" aria-hidden="true"><x-icon name="users" size="15" /></span>
 <div>
 <h2>Top Borrowing Units</h2>
 <p>Ranked by filed requests.</p>
 </div>
 <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.units']) }}" aria-label="View Top Borrowing Units details"><x-icon name="chevron-right" size="15" /></a>
 </header>

 <div class="analytics-card-body">
 @if($rankedUnits->isEmpty())
 <p class="analytics-blank is-visualization-empty">
 <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="users" size="19" /></span>
 No borrowing unit activity during this period.
 </p>
 @else
 <ol class="analytics-rank">
 @foreach($rankedUnits as $index => $row)
 <li>
 <a
 class="analytics-rank-row"
 href="{{ AnalyticsDetailLink::to('unit', 'demand', $periodSelection, $row['division_code'], $row['name'], ['for' => $row['name']]) }}"
 data-chart-tip
 data-tip-title="{{ $row['name'] }}"
 data-tip-rows="{{ json_encode([
 ['Division', $row['division_label']],
 [$row['count'] === 1 ? 'Request filed' : 'Requests filed', (string) $row['count']],
 ]) }}"
 aria-label="View Details for {{ $row['name'] }}"
 >
 <span class="analytics-rank-no">{{ $index + 1 }}</span>
 <span class="analytics-rank-main">
 <span class="analytics-rank-name">
 {{ $row['name'] }}
 <small class="analytics-rank-tag is-{{ $row['division_tone'] }}">{{ $row['division_label'] }}</small>
 </span>
 <span class="analytics-rank-track">
 <span
 class="analytics-rank-fill"
 style="width: {{ $unitLeaderCount > 0 ? max(3, round($row['count'] / $unitLeaderCount * 100)) : 3 }}%"
 ></span>
 </span>
 </span>
 <span class="analytics-rank-value">
 {{ $row['count'] }}
 <small>{{ $row['count'] === 1 ? 'request' : 'requests' }}</small>
 </span>
 </a>
 </li>
 @endforeach
 </ol>
 @endif
 </div>
 </section>
</div>

{{-- Top Released Items is consolidated into Equipment Demand & Actual Usage above. --}}

<style>

.analytics-processing-strip {
 display: grid;
 grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
 gap: 12px;
 align-items: stretch;
 padding: 12px;
}

.analytics-processing-strip .analytics-lifecycle-stage {
 min-height: 112px;
 padding: 12px 12px 12px;
}

.analytics-processing-strip .analytics-lifecycle-link {
 display: none;
}

.analytics-processing-strip .analytics-lifecycle-note {
 margin-top: 2px;
}

.analytics-peak-heat {
 grid-template-columns: repeat(auto-fit, minmax(128px, 1fr));
 gap: 12px;
}

.analytics-peak-cell {
 min-height: 92px;
 padding: 12px 8px;
}

@media (max-width: 760px) {
 .analytics-processing-strip {
 grid-template-columns: minmax(0, 1fr);
 gap: 12px;
 padding: 12px;
 }

 .analytics-peak-heat {
 grid-template-columns: repeat(2, minmax(0, 1fr));
 }
}
</style>

<section
 data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.peak']) }}"
 class="analytics-card">
 <header class="analytics-card-head">
 <span class="analytics-card-mark" aria-hidden="true"><x-icon name="clock" size="15" /></span>
 <div>
 <h2>Peak Borrowing Days</h2>
 <p>Shows which days of the week borrowing requests were filed on.</p>
 </div>
 <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.peak']) }}" aria-label="View Peak Borrowing Days details"><x-icon name="chevron-right" size="15" /></a>
 </header>

 @if(! $peak['available'])
 <div class="analytics-card-body">
 <p class="analytics-blank is-visualization-empty">
 <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="clock" size="19" /></span>
 Not enough activity to determine a reliable peak.
 </p>
 </div>
 @else
 <div class="analytics-card-body">
 <div class="analytics-peak-heat" role="list" aria-label="Borrowing requests by weekday">
 @foreach($peak['days'] as $day)
@php
 $heat = $peakHighest > 0 ? max(0, min(5, (int) ceil($day['count'] / $peakHighest * 5))) : 0;
 @endphp
 <div
 class="analytics-peak-cell heat-{{ $heat }}{{ $peakHighest > 0 && $day['count'] === $peakHighest ? ' is-peak' : '' }}"
 role="listitem"
 tabindex="0"
 data-chart-tip
 data-tip-title="{{ $day['label'] }}"
 data-tip-rows="{{ json_encode(array_values(array_filter([
 [$day['count'] === 1 ? 'Request filed' : 'Requests filed', (string) $day['count']],
 $peakHighest > 0 && $day['count'] === $peakHighest ? ['Busiest weekday', 'Yes'] : null,
 ]))) }}"
 aria-label="{{ $day['label'] }}: {{ $day['count'] }} {{ $day['count'] === 1 ? 'request' : 'requests' }} filed"
 >
 <span class="analytics-peak-cell-day">{{ str($day['label'])->substr(0, 3) }}</span>
 <strong>{{ $day['count'] }}</strong>
 @if($peakHighest > 0 && $day['count'] === $peakHighest)
 <small>Peak</small>
 @endif
 </div>
 @endforeach
 </div>
 </div>

 <p class="analytics-insight-strip">
 <x-icon name="lightbulb" size="14" aria-hidden="true" />
 <span>{{ $peak['summary'] }}</span>
 </p>
 @endif
</section>

<style>
#top-borrowing-units,
#demand-by-division,
#equipment-demand-usage,
#borrowing-demand-trend {
    scroll-margin-top: 110px;
}
</style>
