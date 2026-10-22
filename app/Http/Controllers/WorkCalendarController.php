<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkWorkCalendarRequest;
use App\Http\Requests\WorkCalendarRequest;
use App\Models\WorkCalendar;
use App\Services\WorkCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkCalendarController extends Controller
{
    public function __construct(private readonly WorkCalendarService $calendars) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->calendars->list($request->only('unit_id', 'date_from', 'date_to', 'type'))]);
    }

    public function store(WorkCalendarRequest $request): JsonResponse
    {
        $calendar = $this->calendars->create($request->validated(), $request->user()?->id);

        return response()->json([
            'data' => $calendar,
            'affected_unit_id' => $calendar->unit_id,
            'affected_dates' => [$calendar->date->format('Y-m-d')],
        ], 201);
    }

    public function update(WorkCalendarRequest $request, WorkCalendar $calendar): JsonResponse
    {
        if ((int) $request->validated('unit_id') !== (int) $calendar->unit_id) {
            abort(403, 'Calendar does not belong to the selected unit.');
        }

        $previous = [
            'unit_id' => $calendar->unit_id,
            'date' => $calendar->date->format('Y-m-d'),
        ];
        $updated = $this->calendars->update($calendar, $request->validated(), $request->user()?->id);
        $affectedDates = array_values(array_unique([$previous['date'], $updated->date->format('Y-m-d')]));
        $affectedUnits = array_values(array_unique([$previous['unit_id'], $updated->unit_id]));

        return response()->json([
            'data' => $updated,
            'affected_unit_ids' => $affectedUnits,
            'affected_dates' => $affectedDates,
            'attendance_reprocessing_recommended' => true,
        ]);
    }

    public function destroy(Request $request, WorkCalendar $calendar): JsonResponse
    {
        if ($request->filled('unit_id') && (int) $request->input('unit_id') !== (int) $calendar->unit_id) {
            abort(403, 'Calendar does not belong to the selected unit.');
        }

        return response()->json(['data' => $this->calendars->delete($calendar, $request->user()?->id)]);
    }

    public function upsert(WorkCalendarRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->calendars->upsert($request->validated(), $request->user()?->id)]);
    }

    public function bulkUpsert(BulkWorkCalendarRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->calendars->bulkUpsert($request->validated(), $request->user()?->id)]);
    }
}
