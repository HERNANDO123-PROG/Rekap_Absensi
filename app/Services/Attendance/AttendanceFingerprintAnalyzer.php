<?php

namespace App\Services\Attendance;

use Illuminate\Support\Collection;

class AttendanceFingerprintAnalyzer
{
    public function analyze(Collection $logs): array
    {
        $rawEvents = $logs->map(static fn ($log): array => [
            'time' => substr((string) $log->attendance_time, 0, 5),
            'id' => (int) $log->id,
        ])->sort(static fn (array $left, array $right): int => [$left['time'], $left['id']] <=> [$right['time'], $right['id']]
        )->values();
        $counts = [];

        foreach ($rawEvents as $event) {
            $counts[$event['time']] = ($counts[$event['time']] ?? 0) + 1;
        }

        $times = array_keys($counts);
        sort($times, SORT_STRING);
        $duplicates = [];

        foreach ($counts as $time => $count) {
            if ($count > 1) {
                $duplicates[] = ['time' => $time, 'count' => $count];
            }
        }

        return [
            'raw_count' => $logs->count(),
            'raw_events' => $rawEvents->all(),
            'times' => $times,
            'distinct_count' => count($times),
            'duplicates' => $duplicates,
            'actual_in' => $times[0] ?? null,
            'actual_out' => count($times) >= 2 ? $times[array_key_last($times)] : null,
            'possible_exit_reentry' => count($times) > 2,
        ];
    }
}
