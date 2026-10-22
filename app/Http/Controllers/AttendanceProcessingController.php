<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcessAttendanceRequest;
use App\Services\Attendance\AttendanceProcessingService;
use Illuminate\Http\JsonResponse;

class AttendanceProcessingController extends Controller
{
    public function __construct(private readonly AttendanceProcessingService $attendance) {}

    public function store(ProcessAttendanceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $summary = $this->attendance->processRange(
            (int) $validated['unit_id'],
            $validated['date_from'],
            $validated['date_to'],
            isset($validated['employee_id']) ? (int) $validated['employee_id'] : null,
            $request->user()?->id,
        );

        return response()->json(['data' => $summary]);
    }
}
