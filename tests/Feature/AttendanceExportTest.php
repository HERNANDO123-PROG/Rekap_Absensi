<?php

namespace Tests\Feature;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceIntervalSource;
use App\Enums\AttendanceIntervalType;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceInterval;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use App\Services\Exports\AttendanceAnnualExportService;
use App\Services\Exports\AttendancePeriodExportService;
use App\Services\Exports\EmployeeAttendanceDetailExportService;
use App\Services\Exports\ExcelExportHelper;
use App\Services\Reports\AttendanceAnnualReportService;
use App\Services\Reports\AttendancePeriodReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class AttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_helper_preserves_formula_prefixes_as_literal_text(): void
    {
        $values = [
            '=1+1',
            '+1+1',
            '-1+1',
            '@SUM(1,1)',
            "\t=1+1",
            "\r=1+1",
            "\n=1+1",
            " \t=1+1",
        ];
        $sheet = (new Spreadsheet)->getActiveSheet();

        ExcelExportHelper::writeRow($sheet, 1, $values);

        foreach ($values as $index => $value) {
            $cell = $sheet->getCell(ExcelExportHelper::columnName($index + 1).'1');
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
            if (! str_contains($value, "\r")) {
                $this->assertSame($value, $cell->getValue());
            }
        }
    }

    public function test_failed_workbook_creation_removes_its_temporary_file(): void
    {
        $temporaryFilesBefore = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'export-*');
        $spreadsheet = \Mockery::mock(new Spreadsheet)->makePartial();
        $spreadsheet->shouldReceive('getSheetCount')->andThrow(new \RuntimeException('Expected test failure.'));

        try {
            ExcelExportHelper::downloadPath($spreadsheet);
            $this->fail('Workbook creation should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Expected test failure.', $exception->getMessage());
        }

        $this->assertSame($temporaryFilesBefore, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'export-*'));
    }

    public function test_period_export_requires_authentication(): void
    {
        $unit = $this->createUnit('TK');

        $this->getJson('/reports/period/export?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31')
            ->assertStatus(401);
    }

    public function test_period_export_downloads_xlsx_and_contains_expected_headers(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-100', 'Arin');
        $record = $this->createAttendanceRecord($employee, '2026-08-11', AttendanceStatus::Late, '07:00', '15:00', 10, 0);
        $record->update([
            'scheduled_in' => '07:00',
            'actual_in' => '07:10',
            'scheduled_out' => '15:40',
            'actual_out' => '15:00',
        ]);
        $this->createAttendanceInterval($record, AttendanceIntervalType::TemporaryExit, '12:00', '12:30', 30, AttendanceIntervalSource::Fingerprint);

        $this->actingAs(User::factory()->create());

        $response = $this->get('/reports/period/export?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmp = tempnam(sys_get_temp_dir(), 'export-');
        file_put_contents($tmp, $response->streamedContent() ?: $response->getContent());

        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Rekap Periode', $sheet->getTitle());
        $this->assertSame([
            'No', 'ID Pegawai', 'Nama', 'Jabatan', 'Hari Kerja', 'Hadir', 'Terlambat',
            'Pulang Awal', 'Total Finger', 'Izin', 'Sakit', 'Dinas', 'Izin Keluar',
            'Alpha', 'Perlu Verifikasi',
        ], $this->rowValues($sheet, 'A5', 'O5'));
        $this->assertNotContains('Total Hari Kerja', $this->rowValues($sheet, 'A5', 'O5'));
        $this->assertSame('Unit:', $sheet->getCell('A1')->getValue());
        $this->assertSame('TK', $sheet->getCell('B1')->getValue());
        $this->assertSame('Periode:', $sheet->getCell('A2')->getValue());
        $this->assertSame('01-08-2026 s/d 31-08-2026', $sheet->getCell('B2')->getValue());

        $this->assertSame($employee->id, $sheet->getCell('B6')->getValue());
        $this->assertSame('Arin', $sheet->getCell('C6')->getValue());
        $this->assertSame(26, $sheet->getCell('E6')->getValue());
        $this->assertSame(1, $sheet->getCell('F6')->getValue());
        $this->assertSame(1, $sheet->getCell('G6')->getValue());
        $this->assertSame(1, $sheet->getCell('H6')->getValue());
        $this->assertSame(2, $sheet->getCell('I6')->getValue());
        $this->assertSame(0, $sheet->getCell('J6')->getValue());
        $this->assertSame(0, $sheet->getCell('O6')->getValue());

        unlink($tmp);
    }

    public function test_untrusted_text_is_written_as_literal_text_in_period_annual_and_employee_exports(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-FORMULA', '=HYPERLINK("https://example.test","open")');
        $employee->update(['position' => "\t=1+1"]);

        $period = app(AttendancePeriodExportService::class)
            ->buildSpreadsheet($unit->id, '2026-08-01', '2026-08-31')
            ->getActiveSheet();
        $this->assertSame('=HYPERLINK("https://example.test","open")', $period->getCell('C6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $period->getCell('C6')->getDataType());
        $this->assertSame("\t=1+1", $period->getCell('D6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $period->getCell('D6')->getDataType());

        $annual = app(AttendanceAnnualExportService::class)
            ->buildSpreadsheet($unit->id, 2026)
            ->getActiveSheet();
        $this->assertSame(DataType::TYPE_STRING, $annual->getCell('C6')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $annual->getCell('D6')->getDataType());

        $detail = app(EmployeeAttendanceDetailExportService::class)
            ->buildSpreadsheet($employee, '2026-08-01', '2026-08-31')
            ->getActiveSheet();
        $this->assertSame(DataType::TYPE_STRING, $detail->getCell('A3')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $detail->getCell('B4')->getDataType());
    }

    public function test_period_export_uses_service_values_and_inclusive_boundaries_for_all_unit_employees(): void
    {
        $unit = $this->createUnit('TK');
        $otherUnit = $this->createUnit('SD');
        $employee = $this->createEmployee($unit, 'TK-BOUNDARY', 'Boundary');
        $withoutAttendance = $this->createEmployee($unit, 'TK-NONE', 'No attendance');
        $otherEmployee = $this->createEmployee($otherUnit, 'SD-OUTSIDE', 'Other unit');
        $additionalEmployees = [];
        for ($index = 1; $index <= 21; $index++) {
            $additionalEmployees[] = $this->createEmployee($unit, sprintf('TK-EXTRA-%02d', $index), sprintf('Extra %02d', $index));
        }

        $this->createAttendanceRecord($employee, '2026-08-31', AttendanceStatus::Present, '07:00', '15:40', 0, 0);
        $this->createAttendanceRecord($employee, '2026-09-01', AttendanceStatus::Present, '07:00', '15:40', 0, 0);
        $this->createAttendanceRecord($employee, '2026-09-30', AttendanceStatus::Present, '07:00', '15:40', 0, 0);
        $this->createAttendanceRecord($employee, '2026-10-01', AttendanceStatus::Present, '07:00', '15:40', 0, 0);
        $this->createAttendanceRecord($otherEmployee, '2026-09-01', AttendanceStatus::Present, '07:00', '15:40', 0, 0);

        $serviceRows = collect(app(AttendancePeriodReportService::class)->generate(
            $unit->id,
            '2026-09-01',
            '2026-09-30',
        )['data'])->keyBy('employee_id');
        $this->actingAs(User::factory()->create());

        $response = $this->get('/reports/period/export?unit_id='.$unit->id.'&start_date=2026-09-01&end_date=2026-09-30');
        $response->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'period-boundary-export-');
        file_put_contents($tmp, $response->streamedContent() ?: $response->getContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();

        $rows = [];
        for ($row = 6; $sheet->getCell('A'.$row)->getValue() !== null; $row++) {
            $rows[(int) $sheet->getCell('B'.$row)->getValue()] = $row;
        }

        $this->assertCount(23, $rows);
        $this->assertArrayHasKey($employee->id, $rows);
        $this->assertArrayHasKey($withoutAttendance->id, $rows);
        $this->assertArrayHasKey($additionalEmployees[20]->id, $rows);
        $this->assertArrayNotHasKey($otherEmployee->id, $rows);
        $recordRow = $rows[$employee->id];
        $serviceRow = $serviceRows->get($employee->id);
        $this->assertSame('01-09-2026 s/d 30-09-2026', $sheet->getCell('B2')->getValue());
        $this->assertSame($serviceRow['hari_kerja'], $sheet->getCell('E'.$recordRow)->getValue());
        $this->assertSame($serviceRow['hadir'], $sheet->getCell('F'.$recordRow)->getValue());
        $this->assertSame($serviceRow['terlambat'], $sheet->getCell('G'.$recordRow)->getValue());
        $this->assertSame($serviceRow['pulang_awal'], $sheet->getCell('H'.$recordRow)->getValue());
        $this->assertSame($serviceRow['total_finger'], $sheet->getCell('I'.$recordRow)->getValue());
        $this->assertSame(26, $sheet->getCell('E'.$recordRow)->getValue());
        $this->assertSame(2, $sheet->getCell('F'.$recordRow)->getValue());
        $this->assertSame(4, $sheet->getCell('I'.$recordRow)->getValue());
        $this->assertSame(26, $sheet->getCell('E'.$rows[$withoutAttendance->id])->getValue());
        $this->assertSame(0, $sheet->getCell('F'.$rows[$withoutAttendance->id])->getValue());
        $this->assertSame(0, $sheet->getCell('I'.$rows[$withoutAttendance->id])->getValue());

        unlink($tmp);
    }

    public function test_period_export_validates_dates_and_unit(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/reports/period/export?unit_id=999999&start_date=2026-08-01&end_date=2026-08-31')
            ->assertStatus(422);

        $this->getJson('/reports/period/export?unit_id=1&start_date=2026-08-31&end_date=2026-08-01')
            ->assertStatus(422);
    }

    public function test_annual_export_downloads_xlsx_and_contains_months(): void
    {
        $unit = $this->createUnit('SD');
        $otherUnit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'SD-200', 'Budi');
        $this->createAttendanceRecord($employee, '2026-08-03', AttendanceStatus::Present, '07:00', '15:00', 0, 0);
        $lateAndEarly = $this->createAttendanceRecord($employee, '2026-09-01', AttendanceStatus::Late, '07:15', '15:30', 15, 10);
        $lateAndEarly->update([
            'scheduled_in' => '07:00',
            'scheduled_out' => '15:40',
        ]);
        $this->createAttendanceRecord($employee, '2025-09-01', AttendanceStatus::Present, '07:00', '15:40', 0, 0);
        $this->createAttendanceRecord($employee, '2027-09-01', AttendanceStatus::Present, '07:00', '15:40', 0, 0);
        $this->createAttendanceRecord($this->createEmployee($otherUnit, 'TK-201', 'Other unit'), '2026-09-01', AttendanceStatus::Present, '07:00', '15:40', 0, 0);

        $serviceRow = collect(app(AttendanceAnnualReportService::class)->generate($unit->id, 2026)['data'])
            ->firstWhere('employee_id', $employee->id);

        $this->actingAs(User::factory()->create());

        $response = $this->get('/reports/annual/export?unit_id='.$unit->id.'&year=2026');
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'annual-export-');
        file_put_contents($tmp, $response->streamedContent() ?: $response->getContent());

        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('Rekap Tahunan', $sheet->getTitle());
        $this->assertSame([
            'No', 'ID Pegawai', 'Nama', 'Jabatan', 'Metric', 'Jan', 'Feb', 'Mar', 'Apr',
            'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des', 'Total',
        ], $this->rowValues($sheet, 'A5', 'R5'));
        $this->assertSame($employee->id, $sheet->getCell('B6')->getValue());
        $this->assertSame(['Hari Kerja', 'Hadir', 'Terlambat', 'Pulang Awal', 'Total Finger'], [
            $sheet->getCell('E6')->getValue(),
            $sheet->getCell('E7')->getValue(),
            $sheet->getCell('E8')->getValue(),
            $sheet->getCell('E9')->getValue(),
            $sheet->getCell('E10')->getValue(),
        ]);
        $this->assertSame(26, $sheet->getCell('N6')->getValue());
        $this->assertSame(1, $sheet->getCell('N7')->getValue());
        $this->assertSame(1, $sheet->getCell('N8')->getValue());
        $this->assertSame(1, $sheet->getCell('N9')->getValue());
        $this->assertSame(2, $sheet->getCell('N10')->getValue());
        foreach ([
            'hari_kerja' => 6,
            'hadir' => 7,
            'terlambat' => 8,
            'pulang_awal' => 9,
            'total_finger' => 10,
        ] as $metric => $row) {
            $monthColumns = [
                'January' => 'F', 'February' => 'G', 'March' => 'H', 'April' => 'I',
                'May' => 'J', 'June' => 'K', 'July' => 'L', 'August' => 'M',
                'September' => 'N', 'October' => 'O', 'November' => 'P', 'December' => 'Q',
            ];
            foreach ($monthColumns as $month => $column) {
                $this->assertSame(
                    $serviceRow['monthly'][$month][$metric],
                    $sheet->getCell($column.$row)->getValue(),
                    $month.' '.$metric.' must match the annual report service.',
                );
            }
            $this->assertSame($serviceRow['annual'][$metric], $sheet->getCell('R'.$row)->getValue());
        }
        $this->assertSame(313, $sheet->getCell('R6')->getValue());
        $this->assertSame(2, $sheet->getCell('R7')->getValue());
        $this->assertSame(1, $sheet->getCell('R8')->getValue());
        $this->assertSame(1, $sheet->getCell('R9')->getValue());
        $this->assertSame(4, $sheet->getCell('R10')->getValue());
        $this->assertSame(10, $sheet->getHighestRow());
        $this->assertSame(2026, $sheet->getCell('B2')->getValue());

        unlink($tmp);
    }

    public function test_employee_detail_export_downloads_xlsx_and_contains_intervals_and_anomalies(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-300', 'Citra');
        $record = $this->createAttendanceRecord($employee, '2026-08-25', AttendanceStatus::Late, '07:00', '08:00', 60, 15);
        $this->createAttendanceInterval($record, AttendanceIntervalType::TemporaryExit, '12:00', '12:30', 30, AttendanceIntervalSource::Leave);
        $this->createAttendanceAnomaly($record, 'duplicate_timestamp', 'A duplicate was found', 'warning');

        $this->actingAs(User::factory()->create());

        $response = $this->get('/reports/employee/'.$employee->id.'/export?unit_id='.$unit->id.'&start_date=2026-08-20&end_date=2026-08-30');
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'detail-export-');
        file_put_contents($tmp, $response->streamedContent() ?: $response->getContent());

        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Detail Absensi', $sheet->getTitle());
        $this->assertSame('Citra', $sheet->getCell('A3')->getValue());
        $this->assertSame($employee->id, $sheet->getCell('B3')->getValue());
        $this->assertSame('2026-08-25', $sheet->getCell('A8')->getValue());

        $intervalSheet = $spreadsheet->getSheetByName('Interval');
        $this->assertNotNull($intervalSheet);
        $this->assertSame('12:00', $intervalSheet->getCell('C7')->getValue());

        $anomalySheet = $spreadsheet->getSheetByName('Anomali');
        $this->assertNotNull($anomalySheet);
        $this->assertSame('duplicate_timestamp', $anomalySheet->getCell('B7')->getValue());

        unlink($tmp);
    }

    public function test_export_rejects_employee_from_other_unit(): void
    {
        $ownerUnit = $this->createUnit('TK');
        $otherUnit = $this->createUnit('SD');
        $employee = $this->createEmployee($otherUnit, 'SD-444', 'Dewi');

        $this->actingAs(User::factory()->create());

        $this->get('/reports/employee/'.$employee->id.'/export?unit_id='.$ownerUnit->id.'&start_date=2026-08-01&end_date=2026-08-31')
            ->assertStatus(403);

        $this->get('/reports/period/export?unit_id='.$ownerUnit->id.'&start_date=2026-08-01&end_date=2026-08-31')
            ->assertOk();
    }

    public function test_export_is_read_only_and_does_not_modify_data(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-500', 'Erik');
        $record = $this->createAttendanceRecord($employee, '2026-08-20', AttendanceStatus::Present, '07:00', '15:00', 0, 0);
        $original = $record->getAttributes();

        $this->actingAs(User::factory()->create());

        $response = $this->get('/reports/period/export?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31');
        $response->assertOk();

        $this->assertSame($original['status'], $record->fresh()->status->value);
        $this->assertSame($original['actual_out'], $record->fresh()->actual_out);
        $this->assertSame(1, $employee->attendanceRecords()->count());
    }

    public function test_filename_is_sanitized(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-600', 'Rizki / Wibowo');

        $this->actingAs(User::factory()->create());

        $response = $this->get('/reports/employee/'.$employee->id.'/export?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31');
        $response->assertOk();
        $this->assertStringContainsString('detail-pegawai-rizki-wibowo-2026-08-01-2026-08-31.xlsx', $response->headers->get('content-disposition', ''));
    }

    public function test_export_temporary_file_is_removed_after_download(): void
    {
        $unit = $this->createUnit('TK');
        $this->actingAs(User::factory()->create());

        $response = $this->get('/reports/period/export?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31');
        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($path);

        ob_start();
        try {
            $response->baseResponse->sendContent();
        } finally {
            ob_end_clean();
        }

        $this->assertFileDoesNotExist($path);
    }

    private function createUnit(string $code): Unit
    {
        return Unit::create(['name' => $code, 'code' => $code]);
    }

    private function createEmployee(Unit $unit, string $code, string $name): Employee
    {
        $employee = Employee::create([
            'unit_id' => $unit->id,
            'fingerprint_id' => $code,
            'nama_lengkap' => $name,
            'position' => 'Guru',
            'status' => EmployeeStatus::Active,
        ]);

        return $employee;
    }

    private function createAttendanceRecord(Employee $employee, string $date, AttendanceStatus $status, ?string $in, ?string $out, int $late, int $early): AttendanceRecord
    {
        return AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $employee->unit_id,
            'attendance_date' => $date,
            'status' => $status->value,
            'scheduled_in' => $in,
            'actual_in' => $in,
            'scheduled_out' => $out,
            'actual_out' => $out,
            'late_minutes' => $late,
            'early_leave_minutes' => $early,
            'absence_reason' => $status === AttendanceStatus::Permission ? AbsenceReason::Permission->value : null,
            'is_holiday' => false,
            'activity_on_holiday' => false,
            'needs_verification' => $status === AttendanceStatus::NeedsVerification,
            'verification_note' => $status === AttendanceStatus::NeedsVerification ? 'Check data' : null,
        ]);
    }

    private function createAttendanceInterval(AttendanceRecord $record, AttendanceIntervalType $type, string $start, string $end, int $minutes, AttendanceIntervalSource $source): AttendanceInterval
    {
        return AttendanceInterval::create([
            'attendance_record_id' => $record->id,
            'type' => $type->value,
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => $minutes,
            'source' => $source->value,
            'notes' => 'interval',
        ]);
    }

    private function createAttendanceAnomaly(AttendanceRecord $record, string $type, string $message, string $severity): AttendanceAnomaly
    {
        return AttendanceAnomaly::create([
            'attendance_record_id' => $record->id,
            'type' => $type,
            'message' => $message,
            'severity' => $severity,
            'is_resolved' => false,
        ]);
    }

    private function rowValues(Worksheet $sheet, string $startCell, string $endCell): array
    {
        return $sheet->rangeToArray($startCell.':'.$endCell, null, true, false)[0];
    }
}
