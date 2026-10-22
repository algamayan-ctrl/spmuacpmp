<?php

namespace App\Services;

use App\Models\BorrowingRequest;
use App\Models\ProcessingStageRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Informational estimate only; never authorizes, approves, schedules or releases assets.
 *
 * Stage targets are ACTIVE staff handling time. Queued/waiting time and scheduled
 * pickup availability are not silently counted as active work.
 */
class ProcessingTimeEstimateService
{
    public const TARGETS = [
        'SAO_VERIFICATION' => ['label' => 'SAO Verification', 'min' => 2, 'max' => 5, 'typical' => 3],
        'HEAD_APPROVAL' => ['label' => 'Head/Admin Approval', 'min' => 2, 'max' => 5, 'typical' => 5],
        'EQUIPMENT_PREPARATION' => ['label' => 'Equipment Preparation', 'min' => 5, 'max' => 10, 'typical' => 10],
        'RELEASE_RECORDING' => ['label' => 'Release Recording', 'min' => 1, 'max' => 3, 'typical' => 2],
    ];

    public static function stageKeys(bool $requiresSao): array
    {
        return $requiresSao
            ? array_keys(self::TARGETS)
            : ['HEAD_APPROVAL', 'EQUIPMENT_PREPARATION', 'RELEASE_RECORDING'];
    }

    public function estimate(BorrowingRequest $request, ?CarbonInterface $at = null): array
    {
        $now = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $request->loadMissing(['currentVersion.approvalSteps', 'custody']);
        $version = $request->currentVersion;
        if (! $version) {
            return [
                'stages' => [], 'min_total' => 0, 'max_total' => 0,
                'typical_total' => 0, 'remaining_min' => 0, 'remaining_max' => 0,
                'completed_count' => 0, 'progress' => 0, 'expected_release' => null,
                'release_note' => 'Submit a request to see an estimate.',
            ];
        }

        $custody = $request->custody;
        $requiresSao = (bool) $version->off_campus;
        $keys = self::stageKeys($requiresSao);
        $runs = ProcessingStageRun::query()
            ->where('request_version_id', $version->id)
            ->get()->keyBy('stage_code');
        $sao = $version->approvalSteps->firstWhere('sequence_no', 1);
        $head = $version->approvalSteps->firstWhere('sequence_no', 2);
        $approved = $head && $head->decision === 'APPROVED';
        $terminal = in_array($request->status->value, [
            'CANCELLED', 'EXPIRED', 'REJECTED', 'RETURNED_FOR_REVISION',
        ], true);
        $lastFinished = null;
        $completed = 0;
        $remainingMin = 0.0;
        $remainingMax = 0.0;
        $remainingTypical = 0.0;
        $stages = [];

        foreach ($keys as $key) {
            $target = self::TARGETS[$key];
            $run = $runs->get($key);
            $ended = match ($key) {
                'SAO_VERIFICATION' => $sao?->decided_at,
                'HEAD_APPROVAL' => $head?->decided_at,
                'EQUIPMENT_PREPARATION' => $custody?->prepared_at,
                'RELEASE_RECORDING' => $custody?->released_at,
            };
            $queueAt = match ($key) {
                'SAO_VERIFICATION' => $sao?->received_at ?: $version->submitted_at,
                'HEAD_APPROVAL' => $head?->received_at
                    ?: ($requiresSao ? $sao?->decided_at : $version->submitted_at),
                'EQUIPMENT_PREPARATION' => $request->final_approved_at,
                'RELEASE_RECORDING' => $custody?->prepared_at,
            };
            // Release processing cannot start before the approved pickup window.
            if ($key === 'RELEASE_RECORDING' && $custody?->scheduled_release_at && $queueAt) {
                $queueAt = CarbonImmutable::instance($queueAt)->max(
                    CarbonImmutable::instance($custody->scheduled_release_at)
                );
            }

            $started = $run?->started_at;
            $elapsed = $started
                ? max(0, CarbonImmutable::instance($started)->diffInSeconds(
                    $ended ? CarbonImmutable::instance($ended) : $now, false
                ) / 60)
                : null;
            // "Delayed" only applies to a stage explicitly started by staff.
            $status = $ended
                ? 'Completed'
                : ($terminal
                    ? 'Not applicable'
                    : ($started ? ($elapsed > $target['max'] ? 'Delayed' : 'In Progress') : 'Pending'));
            if ($ended) {
                $completed++;
                $lastFinished = $ended;
            } elseif (! $terminal) {
                $remainingMin += max(0, $target['min'] - ($elapsed ?? 0));
                $remainingMax += max(0, $target['max'] - ($elapsed ?? 0));
                $remainingTypical += max(0, $target['typical'] - ($elapsed ?? 0));
            }
            $queueMinutes = null;
            if ($queueAt && ($started || $ended || $now)) {
                $until = $started ?: ($ended ?: $now);
                $queueMinutes = max(0, CarbonImmutable::instance($queueAt)->diffInSeconds(
                    CarbonImmutable::instance($until), false
                ) / 60);
            }

            $stages[] = [
                'key' => $key,
                'label' => $target['label'],
                'min' => $target['min'],
                'max' => $target['max'],
                'typical' => $target['typical'],
                'status' => $status,
                'started_at' => $started?->toIso8601String(),
                'finished_at' => $ended?->toIso8601String(),
                'elapsed_active_min' => $elapsed,
                'queue_minutes' => $queueMinutes,
                'remaining_seconds' => $started && ! $ended
                    ? max(0, ($target['max'] * 60) - (int) round($elapsed * 60))
                    : null,
                'can_start' => ! $started && ! $ended && ! $terminal
                    && $this->canStart($key, $request, $sao?->decision, $approved),
            ];
        }

        $minimum = array_sum(array_map(fn ($key) => self::TARGETS[$key]['min'], $keys));
        $maximum = array_sum(array_map(fn ($key) => self::TARGETS[$key]['max'], $keys));
        $typical = array_sum(array_map(fn ($key) => self::TARGETS[$key]['typical'], $keys));

        $expectedRelease = null;
        $releaseNote = 'Provisional active-processing estimate; pickup time not confirmed.';
        if ($custody?->released_at) {
            $expectedRelease = $custody->released_at->toIso8601String();
            $releaseNote = 'Release recorded (actual timestamp).';
        } elseif ($terminal) {
            $releaseNote = 'Processing stopped; no release forecast.';
        } elseif ($custody?->scheduled_release_at && $custody?->pickup_expires_at
            && ! $custody->pickup_expired_at && $custody->pickup_expires_at->isFuture()) {
            $earliest = $now->addSeconds((int) ceil($remainingTypical * 60));
            $scheduled = CarbonImmutable::instance($custody->scheduled_release_at);
            $expectedRelease = ($scheduled->gt($earliest) ? $scheduled : $earliest)->toIso8601String();
            $releaseNote = 'Provisional: incorporates the recorded pickup window and active-work estimates; additional queue and availability delays may apply.';
        } elseif ($custody) {
            $releaseNote = 'A valid pickup window must be confirmed before a release time can be estimated.';
        }

        return [
            'stages' => $stages,
            'min_total' => $minimum,
            'max_total' => $maximum,
            'typical_total' => $typical,
            'remaining_min' => round($remainingMin, 1),
            'remaining_max' => round($remainingMax, 1),
            'completed_count' => $completed,
            'progress' => count($keys) ? (int) round($completed / count($keys) * 100) : 0,
            'expected_release' => $expectedRelease,
            'release_note' => $releaseNote,
            'requires_sao' => $requiresSao,
        ];
    }

    public function canStart(string $key, BorrowingRequest $request, ?string $saoDecision, bool $approved): bool
    {
        $custody = $request->custody;
        return match ($key) {
            'SAO_VERIFICATION' => (bool) $request->currentVersion?->off_campus
                && $request->status->value === 'UNDER_SPMU',
            'HEAD_APPROVAL' => $request->status->value === 'UNDER_SPMU'
                && (! $request->currentVersion?->off_campus || $saoDecision === 'VERIFIED'),
            'EQUIPMENT_PREPARATION' => $approved && (bool) $custody
                && ! $custody->prepared_at && ! $custody->released_at,
            'RELEASE_RECORDING' => (bool) $custody?->prepared_at && ! $custody->released_at
                && (bool) $custody->scheduled_release_at
                && (bool) $custody->pickup_expires_at
                && ! $custody->pickup_expired_at
                && now()->betweenIncluded($custody->scheduled_release_at, $custody->pickup_expires_at),
            default => false,
        };
    }
}
