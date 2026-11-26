<?php

namespace App\Services;

use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\ReturnTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryStockCardService
{
    private const HELD_STATES = ['AVAILABLE', 'ALLOCATED', 'LAUNDRY', 'DAMAGED_MAINTENANCE', 'CONDEMNED'];

    public function forItem(InventoryItem $item, array $balance): array
    {
        $entries = DB::table('inventory_transaction_lines as line')
            ->join('inventory_transactions as tx', 'tx.id', '=', 'line.inventory_transaction_id')
            ->where('line.inventory_item_id', $item->id)
            ->orderBy('tx.occurred_at')->orderBy('tx.id')->orderBy('line.id')
            ->get(['line.id', 'tx.id as transaction_id', 'tx.transaction_type', 'tx.source_type', 'tx.source_id',
                'tx.occurred_at', 'line.from_state', 'line.to_state', 'line.quantity']);

        $movements = $entries->filter(fn ($entry) => $this->direction($entry) !== 0)->values();
        $sources = $this->sources($movements);
        $current = round((float) $item->total_quantity - (float) ($balance['borrowed'] ?? 0)
            - (float) ($balance['lost'] ?? 0) - (float) ($balance['stolen'] ?? 0)
            - (float) ($balance['destroyed'] ?? 0), 3);
        if (in_array($item->condition_code, ['LOST', 'STOLEN', 'DESTROYED'], true)) {
            $current = 0.0;
        }
        $netMovement = (float) $movements->sum(fn ($entry) => $this->direction($entry) * (float) $entry->quantity);
        $hasOpeningEntry = $entries->contains('transaction_type', 'INVENTORY_INITIAL_STOCK');
        $inferredOpening = round($current - $netMovement, 3);
        $opening = $hasOpeningEntry ? 0.0 : $inferredOpening;
        $unverified = $current < -0.0001 || $opening < -0.0001
            || ($hasOpeningEntry && abs($inferredOpening) > 0.0001);
        $running = $opening;
        $rows = collect();
        $custodyRows = [];

        // Reconcile the original chronological ledger before consolidating its display.
        foreach ($movements as $entry) {
            $direction = $this->direction($entry);
            $quantity = (float) $entry->quantity;
            if ($quantity <= 0) {
                $unverified = true;
            }
            $running = round($running + $direction * $quantity, 3);
            if ($running < -0.0001) {
                $unverified = true;
            }
            $source = $sources->get($entry->source_type.':'.$entry->source_id);
            $remarks = $this->remarks($entry);
            $row = [
                'date' => $entry->occurred_at,
                'reference' => $source['reference'] ?? 'Stock entry #'.$entry->transaction_id,
                'office' => $source['office'] ?? 'SPMU',
                'receipt' => $direction > 0 ? $quantity : null,
                'issue' => $direction < 0 ? $quantity : null,
                'balance' => null,
                'remarks' => $remarks,
                'kind' => $direction > 0 ? 'RECEIPT' : 'ISSUE',
                'search_aliases' => [],
            ];

            // The query is already scoped to one inventory item. Only genuine
            // release/return movements with a resolved custody ID can be merged.
            // Unlinked legacy movements and other stock adjustments stay separate.
            $custodyId = $source['custody_id'] ?? null;
            $isRelease = $custodyId !== null
                && $entry->transaction_type === 'PHYSICAL_RELEASE'
                && $entry->source_type === CustodyTransaction::class
                && $direction < 0 && $entry->to_state === 'BORROWED';
            $isReturn = $custodyId !== null
                && $entry->transaction_type === 'PHYSICAL_RETURN'
                && $entry->source_type === ReturnTransaction::class
                && $direction > 0 && $entry->from_state === 'BORROWED';

            if (($isRelease || $isReturn) && isset($custodyRows[$custodyId])) {
                $index = $custodyRows[$custodyId];
                $group = $rows->get($index);
                $field = $isRelease ? 'issue' : 'receipt';
                $group[$field] = round((float) $group[$field] + $quantity, 3);
                $group['kind'] = $group['receipt'] !== null ? 'BOTH' : 'ISSUE';
                $group['remarks'] = implode(' · ', array_unique([...explode(' · ', $group['remarks']), $remarks]));
                $group['search_aliases'][] = $row['reference'];
                $group['search_aliases'][] = $row['office'];
                $group['search_aliases'][] = $row['date'] ? Carbon::parse($row['date'])->format('m-d-Y d M Y') : '';
                $rows->put($index, $group);
                continue;
            }

            if ($isRelease) {
                $custodyRows[$custodyId] = $rows->count();
            }
            $rows->push($row);
        }

        // A consolidated row is positioned at its first physical release.
        // Its IN includes subsequent returns, so this balance is a cumulative
        // net balance of displayed groups, not a historical balance on that date.
        $running = $opening;
        $rows = $rows->map(function (array $row) use (&$running, &$unverified): array {
            $running = round($running + (float) $row['receipt'] - (float) $row['issue'], 3);
            if ($running < -0.0001 || ($row['kind'] === 'BOTH' && $row['receipt'] > $row['issue'] + 0.0001)) {
                $unverified = true;
            }
            $row['balance'] = $running;

            return $row;
        });
        if (abs($running - $current) > 0.0001) {
            $unverified = true;
        }

        if ($unverified) {
            $rows = $rows->map(fn ($row) => array_replace($row, ['balance' => null]));
        }

        return [
            'rows' => $rows,
            'current_balance' => $unverified ? null : $current,
            'warning' => $unverified ? 'The stock balance needs SPMU review because the retained movements do not reconcile with the current inventory record.' : null,
            'balance_note' => 'Balances are consolidated by custody transaction, including subsequent returns, and do not represent historical stock on the release date. Balance includes allocated stock and returned items under laundry or repair. Items still with borrowers or recorded as lost, stolen, or destroyed are excluded.',
        ];
    }

    private function direction(object $entry): int
    {
        return (int) in_array($entry->to_state, self::HELD_STATES, true)
            - (int) in_array($entry->from_state, self::HELD_STATES, true);
    }

    private function sources(Collection $entries): Collection
    {
        $sources = collect();
        foreach ([CustodyTransaction::class, ReturnTransaction::class, Incident::class] as $type) {
            $ids = $entries->where('source_type', $type)->pluck('source_id')->unique();
            if ($ids->isEmpty()) {
                continue;
            }
            $relations = ['request.accountableUnit', 'requestVersion', 'borrower.organizationalUnit', 'borrower.borrowerRegistration'];
            $records = $type === CustodyTransaction::class
                ? CustodyTransaction::with($relations)->whereIn('id', $ids)->get()
                : $type::with(array_map(fn ($relation) => 'custody.'.$relation, $relations))->whereIn('id', $ids)->get();
            foreach ($records as $record) {
                $custody = $type === CustodyTransaction::class ? $record : $record->custody;
                $sources->put($type.':'.$record->id, [
                    'reference' => $record->custody_no ?? $record->return_no ?? $record->incident_no,
                    'custody_id' => $custody?->id,
                    'office' => $custody?->request?->accountableUnit?->unit_name
                        ?: $custody?->requestVersion?->office_unit
                        ?: $custody?->borrower?->organizationalUnit?->unit_name
                        ?: $custody?->borrower?->borrowerRegistration?->company_name ?: 'Not recorded',
                ]);
            }
        }

        return $sources;
    }

    private function remarks(object $entry): string
    {
        return match ($entry->transaction_type) {
            'INVENTORY_INITIAL_STOCK' => 'Opening stock',
            'INVENTORY_STOCK_ADDITION' => 'Additional stock received',
            'INVENTORY_QUANTITY_CORRECTION' => 'Total quantity corrected',
            'PHYSICAL_RELEASE' => 'Released for borrowing',
            'PHYSICAL_RETURN' => match ($entry->to_state) {
                'LAUNDRY' => 'Returned — for laundry',
                'DAMAGED_MAINTENANCE' => 'Returned — for repair',
                default => 'Returned',
            },
            'INVENTORY_CONDITION_RETIREMENT', 'INVENTORY_INCIDENT_WRITE_OFF' => 'Stock retired / written off',
            'INVENTORY_INCIDENT_RESTORATION' => 'Recovered / replacement stock received',
            default => $this->direction($entry) > 0 ? 'Stock received' : 'Stock removed',
        };
    }
}
