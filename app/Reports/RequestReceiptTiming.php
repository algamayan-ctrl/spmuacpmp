<?php

namespace App\Reports;

use Carbon\CarbonInterface;

/** Receipt is submission of the current request version; release is physical custody handover. */
final class RequestReceiptTiming
{
    public static function fields(?CarbonInterface $received, ?CarbonInterface $released): array
    {
        $seconds = $received && $released && $released->greaterThanOrEqualTo($received)
            ? (int) $received->diffInSeconds($released)
            : null;
        $received = $received?->copy()->timezone(config('app.timezone'));
        $released = $released?->copy()->timezone(config('app.timezone'));

        return [
            'received_date' => $received?->format('d/m/Y') ?? '-',
            'received_time' => $received?->format('g:i A') ?? '-',
            'released_date' => $released?->format('d/m/Y') ?? '-',
            'released_time' => $released?->format('g:i A') ?? '-',
            'total_time_allocated' => $seconds === null ? '-' : self::elapsed($seconds),
            '_sort_total_time_allocated' => $seconds,
            '_sort_received_time' => $received?->format('H:i:s'),
            '_sort_released_time' => $released?->format('H:i:s'),
        ];
    }

    private static function elapsed(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' sec';
        }

        $minutes = intdiv($seconds, 60);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $minutes %= 60;

        return implode(' ', array_filter([
            $days ? $days.' d' : null,
            $hours ? $hours.' h' : null,
            $minutes ? $minutes.' min' : null,
        ]));
    }
}
