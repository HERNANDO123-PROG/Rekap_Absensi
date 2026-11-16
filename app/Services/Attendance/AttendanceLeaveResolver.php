<?php

namespace App\Services\Attendance;

use App\Models\LeaveRequest;
use Illuminate\Support\Collection;

class AttendanceLeaveResolver
{
    public function resolve(Collection $requests): array
    {
        $requests = $requests
            ->unique(fn (LeaveRequest $request): string => serialize([
                (int) $request->unit_id,
                (int) $request->employee_id,
                $request->request_date?->format('Y-m-d'),
                $request->source,
                $request->leave_type?->value,
                $request->request_time === null ? null : substr((string) $request->request_time, 0, 8),
                trim((string) $request->description),
                trim((string) $request->attachment),
                $request->absence_reason?->value,
                $request->approval_status?->value,
                $request->matching_status?->value,
            ]))
            ->sortBy('id')
            ->values();

        return [
            'requests' => $requests,
            'count' => $requests->count(),
            'primary' => $requests->count() === 1 ? $requests->first() : null,
            'has_conflict' => $requests->count() > 1,
        ];
    }
}
