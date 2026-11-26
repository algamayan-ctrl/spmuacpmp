<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Support\AnalyticsDrilldown;

/** Inventory Health evidence, using the same stock balances and release scope as Analytics. */
class AnalyticsInventoryDetailService
{
    public const KEYS = [
        'inventory.available', 'inventory.reserved', 'inventory.custody', 'inventory.attention',
        'inventory.availability', 'inventory.distribution', 'inventory.low-availability',
        'inventory.utilization', 'inventory.operational', 'inventory.coverage',
    ];

    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly InventoryService $inventory,
    ) {}

    public function resolve(array $scope, string $key): array
    {
        return match ($key) {
            'inventory.utilization' => $this->utilization($scope),
            'inventory.coverage' => $this->coverage($scope),
            default => $this->snapshot($scope, $key),
        };
    }

    private function currentNote(): string
    {
        return 'Institution-wide current inventory snapshot: measured as of today. Reporting Period, Division, Office / College / Unit, and Borrower filters do not change physical stock totals.';
    }

    private function reference(array $item): array
    {
        return ['primary' => $item['name'], 'secondary' => 'INV-'.str_pad((string) $item['item_id'], 4, '0', STR_PAD_LEFT).' · '.$item['unit'],
            'url' => route('inventory.show', ['inventory' => $item['item_id']])];
    }

    private function date($value): string
    {
        return $value === null ? '—' : Carbon::parse($value)->format('d M Y');
    }

    private function stats(array $values): array
    {
        $stats = [];
        foreach ($values as $label => $value) $stats[] = compact('label', 'value');
        return $stats;
    }

    private function section(string $title, array $columns, array $rows): array
    {
        return compact('title', 'columns', 'rows');
    }

    /** Full records page has no cap; retain the original compact drawer limit. */
    private function finish(array $scope, array $detail, array $table, array $breakdowns = [], ?int $filter = null, ?string $filterLabel = null): array
    {
        $count = count($table['rows']);
        $limit = $scope['record_limit'] ?? PHP_INT_MAX;
        if ($count > $limit) $detail['note'] .= ' The compact view lists only the first records; open Analytics Records for the full evidence.';
        return $detail + [
            'structured_inventory' => true,
            'breakdown_tables' => array_values(array_filter($breakdowns, fn ($b) => $b['rows'] !== [])),
            'table_title' => $table['title'],
            'table' => ['columns' => $table['columns'], 'rows' => array_slice($table['rows'], 0, $limit)],
            'record_count' => $count,
            'table_filter_column' => $filter,
            'table_filter_label' => $filterLabel,
            'empty' => $count === 0 ? 'No records match this inventory reading.' : null,
        ];
    }

    private function snapshot(array $scope, string $key): array
    {
        $inventory = $this->analytics->inventory($this->inventory);
        $threshold = $inventory['threshold'];
        $all = collect($inventory['records']);
        $selected = $scope['inventory_item'] ?? null;
        $population = $all->filter(fn ($r) => $selected === null || (string) $r['item_id'] === (string) $selected);
        $rows = $population->filter(fn ($r) => match ($key) {
            'inventory.available' => $r['available'] > 0,
            'inventory.reserved' => $r['reserved'] > 0,
            'inventory.custody' => $r['on_custody'] > 0,
            'inventory.attention' => $r['attention'] > 0,
            'inventory.availability' => $r['borrowable'] && $r['serviceable'] > 0,
            'inventory.low-availability' => $r['borrowable'] && $r['serviceable'] > 0 && $r['available'] / $r['serviceable'] <= $threshold,
            'inventory.operational' => $r['laundry'] > 0 || $r['maintenance'] > 0 || $r['incident'] > 0 || $r['attention'] > 0,
            default => true,
        })->values();
        $title = match ($key) {
            'inventory.available' => 'Available Units', 'inventory.reserved' => 'Reserved / Allocated',
            'inventory.custody' => 'On Custody', 'inventory.attention' => 'Attention Needed',
            'inventory.availability' => 'Inventory Availability', 'inventory.low-availability' => 'Inventory Risk Watch',
            'inventory.operational' => 'Operational Inventory Status', default => 'Current Inventory Distribution',
        };
        if ($selected !== null && $population->isNotEmpty()) $title .= ' — '.$population->first()['name'];
        $detail = [
            'title' => $title, 'value' => null, 'context' => 'Current physical stock',
            'period_label' => 'Inventory snapshot as of '.now()->format('d M Y'), 'filters' => null,
            'note' => $this->currentNote(),
        ];
        $breakdowns = [];
        $filter = 0;
        $filterLabel = 'Equipment';

        if (in_array($key, ['inventory.available', 'inventory.availability', 'inventory.low-availability'], true)) {
            $rows = $key === 'inventory.available' ? $rows->sortByDesc('available')->values() : $rows->sortBy('share')->values();
            $risk = $key === 'inventory.low-availability';
            $detail['value'] = $risk ? $rows->count() : ($key === 'inventory.available' ? $rows->sum('available') : null);
            $detail['value_label'] = $risk ? 'item types' : 'units';
            $detail['stats'] = $risk ? $this->stats(['Affected item types' => $rows->count(), 'Availability threshold' => round($threshold * 100).'%'])
                : $this->stats(['Available units' => $rows->sum('available'), 'Qualifying item types' => $rows->count(), 'Serviceable stock' => $rows->sum('serviceable')]);
            $detail['note'] .= ' Availability = available quantity / serviceable stock. The table displays rounded percentages. The availability and risk rankings include active borrowable items with positive serviceable stock, including those with zero available units. Risk uses the unrounded ratio at or below '.round($threshold * 100).'%.';
            $columns = ['Item / Equipment', 'Category', 'Serviceable Stock', 'Available Qty.', 'Availability', 'Status'];
            if ($risk) $columns = array_merge($columns, ['Threshold', 'Reserved', 'On Custody', 'Laundry', 'Incident-held', 'Condition Hold']);
            $table = $this->section($risk ? 'Low Availability Records' : 'Item Availability Records', $columns,
                $rows->map(fn ($r) => array_merge([$this->reference($r), $r['category'], $r['serviceable'], $r['available'], $r['share'].'%', $r['status']],
                    $risk ? [round($threshold*100).'%', $r['reserved'], $r['on_custody'], $r['laundry'], $r['incident'], $r['condition_hold']] : []))->all());
            $detail['reports_url'] = $key === 'inventory.available' ? AnalyticsDrilldown::inventory($scope['period'], ['availability_status' => 'AVAILABLE'])
                : ($risk ? AnalyticsDrilldown::inventory($scope['period'], ['availability_status' => 'LOW_AVAILABILITY']) : AnalyticsDrilldown::inventory($scope['period']));
            $filter = 5; $filterLabel = 'Status';
        } elseif (in_array($key, ['inventory.reserved', 'inventory.custody'], true)) {
            $reserved = $key === 'inventory.reserved';
            $field = $reserved ? 'reserved' : 'on_custody';
            $rows = $rows->sortByDesc($field)->values();
            $detail['value'] = $rows->sum($field);
            $detail['value_label'] = 'units';
            $detail['stats'] = $this->stats([$reserved ? 'Allocated units' : 'Units on custody' => $detail['value'], 'Qualifying item types' => $rows->count()]);
            $detail['note'] .= $reserved ? ' Allocation evidence counts the positive remainder of allocated minus released minus restored quantity, for active or partially released allocations.'
                : ' Custody evidence counts the positive remainder of physically released minus returned quantity in the existing outstanding custody states. Unreleased and cancelled custody is excluded.';
            $breakdowns[] = $this->section($reserved ? 'Allocation by Item' : 'Outstanding Custody by Item', ['Item', 'Unit', $reserved ? 'Allocated Qty.' : 'Outstanding Qty.'],
                $rows->map(fn ($r) => [$r['name'], $r['unit'], $r[$field]])->all());
            $sources = $this->commitmentEvidence($rows, $reserved);
            $byItem = $rows->keyBy('item_id');
            $table = $this->section($reserved ? 'Allocation Records' : 'Outstanding Custody Records',
                ['Item', $reserved ? 'Request No.' : 'Custody No.', 'Borrower', $reserved ? 'Allocated Qty.' : 'Outstanding Qty.', $reserved ? 'Reservation Start' : 'Due Date', 'Status'],
                $sources->map(fn ($r) => [$this->reference($byItem->get($r->item_id)),
                    ['primary' => $r->reference, 'url' => route($reserved ? 'requests.show' : 'custody.show', $r->source_id)],
                    $r->borrower ?? '—', (float) $r->quantity, $this->date($r->date_basis), str_replace('_', ' ', $r->status)])->all());
            if (abs((float) $sources->sum('quantity') - $detail['value']) > 0.0001) $detail['note'] .= ' Source totals do not reconcile with the current balance; review the linked inventory records.';
            $detail['reports_url'] = AnalyticsDrilldown::inventory($scope['period'], ['availability_status' => $reserved ? 'ALLOCATED' : 'ON_CUSTODY']);
        } elseif (in_array($key, ['inventory.attention', 'inventory.operational'], true)) {
            $attention = $key === 'inventory.attention';
            $rows = $rows->sortByDesc('attention')->values();
            $detail['value'] = $attention ? $rows->sum('attention') : null;
            $detail['value_label'] = 'units';
            $detail['stats'] = $this->stats($attention ? ['Attention quantity' => $rows->sum('attention'), 'Affected item types' => $rows->count()]
                : ['In laundry' => $rows->sum('laundry'), 'Maintenance' => $rows->sum('maintenance'), 'Held by incident' => $rows->sum('incident'), 'Attention needed' => $rows->sum('attention')]);
            $detail['note'] .= ' Attention per item is the greater of incident-held plus condition holds, maintenance, or lost/stolen/destroyed combined, as calculated by the existing inventory service. Maintenance and incident figures may overlap, and laundry is separate. These columns must not be added together. Item links open the Inventory Overview with its operational source records.';
            $refs = $this->operationalReferences($rows);
            $table = $this->section($attention ? 'Inventory Attention Records' : 'Operational Inventory Records',
                ['Item', 'Attention Qty.', 'Laundry Qty.', 'Maintenance Qty.', 'Incident-held Qty.', 'Condition Hold', 'Master Condition', 'Recorded Source References'],
                $rows->map(fn ($r) => [$this->reference($r), $r['attention'], $r['laundry'], $r['maintenance'], $r['incident'], $r['condition_hold'],
                    str_replace('_', ' ', $r['condition']), $refs[$r['item_id']] ?? 'See Inventory Overview / Stock Card'])->all());
            $breakdowns[] = $this->section('Attention and Condition Breakdown by Item', ['Item', 'Lost', 'Stolen', 'Destroyed', 'Maintenance Outside Serviceable', 'Attention Qty.'],
                $rows->map(fn ($r) => [$r['name'], $r['lost'], $r['stolen'], $r['destroyed'], $r['maintenance_outside'], $r['attention']])->all());
            $detail['note'] .= ' Recorded references are history, not an assertion that every referenced incident or movement is still open; net quantities are reconciled at item/state level by InventoryService.';
        } else {
            // All active catalogue rows, including zero availability, zero stock and non-borrowable items.
            $states = ['Available' => $rows->sum('available'), 'Reserved' => $rows->sum('reserved'), 'On Custody' => $rows->sum('on_custody'),
                'In Laundry' => $rows->sum('laundry'), 'Incident / Unavailable' => $rows->sum('incident') + $rows->sum('condition_hold')];
            $serviceable = $rows->sum('serviceable');
            $detail['stats'] = $this->stats($states);
            $detail['value'] = $serviceable; $detail['value_label'] = 'serviceable units';
            $detail['note'] .= ' Distribution follows the dashboard serviceable-stock states. Whole-item maintenance outside serviceable stock is shown separately. All active items are included, even with zero available stock. State counts are not normalized to conceal discrepancies.';
            $breakdown = [];
            foreach ($states as $label => $qty) $breakdown[] = [$label, $qty, $serviceable > 0 ? round($qty/$serviceable*100, 1).'%' : '—'];
            $reconciles = ! $rows->contains(fn ($r) => abs($r['serviceable']-$r['available']-$r['reserved']-$r['on_custody']-$r['laundry']-$r['incident']-$r['condition_hold']) > 0.0001);
            if ($serviceable > 0 && $reconciles && abs(array_sum($states) - $serviceable) < 0.0001) {
                $detail['inventory_distribution'] = true;
                $detail['distribution'] = array_map(fn ($r, $i) => ['label' => $r[0], 'value' => $r[1], 'share' => $r[1]/$serviceable*100, 'tone' => ['positive','warning','info','laundry','danger'][$i]], $breakdown, array_keys($breakdown));
            }

            $table = $this->section('Inventory State Breakdown', ['Item', 'Total Stock', 'Serviceable Stock', 'Available', 'Reserved', 'On Custody', 'Laundry', 'Incident / Unavailable', 'Maintenance Outside Serviceable'],
                $rows->map(fn ($r) => [$this->reference($r), $r['total'], $r['serviceable'], $r['available'], $r['reserved'], $r['on_custody'], $r['laundry'], $r['incident']+$r['condition_hold'], $r['maintenance_outside']])->all());
            if (! $reconciles) $detail['note'] .= ' One or more item state totals differ from serviceable stock; review the linked Inventory Overview records.';
            $detail['reports_url'] = AnalyticsDrilldown::inventory($scope['period']);
        }
        $result = $this->finish($scope, $detail, $table, $breakdowns, $filter, $filterLabel);
        if ($filter === 0) {
            $result['table_filter_values'] = array_map(fn ($r) => $r[0]['primary'].' ('.$r[0]['secondary'].')', $result['table']['rows']);
        }
        return $result;
    }

    /** Identical current-state inclusion and quantity predicates to InventoryService::portfolio(). */
    private function commitmentEvidence(Collection $items, bool $reserved): Collection
    {
        $ids = $items->pluck('item_id')->all();
        if ($ids === []) return collect();
        if ($reserved) {
            $quantity = '(COALESCE(allocations.allocated_quantity, 0) - COALESCE(allocations.released_quantity, 0) - COALESCE(allocations.restored_quantity, 0))';
            return DB::table('allocations')->join('request_items', 'request_items.id', '=', 'allocations.request_item_id')
                ->join('request_versions', 'request_versions.id', '=', 'request_items.request_version_id')
                ->join('borrowing_requests', 'borrowing_requests.id', '=', 'request_versions.request_id')
                ->leftJoin('users', 'users.id', '=', 'borrowing_requests.borrower_user_id')
                ->whereIn('request_items.inventory_item_id', $ids)->whereIn('allocations.status', ['ACTIVE','PARTIALLY_RELEASED'])
                ->whereRaw($quantity.' > 0')->selectRaw($quantity.' AS quantity')
                ->addSelect('request_items.inventory_item_id as item_id', 'borrowing_requests.id as source_id', 'borrowing_requests.request_no as reference', 'users.full_name as borrower', 'allocations.period_start as date_basis', 'allocations.status')
                ->orderBy('allocations.period_start')->orderBy('allocations.id')->get();
        }
        $quantity = '(COALESCE(custody_lines.actual_released_quantity, 0) - COALESCE(custody_lines.returned_quantity, 0))';
        return DB::table('custody_lines')->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
            ->leftJoin('users', 'users.id', '=', 'custody_transactions.borrower_user_id')
            ->whereIn('request_items.inventory_item_id', $ids)->whereNotNull('custody_transactions.released_at')
            ->whereIn('custody_transactions.status', ['ACTIVE','RETURN_PROCESSING','OVERDUE','INCIDENT_OPEN','OBLIGATION_OPEN'])
            ->whereRaw($quantity.' > 0')->selectRaw($quantity.' AS quantity')
            ->addSelect('request_items.inventory_item_id as item_id', 'custody_transactions.id as source_id', 'custody_transactions.custody_no as reference', 'users.full_name as borrower', 'custody_transactions.due_at as date_basis', 'custody_transactions.status')
            ->orderBy('custody_transactions.due_at')->orderBy('custody_lines.id')->get();
    }

    /** Source references, not case-level allocations of item/state net balances. */
    private function operationalReferences(Collection $items): array
    {
        $ids = $items->pluck('item_id')->all();
        if ($ids === []) return [];
        $incidents = DB::table('incident_lines')->join('incidents', 'incidents.id', '=', 'incident_lines.incident_id')
            ->join('custody_lines', 'custody_lines.id', '=', 'incident_lines.custody_line_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->whereIn('request_items.inventory_item_id', $ids)->distinct()->get(['request_items.inventory_item_id as item_id', 'incidents.incident_no as reference']);
        $holds = DB::table('inventory_transaction_lines as line')->join('inventory_transactions as tx', 'tx.id', '=', 'line.inventory_transaction_id')
            ->whereIn('line.inventory_item_id', $ids)->whereIn('tx.transaction_type', ['INVENTORY_CONDITION_HOLD','INVENTORY_CONDITION_RESTORATION','INVENTORY_CONDITION_RETIREMENT','INVENTORY_INCIDENT_RESTORATION','INVENTORY_INCIDENT_WRITE_OFF'])
            ->distinct()->get(['line.inventory_item_id as item_id', 'tx.id as reference']);
        $laundry = DB::table('return_lines')->join('custody_lines', 'custody_lines.id', '=', 'return_lines.custody_line_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
            ->leftJoin('laundry_records', 'laundry_records.return_line_id', '=', 'return_lines.id')
            ->whereIn('request_items.inventory_item_id', $ids)->where(fn ($q) => $q->where('return_lines.disposition_state', 'LAUNDRY')->orWhereNotNull('laundry_records.id'))
            ->distinct()->get(['request_items.inventory_item_id as item_id', 'custody_transactions.custody_no as reference']);
        $incidents = $incidents->groupBy('item_id');
        $holds = $holds->groupBy('item_id');
        $laundry = $laundry->groupBy('item_id');
        $result = [];
        foreach ($items as $item) {
            $id = $item['item_id'];
            $references = $incidents->get($id, collect())->pluck('reference')->filter()->all();
            foreach ($holds->get($id, collect()) as $movement) $references[] = 'Stock movement #'.$movement->reference;
            foreach ($laundry->get($id, collect()) as $record) $references[] = 'Laundry · '.$record->reference;
            if ($references !== []) $result[$id] = implode(' · ', array_unique($references));
        }
        return $result;
    }

    private function releaseTable(Collection $rows): array
    {
        return $this->section('Physical Release Records', ['Custody No.', 'Request No.', 'Item', 'Unit', 'Released Qty.', 'Released Date', 'Borrower'],
            $rows->map(fn ($r) => [['primary' => $r->custody_no, 'url' => route('custody.show', $r->custody_id)], $r->request_no ?? '—', $r->name, $r->unit, (float) $r->quantity, $this->date($r->released_at), $r->borrower ?? '—'])->all());
    }

    private function utilization(array $scope): array
    {
        $selection = $scope['inventory_group'] ?? null;
        $items = $selection === null ? $this->analytics->equipment($scope['from'], $scope['to'], $scope['division'], $scope['unit'], PHP_INT_MAX, $scope['borrower'] ?? null)['items'] : [];
        $rows = $this->analytics->demandReleaseEvidence($scope);
        // Use database equality for the selected snapshot group, matching the SQL grouping.
        if ($selection !== null) {
            $items = $rows->isEmpty() ? [] : [[
                'name' => $selection['name'], 'unit' => $selection['unit'],
                'released' => (float) $rows->sum('quantity'), 'release_transactions' => $rows->pluck('custody_id')->unique()->count(),
            ]];
        }
        $detail = ['title' => $selection === null ? 'Utilization' : $selection['name'].' — Utilization', 'value' => count($items), 'value_label' => 'items released',
            'context' => 'Ranked by quantity physically released',
            'note' => 'Period-scoped physical release activity, by recorded equipment description and unit. The dashboard previews five groups; this page includes the complete qualifying release population. Release counts are distinct custody transactions per group.',
            'stats' => $this->stats(['Released quantity' => array_sum(array_column($items, 'released')), 'Item groups' => count($items), 'Release transactions' => $rows->pluck('custody_id')->unique()->count()]),
            'reports_url' => AnalyticsDrilldown::utilization($scope['period'], $scope['division'], $scope['unit'])];
        if (abs((float) $rows->sum('quantity') - (float) array_sum(array_column($items, 'released'))) > 0.0001) $detail['note'] .= ' Source totals do not reconcile with the period release totals; review the linked custody records.';
        $breakdown = $this->section('Released Quantity by Item', ['Equipment', 'Unit', 'Release Transactions', 'Released Qty.'],
            array_map(fn ($r) => [$r['name'], $r['unit'], $r['release_transactions'], $r['released']], $items));
        return $this->releaseDetail($scope, $detail, $rows, $breakdown);
    }

    private function releaseDetail(array $scope, array $detail, Collection $rows, array $breakdown): array
    {
        $result = $this->finish($scope, $detail, $this->releaseTable($rows), [$breakdown], 2, 'Equipment');
        $result['table_filter_values'] = array_map(fn ($r) => $r[2].' ('.$r[3].')', $result['table']['rows']);
        return $result;
    }

    public function coverageItem(array $scope, string $itemId): ?array
    {
        $scope['inventory_item'] = $itemId;
        $detail = $this->coverage($scope);
        return $detail['value'] === null ? null : $detail;
    }

    private function coverage(array $scope): array
    {
        $coverage = $this->analytics->stockCoverage($this->inventory, $scope['from'], $scope['to'], PHP_INT_MAX, $scope['division'], $scope['unit'], $scope['borrower'] ?? null);
        $items = collect($coverage['items']);
        $selection = $scope['inventory_item'] ?? null;
        if ($selection !== null) $items = $items->filter(fn ($r) => (string) $r['item_id'] === (string) $selection)->values();
        $row = $items->first();
        $detail = ['title' => $selection !== null && $row ? $row['name'] : 'Coverage Detail',
            'value' => $selection === null ? $items->count() : ($row['available'] ?? null), 'value_label' => $selection === null ? 'items released in this period' : 'units available now',
            'context' => 'Available stock and release history',
            'note' => 'Available stock is the current count. Released quantity and release transactions are those recorded in the selected reporting period for active borrowable catalogue items. The dashboard previews five items; the detail includes every qualifying item. No future shortage is estimated.',
            'stats' => $selection !== null && $row ? $this->stats(['Current usable stock' => $row['available'], 'Serviceable stock' => $row['stock'], 'Released in this period' => $row['released'], 'Release transactions' => $row['releases']]) : $this->stats(['Items with release history' => $items->count(), 'Reporting window' => $coverage['window_days'].' days']),
            'reports_url' => $selection !== null && $row ? AnalyticsDrilldown::utilization($scope['period'], $scope['division'], $scope['unit'], ['equipment' => $row['item_id']]) : null];
        $breakdown = $this->section('Stock and Release History', ['Item', 'Available Now', 'Serviceable Stock', 'Period Released', 'Release Count', 'Last Released'],
            $items->map(fn ($r) => [$r['name'], $r['available'], $r['stock'], $r['released'], $r['releases'], $this->date($r['last_released_at'])])->all());
        $ids = $items->pluck('item_id')->all();
        $idLookup = array_fill_keys($ids, true);
        $rows = $ids === [] ? collect() : $this->analytics->demandReleaseEvidence($scope)->filter(fn ($r) => isset($idLookup[$r->item_id]))->values();
        if (abs((float) $rows->sum('quantity') - (float) $items->sum('released')) > 0.0001) $detail['note'] .= ' Source totals do not reconcile with the period release totals; review the linked custody records.';
        return $this->releaseDetail($scope, $detail, $rows, $breakdown);
    }
}
