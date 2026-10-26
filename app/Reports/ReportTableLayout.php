<?php

namespace App\Reports;

/** Shared column geometry and grouped headings for screen and every document exporter. */
final class ReportTableLayout
{
    public static function fontSize(array $columns): float
    {
        return count($columns) > 16 ? 7.5 : 8.4;
    }

    /** @return list<list<array<string, mixed>>> */
    public static function headers(array $columns): array
    {
        $grouped = collect($columns)->contains(fn (array $column): bool => filled($column['group'] ?? null));
        $top = [];
        $bottom = [];

        foreach ($columns as $index => $column) {
            $group = $column['group'] ?? null;

            if ($group) {
                $last = count($top) - 1;

                if ($index > 0 && ($columns[$index - 1]['group'] ?? null) === $group) {
                    $top[$last]['colspan']++;
                } else {
                    $top[] = ['label' => $group, 'colspan' => 1, 'rowspan' => 1, 'index' => $index];
                }

                $bottom[] = ['label' => $column['heading'] ?? $column['label'], 'colspan' => 1, 'rowspan' => 1, 'index' => $index, 'column' => $column];
            } else {
                $top[] = ['label' => $column['heading'] ?? ($column['key'] === 'borrower' ? 'Name' : $column['label']), 'colspan' => 1, 'rowspan' => $grouped ? 2 : 1, 'index' => $index, 'column' => $column];
            }
        }

        return $grouped ? [$top, $bottom] : [$top];
    }

    /** Relative weights, normalized to percentages so each format fits the same columns. */
    public static function widths(array $columns): array
    {
        $weights = array_map(function (array $column): float {
            if (isset($column['width'])) {
                return (float) $column['width'];
            }

            return match (true) {
                ($column['align'] ?? '') === 'numeric' => max(52, min(100, max(array_map('mb_strlen', preg_split('/\s+/', $column['label']))) * 8.5)),
                (bool) preg_match('/(^|_)(item|equipment|property|description|remarks|purpose|office|basis|location)/', $column['key']) => 110,
                (bool) preg_match('/_at$|_date$/', $column['key']) => 100,
                default => 76,
            };
        }, $columns);
        $total = array_sum($weights) ?: 1;

        return array_map(fn (float $weight): float => round(100 * $weight / $total, 5), $weights);
    }
}
