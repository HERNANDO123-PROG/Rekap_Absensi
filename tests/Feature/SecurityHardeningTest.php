<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_protected_backend_routes(): void
    {
        $unit = $this->createUnit('TK');

        $this->get('/employees')->assertRedirect('/login');
        $this->get('/reports/period?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31')->assertRedirect('/login');
        $this->get('/reports/employee/'.$this->createEmployee($unit, 'TK-777', 'Guest User')->id.'/export?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31')->assertRedirect('/login');
        $this->post('/attendance/process', ['unit_id' => $unit->id, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31'])->assertRedirect('/login');
    }

    public function test_cross_unit_schedule_updates_and_deletes_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $schedule = $this->createSchedule($tk, 0, true, '07:00', '15:00');

        $this->put('/work-schedules/'.$schedule->id, [
            'unit_id' => $sd->id,
            'day_of_week' => 1,
            'is_working_day' => true,
            'start_time' => '08:00',
            'end_time' => '16:00',
        ])->assertStatus(403);

        $this->delete('/work-schedules/'.$schedule->id, ['unit_id' => $sd->id])->assertStatus(403);
    }

    public function test_cross_unit_calendar_updates_and_deletes_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $calendar = $this->createCalendar($tk, '2026-08-17', 'holiday', false);

        $this->put('/work-calendars/'.$calendar->id, [
            'unit_id' => $sd->id,
            'date' => '2026-08-17',
            'type' => 'holiday',
            'title' => 'Holiday',
            'description' => 'Cross unit attempt',
            'is_working_day' => false,
            'start_time' => null,
            'end_time' => null,
        ])->assertStatus(403);

        $this->delete('/work-calendars/'.$calendar->id, ['unit_id' => $sd->id])->assertStatus(403);
    }

    public function test_invalid_dates_and_units_are_rejected_for_reports_and_exports(): void
    {
        $this->actingAs(User::factory()->create());

        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-888', 'Valid Employee');

        $this->get('/reports/period?unit_id=999999&start_date=2026-08-01&end_date=2026-08-31')
            ->assertStatus(302)
            ->assertSessionHasErrors('unit_id');

        $this->get('/reports/period?unit_id='.$unit->id.'&start_date=2026-08-31&end_date=2026-08-01')
            ->assertStatus(302)
            ->assertSessionHasErrors('end_date');

        $this->get('/reports/employee/'.$employee->id.'/export?unit_id=999999&start_date=2026-08-01&end_date=2026-08-31')
            ->assertStatus(302)
            ->assertSessionHasErrors('unit_id');
    }

    public function test_report_and_export_endpoints_are_read_only(): void
    {
        $this->actingAs(User::factory()->create());

        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-900', 'Report User');
        $record = $this->createAttendanceRecord($employee, '2026-08-21', AttendanceStatus::Present, '07:00', '15:00', 0, 0);
        $before = $record->fresh()->getAttributes();

        $this->get('/reports/period?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31')->assertOk();
        $this->get('/reports/employee/'.$employee->id.'/export?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31')->assertOk();

        $this->assertSame($before['status'], $record->fresh()->status->value);
        $this->assertSame($before['actual_out'], $record->fresh()->actual_out);
    }

    private function createUnit(string $code): Unit
    {
        return Unit::query()->firstOrCreate(['code' => $code, 'name' => $code]);
    }

    private function createEmployee(Unit $unit, string $code, string $name): Employee
    {
        return Employee::query()->create([
            'unit_id' => $unit->id,
            'fingerprint_id' => $code,
            'nama_lengkap' => $name,
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function createAttendanceRecord(Employee $employee, string $date, AttendanceStatus $status, ?string $in, ?string $out, int $late, int $early): AttendanceRecord
    {
        return AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'unit_id' => $employee->unit_id,
            'attendance_date' => $date,
            'status' => $status,
            'actual_in' => $in,
            'actual_out' => $out,
            'late_minutes' => $late,
            'early_leave_minutes' => $early,
        ]);
    }

    private function createSchedule(Unit $unit, int $dayOfWeek, bool $isWorkingDay, ?string $startTime, ?string $endTime): WorkSchedule
    {
        return WorkSchedule::query()->create([
            'unit_id' => $unit->id,
            'day_of_week' => $dayOfWeek,
            'is_working_day' => $isWorkingDay,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    private function createCalendar(Unit $unit, string $date, string $type, bool $isWorkingDay): WorkCalendar
    {
        return WorkCalendar::query()->create([
            'unit_id' => $unit->id,
            'date' => $date,
            'type' => $type,
            'title' => 'Calendar entry',
            'description' => 'Entry',
            'is_working_day' => $isWorkingDay,
            'start_time' => $isWorkingDay ? '08:00' : null,
            'end_time' => $isWorkingDay ? '16:00' : null,
        ]);
    }
}
