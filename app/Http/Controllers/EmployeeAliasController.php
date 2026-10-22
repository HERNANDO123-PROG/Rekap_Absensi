<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateEmployeeAliasRequest;
use App\Http\Requests\UpdateEmployeeAliasRequest;
use App\Models\Employee;
use App\Models\EmployeeAlias;
use App\Services\EmployeeAliasService;
use Illuminate\Http\JsonResponse;

class EmployeeAliasController extends Controller
{
    public function __construct(private readonly EmployeeAliasService $aliases) {}

    public function store(CreateEmployeeAliasRequest $request, Employee $employee): JsonResponse
    {
        $alias = $this->aliases->create($employee, $request->validated('alias_name'));

        return response()->json(['data' => $alias], 201);
    }

    public function update(
        UpdateEmployeeAliasRequest $request,
        Employee $employee,
        EmployeeAlias $alias,
    ): JsonResponse {
        abort_unless($alias->employee_id === $employee->id, 404);

        return response()->json(['data' => $this->aliases->update($alias, $request->validated('alias_name'))]);
    }

    public function destroy(Employee $employee, EmployeeAlias $alias): JsonResponse
    {
        abort_unless($alias->employee_id === $employee->id, 404);
        $this->aliases->delete($alias);

        return response()->json(status: 204);
    }
}
