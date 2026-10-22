<?php

namespace Tests\Feature;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceIntervalSource;
use App\Enums\AttendanceIntervalType;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Livewire\Reports\AnnualReport;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceInterval;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use App\Services\Reports\AttendanceFingerprintInitialReportService;
use App\Services\Reports\AttendanceFingerprintPeriodReportService;
use App\Services\Reports\AttendanceFingerprintPreviewService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ReportUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_and_employee_detail_pages_require_authentication(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-001', 'Ayu');

        $this->get('/rekap-periode')->assertRedirect(route('login'));
        $this->get('/rekap-tahunan')->assertRedirect(route('login'));
        $this->get('/rekap/pegawai/'.$employee->id.'?unit_id='.$unit->id)->assertRedirect(route('login'));
    }

    public function test_period_page_shows_filters_without_loading_report_until_required_filters_are_selected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mock(AttendanceFingerprintPeriodReportService::class, function ($mock): void {
            $mock->shouldNotReceive('generate');
        });
        $this->mock(AttendanceFingerprintInitialReportService::class, function ($mock): void {
            $mock->shouldNotReceive('generate');
        });
        $this->mock(AttendanceFingerprintPreviewService::class, function ($mock): void {
            $mock->shouldNotReceive('generate');
        });

        $dataQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$dataQueries): void {
            if (
                preg_match('/^\s*select\b/i', $query->sql) === 1
                && preg_match('/\b(attendance_records|fingerprint_logs)\b/i', $query->sql) === 1
            ) {
                $dataQueries[] = $query->sql;
            }
        });

        $this->get('/rekap-periode')
            ->assertOk()
            ->assertSee('Rekap Periode')
            ->assertSee('Pilih unit dan rentang tanggal')
            ->assertSee('Tanggal mulai')
            ->assertSee('Tanggal selesai')
            ->assertSee('Kategori')
            ->assertSee('Nama atau NIP')
            ->assertDontSee('Log matched valid')
            ->assertDontSee('Ekspor seluruh hasil');

        $this->assertSame([], $dataQueries);
    }

    public function test_annual_page_renders_service_totals_months_and_export_link(): void
    {
        $unit = $this->createUnit('SD');
        $employee = $this->createEmployee($unit, 'SD-001', 'Citra');
        $this->createAttendanceRecord($employee, '2026-01-05', AttendanceStatus::Present);
        $this->createAttendanceRecord($employee, '2026-02-05', AttendanceStatus::Sick);

        $this->actingAs(User::factory()->create());
        $this->get('/rekap-tahunan')->assertOk()->assertSee('Rekap Tahunan');

        Livewire::test(AnnualReport::class)
            ->set('unitId', (string) $unit->id)
            ->set('year', 2026)
            ->call('applyFilters')
            ->assertSee('Citra')
            ->assertSee('Hari Kerja')
            ->assertSee('Total Finger')
            ->assertSee('Hadir')
            ->assertSee('Terlambat')
            ->assertSee('Pulang Awal')
            ->assertSee('Jan')
            ->assertSee('Des')
            ->assertSee(route('reports.annual.export', ['unit_id' => $unit->id, 'year' => 2026]))
            ->assertSee('1');
    }

    public function test_employee_detail_shows_backend_data_export_and_rejects_unit_mismatch_read_only(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $employee = $this->createEmployee($tk, 'TK-003', 'Dewi');
        $record = $this->createAttendanceRecord($employee, '2026-10-02', AttendanceStatus::Late);
        AttendanceInterval::create([
            'attendance_record_id' => $record->id,
            'type' => AttendanceIntervalType::TemporaryExit->value,
            'start_time' => '10:00',
            'end_time' => null,
            'duration_minutes' => 0,
            'source' => AttendanceIntervalSource::Fingerprint->value,
            'notes' => 'open interval',
        ]);
        AttendanceAnomaly::create([
            'attendance_record_id' => $record->id,
            'type' => 'duplicate_timestamp',
            'message' => 'Periksa data waktu',
            'severity' => 'warning',
            'is_resolved' => false,
        ]);
        $before = [
            AttendanceRecord::count(),
            AttendanceInterval::count(),
            AttendanceAnomaly::count(),
        ];

        $this->actingAs(User::factory()->create());
        $this->get('/rekap/pegawai/'.$employee->id.'?unit_id='.$tk->id.'&start_date=2026-10-01&end_date=2026-10-31')
            ->assertOk()
            ->assertSee('Dewi')
            ->assertSee('Guru')
            ->assertSee('Perlu Verifikasi')
            ->assertSee('Masih terbuka')
            ->assertSee('Periksa data waktu')
            ->assertSee(route('reports.employee.export', ['employee' => $employee->id, 'unit_id' => $tk->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']));

        $this->get('/rekap/pegawai/'.$employee->id.'?unit_id='.$sd->id)->assertForbidden();
        $this->assertSame($before, [
            AttendanceRecord::count(),
            AttendanceInterval::count(),
            AttendanceAnomaly::count(),
        ]);
    }

    private function createUnit(string $code): Unit
    {
        return Unit::create(['name' => $code, 'code' => $code]);
    }

    private function createEmployee(Unit $unit, string $code, string $name): Employee
    {
        return Employee::create([
            'unit_id' => $unit->id,
            'fingerprint_id' => 'FP-'.$code,
            'nama_lengkap' => $name,
            'position' => 'Guru',
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function createAttendanceRecord(Employee $employee, string $date, AttendanceStatus $status): AttendanceRecord
    {
        return AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $employee->unit_id,
            'attendance_date' => $date,
            'status' => $status->value,
            'scheduled_in' => '07:00',
            'actual_in' => '07:00',
            'scheduled_out' => '15:00',
            'actual_out' => '15:00',
            'late_minutes' => $status === AttendanceStatus::Late ? 15 : 0,
            'early_leave_minutes' => 0,
            'absence_reason' => $status === AttendanceStatus::Permission ? AbsenceReason::Permission->value : null,
            'is_holiday' => false,
            'activity_on_holiday' => false,
            'needs_verification' => false,
        ]);
    }
}
