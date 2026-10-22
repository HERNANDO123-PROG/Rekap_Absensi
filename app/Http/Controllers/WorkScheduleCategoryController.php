<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkScheduleCategoryRequest;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Services\WorkScheduleCategoryService;
use App\Services\WorkScheduleProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkScheduleCategoryController extends Controller
{
    public function __construct(private readonly WorkScheduleCategoryService $categories) {}

    public function storeProfile(
        Request $request,
        WorkScheduleCategory $category,
        WorkScheduleProfileService $profiles,
    ): JsonResponse
    {
        $profile = $profiles->saveForCategory(
            $category,
            $request->only(['name', 'is_normal', 'rules', 'start_time', 'end_time']),
            null,
            $request->user()?->id,
        );

        return response()->json(['data' => $profile], 201);
    }

    public function updateProfile(
        Request $request,
        WorkScheduleCategory $category,
        WorkScheduleProfile $profile,
        WorkScheduleProfileService $profiles,
    ): JsonResponse {
        abort_unless((int) $profile->work_schedule_category_id === (int) $category->id, 404);

        return response()->json([
            'data' => $profiles->saveForCategory(
                $category,
                $request->only(['name', 'is_normal', 'rules', 'start_time', 'end_time']),
                $profile,
                $request->user()?->id,
            ),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->categories->list()]);
    }

    public function store(WorkScheduleCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->validated(), $request->user()?->id);

        return response()->json(['data' => $category], 201);
    }

    public function update(WorkScheduleCategoryRequest $request, WorkScheduleCategory $category): JsonResponse
    {
        return response()->json([
            'data' => $this->categories->update($category, $request->validated(), $request->user()?->id),
        ]);
    }

    public function activate(Request $request, WorkScheduleCategory $category): JsonResponse
    {
        return response()->json([
            'data' => $this->categories->activate($category, $request->user()?->id),
        ]);
    }

    public function deactivate(Request $request, WorkScheduleCategory $category): JsonResponse
    {
        return response()->json([
            'data' => $this->categories->deactivate($category, $request->user()?->id),
        ]);
    }
}