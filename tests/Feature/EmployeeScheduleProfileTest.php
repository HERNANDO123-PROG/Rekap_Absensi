<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeAlias;
use App\Models\Unit;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfileRule;
use App\Services\EmployeeScheduleProfileValidator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmployeeScheduleProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_without_explicit_profile_keeps_category_and_historical_relations(): void
    {
        $unit = $this->createUnit();
        $category = $this->createCategory('Staff');
        $profile = $this->createProfile($category, 'Normal');
        $category->update(['work_schedule_profile_id' => $profile->id]);
        $employee = Employee::create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'nama_lengkap' => 'Existing employee',
            'status' => EmployeeStatus::Active,
        ]);
        $alias = EmployeeAlias::create([
            'employee_id' => $employee->id,
            'alias_name' => 'Existing alias',
            'normalized_alias' => 'existing alias',
        ]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'attendance_date' => '2026-10-01',
            'status' => AttendanceStatus::Present->value,
        ]);

        $employeeData = DB::table('employees')->where('id', $employee->id)->first();
        $this->assertNotNull($employeeData);
        $this->assertSame($category->id, (int) $employeeData->category_id);
        $this->assertNull($employeeData->work_schedule_profile_id);
        $this->assertDatabaseHas('employee_aliases', [
            'id' => $alias->id,
            'employee_id' => $employee->id,
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-10-01',
        ]);

        $profileAfterMigration = WorkScheduleProfile::query()->findOrFail($profile->id);
        $this->assertDatabaseHas('work_schedule_categories', [
            'id' => $category->id,
            'work_schedule_profile_id' => $profile->id,
        ]);
        $this->assertTrue($profileAfterMigration->workScheduleCategory->is($category));
    }

    public function test_employee_profile_relation_is_optional_and_foreign_key_restricts_invalid_references_and_deletion(): void
    {
        $unit = $this->createUnit();
        $category = $this->createCategory('Staff');
        $normal = $this->createProfile($category, 'Normal');
        $semester = $this->createProfile($category, 'Liburan Semester');
        $category->update(['work_schedule_profile_id' => $normal->id]);

        $employeeA = Employee::create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'nama_lengkap' => 'Employee A',
            'status' => EmployeeStatus::Active,
        ]);
        $employeeB = Employee::create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'work_schedule_profile_id' => $semester->id,
            'nama_lengkap' => 'Employee B',
            'status' => EmployeeStatus::Active,
        ]);

        $this->assertTrue(Schema::hasColumn('employees', 'work_schedule_profile_id'));
        $profileColumn = collect(Schema::getColumns('employees'))
            ->firstWhere('name', 'work_schedule_profile_id');
        $this->assertTrue($profileColumn['nullable']);
        $this->assertNull($employeeA->fresh()->work_schedule_profile_id);
        $this->assertTrue($employeeB->fresh()->workScheduleProfile->is($semester));
        $this->assertTrue($employeeB->fresh()->category->is($category));
        $this->assertSame($normal->id, $category->fresh()->work_schedule_profile_id);

        try {
            Employee::create([
                'unit_id' => $unit->id,
                'category_id' => $category->id,
                'work_schedule_profile_id' => $semester->id + 1000,
                'nama_lengkap' => 'Invalid profile reference',
                'status' => EmployeeStatus::Active,
            ]);
            $this->fail('A nonexistent profile must be rejected by the database foreign key.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('employees', ['nama_lengkap' => 'Invalid profile reference']);
        }

        try {
            $semester->delete();
            $this->fail('A profile used by an employee must not be deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('employees', [
                'id' => $employeeB->id,
                'work_schedule_profile_id' => $semester->id,
            ]);
        }

        $migration = require database_path('migrations/2026_10_22_000002_add_work_schedule_profile_id_to_employees_table.php');
        try {
            $migration->down();
            $this->fail('Rolling back must not discard assigned employee profile values.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot remove employee work schedule profile assignments', $exception->getMessage());
            $this->assertDatabaseHas('employees', [
                'id' => $employeeB->id,
                'work_schedule_profile_id' => $semester->id,
            ]);
        }
    }

    public function test_schedule_profile_validator_requires_active_matching_category_and_profile(): void
    {
        $category = $this->createCategory('Staff');
        $otherCategory = $this->createCategory('BTQ/Madin');
        $profile = $this->createProfile($category, 'Normal');
        $validator = app(EmployeeScheduleProfileValidator::class);

        $validator->validate(null, null);
        $validator->validate($category->id, $profile->id);

        try {
            $validator->validate($otherCategory->id, $profile->id);
            $this->fail('A profile owned by another category must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_schedule_profile_id', $exception->errors());
        }

        try {
            $validator->validate(null, $profile->id);
            $this->fail('A profile assignment without a category must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category_id', $exception->errors());
        }

        $inactiveCategory = $this->createCategory('Inactive category', false);
        try {
            $validator->validate($inactiveCategory->id, $this->createProfile($inactiveCategory, 'Inactive owner profile')->id);
            $this->fail('An inactive category must not receive a new profile assignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category_id', $exception->errors());
        }

        $inactiveProfile = $this->createProfile($category, 'Inactive profile', false);
        try {
            $validator->validate($category->id, $inactiveProfile->id);
            $this->fail('An inactive profile must not be newly assigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_schedule_profile_id', $exception->errors());
        }

        $incompleteProfile = WorkScheduleProfile::create([
            'work_schedule_category_id' => $category->id,
            'name' => 'Incomplete profile',
            'start_time' => null,
            'end_time' => null,
            'is_active' => true,
        ]);
        try {
            $validator->validate($category->id, $incompleteProfile->id);
            $this->fail('A profile without all valid weekly rules must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_schedule_profile_id', $exception->errors());
        }

        try {
            $validator->validate($category->id, $profile->id + 1000);
            $this->fail('A nonexistent profile must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_schedule_profile_id', $exception->errors());
        }
    }

    private function createUnit(): Unit
    {
        return Unit::create(['name' => 'SD', 'code' => 'SD']);
    }

    private function createCategory(string $name, bool $active = true): WorkScheduleCategory
    {
        return WorkScheduleCategory::create([
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    private function createProfile(
        WorkScheduleCategory $category,
        string $name,
        bool $active = true,
    ): WorkScheduleProfile {
        $profile = WorkScheduleProfile::create([
            'work_schedule_category_id' => $category->id,
            'name' => $name,
            'start_time' => '07:00',
            'end_time' => '15:00',
            'is_active' => $active,
        ]);

        for ($day = 0; $day <= 6; $day++) {
            WorkScheduleProfileRule::create([
                'work_schedule_profile_id' => $profile->id,
                'day_of_week' => $day,
                'is_working_day' => $day !== 0,
                'start_time' => $day === 0 ? null : '07:00',
                'end_time' => $day === 0 ? null : '15:00',
            ]);
        }

        return $profile;
    }
}
