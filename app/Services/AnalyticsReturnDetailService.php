<?php

namespace App\Services;

use App\Models\CustodyTransaction;
use App\Support\OrganizationalStructure;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Read-only evidence for the full Return Performance Analytics Records pages. */
class AnalyticsReturnDetailService
{
    public const CARD_KEYS = [
        'returns.trend', 'returns.outcome', 'returns.followup', 'returns.condition',
        'returns.issues', 'overview.return-compliance',
    ];

    public function __construct(private readonly AnalyticsService $analytics) {}

    public function card(array $scope, string $key, array $detail): array
    {
        return match ($key) {
            'returns.condition' => $this->condition($scope, $detail),
            'returns.issues' => $this->accountability($scope, $detail, true),
            'returns.followup' => $this->overdue($scope, $detail, null, true),
            default => $this->completed($scope, $detail, null, $key === 'returns.trend'),
        };
    }

    public function record(array $scope, string $type, Request $request, array $detail): array
    {
        return match ($type) {
            'follow-up' => $this->overdue($scope, $detail, null, false),
            'overdue-aging' => $this->overdue($scope, $detail, $request->input('bucket') ?: null, true),
            'late-rate' => $this->lateRate($scope, $detail, $request),
            default => match ($request->input('state')) {
                'accountability' => $this->accountability($scope, $detail, false),
                'overdue' => $this->overdue($scope, $detail, null, false),
                'late' => $this->completed($scope, $detail, ReturnMetricsService::RETURNED_LATE, false),
                default => $this->completed($scope, $detail, ReturnMetricsService::RETURNED_ON_TIME, false),
            },
        };
    }

    private function periodArgs(array $s): array
    {
        return [$s['from'], $s['to'], $s['division'], $s['unit'], $s['borrower'] ?? null];
    }

    private function orgArgs(array $s): array
    {
        return [$s['division'], $s['unit'], $s['borrower'] ?? null];
    }

    private function date($date): string
    {
        return $date ? Carbon::parse($date)->format('d M Y') : '—';
    }

    private function label($code): string
    {
        return $code === null || $code === '' ? '—' : str((string) $code)->replace('_', ' ')->title()->toString();
    }

    private function link(string $label, ?int $id, string $route, string $parameter): array|string
    {
        return $id ? ['primary' => $label, 'url' => route($route, [$parameter => $id])] : $label;
    }

    private function custodyLink(CustodyTransaction $custody): array|string
    {
        return $this->link((string) $custody->custody_no, $custody->id, 'custody.show', 'custody');
    }

    private function rate(int|float $n, int|float $d, int $precision = 1): string
    {
        return $d > 0 ? round($n / $d * 100, $precision).'%' : 'Not measurable';
    }

    private function stats(array $values): array
    {
        $stats = [];
        foreach ($values as $label => $value) $stats[] = compact('label', 'value');
        return $stats;
    }

    private function section(string $title, array $columns, array $rows, ?string $note = null): array
    {
        return compact('title', 'columns', 'rows', 'note');
    }

    private function finish(array $detail, string $title, array $columns, array $rows, array $breakdowns, int $filter, string $label): array
    {
        return array_replace($detail, [
            'structured_returns' => true,
            'breakdown_tables' => array_values(array_filter($breakdowns, fn ($b) => $b['rows'] !== [])),
            'table_title' => $title,
            'table' => compact('columns', 'rows'),
            'table_filter_column' => $filter,
            'table_filter_label' => $label,
            'record_count' => count($rows),
            'hide_table_footer' => false,
        ]);
    }

    private function completed(array $scope, array $detail, ?string $state, bool $trend): array
    {
        $records = $this->analytics->completedReturnDetailRecords(...$this->periodArgs($scope));
        $total = $records->count();
        $onTime = $records->where('state', ReturnMetricsService::RETURNED_ON_TIME)->count();
        $late = $records->where('state', ReturnMetricsService::RETURNED_LATE)->count();
        $selected = $state === null ? $records : $records->where('state', $state);
        $metrics = app(ReturnMetricsService::class);
        $rows = $selected->sortByDesc(fn ($r) => $r['returned_at']->timestamp)->map(function ($r) use ($metrics, $scope, $trend) {
            $custody = $r['custody'];
            $isLate = $r['state'] === ReturnMetricsService::RETURNED_LATE;
            $due = $metrics->expectedReturnDate($custody);
            $row = [$this->custodyLink($custody), $custody->borrower?->full_name ?? '—', $this->date($due), $this->date($r['returned_at']),
                $isLate && $due ? $this->analytics->lateDaysForReturn($custody, $r['returned_at']) : ($due ? 0 : '—')];
            if ($trend) $row[] = $this->analytics->demandBucketLabel($r['returned_at'], $scope['period']);
            $row[] = $isLate ? 'Returned Late' : 'Returned On Time';
            return $row;
        })->values()->all();
        $columns = ['Custody No.', 'Borrower', 'Effective Due', 'Actual Return', 'Chargeable Days Late'];
        if ($trend) $columns[] = 'Period Bucket';
        $columns[] = 'Outcome';
        // Preserve the parent outcome chart's rounded whole-percent display.
        $precision = ($trend || $detail['title'] === 'Return Outcome') ? 0 : 1;
        $detail['stats'] = $this->stats(['Completed returns' => $total, 'Returned on time' => $onTime,
            'Returned late' => $late, 'On-time return rate' => $this->rate($onTime, $total, $precision)]);
        $detail['note'] = 'On-time return rate = on-time completed returns ÷ all completed returns × 100. Physical completion date determines the reporting period, with the existing legacy completion-date fallback retained. Cancelled and unreleased transactions are excluded; a late return with open accountability is still a completed return. Chargeable days use the finalized assessment when available, otherwise the operational return calendar.';
        $breakdowns = [];
        if ($trend && $total > 0) {
            $points = $this->analytics->returnTrend($scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['period'], $scope['borrower'] ?? null)['points'];
            $breakdowns[] = $this->section('Completed Returns by Period', ['Period', 'Completed', 'On Time', 'Late', 'On-time Rate'], array_map(fn ($p) => [$p['label'], $p['on_time'] + $p['late'], $p['on_time'], $p['late'], $this->rate($p['on_time'], $p['on_time'] + $p['late'])], $points));
        } elseif ($state === null && $total > 0 && $detail['title'] !== 'Return Outcome') {
            $breakdowns[] = $this->section('Return Outcome Breakdown', ['Outcome', 'Completed Returns', 'Share'], [
                ['Returned On Time', $onTime, $this->rate($onTime, $total)], ['Returned Late', $late, $this->rate($late, $total)],
            ]);
        }
        if ($state !== null) $detail['note'] .= ' The supporting table is restricted to the selected outcome; the summary gives the complete denominator.';
        return $this->finish($detail, $state === ReturnMetricsService::RETURNED_LATE ? 'Late Return Records' : ($state === null ? 'Completed Return Records' : 'On-time Return Records'), $columns, $rows, $breakdowns, count($columns)-1, 'Outcome');
    }

    private function overdue(array $scope, array $detail, ?string $band, bool $showAging): array
    {
        [$division, $unit, $borrower] = $this->orgArgs($scope);
        $aging = $this->analytics->overdueAging($division, $unit, $borrower);
        $records = $this->analytics->overdueAgingRecords($division, $unit, $borrower, $band, PHP_INT_MAX)['rows'];
        $records->loadMissing(['lines.requestItem.inventoryItem', 'laundryJob']);
        $metrics = app(ReturnMetricsService::class);
        $lateReturns = app(LateReturnService::class);
        $labels = array_column(AnalyticsService::OVERDUE_AGING_BANDS, 'label', 'key') + ['unbanded' => 'Unbanded'];
        $rows = $records->map(function (CustodyTransaction $custody) use ($metrics, $lateReturns, $labels) {
            $days = $this->analytics->daysOverdue($custody);
            return [$this->custodyLink($custody), $custody->borrower?->full_name ?? '—', $this->date($metrics->expectedReturnDate($custody)),
                $metrics->quantities($custody)['outstanding'] + 0, $days ?? '—', $labels[$this->analytics->overdueAgingBand($days)],
                LateReturnService::linenStateLabel($lateReturns->linenReturnState($custody)) ?? '—', $this->label($custody->status),
                $custody->request?->request_no ?? '—', $custody->requestVersion?->office_unit ?: '—'];
        })->all();
        $stats = ['Currently overdue' => $aging['total'], 'Outstanding quantity (recorded)' => $records->sum(fn ($c) => $metrics->quantities($c)['outstanding']) + 0];
        if ($band !== null) $stats = ['Currently overdue' => $aging['total'], 'Borrowings in selected band' => $records->count(), 'Share of current overdue' => $this->rate($records->count(), $aging['total']), 'Outstanding quantity in band (recorded)' => $stats['Outstanding quantity (recorded)']];
        $detail['stats'] = $this->stats($stats);
        if ($band === null && $aging['provisional'] > 0) $detail['note'] = ($detail['note'] ?? '').' '.$aging['provisional'].' linen borrowing(s) are provisionally overdue, pending verification.';
        $detail['period_label'] = 'Current custody snapshot — '.$this->date(now());
        $detail['note'] = ($detail['note'] ?? '').' Current-state measure, independent of the reporting period. Outstanding quantity is the recorded released-minus-returned balance, not an estimate of physical linen receipt; the linen verification state is shown separately. The existing operational-day aging calculation is retained.';
        $breakdowns = [];
        if ($showAging && $aging['total'] > 0) {
            $groups = array_map(fn ($g) => [$g['label'], $g['count'], $this->rate($g['count'], $aging['total'])], $aging['groups']);
            if ($aging['unbanded'] > 0) $groups[] = ['Unbanded (no positive open return days)', $aging['unbanded'], $this->rate($aging['unbanded'], $aging['total'])];
            $breakdowns[] = $this->section('Current Overdue Aging', ['Aging Band', 'Borrowings', 'Share'], $groups, $band === null ? 'All current overdue borrowings, including unbanded cases.' : 'Breakdown is the complete current backlog; supporting records are limited to the selected band.');
        }
        return $this->finish($detail, 'Overdue Custody Records', ['Custody No.', 'Borrower', 'Effective Due', 'Outstanding Qty. (Recorded)', 'Open Return Days Overdue', 'Aging Group', 'Linen Verification', 'Status', 'Request No.', 'Office / Unit'], $rows, $breakdowns, 5, 'Aging group');
    }

    private function lateRate(array $scope, array $detail, Request $request): array
    {
        $level = (string) $request->input('level', 'division');
        $for = (string) $request->input('for', '');
        $segment = (string) $request->input('segment', '');
        $rates = $this->analytics->lateReturnRates(...[...$this->periodArgs($scope), $level]);
        $selected = $for === '' ? null : collect($rates['groups'])->firstWhere('key', $level === 'unit' ? $for.'|'.$segment : $for);
        $records = $this->analytics->lateReturnRateRecords(...[...$this->periodArgs($scope), $selected !== null ? ($selected['code'] ?? 'unspecified') : null, $selected !== null && $level === 'unit' ? $selected['unit'] : null, PHP_INT_MAX])['rows'];
        $metrics = app(ReturnMetricsService::class);
        $rows = $records->map(function ($r) use ($metrics) {
            $c = $r['custody']; $v = $c->requestVersion;
            $division = trim((string) ($v?->division_code ?? ''));
            $group = $division === '' ? 'Unspecified' : (AnalyticsService::divisions()[$division] ?? OrganizationalStructure::label($division));
            $late = $r['state'] === ReturnMetricsService::RETURNED_LATE;
            $due = $metrics->expectedReturnDate($c);
            return [$this->custodyLink($c), $c->borrower?->full_name ?? '—', $group, $v?->office_unit ?: 'Unspecified unit', $this->date($due), $this->date($r['returned_at']), $late && $due ? $this->analytics->lateDaysForReturn($c, $r['returned_at']) : ($due ? 0 : '—'), $late ? 'Returned Late' : 'Returned On Time', $c->request?->request_no ?? '—'];
        })->all();
        $groups = $selected === null ? $rates['groups'] : [$selected];
        $breakdown = array_map(fn ($g) => [$g['label'].($level === 'unit' ? ' — '.$g['division_label'] : ''), $g['completed'], $g['on_time'], $g['late'], $this->rate($g['late'], $g['completed'])], $groups);
        if ($level === 'unit' && $selected === null) {
            $unassigned = $records->filter(fn ($r) => trim((string) ($r['custody']->requestVersion?->office_unit ?? '')) === '');
            if ($unassigned->isNotEmpty()) {
                $late = $unassigned->where('state', ReturnMetricsService::RETURNED_LATE)->count();
                $breakdown[] = ['Unspecified unit (not ranked)', $unassigned->count(), $unassigned->count()-$late, $late, $this->rate($late, $unassigned->count())];
            }
        }
        $detail['note'] .= ' Late rate = late completed returns ÷ all completed returns in the same group × 100. Records with no unit are shown separately when applicable, not added to a ranked unit.';
        return $this->finish($detail, 'Contributing Return Records', ['Custody No.', 'Borrower', 'Division', 'Office / Unit', 'Effective Due', 'Actual Return', 'Chargeable Days Late', 'Outcome', 'Request No.'], $rows, [$this->section('Late Return Rate Breakdown', ['Group', 'Completed', 'On Time', 'Late', 'Late Rate'], $breakdown)], $selected !== null ? 7 : ($level === 'unit' ? 3 : 2), $selected !== null ? 'Outcome' : ($level === 'unit' ? 'Office / Unit' : 'Division'));
    }

    private function condition(array $scope, array $detail): array
    {
        $records = $this->analytics->returnInspectionEvidence(...$this->periodArgs($scope));
        $rows = $records->map(fn ($r) => [$r->return_no, $this->link($r->custody_no, $r->custody_id, 'custody.show', 'custody'), $r->item ?? '—', $r->unit ?? '—', (float) $r->quantity_received + 0, $this->label($r->condition_code), $this->date($r->received_at)])->all();
        $total = (float) $records->sum('quantity_received') + 0;
        $breakdown = $records->groupBy('condition_code')->map(fn ($g, $code) => [$this->label($code), (float) $g->sum('quantity_received') + 0, $this->rate($g->sum('quantity_received'), $total)])->sortByDesc(fn ($r) => $r[1])->values()->all();
        $detail['value'] = $total;
        $detail['stats'] = $this->stats(['Inspected quantity' => $total, 'Recorded conditions' => count($breakdown)]);
        $detail['note'] = 'Quantities come from actual inspection lines received within the selected period, including partial inspections. Share = condition quantity ÷ total inspected quantity × 100. These are inspection quantities, not custody counts or incident counts. The formal completed-return report may not include all partial inspections.';
        return $this->finish($detail, 'Return Inspection Records', ['Return Ref.', 'Custody No.', 'Item', 'Unit', 'Inspected Qty.', 'Condition', 'Inspection Date'], $rows, [$this->section('Condition Distribution', ['Condition', 'Inspected Qty.', 'Share'], $breakdown) + ['bar_column' => 1]], 5, 'Condition');
    }

    private function accountability(array $scope, array $detail, bool $includeIncidents): array
    {
        $cases = $this->analytics->openAccountabilityRecords(...$this->periodArgs($scope));
        $rows = $cases->map(fn ($r) => [$this->link($r['reference'] ?: '—', $r['borrower_id'], 'accountability.borrower', 'borrower'),
            $this->link($r['custody_no'] ?: '—', $r['custody_id'], 'custody.show', 'custody'), $r['borrower'] ?: '—', $r['accountability'], $this->label($r['incident_type']), $r['affected_quantity'] ?? '—', $this->date($r['opened_at']), $r['status'], $r['request_no'] ?: '—'])->all();
        $groups = $cases->groupBy('accountability')->map(fn (Collection $group, $name) => [$name, $group->count()])->values()->all();
        $breakdowns = [$this->section('Open Case Breakdown', ['Accountability Type', 'Cases'], $groups)];
        $stats = ['Open accountability cases' => $cases->count()];
        if ($includeIncidents) {
            $incidents = $this->analytics->incidentDetailRecords(...$this->periodArgs($scope));
            $incidentGroups = $incidents->groupBy('incident_type')->map(fn ($g, $type) => [$this->label($type), $g->count(), (float) $g->sum(fn ($i) => $i->lines->sum('quantity')) + 0])->values()->all();
            $stats += ['Incidents recorded' => $incidents->count(), 'Incidents still open' => $incidents->reject(fn ($i) => in_array($i->status, ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'], true))->count(), 'Incident affected quantity' => array_sum(array_column($incidentGroups, 2))];
            $breakdowns[] = $this->section('Property Incident Breakdown', ['Incident Type', 'Cases', 'Affected Qty.'], $incidentGroups);
            $breakdowns[] = $this->section('Property Incident Records', ['Incident No.', 'Custody No.', 'Borrower', 'Incident Type', 'Affected Qty.', 'Reported', 'Status'], $incidents->map(fn ($i) => [
                $this->link($i->incident_no, $i->borrower_user_id, 'accountability.borrower', 'borrower'),
                $this->link($i->custody?->custody_no ?? '—', $i->custody?->id, 'custody.show', 'custody'),
                $i->borrower?->full_name ?? '—', $this->label($i->incident_type), (float) $i->lines->sum('quantity') + 0, $this->date($i->reported_at), $this->label($i->status),
            ])->all(), 'All recorded property incidents in the selected period, including resolved records. This is a separate population, not additional open cases.') + ['evidence' => true, 'filter_column' => 6, 'filter_label' => 'Status'];
        }
        $detail['stats'] = $this->stats($stats);
        $detail['note'] = 'One unresolved property incident or late-return case counts as one case. Linked billings and restrictions are not counted again; eligible standalone legacy records remain included. Affected quantity is recorded for property incidents only. Case references open the existing borrower accountability workspace.';
        if ($cases->isEmpty() && $includeIncidents && $incidents->isNotEmpty()) $detail['note'] .= ' No open accountability cases match this period; the property incident records below are historical evidence.';
        return $this->finish($detail, 'Open Accountability Records', ['Case / Reference', 'Custody No.', 'Borrower', 'Accountability Type', 'Incident Type', 'Affected Qty.', 'Opened', 'Status', 'Request No.'], $rows, $breakdowns, 3, 'Accountability type');
    }
}
