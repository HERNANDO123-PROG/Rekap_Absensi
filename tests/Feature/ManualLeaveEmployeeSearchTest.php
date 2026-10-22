<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualLeaveEmployeeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_search_active_employees_by_name_or_nip_in_selected_unit(): void
    {
        $unit = Unit::create(['name' => 'Sekolah Dasar', 'code' => 'SD']);
        $otherUnit = Unit::create(['name' => 'Taman Kanak-kanak', 'code' => 'TK']);
        $employee = $this->createEmployee($unit, 'Siti Aminah', '19870011');
        $this->createEmployee($otherUnit, 'Siti Aminah Unit Lain', '19870022');
        $this->createEmployee($unit, 'Pegawai Nonaktif', '19870033', EmployeeStatus::Inactive);

        $this->actingAs(User::factory()->create())
            ->getJson(route('employees.search', ['unit_id' => $unit->id, 'search' => 'Siti']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $employee->id)
            ->assertJsonPath('data.0.name', 'Siti Aminah')
            ->assertJsonPath('data.0.nip', '19870011')
            ->assertExactJson([
                'data' => [[
                    'id' => $employee->id,
                    'name' => 'Siti Aminah',
                    'nip' => '19870011',
                ]],
            ]);

        $this->getJson(route('employees.search', ['unit_id' => $unit->id, 'search' => '19870011']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $employee->id);
    }

    public function test_employee_search_returns_empty_results_for_unknown_employee_or_wrong_unit(): void
    {
        $unit = Unit::create(['name' => 'Sekolah Dasar', 'code' => 'SD']);
        $otherUnit = Unit::create(['name' => 'Taman Kanak-kanak', 'code' => 'TK']);
        $this->createEmployee($unit, 'Siti Aminah', '19870011');

        $this->actingAs(User::factory()->create())
            ->getJson(route('employees.search', ['unit_id' => $unit->id, 'search' => 'Tidak Ada']))
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->getJson(route('employees.search', ['unit_id' => $otherUnit->id, 'search' => 'Siti']))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_employee_search_requires_valid_unit_and_minimum_search_term(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('employees.search', ['unit_id' => 999999, 'search' => 'S']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_id', 'search']);
    }

    public function test_employee_search_requires_authentication(): void
    {
        $this->getJson(route('employees.search', ['unit_id' => 1, 'search' => 'Siti']))
            ->assertUnauthorized();
    }

    private function createEmployee(Unit $unit, string $name, string $nip, EmployeeStatus $status = EmployeeStatus::Active): Employee
    {
        return Employee::query()->create([
            'unit_id' => $unit->id,
            'nama_lengkap' => $name,
            'nip' => $nip,
            'status' => $status,
        ]);
    }
}
