<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkScheduleRequest;
use App\Models\WorkSchedule;
use App\Services\WorkScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkScheduleController extends Controller
{
    public function __construct(private readonly WorkScheduleService $schedules) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->schedules->list($request->only('unit_id'))]);
    }

    public function store(WorkScheduleRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $schedule = $this->schedules->create($attributes, $request->user()?->id);

        return response()->json([
            'data' => $schedule,
            'affected' => [[
                'unit_id' => $schedule->unit_id,
                'day_of_week' => $schedule->day_of_week,
            ]],
        ], 201);
    }

    public function update(WorkScheduleRequest $request, WorkSchedule $schedule): JsonResponse
    {
        if ((int) $request->validated('unit_id') !== (int) $schedule->unit_id) {
            abort(403, 'Schedule does not belong to the selected unit.');
        }

        $previous = [
            'unit_id' => $schedule->unit_id,
            'day_of_week' => $schedule->day_of_week,
        ];
        $updated = $this->schedules->update($schedule, $request->validated(), $request->user()?->id);
        $affected = [$previous, [
            'unit_id' => $updated->unit_id,
            'day_of_week' => $updated->day_of_week,
        ]];

        return response()->json([
            'data' => $updated,
            'affected' => array_values(array_unique($affected, SORT_REGULAR)),
            'attendance_reprocessing_recommended' => true,
        ]);
    }

    public function destroy(Request $request, WorkSchedule $schedule): JsonResponse
    {
        if ($request->filled('unit_id') && (int) $request->input('unit_id') !== (int) $schedule->unit_id) {
            abort(403, 'Schedule does not belong to the selected unit.');
        }

        return response()->json(['data' => $this->schedules->delete($schedule, $request->user()?->id)]);
    }
}
