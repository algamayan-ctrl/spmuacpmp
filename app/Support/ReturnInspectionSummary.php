<?php

namespace App\Support;

use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Services\LateReturnService;

/** Read-only presentation of the recorded return and accountability facts. */
class ReturnInspectionSummary
{
    public static function for(?CustodyTransaction $custody): ?array
    {
        if (! $custody) {
            return null;
        }

        $custody->loadMissing([
            'lines.requestItem.inventoryItem', 'returns.lines', 'returns.receivedBy',
            'incidents.lines', 'incidents.reportedBy', 'incidents.headDecidedBy',
            'overdueCase.confirmedBy', 'laundryJob',
        ]);
        $returns = $custody->returns->reject(fn ($record) => in_array($record->status, ['VOID', 'VOID_CORRECTION', 'CANCELLED'], true));
        $incidents = $custody->incidents->reject(fn ($record) => in_array($record->status, ['VOID', 'VOID_CORRECTION', 'CANCELLED'], true));
        $lateCase = $custody->overdueCase;
        $active = $custody->activeAccountabilityIndicator();
        $flagged = $active !== null || in_array((string) $custody->status, ['OBLIGATION_OPEN', 'INCIDENT_OPEN'], true);
        if ($returns->isEmpty() && $incidents->isEmpty() && ! $lateCase && ! $active
            && ! in_array((string) $custody->status, ['CLOSED', 'OBLIGATION_OPEN', 'INCIDENT_OPEN', 'RETURN_PROCESSING', 'PARTIALLY_RETURNED'], true)) {
            return null;
        }

        $returnLines = $returns->flatMap(fn ($record) => $record->lines);
        $incidentLines = $incidents->flatMap(fn ($incident) => $incident->lines);
        $reasons = [];
        $rows = [];
        foreach ($custody->lines as $line) {
            $findings = [];
            $observed = $returnLines->where('custody_line_id', $line->id);
            foreach ($observed->groupBy(fn ($record) => strtoupper(trim((string) $record->condition_code))) as $condition => $records) {
                $quantity = (float) $records->sum('quantity_received');
                $findings[] = self::finding($condition, $quantity, 'Inspection');
                if ($condition !== '' && $condition !== 'FINE' && $quantity > 0) {
                    $reasons[] = self::condition($condition).': '.self::quantity($quantity);
                }
            }
            // Incident quantities are reports, never fabricated inspection results.
            foreach ($incidentLines->where('custody_line_id', $line->id)->groupBy(fn ($record) => strtoupper(trim((string) $record->observed_condition))) as $condition => $records) {
                if ($condition === '' || $observed->contains(fn ($record) => strtoupper(trim((string) $record->condition_code)) === $condition)) {
                    continue;
                }
                $quantity = (float) $records->sum('quantity');
                $findings[] = self::finding($condition, $quantity, 'Reported');
                if ($condition !== 'FINE' && $quantity > 0) {
                    $reasons[] = self::condition($condition).': '.self::quantity($quantity).' (reported)';
                }
            }
            $rows[] = [
                'item' => $line->requestItem?->description_snapshot ?: 'Item details not recorded',
                'unit' => $line->requestItem?->unit_snapshot ?: '',
                'issued' => self::quantity((float) $line->actual_released_quantity),
                'inspected' => $observed->isNotEmpty(), 'findings' => $findings,
            ];
        }

        foreach ($incidents->filter(fn ($incident) => $incident->lines->isEmpty()) as $incident) {
            $reasons[] = self::condition((string) $incident->incident_type).' reported; affected quantity not recorded';
        }

        $finalizedLateDays = $lateCase?->actual_return_date !== null && $lateCase?->late_days !== null
            ? max(0, (int) $lateCase->late_days) : null;
        $hasLateReturn = $finalizedLateDays !== null ? $finalizedLateDays > 0
            : ($lateCase !== null || $returns->contains(fn ($record) => in_array(strtoupper((string) $record->return_type), ['LATE', 'LATE_RETURN', 'OVERDUE'], true)));
        if ($finalizedLateDays !== null && $finalizedLateDays > 0) {
            $reasons[] = 'Late return: '.$finalizedLateDays.' assessed SPMU return '.($finalizedLateDays === 1 ? 'day' : 'days');
        } elseif ($hasLateReturn) {
            $reasons[] = 'Return timing requires review; a finalized late-day assessment is not recorded';
        }
        $hasPropertyFinding = $incidents->isNotEmpty() || $returnLines->contains(fn ($record) => trim((string) $record->condition_code) !== '' && strtoupper((string) $record->condition_code) !== 'FINE');
        $reason = implode('; ', array_unique($reasons));
        if ($reason === '') {
            $reason = match (true) {
                $flagged => 'Accountability is flagged; the item-level reason was not recorded.',
                $returnLines->isNotEmpty() && $returnLines->every(fn ($record) => strtoupper((string) $record->condition_code) === 'FINE')
                    => 'Good condition recorded for the inspected quantities.',
                $returns->isNotEmpty() => 'An inspection was recorded; item-level condition details are not recorded.',
                default => 'No item-level inspection result is recorded.',
            };
        }

        $caseDetails = $incidents->map(fn (Incident $incident) => [
            'reference' => $incident->incident_no ?: 'Not recorded',
            'finding' => self::condition((string) $incident->incident_type),
            'status' => StatusLabels::label($incident->status) ?: str($incident->status)->replace('_', ' ')->title()->toString(),
            'decision' => self::decision($incident),
            'decision_reason' => $incident->headDecisionReason(),
            'decided_at' => $incident->head_decided_at?->format('d M Y, g:i A'),
            'decided_by' => $incident->headDecidedBy?->full_name,
            'requirement' => AccountabilityDispositionLabels::officialDispositionLabel($incident->official_disposition),
            'remarks' => $incident->remarks,
        ])->values()->all();
        $decisions = collect($caseDetails)->pluck('decision')->unique()->values()->all();
        if ($lateCase) {
            $decisions[] = match ((string) $lateCase->status) {
                LateReturnService::STATUS_FOR_HEAD_APPROVAL => 'Late return: pending Admin review',
                LateReturnService::STATUS_AWAITING_PAYMENT => 'Late return: approved; awaiting payment',
                LateReturnService::STATUS_RESOLVED => 'Late return: resolved',
                default => 'Late return: decision not recorded',
            };
        }
        $physicalReturn = $lateCase?->actual_return_date;
        $physicalSource = $lateCase?->return_date_source;
        if (! $physicalReturn && ($lateCase || $hasLateReturn)) {
            $physicalCustody = clone $custody;
            $physicalCustody->setRelation('returns', $returns);
            [$physicalReturn, $physicalSource] = app(LateReturnService::class)->actualReturn($physicalCustody);
        }

        $inspections = $returns->sortByDesc('received_at')->map(function ($record) use ($custody) {
            return [
                'reference' => $record->return_no ?: 'Not recorded',
                'recorded_at' => $record->received_at?->format('d M Y, g:i A'),
                'recorded_by' => $record->receivedBy?->full_name,
                'physical_date' => $record->physical_return_date?->format('d M Y'),
                'physical_source' => self::source($record->physical_return_source),
                'status' => StatusLabels::label($record->status) ?: str($record->status)->replace('_', ' ')->title()->toString(),
                'remarks' => $record->remarks,
                'observations' => $record->lines->map(fn ($finding) => [
                    'item' => $custody->lines->firstWhere('id', $finding->custody_line_id)?->requestItem?->description_snapshot ?: 'Item details not recorded',
                    'condition' => self::condition((string) $finding->condition_code),
                    'quantity' => self::quantity((float) $finding->quantity_received), 'remarks' => $finding->remarks,
                ])->all(),
            ];
        })->values()->all();

        return [
            'rows' => $rows, 'reason' => $reason, 'flagged' => $flagged,
            'category' => match (true) {
                $hasPropertyFinding && $hasLateReturn => 'SLDDP findings + late return',
                $hasPropertyFinding => 'SLDDP findings', $hasLateReturn => 'Late return',
                $flagged => 'Accountability flagged', default => 'Recorded inspection',
            },
            'decision' => implode(' · ', $decisions) ?: ($hasPropertyFinding || $flagged ? 'No linked Admin decision recorded' : 'Not applicable; no accountability case recorded'),
            'inspections' => $inspections, 'cases' => $caseDetails,
            'late' => ($lateCase || $hasLateReturn) ? [
                'effective_due' => $custody->due_at?->format('d M Y'),
                'physical_return' => $physicalReturn?->format('d M Y'),
                'days' => $finalizedLateDays, 'source' => self::source($physicalSource),
                'confirmed_at' => $lateCase?->ao_confirmed_at?->format('d M Y, g:i A'),
                'confirmed_by' => $lateCase?->confirmedBy?->full_name,
            ] : null,
        ];
    }

    private static function finding(string $condition, float $quantity, string $source): array
    {
        return ['label' => self::condition($condition), 'quantity' => self::quantity($quantity), 'source' => $source,
            'tone' => match ($condition) { 'FINE' => 'success', 'DAMAGED' => 'warning', '' => 'neutral', default => 'danger' }];
    }

    private static function condition(string $condition): string
    {
        return match (strtoupper(trim($condition))) {
            'FINE' => 'Good', '' => 'Condition not recorded',
            default => str($condition)->replace('_', ' ')->lower()->title()->toString(),
        };
    }

    private static function decision(Incident $incident): string
    {
        return match (true) {
            $incident->findingWasCleared() => 'Finding cleared',
            in_array($incident->status, ['RESOLVED', 'CLOSED'], true) => 'Resolved',
            $incident->accountabilityWasConfirmed() => 'Accountability confirmed',
            $incident->head_decided_at !== null => 'Decision recorded; see case details',
            default => 'Pending Admin review',
        };
    }

    private static function source(?string $source): string
    {
        return match ($source) {
            'LAUNDRY_RECEIPT' => 'Laundry received date', 'RETURN_INSPECTION' => 'Return inspection',
            'MIXED_RETURN_COMPLETION' => 'Completion of equipment and linen returns',
            'AO_RECORDED_PHYSICAL_DATE' => 'Documented physical return date',
            null, '' => 'Not recorded', default => str($source)->replace('_', ' ')->lower()->title()->toString(),
        };
    }

    private static function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }
}
