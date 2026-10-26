<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkSchedulePeriodRequest;
use App\Models\WorkSchedulePeriod;
use App\Services\WorkSchedulePeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkSchedulePeriodController extends Controller
{
    public function __construct(private readonly WorkSchedulePeriodService $periods) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->periods->list()]);
    }

    public function store(WorkSchedulePeriodRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->periods->create($request->validated(), $request->user()?->id),
            'attendance_reprocessing_recommended' => true,
        ], 201);
    }

    public function update(WorkSchedulePeriodRequest $request, WorkSchedulePeriod $period): JsonResponse
    {
        return response()->json([
            'data' => $this->periods->update($period, $request->validated(), $request->user()?->id),
            'attendance_reprocessing_recommended' => true,
        ]);
    }

    public function destroy(Request $request, WorkSchedulePeriod $period): JsonResponse
    {
        return response()->json(['data' => $this->periods->delete($period, $request->user()?->id)]);
    }
}
