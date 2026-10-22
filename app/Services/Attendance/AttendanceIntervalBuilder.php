<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

class AttendanceIntervalBuilder
{
    public const MIN_EXIT_INTERVAL_MINUTES = 15;

    public function build(array $times, string $date): array
    {
        $count = count($times);

        if ($count < 2) {
            return [
                'actual_in' => $times[0] ?? null,
                'actual_out' => null,
                'intervals' => [],
                'odd_count' => true,
            ];
        }

        if ($count === 2) {
            return [
                'actual_in' => null,
                'actual_out' => null,
                'intervals' => [$this->interval($times[0], $times[1], $date)],
                'odd_count' => false,
            ];
        }

        $intervals = [];

        for ($index = 1; $index + 1 < $count; $index += 2) {
            $intervals[] = $this->interval($times[$index], $times[$index + 1], $date);
        }

        return [
            'actual_in' => $times[0],
            'actual_out' => $count % 2 === 0 ? $times[$count - 1] : null,
            'intervals' => $intervals,
            'odd_count' => $count % 2 !== 0,
        ];
    }

    private function interval(string $start, string $end, string $date): array
    {
        $startAt = CarbonImmutable::parse($date.' '.$start);
        $endAt = CarbonImmutable::parse($date.' '.$end);

        if ($endAt->lessThan($startAt)) {
            $endAt = $endAt->addDay();
        }

        $duration = (int) floor(($endAt->getTimestamp() - $startAt->getTimestamp()) / 60);

        return [
            'type' => 'izin_keluar',
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => $duration,
            'source' => 'fingerprint+leave_request',
            'notes' => $duration > self::MIN_EXIT_INTERVAL_MINUTES
                ? 'Interval keluar dan kembali didukung timestamp fingerprint.'
                : null,
        ];
    }
}
