<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\MatchingStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfileRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeMassProfileAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => null]);
    }

    public function test_mass_assignment_endpoints_require_authentication_and_approved_scope(): void
    {
        $category = $this->createCategory('Staff');
        $profile = $this->createProfile($category, 'Normal');
        $employee = $this->createEmployee($category);

        $this->postJson(route('employees.mass-profile.preview', 'normal'))->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->postJson(route('employees.mass-profile.preview', 'normal'))
            ->assertStatus(423)
            ->assertJsonPath('message', 'Penerapan massal dikunci sampai kebijakan cakupannya disetujui secara eksplisit.');

        $this->actingAs(User::factory()->create())
            ->postJson(route('employees.mass-profile.apply', 'normal'), [
                'confirmed' => true,
                'confirmation_fingerprint' => str_repeat('a', 64),
            ])
            ->assertStatus(423);

        $this->assertNull($employee->fresh()->work_schedule_profile_id);
        $this->assertDatabaseHas('work_schedule_profiles', ['id' => $profile->id]);
    }

    public function test_targets_are_distinct_and_assignment_uses_backend_scope_not_list_filters(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'active_categories_only']);
        $this->actingAs(User::factory()->create());
        $category = $this->createCategory('Staff');
        $inactiveCategory = $this->createCategory('Retired Category', false);
        $normal = $this->createProfile($category, 'Normal');
        $semester = $this->createProfile($category, 'Liburan Semester');
        $inactiveCategoryProfile = $this->createProfile($inactiveCategory, 'Normal');
        $first = $this->createEmployee($category);
        $second = $this->createEmployee($category);
        $inactive = $this->createEmployee($category, status: EmployeeStatus::Inactive);
        $excluded = $this->createEmployee($inactiveCategory);
        $record = AttendanceRecord::create([
            'employee_id' => $first->id,
            'unit_id' => $first->unit_id,
            'attendance_date' => '2026-09-01',
            'status' => AttendanceStatus::Present->value,
        ]);
        $batch = FingerprintImportBatch::create([
            'unit_id' => $first->unit_id,
            'filename' => 'existing.xls',
            'file_hash' => str_repeat('b', 64),
            'status' => 'completed',
        ]);
        $log = FingerprintLog::create([
            'import_batch_id' => $batch->id,
            'unit_id' => $first->unit_id,
            'employee_id' => $first->id,
            'fingerprint_id' => 'FP-1',
            'attendance_date' => '2026-09-01',
            'attendance_time' => '07:00:00',
            'raw_value' => '07:00',
            'matching_status' => MatchingStatus::Matched->value,
        ]);

        $normalPlan = $this->postJson(route('employees.mass-profile.preview', 'normal'), [
            'search' => 'not a real employee',
            'status' => 'inactive',
            'page' => 99,
        ])->assertOk()
            ->assertJsonPath('data.target', 'Normal')
            ->assertJsonPath('data.eligible_employee_count', 2)
            ->assertJsonPath('data.replacement_count', 0)
            ->assertJsonPath('data.new_assignment_count', 2)
            ->assertJsonPath('data.included_categories.0.profile_id', $normal->id)
            ->assertJsonPath('data.excluded_categories.0.name', 'Retired Category')
            ->assertJsonMissingPath('data._assignments')
            ->json('data.confirmation_fingerprint');

        $this->postJson(route('employees.mass-profile.apply', 'normal'), [
            'confirmed' => true,
            'confirmation_fingerprint' => $normalPlan,
        ])->assertOk()
            ->assertJsonPath('data.target', 'Normal')
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.replaced_assignment_count', 0)
            ->assertJsonPath('data.new_assignment_count', 2);

        $semesterPlan = $this->postJson(route('employees.mass-profile.preview', 'liburan-semester'))
            ->assertOk()
            ->assertJsonPath('data.target', 'Liburan Semester')
            ->assertJsonPath('data.included_categories.0.profile_id', $semester->id)
            ->json('data.confirmation_fingerprint');

        $this->postJson(route('employees.mass-profile.apply', 'liburan-semester'), [
            'confirmed' => true,
            'confirmation_fingerprint' => $semesterPlan,
        ])->assertOk()->assertJsonPath('data.target', 'Liburan Semester');

        $this->assertSame($semester->id, $first->fresh()->work_schedule_profile_id);
        $this->assertSame($semester->id, $second->fresh()->work_schedule_profile_id);
        $this->assertNull($inactive->fresh()->work_schedule_profile_id);
        $this->assertNull($excluded->fresh()->work_schedule_profile_id);
        $this->assertSame($inactiveCategoryProfile->id, $inactiveCategoryProfile->fresh()->id);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'status' => AttendanceStatus::Present->value,
        ]);
        $this->assertDatabaseHas('fingerprint_logs', ['id' => $log->id, 'raw_value' => '07:00']);
    }

    public function test_foreign_inactive_and_invalid_target_profiles_block_execution(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'active_categories_only']);
        $this->actingAs(User::factory()->create());
        $category = $this->createCategory('Target Category');
        $foreignCategory = $this->createCategory('Other Category');
        $categoryWithoutOwnedTarget = $this->createCategory('No Owned Target');
        $categoryWithoutEmployees = $this->createCategory('No Employees');
        $this->createProfile($foreignCategory, 'Normal');
        $inactive = $this->createProfile($category, 'Normal', false);
        $employee = $this->createEmployee($category);
        $foreignEmployee = $this->createEmployee($categoryWithoutOwnedTarget);

        $plan = $this->postJson(route('employees.mass-profile.preview', 'normal'))
            ->assertOk()
            ->assertJsonPath('data.blocking_conditions.0.category', 'Target Category')
            ->assertJsonPath('data.blocking_conditions.0.reason', 'Profil target nonaktif.')
            ->assertJsonPath('data.blocking_conditions.1.category', 'No Owned Target')
            ->assertJsonPath('data.blocking_conditions.1.reason', 'Profil target belum tersedia pada kategori ini.')
            ->assertJsonPath('data.blocking_conditions.2.category', 'No Employees')
            ->assertJsonPath('data.blocking_conditions.2.reason', 'Profil target belum tersedia pada kategori ini.')
            ->json('data.confirmation_fingerprint');

        $this->postJson(route('employees.mass-profile.apply', 'normal'), [
            'confirmed' => true,
            'confirmation_fingerprint' => $plan,
        ])->assertUnprocessable()->assertJsonValidationErrors('target');

        $inactive->update(['is_active' => true]);
        $inactive->rules()->where('day_of_week', 1)->delete();
        $invalidPlan = $this->postJson(route('employees.mass-profile.preview', 'normal'))
            ->assertOk()
            ->assertJsonPath('data.blocking_conditions.0.reason', 'Aturan mingguan profil target tidak valid.')
            ->json('data.confirmation_fingerprint');

        $this->postJson(route('employees.mass-profile.apply', 'normal'), [
            'confirmed' => true,
            'confirmation_fingerprint' => $invalidPlan,
        ])->assertUnprocessable();

        $this->assertNull($employee->fresh()->work_schedule_profile_id);
        $this->assertNull($foreignEmployee->fresh()->work_schedule_profile_id);
    }

    public function test_active_categories_without_employees_block_only_the_unready_target_and_inactive_categories_are_excluded(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'active_categories_only']);
        $this->actingAs(User::factory()->create());
        $readyCategory = $this->createCategory('Ready Category');
        $unreadyCategory = $this->createCategory('Unready Category');
        $inactiveCategory = $this->createCategory('Inactive Category', false);
        $normal = $this->createProfile($readyCategory, 'Normal');
        $semester = $this->createProfile($readyCategory, 'Liburan Semester');
        $this->createEmployee($readyCategory);
        $inactiveEmployee = $this->createEmployee($inactiveCategory);

        $normalPlan = $this->postJson(route('employees.mass-profile.preview', 'normal'))
            ->assertOk()
            ->assertJsonPath('data.blocking_conditions.0.category', 'Unready Category')
            ->assertJsonPath('data.blocking_conditions.0.reason', 'Profil target belum tersedia pada kategori ini.')
            ->assertJsonPath('data.excluded_categories.0.name', 'Inactive Category')
            ->json('data.confirmation_fingerprint');

        $this->postJson(route('employees.mass-profile.apply', 'normal'), [
            'confirmed' => true,
            'confirmation_fingerprint' => $normalPlan,
        ])->assertUnprocessable()->assertJsonValidationErrors('target');

        $this->postJson(route('employees.mass-profile.preview', 'liburan-semester'))
            ->assertOk()
            ->assertJsonPath('data.blocking_conditions.0.category', 'Unready Category');

        $this->assertNull($inactiveEmployee->fresh()->work_schedule_profile_id);
        $this->assertDatabaseHas('work_schedule_profiles', ['id' => $normal->id]);
        $this->assertDatabaseHas('work_schedule_profiles', ['id' => $semester->id]);
    }

    public function test_backend_readiness_and_application_are_independent_for_each_target(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'active_categories_only']);
        $this->actingAs(User::factory()->create());
        $category = $this->createCategory('Single Target Category');
        $inactiveCategory = $this->createCategory('Inactive Category', false);
        $normal = $this->createProfile($category, 'Normal');
        $employee = $this->createEmployee($category);
        $inactiveEmployee = $this->createEmployee($inactiveCategory);

        $normalFingerprint = $this->postJson(route('employees.mass-profile.preview', 'normal'))
            ->assertOk()
            ->assertJsonPath('data.blocking_conditions', [])
            ->assertJsonPath('data.excluded_categories.0.name', 'Inactive Category')
            ->json('data.confirmation_fingerprint');
        $this->postJson(route('employees.mass-profile.apply', 'normal'), [
            'confirmed' => true,
            'confirmation_fingerprint' => $normalFingerprint,
        ])->assertOk();

        $semesterFingerprint = $this->postJson(route('employees.mass-profile.preview', 'liburan-semester'))
            ->assertOk()
            ->assertJsonPath('data.blocking_conditions.0.category', 'Single Target Category')
            ->assertJsonPath('data.blocking_conditions.0.reason', 'Profil target belum tersedia pada kategori ini.')
            ->json('data.confirmation_fingerprint');
        $this->postJson(route('employees.mass-profile.apply', 'liburan-semester'), [
            'confirmed' => true,
            'confirmation_fingerprint' => $semesterFingerprint,
        ])->assertUnprocessable()->assertJsonValidationErrors('target');

        $this->assertSame($normal->id, $employee->fresh()->work_schedule_profile_id);
        $this->assertNull($inactiveEmployee->fresh()->work_schedule_profile_id);
    }

    public function test_changed_scope_or_assignments_require_a_fresh_confirmation(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'active_categories_only']);
        $this->actingAs(User::factory()->create());
        $category = $this->createCategory('Staff');
        $normal = $this->createProfile($category, 'Normal');
        $other = $this->createProfile($category, 'Other');
        $employee = $this->createEmployee($category);

        $fingerprint = $this->postJson(route('employees.mass-profile.preview', 'normal'))
            ->assertOk()
            ->json('data.confirmation_fingerprint');
        $employee->update(['work_schedule_profile_id' => $other->id]);

        $this->postJson(route('employees.mass-profile.apply', 'normal'), [
            'confirmed' => true,
            'confirmation_fingerprint' => $fingerprint,
        ])->assertStatus(409);

        $this->assertSame($other->id, $employee->fresh()->work_schedule_profile_id);
        $this->assertDatabaseHas('work_schedule_profiles', ['id' => $normal->id]);
    }

    public function test_changed_target_rules_require_a_fresh_confirmation(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'active_categories_only']);
        $this->actingAs(User::factory()->create());
        $category = $this->createCategory('Staff');
        $profile = $this->createProfile($category, 'Normal');
        $employee = $this->createEmployee($category);
        $fingerprint = $this->postJson(route('employees.mass-profile.preview', 'normal'))
            ->assertOk()
            ->json('data.confirmation_fingerprint');

        $profile->rules()->where('day_of_week', 1)->update([
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        $this->postJson(route('employees.mass-profile.apply', 'normal'), [
            'confirmed' => true,
            'confirmation_fingerprint' => $fingerprint,
        ])->assertStatus(409);

        $this->assertNull($employee->fresh()->work_schedule_profile_id);
    }

    public function test_all_categories_scope_is_rejected_and_does_not_change_assignments(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'all_categories']);
        $this->actingAs(User::factory()->create());
        $activeCategory = $this->createCategory('Active Category');
        $inactiveCategory = $this->createCategory('Inactive Category', false);
        $this->createProfile($activeCategory, 'Normal');
        $this->createProfile($inactiveCategory, 'Normal');
        $activeEmployee = $this->createEmployee($activeCategory);
        $inactiveCategoryEmployee = $this->createEmployee($inactiveCategory);

        $this->postJson(route('employees.mass-profile.preview', 'normal'))
            ->assertStatus(423);
        $this->postJson(route('employees.mass-profile.apply', 'normal'), [
            'confirmed' => true,
            'confirmation_fingerprint' => str_repeat('a', 64),
        ])->assertStatus(423);

        $this->assertNull($activeEmployee->fresh()->work_schedule_profile_id);
        $this->assertNull($inactiveCategoryEmployee->fresh()->work_schedule_profile_id);
    }

    public function test_database_failure_rolls_back_every_assignment_and_audit_entry(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'active_categories_only']);
        $this->actingAs(User::factory()->create());
        $category = $this->createCategory('Staff');
        $profile = $this->createProfile($category, 'Normal');
        $first = $this->createEmployee($category);
        $second = $this->createEmployee($category);
        $fingerprint = $this->postJson(route('employees.mass-profile.preview', 'normal'))
            ->assertOk()
            ->json('data.confirmation_fingerprint');
        DB::unprepared(sprintf(
            "CREATE TRIGGER fail_mass_profile_update BEFORE UPDATE OF work_schedule_profile_id ON employees WHEN OLD.id = %d BEGIN SELECT RAISE(ABORT, 'forced update failure'); END",
            $second->id,
        ));

        try {
            $this->postJson(route('employees.mass-profile.apply', 'normal'), [
                'confirmed' => true,
                'confirmation_fingerprint' => $fingerprint,
            ])->assertServerError();
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_mass_profile_update');
        }

        $this->assertNull($first->fresh()->work_schedule_profile_id);
        $this->assertNull($second->fresh()->work_schedule_profile_id);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'employee.work_schedule_profile.mass_assigned',
        ]);
        $this->assertDatabaseHas('work_schedule_profiles', ['id' => $profile->id]);
    }

    private function createCategory(string $name, bool $active = true): WorkScheduleCategory
    {
        return WorkScheduleCategory::create(['name' => $name, 'is_active' => $active]);
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

    private function createEmployee(
        WorkScheduleCategory $category,
        EmployeeStatus $status = EmployeeStatus::Active,
    ): Employee {
        static $unitIndex = 0;
        $code = ++$unitIndex % 2 === 0 ? 'SD' : 'TK';
        $unit = Unit::firstOrCreate(['code' => $code], ['name' => $code]);

        return Employee::create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'nama_lengkap' => 'Test employee '.$unitIndex,
            'status' => $status,
        ]);
    }
}
