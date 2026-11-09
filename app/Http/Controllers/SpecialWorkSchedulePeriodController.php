<?php

namespace App\Http\Controllers;

use App\Http\Requests\SpecialWorkSchedulePeriodRequest;
use App\Models\SpecialWorkSchedulePeriod;
use App\Services\SpecialWorkSchedulePeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpecialWorkSchedulePeriodController extends Controller
{
    public function __construct(private readonly SpecialWorkSchedulePeriodService $periods) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->periods->list()]);
    }

    public function store(SpecialWorkSchedulePeriodRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->periods->create($request->validated()),
        ], 201);
    }

    public function update(
        SpecialWorkSchedulePeriodRequest $request,
        SpecialWorkSchedulePeriod $period,
    ): JsonResponse {
        return response()->json([
            'data' => $this->periods->update($period, $request->validated()),
        ]);
    }

    public function destroy(Request $request, SpecialWorkSchedulePeriod $period): JsonResponse
    {
        return response()->json(['data' => $this->periods->delete($period)]);
    }
}
