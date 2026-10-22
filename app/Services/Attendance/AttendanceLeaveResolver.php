<?php

namespace App\Services\Attendance;

use Illuminate\Support\Collection;

class AttendanceLeaveResolver
{
    public function resolve(Collection $requests): array
    {
        $requests = $requests->sortBy('id')->values();

        return [
            'requests' => $requests,
            'count' => $requests->count(),
            'primary' => $requests->count() === 1 ? $requests->first() : null,
            'has_conflict' => $requests->count() > 1,
        ];
    }
}
