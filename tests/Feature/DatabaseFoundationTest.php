<?php

namespace Tests\Feature;

use App\Enums\AttendanceAnomalyType;
use App\Enums\AttendanceIntervalSource;
use App\Enums\AttendanceIntervalType;
use App\Enums\AttendanceStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Enums\WorkCalendarType;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAlias;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\LeaveImportBatch;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use Database\Seeders\UnitSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_foundation_tables_are_created(): void
    {
        foreach ([
            'units',
            'employees',
            'employee_aliases',
            'work_schedules',
            'work_calendars',
            'fingerprint_import_batches',
            'fingerprint_logs',
            'leave_import_batches',
            'leave_requests',
            'attendance_records',
            'attendance_intervals',
            'attendance_anomalies',
            'audit_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} should exist.");
        }
    }

    public function test_retired_period_tables_and_routes_are_absent_while_shared_schedule_tables_remain(): void
    {
        foreach (['employee_employment_periods', 'work_periods', 'work_period_rules'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Retired table {$table} should not exist.");
        }

        foreach ([
            'work_schedule_categories',
            'work_schedule_profiles',
            'work_schedule_profile_rules',
            'work_schedules',
            'work_calendars',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Shared schedule table {$table} must remain.");
        }

        foreach ([
            'special-periods.index',
            'special-periods.store',
            'special-periods.update',
            'special-periods.activate',
            'special-periods.deactivate',
        ] as $route) {
            $this->assertFalse(Route::has($route), "Retired route {$route} should not be registered.");
        }
    }

    public function test_unit_seeder_creates_only_tk_and_sd_and_is_idempotent(): void
    {
        Artisan::call('db:seed', ['--class' => UnitSeeder::class]);
        Artisan::call('db:seed', ['--class' => UnitSeeder::class]);

        $this->assertDatabaseCount('units', 2);
        $this->assertDatabaseHas('units', ['name' => 'TK', 'code' => 'TK']);
        $this->assertDatabaseHas('units', ['name' => 'SD', 'code' => 'SD']);
    }

    public function test_unit_employee_alias_schedule_and_calendar_relationships_work(): void
    {
        $unit = $this->createUnit('TK');
        $employee = Employee::create([
            'unit_id' => $unit->id,
            'fingerprint_id' => '12',
            'namafinger' => 'Intan Permata Sari, S.Pd',
            'status' => 'active',
        ]);
        $otherUnit = $this->createUnit('SD');
        $otherUnitEmployee = Employee::create([
            'unit_id' => $otherUnit->id,
            'fingerprint_id' => '12',
            'nama_lengkap' => 'Employee in other unit',
            'status' => 'active',
        ]);

        try {
            Employee::create([
                'unit_id' => $unit->id,
                'fingerprint_id' => '12',
                'nama_lengkap' => 'Duplicate in same unit',
                'status' => 'active',
            ]);
            $this->fail('A fingerprint ID must be unique within its unit.');
        } catch (QueryException) {
            $this->assertDatabaseCount('employees', 2);
        }
        $alias = EmployeeAlias::create([
            'employee_id' => $employee->id,
            'alias_name' => 'Intan~',
            'normalized_alias' => 'intan',
        ]);
        $schedule = WorkSchedule::create([
            'unit_id' => $unit->id,
            'day_of_week' => 1,
            'is_working_day' => true,
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);
        $calendar = WorkCalendar::create([
            'unit_id' => $unit->id,
            'date' => '2026-10-01',
            'type' => WorkCalendarType::Workday,
            'is_working_day' => true,
        ]);

        $this->assertTrue($employee->unit->is($unit));
        $this->assertSame('12', $otherUnitEmployee->fingerprint_id);
        $this->assertTrue($unit->employees->first()->is($employee));
        $this->assertTrue($alias->employee->is($employee));
        $this->assertTrue($employee->aliases->first()->is($alias));
        $this->assertTrue($schedule->unit->is($unit));
        $this->assertTrue($calendar->unit->is($unit));
    }

    public function test_fingerprint_logs_allow_null_employee_and_duplicate_timestamps(): void
    {
        $unit = $this->createUnit('SD');
        $user = User::factory()->create();
        $batch = FingerprintImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => 'attendance.xls',
            'file_hash' => str_repeat('a', 64),
            'imported_by' => $user->id,
            'status' => ImportBatchStatus::Completed,
        ]);

        $attributes = [
            'import_batch_id' => $batch->id,
            'unit_id' => $unit->id,
            'fingerprint_id' => '12',
            'employee_name_raw' => 'Intan~',
            'attendance_date' => '2026-10-01',
            'attendance_time' => '06:44:00',
            'raw_value' => '06:44',
            'matching_status' => MatchingStatus::Unmatched,
        ];

        $firstLog = FingerprintLog::create($attributes);
        $secondLog = FingerprintLog::create($attributes);

        $this->assertDatabaseCount('fingerprint_logs', 2);
        $this->assertNull($firstLog->employee);
        $this->assertTrue($firstLog->importBatch->is($batch));
        $this->assertTrue($firstLog->unit->is($unit));
        $this->assertTrue($batch->importedBy->is($user));
        $this->assertCount(2, $batch->logs);
        $this->assertNotSame($firstLog->id, $secondLog->id);
    }

    public function test_leave_requests_can_be_unmatched_and_keep_the_raw_leave_type(): void
    {
        $unit = $this->createUnit('TK');
        $user = User::factory()->create();
        $batch = LeaveImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => 'responses.csv',
            'file_hash' => str_repeat('b', 64),
            'imported_by' => $user->id,
            'status' => ImportBatchStatus::Pending,
        ]);
        $request = LeaveRequest::create([
            'import_batch_id' => $batch->id,
            'unit_id' => $unit->id,
            'raw_name' => 'Intan Permata Sari',
            'normalized_name' => 'intan permata sari',
            'request_date' => '2026-10-01',
            'leave_type' => LeaveType::TemporaryExit,
            'raw_leave_type' => 'Keluar',
            'matching_status' => MatchingStatus::Unmatched,
        ]);

        $this->assertNull($request->employee);
        $this->assertTrue($request->importBatch->is($batch));
        $this->assertTrue($request->unit->is($unit));
        $this->assertSame('Keluar', $request->raw_leave_type);
        $this->assertCount(1, $batch->leaveRequests);
        $this->assertTrue($user->leaveImportBatches->first()->is($batch));
    }

    public function test_attendance_is_unique_per_employee_and_has_intervals_and_anomalies(): void
    {
        $unit = $this->createUnit('SD');
        $employee = Employee::create([
            'unit_id' => $unit->id,
            'fingerprint_id' => '15',
            'nama_lengkap' => 'Rizky',
            'status' => 'active',
        ]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'attendance_date' => '2026-10-01',
            'status' => AttendanceStatus::Late,
            'late_minutes' => 15,
            'early_leave_minutes' => 20,
        ]);

        $record->intervals()->createMany([
            [
                'type' => AttendanceIntervalType::TemporaryExit,
                'start_time' => '10:10:00',
                'end_time' => '12:05:00',
                'duration_minutes' => 115,
                'source' => AttendanceIntervalSource::Fingerprint,
            ],
            [
                'type' => AttendanceIntervalType::Attendance,
                'start_time' => '07:02:00',
                'source' => AttendanceIntervalSource::Fingerprint,
            ],
        ]);

        $user = User::factory()->create();
        $anomaly = AttendanceAnomaly::create([
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::UnexpectedExit,
            'message' => 'Exit has no matching leave request.',
            'severity' => 'warning',
            'resolved_by' => $user->id,
        ]);

        try {
            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'unit_id' => $unit->id,
                'attendance_date' => '2026-10-01',
                'status' => AttendanceStatus::Present,
            ]);
            $this->fail('A second attendance record for the same employee and date should be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseCount('attendance_records', 1);
        }

        $this->assertTrue($record->employee->is($employee));
        $this->assertTrue($record->unit->is($unit));
        $this->assertSame(15, $record->late_minutes);
        $this->assertSame(20, $record->early_leave_minutes);
        $this->assertCount(2, $record->intervals);
        $this->assertTrue($record->intervals->first()->attendanceRecord->is($record));
        $this->assertTrue($anomaly->attendanceRecord->is($record));
        $this->assertTrue($anomaly->resolvedBy->is($user));
        $this->assertCount(1, $user->resolvedAttendanceAnomalies);
    }

    public function test_audit_logs_allow_a_null_user_and_belong_to_a_user_when_present(): void
    {
        $anonymousLog = AuditLog::create([
            'action' => 'system_event',
            'module' => 'setup',
            'description' => 'Automated setup event.',
        ]);

        $user = User::factory()->create();
        $userLog = AuditLog::create([
            'user_id' => $user->id,
            'action' => 'manual_change',
            'module' => 'employees',
        ]);

        $this->assertNull($anonymousLog->user);
        $this->assertTrue($userLog->user->is($user));
        $this->assertCount(1, $user->auditLogs);
    }

    private function createUnit(string $code): Unit
    {
        return Unit::create(['name' => $code, 'code' => $code]);
    }
}
