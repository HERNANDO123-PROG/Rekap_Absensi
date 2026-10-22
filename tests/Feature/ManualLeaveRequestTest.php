<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Models\AuditLog;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\LeaveImportBatch;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkScheduleCategory;
use App\Services\Attendance\AttendanceProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualLeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_leave_request_persists_selected_employee_type_source_and_audit_user(): void
    {
        $unit = Unit::query()->create(['name' => 'Sekolah Dasar', 'code' => 'SD']);
        $employee = $this->createEmployee($unit);
        $user = User::factory()->create();

        $this->actingAs($user);
        $expectedTypes = [
            ['tidak_hadir', LeaveType::Absent],
            ['dinas', LeaveType::OfficialDuty],
            ['keluar', LeaveType::TemporaryExit],
        ];

        foreach ($expectedTypes as $index => [$inputType, $expectedType]) {
            $date = '2026-11-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $response = $this->postJson(route('leave-requests.manual.store'), [
                'employee_id' => $employee->id,
                'unit_id' => $unit->id,
                'request_date' => $date,
                'leave_type' => $inputType,
            ]);

            $response
                ->assertCreated()
                ->assertJsonPath('message', 'Data izin berhasil disimpan.')
                ->assertJsonPath('data.source', 'manual')
                ->assertJsonPath('data.leave_type', $expectedType->value)
                ->assertJsonPath('data.created_by', $user->id);

            $saved = LeaveRequest::query()->findOrFail($response->json('data.id'));
            $this->assertNull($saved->import_batch_id);
            $this->assertSame($unit->id, $saved->unit_id);
            $this->assertSame($employee->id, $saved->employee_id);
            $this->assertSame('Siti Aminah', $saved->raw_name);
            $this->assertSame('siti aminah', $saved->normalized_name);
            $this->assertSame('matched', $saved->matching_status->value);
            $this->assertNull($saved->request_time);
            $this->assertNull($saved->absence_reason);
        }

        $this->assertDatabaseCount('leave_requests', 3);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertSame(3, AuditLog::query()->where('module', 'leave_requests')->count());
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_manual_leave_request_rejects_invalid_employee_unit_date_and_leave_type(): void
    {
        $unit = Unit::query()->create(['name' => 'Sekolah Dasar', 'code' => 'SD']);
        $otherUnit = Unit::query()->create(['name' => 'Taman Kanak-kanak', 'code' => 'TK']);
        $employee = $this->createEmployee($unit);
        $this->actingAs(User::factory()->create());

        $this->postJson(route('leave-requests.manual.store'), [
            'employee_id' => 999999,
            'unit_id' => $unit->id,
            'request_date' => '2026-11-01',
            'leave_type' => 'dinas',
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_id');

        $this->postJson(route('leave-requests.manual.store'), [
            'employee_id' => $employee->id,
            'unit_id' => $otherUnit->id,
            'request_date' => '2026-11-01',
            'leave_type' => 'dinas',
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_id');

        $this->postJson(route('leave-requests.manual.store'), [
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'request_date' => 'not-a-date',
            'leave_type' => 'terlambat',
        ])->assertUnprocessable()->assertJsonValidationErrors(['request_date', 'leave_type']);

        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_manual_leave_request_rejects_inactive_employee_and_duplicate_date(): void
    {
        $unit = Unit::query()->create(['name' => 'Sekolah Dasar', 'code' => 'SD']);
        $employee = $this->createEmployee($unit);
        $inactiveEmployee = $this->createEmployee($unit, EmployeeStatus::Inactive);
        $this->actingAs(User::factory()->create());
        $payload = [
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'request_date' => '2026-11-01',
            'leave_type' => 'dinas',
        ];

        $this->postJson(route('leave-requests.manual.store'), [
            ...$payload,
            'employee_id' => $inactiveEmployee->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_id');

        $this->postJson(route('leave-requests.manual.store'), $payload)->assertCreated();
        $this->postJson(route('leave-requests.manual.store'), [
            ...$payload,
            'leave_type' => 'keluar',
        ])->assertConflict()->assertJsonPath(
            'message',
            'Sudah ada pengajuan izin untuk karyawan ini pada tanggal tersebut.',
        );

        $this->assertDatabaseCount('leave_requests', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_manual_leave_request_requires_authentication(): void
    {
        $this->postJson(route('leave-requests.manual.store'), [])
            ->assertUnauthorized();
    }

    public function test_creating_manual_leave_reprocesses_only_an_existing_employee_date_record(): void
    {
        $unit = Unit::query()->create(['name' => 'Taman Kanak-kanak', 'code' => 'TK']);
        $employee = $this->createAttendanceEmployee($unit);
        $this->createFingerprintLogs($unit, $employee, '2026-11-02', ['07:20', '15:30']);
        $before = app(AttendanceProcessingService::class)->processEmployeeDate($unit->id, $employee->id, '2026-11-02');
        $this->assertSame('terlambat', $before['status']);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('leave-requests.manual.store'), [
                'employee_id' => $employee->id,
                'unit_id' => $unit->id,
                'request_date' => '2026-11-02',
                'leave_type' => 'tidak_hadir',
            ])
            ->assertCreated()
            ->assertJsonPath('data.attendance_reprocessed', true);

        $record = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-02')->firstOrFail();
        $this->assertSame('alpha', $record->status->value);
        $this->assertFalse($record->needs_verification);
        $this->assertNotNull($record->leave_request_id);
        $this->assertSame(20, $record->late_minutes);
        $this->assertNotNull($response->json('data.id'));
    }

    public function test_manual_leave_update_and_delete_reprocess_only_old_and_new_employee_dates(): void
    {
        $unit = Unit::query()->create(['name' => 'Taman Kanak-kanak', 'code' => 'TK']);
        $employee = $this->createAttendanceEmployee($unit);
        $otherEmployee = $this->createAttendanceEmployee($unit, 'Pegawai Lain');
        $this->createFingerprintLogs($unit, $employee, '2026-11-02', ['07:20', '15:30']);
        $this->createFingerprintLogs($unit, $employee, '2026-11-03', ['07:00', '12:00']);
        $this->createFingerprintLogs($unit, $otherEmployee, '2026-11-02', ['07:00', '15:30']);
        $attendance = app(AttendanceProcessingService::class);
        $attendance->processEmployeeDate($unit->id, $employee->id, '2026-11-02');
        $attendance->processEmployeeDate($unit->id, $employee->id, '2026-11-03');
        $unrelated = $attendance->processEmployeeDate($unit->id, $otherEmployee->id, '2026-11-02');
        $unrelatedProcessedAt = AttendanceRecord::query()->findOrFail($unrelated['attendance_record_id'])->processed_at;
        $user = User::factory()->create();
        $this->actingAs($user);

        $created = $this->postJson(route('leave-requests.manual.store'), [
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'request_date' => '2026-11-02',
            'leave_type' => 'dinas',
        ])->assertCreated()->assertJsonPath('data.attendance_reprocessed', true);
        $leaveId = $created->json('data.id');

        $this->putJson(route('leave-requests.manual.update', $leaveId), [
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'request_date' => '2026-11-03',
            'leave_type' => 'tidak_hadir',
        ])->assertOk()->assertJsonPath('data.attendance_reprocessed', true);

        $this->assertSame(
            'terlambat',
            AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-02')->firstOrFail()->status->value,
        );
        $this->assertSame(
            'alpha',
            AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-03')->firstOrFail()->status->value,
        );
        $this->assertSame(
            $unrelatedProcessedAt->toDateTimeString(),
            AttendanceRecord::query()->findOrFail($unrelated['attendance_record_id'])->processed_at->toDateTimeString(),
        );

        $this->deleteJson(route('leave-requests.manual.destroy', $leaveId))
            ->assertOk()
            ->assertJsonPath('data.attendance_reprocessed', true);
        $this->assertSame(
            'pulang_awal',
            AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-03')->firstOrFail()->status->value,
        );
        $this->assertDatabaseMissing('leave_requests', ['id' => $leaveId]);
    }

    public function test_imported_leave_requests_cannot_be_edited_or_deleted_through_manual_routes(): void
    {
        $unit = Unit::query()->create(['name' => 'Taman Kanak-kanak', 'code' => 'TK']);
        $employee = $this->createEmployee($unit);
        $batch = LeaveImportBatch::query()->create([
            'unit_id' => $unit->id,
            'filename' => 'old-import.xlsx',
            'file_hash' => hash('sha256', 'old-import'),
            'status' => ImportBatchStatus::Completed,
        ]);
        $leave = LeaveRequest::query()->create([
            'import_batch_id' => $batch->id,
            'source' => 'import',
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'raw_name' => 'Siti Aminah',
            'normalized_name' => 'siti aminah',
            'request_date' => '2026-11-02',
            'leave_type' => LeaveType::OfficialDuty,
            'matching_status' => MatchingStatus::Matched,
        ]);

        $this->actingAs(User::factory()->create());
        $this->putJson(route('leave-requests.manual.update', $leave), [
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'request_date' => '2026-11-03',
            'leave_type' => 'tidak_hadir',
        ])->assertNotFound();
        $this->deleteJson(route('leave-requests.manual.destroy', $leave))->assertNotFound();
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leave->id,
            'import_batch_id' => $batch->id,
            'leave_type' => LeaveType::OfficialDuty->value,
        ]);
    }

    private function createEmployee(Unit $unit, EmployeeStatus $status = EmployeeStatus::Active): Employee
    {
        return Employee::query()->create([
            'unit_id' => $unit->id,
            'nama_lengkap' => 'Siti Aminah',
            'nip' => '19870011',
            'status' => $status,
        ]);
    }

    private function createAttendanceEmployee(Unit $unit, string $name = 'Siti Aminah'): Employee
    {
        $category = WorkScheduleCategory::query()->create([
            'name' => 'Jam kerja '.$name,
            'start_time' => '07:00',
            'end_time' => '15:30',
            'is_active' => true,
        ]);
        $employee = Employee::query()->create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'nama_lengkap' => $name,
            'nip' => $name === 'Siti Aminah' ? '19870011' : '19870012',
            'status' => EmployeeStatus::Active,
        ]);
        return $employee;
    }

    private function createFingerprintLogs(Unit $unit, Employee $employee, string $date, array $times): void
    {
        $batch = FingerprintImportBatch::query()->create([
            'unit_id' => $unit->id,
            'filename' => 'attendance-fixture.xlsx',
            'file_hash' => hash('sha256', $employee->id.'-'.$date),
            'status' => ImportBatchStatus::Completed,
        ]);

        foreach ($times as $time) {
            FingerprintLog::query()->create([
                'import_batch_id' => $batch->id,
                'unit_id' => $unit->id,
                'employee_id' => $employee->id,
                'fingerprint_id' => $employee->fingerprint_id ?? (string) $employee->id,
                'attendance_date' => $date,
                'attendance_time' => $time.':00',
                'matching_status' => MatchingStatus::Matched,
            ]);
        }
    }
}
