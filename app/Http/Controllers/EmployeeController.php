<?php

namespace App\Http\Controllers;

use App\Enums\EmployeeStatus;
use App\Http\Requests\CreateEmployeeRequest;
use App\Http\Requests\SearchEmployeesRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\WorkScheduleCategory;
use App\Services\EmployeeMassProfileAssignmentService;
use App\Services\EmployeeScheduleProfileValidator;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function manage(
        Request $request,
        EmployeeScheduleProfileValidator $scheduleProfileValidator,
        EmployeeMassProfileAssignmentService $massProfileAssignments,
    ): View {
        $request->validate([
            'status' => ['nullable', Rule::in(array_column(EmployeeStatus::cases(), 'value'))],
        ]);

        $employees = Employee::query()
            ->with(['unit', 'category.workScheduleProfile', 'category.profiles', 'workScheduleProfile', 'aliases'])
            ->when($request->filled('unit_id'), fn ($query) => $query->where('unit_id', $request->query('unit_id')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->query('category_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->query('search'));
                $query->where(function ($query) use ($search): void {
                    $query->where('namafinger', 'like', '%'.$search.'%')
                        ->orWhere('nama_lengkap', 'like', '%'.$search.'%')
                        ->orWhere('nip', 'like', '%'.$search.'%')
                        ->orWhere('fingerprint_id', $search);
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();
        $categories = WorkScheduleCategory::query()
            ->with(['workScheduleProfile', 'profiles.rules'])
            ->withCount([
                'employees as active_employees_count' => fn ($query) => $query
                    ->where('status', EmployeeStatus::Active->value),
            ])
            ->orderBy('name')
            ->get();

        return view('livewire.employees.manage', [
            'employees' => $employees,
            'units' => Unit::query()->orderBy('name')->get(),
            'categories' => $categories,
            'massProfileReadiness' => $massProfileAssignments->readiness(),
            'massProfileActionsEnabled' => $massProfileAssignments->isEnabled(),
            'selectableProfiles' => $categories->mapWithKeys(
                fn (WorkScheduleCategory $category) => [
                    $category->id => $category->profiles
                        ->filter(fn ($profile): bool => $scheduleProfileValidator->isSelectable($profile, (int) $category->id))
                        ->values(),
                ],
            ),
        ]);
    }

    public function previewMassProfile(
        string $target,
        EmployeeMassProfileAssignmentService $massProfileAssignments,
    ): JsonResponse {
        return response()->json([
            'data' => $massProfileAssignments->preview($target),
        ]);
    }

    public function applyMassProfile(
        Request $request,
        string $target,
        EmployeeMassProfileAssignmentService $massProfileAssignments,
    ): JsonResponse {
        $validated = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'confirmation_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        $result = $massProfileAssignments->apply(
            $target,
            $validated['confirmation_fingerprint'],
            $request->user()?->getAuthIdentifier(),
        );

        return response()->json([
            'message' => sprintf(
                '%d penugasan profil pegawai berhasil diperbarui.',
                $result['updated_count'],
            ),
            'data' => $result,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->employees->filter($request->only([
            'unit_id',
            'status',
            'name',
            'namafinger',
            'nama_lengkap',
            'fingerprint_id',
            'position',
            'search',
            'per_page',
        ])));
    }

    public function search(SearchEmployeesRequest $request): JsonResponse
    {
        $employees = $this->employees->searchForSelection(
            (int) $request->validated('unit_id'),
            $request->validated('search'),
        );

        return response()->json([
            'data' => $employees->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'name' => $employee->displayName(),
                'nip' => $employee->nip,
            ])->values(),
        ]);
    }

    public function store(CreateEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employees->create($request->validated());

        return response()->json(['data' => $employee->load(['unit', 'category'])], 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $employee->load(['unit', 'category', 'aliases'])]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->employees->updateProfile($employee, $request->validated());

        return response()->json([
            'message' => 'Data profil pegawai berhasil disimpan.',
            'data' => $employee->load(['unit', 'category']),
        ]);
    }

    public function activate(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $this->employees->activate($employee)]);
    }

    public function deactivate(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $this->employees->deactivate($employee)]);
    }
}
