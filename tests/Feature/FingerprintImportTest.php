<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Enums\MatchingStatus;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Services\EmployeeMatchingService;
use App\Services\WorkScheduleCategoryService;
use App\Services\Fingerprint\FingerprintDuplicateImportException;
use App\Services\Fingerprint\FingerprintExcelReader;
use App\Services\Fingerprint\FingerprintImportException;
use App\Services\Fingerprint\FingerprintImportService;
use App\Services\Fingerprint\FingerprintTimeParser;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class FingerprintImportTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    public function test_time_parser_preserves_compact_separated_and_duplicate_timestamps(): void
    {
        $parser = app(FingerprintTimeParser::class);

        $this->assertSame(['06:30', '13:19'], $parser->parse('06:3013:19'));
        $this->assertSame(['06:44', '06:44', '12:05'], $parser->parse('06:4406:4412:05'));
        $this->assertSame(['07:02', '10:10', '12:05', '15:30'], $parser->parse('07:02 10:10 12:05 15:30'));
        $this->assertSame(['00:00', '23:59'], $parser->parse('00:00; 23:59'));
    }

    public function test_invalid_times_are_rejected_without_becoming_log_entries(): void
    {
        $parser = app(FingerprintTimeParser::class);

        foreach (['25:70', '99:99', 'not-a-time', '07:02 junk'] as $invalidTime) {
            try {
                $parser->parse($invalidTime);
                $this->fail("The invalid time {$invalidTime} should be rejected.");
            } catch (FingerprintImportException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_xls_and_xlsx_are_read_and_raw_sheet_is_found_by_name(): void
    {
        foreach (['xls', 'xlsx'] as $extension) {
            $file = $this->createFixture($extension, rawSheetPosition: 4);
            $parsed = app(FingerprintExcelReader::class)->read($file);

            $this->assertSame('Lap. Log Absen', $parsed['sheet']);
            $this->assertSame(4, $parsed['header_row']);
            $this->assertCount(5, $parsed['rows']);
            $this->assertSame(['06:30', '13:19'], array_column($parsed['rows'][0]['timestamps'], 'time'));
        }
    }

    public function test_machine_matrix_fixture_extracts_employee_blocks_and_maps_month_days(): void
    {
        $file = $this->createMatrixFixture();
        $report = app(FingerprintExcelReader::class)->read($file);

        $this->assertSame('matrix', $report['format']);
        $this->assertSame(2, $report['header_row']);
        $this->assertSame(2, $report['date_header_row']);
        $this->assertSame('2026-08-01', $report['period_start']);
        $this->assertSame('2026-08-31', $report['period_end']);
        $this->assertSame(2, $report['employees_detected']);

        $firstDay = collect($report['rows'])->first(fn (array $row) => $row['fingerprint_id'] === '2' && $row['attendance_date'] === '2026-08-01');
        $lastDay = collect($report['rows'])->first(fn (array $row) => $row['fingerprint_id'] === '2' && $row['attendance_date'] === '2026-08-31');
        $secondEmployee = collect($report['rows'])->first(fn (array $row) => $row['fingerprint_id'] === '99' && $row['attendance_date'] === '2026-08-01');

        $this->assertNotNull($firstDay);
        $this->assertSame('Arlis', $firstDay['employee_name_raw']);
        $this->assertSame('TK', $firstDay['employee_department_raw']);
        $this->assertSame(['06:30', '13:19'], array_column($firstDay['timestamps'], 'time'));
        $this->assertSame('B4', $firstDay['timestamps'][0]['source_cell']);
        $this->assertSame('2026-08-31', $lastDay['attendance_date']);
        $this->assertSame(['06:44', '06:44', '12:05'], array_column(
            collect($report['rows'])->first(fn (array $row) => $row['fingerprint_id'] === '2' && $row['attendance_date'] === '2026-08-05')['timestamps'],
            'time',
        ));
        $this->assertSame(['06:16'], array_column(
            collect($report['rows'])->first(fn (array $row) => $row['fingerprint_id'] === '2' && $row['attendance_date'] === '2026-08-04')['timestamps'],
            'time',
        ));
        $this->assertSame('B6', $secondEmployee['timestamps'][0]['source_cell']);
        $this->assertSame(17, $report['raw_timestamp_count']);
        $this->assertSame(16, $report['valid_timestamp_count']);
        $this->assertSame(1, $report['invalid_timestamp_count']);
    }

    public function test_matrix_date_columns_follow_period_metadata_without_a_fixed_month(): void
    {
        $file = $this->createMatrixFixture('03');
        $report = app(FingerprintExcelReader::class)->read($file);
        $lastDay = collect($report['rows'])->first(fn (array $row) => $row['fingerprint_id'] === '2' && $row['attendance_date'] === '2026-03-31');

        $this->assertSame('2026-03-01', $report['period_start']);
        $this->assertSame('2026-03-31', $report['period_end']);
        $this->assertSame('2026-03-31', $lastDay['attendance_date']);
    }

    public function test_matrix_preview_is_read_only_and_final_import_persists_each_raw_timestamp(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Arlis', '2');
        $file = $this->createMatrixFixture();
        $this->actingAs(User::factory()->create());

        $this->postJson('/fingerprint-imports/preview', [
            'unit_id' => $unit->id,
            'file' => $this->uploadedFile($file, 'machine-matrix-fixture.xls'),
        ])
            ->assertOk()
            ->assertJsonPath('data.filename', 'machine-matrix-fixture.xls')
            ->assertJsonPath('data.period_start', '2026-08-01')
            ->assertJsonPath('data.period_end', '2026-08-31')
            ->assertJsonPath('data.employees_detected', 2)
            ->assertJsonPath('data.raw_timestamps_detected', 17)
            ->assertJsonPath('data.valid_timestamps', 16)
            ->assertJsonPath('data.invalid_timestamps', 1)
            ->assertJsonPath('data.matched_employees', 1)
            ->assertJsonPath('data.unmatched_employees', 1);

        $this->assertDatabaseCount('fingerprint_import_batches', 0);
        $this->assertDatabaseCount('fingerprint_logs', 0);

        $batch = app(FingerprintImportService::class)->import(
            $this->uploadedFile($file, 'machine-matrix-fixture.xls'),
            $unit->id,
            User::factory()->create()->id,
        );

        $this->assertSame('2026-08-01', $batch->period_start->format('Y-m-d'));
        $this->assertSame('2026-08-31', $batch->period_end->format('Y-m-d'));
        $this->assertDatabaseCount('fingerprint_logs', 16);
        $this->assertDatabaseHas('fingerprint_logs', [
            'employee_id' => $employee->id,
            'fingerprint_id' => '2',
            'attendance_date' => '2026-08-01',
            'attendance_time' => '06:30:00',
            'raw_value' => '06:3013:19',
            'source_row' => 4,
            'source_cell' => 'B4',
        ]);
        $unknownEmployee = Employee::query()->where('fingerprint_id', '99')->firstOrFail();
        $this->assertDatabaseHas('fingerprint_logs', [
            'employee_id' => $unknownEmployee->id,
            'fingerprint_id' => '99',
            'attendance_date' => '2026-08-31',
            'attendance_time' => '08:31:00',
            'matching_status' => MatchingStatus::Matched->value,
        ]);
        $this->assertSame(2, FingerprintLog::query()
            ->where('fingerprint_id', '2')
            ->whereDate('attendance_date', '2026-08-05')
            ->whereTime('attendance_time', '06:44:00')
            ->count());
    }

    public function test_import_creates_new_employee_and_category_from_fingerprint_id_and_department(): void
    {
        $unit = $this->createUnit('TK');
        $file = $this->createFixture('xls');

        app(FingerprintImportService::class)->import($this->uploadedFile($file), $unit->id);

        $employee = Employee::query()->where('fingerprint_id', '12')->firstOrFail();
        $category = WorkScheduleCategory::query()->where('name', 'TK')->firstOrFail();

        $this->assertSame('Intan~', $employee->namafinger);
        $this->assertNull($employee->nama_lengkap);
        $this->assertSame('12', $employee->fingerprint_id);
        $this->assertNull($employee->nip);
        $this->assertNull($employee->position);
        $this->assertSame($category->id, $employee->category_id);
        $this->assertNull($category->start_time);
        $this->assertNull($category->end_time);
        $this->assertTrue($category->is_active);
        $this->assertDatabaseCount('employees', 2);
    }

    public function test_fingerprint_identity_can_be_imported_without_inventing_employee_details(): void
    {
        $unit = $this->createUnit('TK');
        $file = $this->createMatrixFixture(firstName: '');

        $result = app(FingerprintImportService::class)->syncEmployees(
            $this->uploadedFile($file, 'fingerprint-without-name.xls'),
            $unit->id,
        );

        $employee = Employee::query()->where('unit_id', $unit->id)->where('fingerprint_id', '2')->firstOrFail();
        $this->assertSame(2, $result['employees_created']);
        $this->assertNull($employee->namafinger);
        $this->assertNull($employee->nama_lengkap);
        $this->assertNull($employee->nip);
        $this->assertNull($employee->position);
        $this->assertSame('2', $employee->fingerprint_id);
    }

    public function test_sync_endpoint_creates_employees_and_unconfigured_categories_idempotently(): void
    {
        $unit = $this->createUnit('TK');
        $file = $this->createFixture('xls');
        $this->actingAs(User::factory()->create());

        $this->postJson(route('fingerprint-imports.preview'), [
            'unit_id' => $unit->id,
            'file' => $this->uploadedFile($file, 'sync-fixture.xls'),
        ])
            ->assertOk()
            ->assertJsonPath('data.employees_detected', 2);

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('work_schedule_categories', 0);
        $this->assertDatabaseCount('fingerprint_import_batches', 0);
        $this->assertDatabaseCount('fingerprint_logs', 0);

        foreach ([1, 2] as $attempt) {
            $this->postJson(route('fingerprint-imports.sync'), [
                'unit_id' => $unit->id,
                'file' => $this->uploadedFile($file, 'sync-fixture.xls'),
            ])
                ->assertOk()
                ->assertJsonPath('data.employees_created', $attempt === 1 ? 2 : 0);
        }

        $this->assertSame(1, Employee::query()->where('unit_id', $unit->id)->where('fingerprint_id', '12')->count());
        $this->assertSame(1, Employee::query()->where('unit_id', $unit->id)->where('fingerprint_id', '99')->count());
        $this->assertSame(1, WorkScheduleCategory::query()->where('name', 'TK')->count());
        $this->assertSame(1, WorkScheduleCategory::query()->where('name', 'SD')->count());

        $category = WorkScheduleCategory::query()->where('name', 'TK')->firstOrFail();
        $this->assertNull($category->start_time);
        $this->assertNull($category->end_time);
        $this->assertSame($category->id, Employee::query()->where('fingerprint_id', '12')->firstOrFail()->category_id);
        $this->assertDatabaseCount('fingerprint_import_batches', 0);
        $this->assertDatabaseCount('fingerprint_logs', 0);
    }

    public function test_employee_sync_updates_fingerprint_name_and_preserves_manual_data_and_category(): void
    {
        $unit = $this->createUnit('TK');
        $category = WorkScheduleCategory::create([
            'name' => 'TK',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'is_active' => true,
        ]);
        $employee = $this->createEmployee($unit, 'Master Name', '12');
        $employee->update(['category_id' => $category->id]);
        $this->actingAs(User::factory()->create());

        $this->postJson(route('fingerprint-imports.sync'), [
            'unit_id' => $unit->id,
            'file' => $this->uploadedFile($this->createFixture('xls'), 'sync-conflict-fixture.xls'),
        ])
            ->assertOk()
            ->assertJsonPath('data.employees_existing', 1)
            ->assertJsonPath('data.conflicts.0.fingerprint_id', '12')
            ->assertJsonPath('data.conflicts.0.master_name', 'Master Name')
            ->assertJsonPath('data.conflicts.0.file_name', 'Intan~');

        $employee->refresh();
        $this->assertSame('Intan~', $employee->namafinger);
        $this->assertSame('Master Name', $employee->nama_lengkap);
        $this->assertSame($category->id, $employee->category_id);
        $this->assertSame('08:00', $category->fresh()->start_time);
        $this->assertSame('16:00', $category->fresh()->end_time);
        $this->assertSame(1, WorkScheduleCategory::query()->where('name', 'TK')->count());
    }

    public function test_reimport_updates_only_fingerprint_name_for_existing_fingerprint_identity(): void
    {
        $unit = $this->createUnit('TK');
        $firstFile = $this->createFixture('xls');
        app(FingerprintImportService::class)->import($this->uploadedFile($firstFile), $unit->id);

        $employee = Employee::query()->where('unit_id', $unit->id)->where('fingerprint_id', '12')->firstOrFail();
        app(\App\Services\EmployeeService::class)->update($employee, [
            'nama_lengkap' => 'Intan Permata Sari',
            'nip' => '1987654321',
            'position' => 'Guru',
        ]);
        $secondFile = $this->createFixture('xls');
        $spreadsheet = IOFactory::load($secondFile);
        $spreadsheet->getSheet(2)->setCellValue('B5', 'Intan Terbaru');
        IOFactory::createWriter($spreadsheet, 'Xls')->save($secondFile);
        $spreadsheet->disconnectWorksheets();

        app(FingerprintImportService::class)->import($this->uploadedFile($secondFile, 'fingerprint-name-update.xls'), $unit->id);

        $employee->refresh();
        $this->assertSame('Intan Terbaru', $employee->namafinger);
        $this->assertSame('Intan Permata Sari', $employee->nama_lengkap);
        $this->assertSame('1987654321', $employee->nip);
        $this->assertSame('Guru', $employee->position);
        $this->assertSame('12', $employee->fingerprint_id);
        $this->assertSame(1, Employee::query()->where('unit_id', $unit->id)->where('fingerprint_id', '12')->count());
    }

    public function test_employee_sync_rolls_back_employee_and_category_changes_when_sync_fails(): void
    {
        $unit = $this->createUnit('TK');
        WorkScheduleCategory::create([
            'name' => 'TK',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'is_active' => true,
        ]);
        $file = $this->createFixture('xls');
        $this->actingAs(User::factory()->create());

        WorkScheduleCategory::creating(static function (WorkScheduleCategory $category): void {
            if ($category->name === 'SD') {
                throw new \RuntimeException('Simulated category creation failure.');
            }
        });

        $this->postJson(route('fingerprint-imports.sync'), [
            'unit_id' => $unit->id,
            'file' => $this->uploadedFile($file, 'sync-rollback-fixture.xls'),
        ])->assertServerError();

        $this->assertDatabaseMissing('employees', [
            'unit_id' => $unit->id,
            'fingerprint_id' => '12',
        ]);
        $this->assertDatabaseMissing('employees', [
            'unit_id' => $unit->id,
            'fingerprint_id' => '99',
        ]);
        $this->assertDatabaseMissing('work_schedule_categories', ['name' => 'SD']);
        $this->assertDatabaseHas('work_schedule_categories', [
            'name' => 'TK',
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);
        $this->assertDatabaseCount('fingerprint_import_batches', 0);
        $this->assertDatabaseCount('fingerprint_logs', 0);
    }

    public function test_fingerprint_import_history_and_detail_pages_are_accessible(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('TK');
        $batch = FingerprintImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => 'sample-import.xls',
            'file_hash' => hash('sha256', 'history-sample'),
            'status' => ImportBatchStatus::Completed,
            'imported_at' => now(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'total_rows' => 12,
            'success_rows' => 12,
            'error_rows' => 0,
        ]);

        $this->get(route('fingerprint-imports.index'))
            ->assertOk()
            ->assertSee('Riwayat Import Fingerprint')
            ->assertSee($batch->filename);

        $this->get(route('fingerprint-imports.show', $batch))
            ->assertOk()
            ->assertSee('Detail Batch')
            ->assertSee($batch->filename)
            ->assertSee(route('work-calendar.index', ['month' => '2026-08']));
    }

    public function test_import_reuses_existing_employee_by_fingerprint_id_without_overwriting_master_data(): void
    {
        $unit = $this->createUnit('TK');
        $existingCategory = WorkScheduleCategory::create([
            'name' => 'Assigned',
            'start_time' => '07:00',
            'end_time' => '17:00',
            'is_active' => true,
        ]);
        $employee = $this->createEmployee($unit, 'Existing Person', '12');
        $employee->update([
            'category_id' => $existingCategory->id,
            'nip' => '1987654321',
            'position' => 'Supervisor',
        ]);
        $file = $this->createFixture('xls');

        app(FingerprintImportService::class)->import($this->uploadedFile($file), $unit->id);

        $employee->refresh();
        $this->assertSame('Intan~', $employee->namafinger);
        $this->assertSame('Existing Person', $employee->nama_lengkap);
        $this->assertSame('1987654321', $employee->nip);
        $this->assertSame('Supervisor', $employee->position);
        $this->assertSame($existingCategory->id, $employee->category_id);
        $this->assertSame(1, Employee::query()->where('unit_id', $unit->id)->where('fingerprint_id', '12')->count());
    }

    public function test_work_calendar_supports_month_navigation_for_imported_periods(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('TK');
        \App\Models\WorkCalendar::create([
            'unit_id' => $unit->id,
            'date' => '2026-08-10',
            'type' => \App\Enums\WorkCalendarType::SpecialEvent,
            'is_working_day' => true,
            'start_time' => '07:00',
            'end_time' => '15:30',
            'title' => 'Agenda Uji',
        ]);

        $this->get(route('work-calendar.index', ['month' => '2026-08']))
            ->assertOk()
            ->assertSee('Kalender Kerja')
            ->assertSee('Agenda Uji');
    }

    public function test_import_keeps_existing_category_and_does_not_create_duplicate_categories(): void
    {
        $unit = $this->createUnit('TK');
        $existingCategory = WorkScheduleCategory::create([
            'name' => 'TK',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'is_active' => true,
        ]);
        $file = $this->createFixture('xls');

        app(FingerprintImportService::class)->import($this->uploadedFile($file), $unit->id);

        $this->assertSame(1, WorkScheduleCategory::query()->where('name', 'TK')->count());
        $this->assertSame($existingCategory->id, Employee::query()->where('fingerprint_id', '12')->firstOrFail()->category_id);
        $this->assertSame('08:00', $existingCategory->fresh()->start_time);
        $this->assertSame('16:00', $existingCategory->fresh()->end_time);
    }

    public function test_import_handles_empty_department_by_not_creating_category_or_guessing_schedule(): void
    {
        $unit = $this->createUnit('TK');
        $file = $this->createFixture('xls');
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getSheet(2);
        $sheet->setCellValue('E5', '');
        $sheet->setCellValue('E6', '');
        $writer = IOFactory::createWriter($spreadsheet, 'Xls');
        $writer->save($file);
        $spreadsheet->disconnectWorksheets();

        app(FingerprintImportService::class)->import($this->uploadedFile($file), $unit->id);

        $employee = Employee::query()->where('fingerprint_id', '12')->firstOrFail();
        $this->assertNull($employee->category_id);
        $this->assertDatabaseMissing('work_schedule_categories', ['name' => '']);
        $this->assertDatabaseHas('work_schedule_categories', ['name' => 'SD']);
    }

    public function test_import_uses_fingerprint_id_not_name_as_primary_match_key(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Different Name', '12');
        $category = WorkScheduleCategory::create([
            'name' => 'Assigned',
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);
        $employee->update(['category_id' => $category->id]);
        $file = $this->createFixture('xls');

        app(FingerprintImportService::class)->import($this->uploadedFile($file), $unit->id);

        $employee->refresh();
        $this->assertSame('Intan~', $employee->namafinger);
        $this->assertSame($category->id, $employee->category_id);
        $this->assertSame(1, Employee::query()->where('unit_id', $unit->id)->where('fingerprint_id', '12')->count());
    }

    public function test_import_resolves_department_to_assigned_category_and_preserves_source_data(): void
    {
        $unit = $this->createUnit('TK');
        $category = WorkScheduleCategory::create([
            'name' => 'A',
            'start_time' => '06:55',
            'end_time' => '16:00',
        ]);
        $profile = WorkScheduleProfile::query()->create([
            'work_schedule_category_id' => $category->id,
            'name' => 'Profile A',
            'start_time' => '07:00',
            'end_time' => '14:00',
            'is_active' => true,
        ]);
        app(WorkScheduleCategoryService::class)->update($category, [
            'name' => $category->name,
            'start_time' => '06:55',
            'end_time' => '16:00',
            'work_schedule_profile_id' => $profile->id,
        ]);
        $profile->update(['is_active' => false]);
        $employee = $this->createEmployee($unit, 'Arlis', '2');
        $employee->update(['category_id' => $category->id]);
        $file = $this->createMatrixFixture(firstDepartment: 'A', secondDepartment: 'Unknown');

        $this->actingAs(User::factory()->create())
            ->postJson('/fingerprint-imports/preview', [
                'unit_id' => $unit->id,
                'file' => $this->uploadedFile($file, 'category-matrix.xls'),
            ])
            ->assertOk()
            ->assertJsonPath('data.needs_verification_count', 1)
            ->assertJsonPath('data.category_assignments.0.employee_category', 'A')
            ->assertJsonPath('data.category_assignments.0.employee_start_time', '07:00')
            ->assertJsonPath('data.category_assignments.0.employee_end_time', '14:00')
            ->assertJsonPath('data.verification_issues.0.fingerprint_id', '99')
            ->assertJsonPath('data.verification_issues.0.department', 'Unknown');

        $batch = app(FingerprintImportService::class)->import(
            $this->uploadedFile($file, 'category-matrix.xls'),
            $unit->id,
        );

        $matchedLog = FingerprintLog::query()->where('fingerprint_id', '2')->firstOrFail();
        $unmatchedLog = FingerprintLog::query()->where('fingerprint_id', '99')->firstOrFail();

        $this->assertSame(ImportBatchStatus::Completed, $batch->status);
        $this->assertSame($employee->id, $matchedLog->employee_id);
        $this->assertSame('A', $matchedLog->employee_department_raw);
        $this->assertSame($category->id, $matchedLog->source_category_id);
        $this->assertFalse($matchedLog->needs_verification);
        $this->assertNull($matchedLog->verification_note);
        $this->assertSame('06:3013:19', $matchedLog->raw_value);
        $this->assertSame('Arlis', $matchedLog->employee_name_raw);

        $this->assertNotNull($unmatchedLog->employee_id);
        $this->assertSame('Unknown', $unmatchedLog->employee_department_raw);
        $this->assertNotNull($unmatchedLog->source_category_id);
        $this->assertStringNotContainsString('[periode-kerja]', (string) $unmatchedLog->verification_note);
        $this->assertSame(2, Employee::query()->count());
    }

    public function test_reader_accepts_a_valid_standard_report_sheet_with_a_custom_name(): void
    {
        $file = $this->createFixture('xls', rawSheetName: 'Standard Report');

        $report = app(FingerprintExcelReader::class)->read($file);

        $this->assertSame('Standard Report', $report['sheet']);
        $this->assertSame('tabular', $report['format']);
        $this->assertSame('2026-08-01', $report['rows'][0]['attendance_date']);
        $this->assertSame('12', $report['rows'][0]['fingerprint_id']);
        $this->assertSame('Intan~', $report['rows'][0]['employee_name_raw']);
        $this->assertSame('TK', $report['rows'][0]['employee_department_raw']);
        $this->assertSame(['06:30', '13:19'], array_column($report['rows'][0]['timestamps'], 'time'));
        $this->assertSame('2026-08-15', $report['rows'][1]['attendance_date']);
        $this->assertSame(['06:44', '06:44', '12:05'], array_column($report['rows'][1]['timestamps'], 'time'));
    }

    public function test_reader_fails_clearly_when_raw_log_sheet_is_missing(): void
    {
        $file = $this->createFixture('xlsx', includeRawSheet: false);

        $this->expectException(FingerprintImportException::class);
        $this->expectExceptionMessage('Required sheet "Lap. Log Absen" was not found.');

        app(FingerprintExcelReader::class)->read($file);
    }

    public function test_preview_uses_selected_unit_and_does_not_write_import_data(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $this->createEmployee($tk, 'TK Intan', '12');
        $sdEmployee = $this->createEmployee($sd, 'SD Intan', '12');
        $file = $this->createFixture('xls');
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/fingerprint-imports/preview', [
            'unit_id' => $sd->id,
            'file' => $this->uploadedFile($file, 'TK_report.xls'),
        ])->assertOk();

        $response
            ->assertJsonPath('data.filename', 'TK_report.xls')
            ->assertJsonPath('data.unit.code', 'SD')
            ->assertJsonPath('data.detected_sheet', 'Lap. Log Absen')
            ->assertJsonPath('data.period_start', '2026-08-01')
            ->assertJsonPath('data.period_end', '2026-08-31')
            ->assertJsonPath('data.total_rows', 5)
            ->assertJsonPath('data.unique_fingerprint_ids', 2)
            ->assertJsonPath('data.raw_attendance_entries', 9)
            ->assertJsonPath('data.parsed_timestamp_count', 10)
            ->assertJsonPath('data.matched_employees', 1)
            ->assertJsonPath('data.unmatched_employees', 1)
            ->assertJsonPath('data.ambiguous_employees', 0)
            ->assertJsonPath('data.invalid_rows', 2)
            ->assertJsonPath('data.already_imported', false);

        $this->assertSame($sdEmployee->id, app(EmployeeMatchingService::class)
            ->findByFingerprint($sd->id, '12')->employee->id);
        $this->assertDatabaseCount('fingerprint_import_batches', 0);
        $this->assertDatabaseCount('fingerprint_logs', 0);
        $this->assertDatabaseCount('employees', 2);
    }

    public function test_import_persists_every_valid_timestamp_raw_values_and_row_references(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Intan Permata Sari', '12');
        $unknownEmployeeCount = Employee::query()->count();
        $file = $this->createFixture('xls');

        $batch = app(FingerprintImportService::class)->import(
            $this->uploadedFile($file),
            $unit->id,
            User::factory()->create()->id,
        );

        $this->assertSame(ImportBatchStatus::Completed, $batch->status);
        $this->assertSame('2026-08-01', $batch->period_start->format('Y-m-d'));
        $this->assertSame('2026-08-31', $batch->period_end->format('Y-m-d'));
        $this->assertSame(5, $batch->total_rows);
        $this->assertSame(3, $batch->success_rows);
        $this->assertSame(2, $batch->error_rows);
        $this->assertDatabaseCount('fingerprint_logs', 9);
        $this->assertSame($unknownEmployeeCount + 1, Employee::query()->count());

        $knownLogs = FingerprintLog::query()->where('fingerprint_id', '12')->orderBy('id')->get();
        $this->assertCount(5, $knownLogs);
        $this->assertTrue($knownLogs->first()->employee->is($employee));
        $this->assertSame(MatchingStatus::Matched, $knownLogs->first()->matching_status);
        $this->assertSame('06:30:00', $knownLogs[0]->attendance_time);
        $this->assertSame('06:3013:19', $knownLogs[0]->raw_value);
        $this->assertSame('Intan~', $knownLogs[0]->employee_name_raw);
        $this->assertSame('TK', $knownLogs[0]->employee_department_raw);
        $this->assertSame('Lap. Log Absen', $knownLogs[0]->source_sheet);
        $this->assertSame(5, $knownLogs[0]->source_row);
        $this->assertSame('D5', $knownLogs[0]->source_cell);
        $this->assertSame('06:44:00', $knownLogs[2]->attendance_time);
        $this->assertSame('06:44:00', $knownLogs[3]->attendance_time);

        $unknownLogs = FingerprintLog::query()->where('fingerprint_id', '99')->get();
        $this->assertCount(4, $unknownLogs);
        $this->assertNotNull($unknownLogs->first()->employee_id);
        $this->assertSame(MatchingStatus::Matched, $unknownLogs->first()->matching_status);
        $this->assertSame('07:02 10:10 12:05 15:30', $unknownLogs->first()->raw_value);
    }

    public function test_same_file_reuses_existing_batch_for_same_unit_without_hard_rejecting(): void
    {
        $unit = $this->createUnit('TK');
        $file = $this->createFixture('xlsx');
        $service = app(FingerprintImportService::class);

        $firstBatch = $service->import($this->uploadedFile($file), $unit->id);
        $reprocessedBatch = $service->import($this->uploadedFile($file), $unit->id);

        $this->assertSame($firstBatch->id, $reprocessedBatch->id);
        $this->assertSame(1, FingerprintImportBatch::query()->count());
        $this->assertSame(9, FingerprintLog::query()->count());
    }

    public function test_reprocess_import_batch_relinks_unresolved_employee_and_keeps_attendance_idempotent(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Intan', '12');
        $file = $this->createFixture('xls');
        $service = app(FingerprintImportService::class);

        $batch = $service->import($this->uploadedFile($file), $unit->id);
        FingerprintLog::query()->where('import_batch_id', $batch->id)->update([
            'employee_id' => null,
            'matching_status' => MatchingStatus::Unmatched->value,
        ]);

        $reprocessed = $service->reprocessImportBatch($batch, User::factory()->create()->id);

        $this->assertSame($batch->id, $reprocessed->id);
        $this->assertSame($employee->id, FingerprintLog::query()->where('import_batch_id', $batch->id)->first()->employee_id);
        $this->assertSame(9, FingerprintLog::query()->where('import_batch_id', $batch->id)->count());

        $attendanceCountBefore = \App\Models\AttendanceRecord::query()->where('employee_id', $employee->id)->count();
        $this->assertGreaterThan(0, $attendanceCountBefore);

        $reprocessedAgain = $service->reprocessImportBatch($batch, User::factory()->create()->id);
        $this->assertSame($batch->id, $reprocessedAgain->id);
        $this->assertSame($attendanceCountBefore, \App\Models\AttendanceRecord::query()->where('employee_id', $employee->id)->count());
    }

    public function test_authenticated_final_import_endpoint_returns_batch_and_duplicate_conflict(): void
    {
        $unit = $this->createUnit('TK');
        $this->createEmployee($unit, 'Intan', '12');
        $file = $this->createFixture('xls');
        $this->actingAs(User::factory()->create());

        $this->postJson('/fingerprint-imports', [
            'unit_id' => $unit->id,
            'file' => $this->uploadedFile($file),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', ImportBatchStatus::Completed->value)
            ->assertJsonPath('data.success_rows', 3)
            ->assertJsonPath('data.error_rows', 2);

        $this->assertDatabaseCount('fingerprint_logs', 9);

        $this->postJson('/fingerprint-imports', [
            'unit_id' => $unit->id,
            'file' => $this->uploadedFile($file),
        ])
            ->assertCreated()
            ->assertJsonPath('data.id', 1);

        $this->assertDatabaseCount('fingerprint_import_batches', 1);
        $this->assertDatabaseCount('fingerprint_logs', 9);
    }

    public function test_reprocess_rebuilds_missing_employee_master_from_raw_logs_and_redirects_html_request(): void
    {
        $unit = $this->createUnit('TK');
        $file = $this->createFixture('xls');
        $batch = app(FingerprintImportService::class)->import($this->uploadedFile($file), $unit->id);

        Employee::query()->where('unit_id', $unit->id)->delete();
        $this->assertDatabaseMissing('employees', ['unit_id' => $unit->id, 'fingerprint_id' => '12']);

        $this->actingAs(User::factory()->create())
            ->from(route('fingerprint-imports.show', $batch))
            ->post(route('fingerprint-imports.reprocess', $batch))
            ->assertRedirect(route('fingerprint-imports.show', $batch));

        $employee = Employee::query()->where('unit_id', $unit->id)->where('fingerprint_id', '12')->firstOrFail();
        $this->assertSame('Intan~', $employee->namafinger);
        $this->assertGreaterThan(0, FingerprintLog::query()->where('import_batch_id', $batch->id)->where('employee_id', $employee->id)->count());
        $this->assertGreaterThan(0, \App\Models\AttendanceRecord::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(0, FingerprintLog::query()
            ->where('import_batch_id', $batch->id)
            ->where('employee_id', $employee->id)
            ->where('verification_note', 'like', '%[periode-kerja]%')
            ->count());
    }

    public function test_reprocess_succeeds_when_original_excel_source_has_been_renamed_or_removed(): void
    {
        $unit = $this->createUnit('TK');
        $file = $this->createFixture('xlsx');
        $batch = app(FingerprintImportService::class)->import($this->uploadedFile($file), $unit->id);

        $this->assertDatabaseCount('fingerprint_logs', 9);

        $renamedPath = dirname($file).'/renamed-source-removed.xlsx';
        if (is_file($file)) {
            rename($file, $renamedPath);
        }

        $this->actingAs(User::factory()->create())
            ->from(route('fingerprint-imports.show', $batch))
            ->post(route('fingerprint-imports.reprocess', $batch))
            ->assertRedirect(route('fingerprint-imports.show', $batch));

        $this->assertSame(1, FingerprintImportBatch::query()->whereKey($batch->id)->where('status', ImportBatchStatus::Completed->value)->count());
        $this->assertSame(9, FingerprintLog::query()->where('import_batch_id', $batch->id)->count());
        $this->assertGreaterThan(0, \App\Models\AttendanceRecord::query()->where('unit_id', $unit->id)->count());
        $this->assertSame(0, FingerprintLog::query()
            ->where('import_batch_id', $batch->id)
            ->where('verification_note', 'like', '%[periode-kerja]%')
            ->count());

        if (is_file($renamedPath)) {
            unlink($renamedPath);
        }
    }

    public function test_reprocessing_inactive_employee_preserves_record_and_reports_review_case(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Inactive history', '12');
        $batch = app(FingerprintImportService::class)->import($this->uploadedFile($this->createFixture('xls')), $unit->id);
        $date = $batch->period_start->format('Y-m-d');
        $employee->update(['status' => \App\Enums\EmployeeStatus::Inactive]);
        $record = \App\Models\AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'attendance_date' => $date,
            'status' => \App\Enums\AttendanceStatus::Present,
            'scheduled_in' => '07:00',
            'actual_in' => '07:00',
            'scheduled_out' => '15:30',
            'actual_out' => '15:30',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'is_holiday' => false,
            'activity_on_holiday' => false,
            'needs_verification' => false,
            'verification_note' => 'Record historis',
        ]);
        $log = FingerprintLog::query()->where('import_batch_id', $batch->id)->firstOrFail();
        $log->update([
            'verification_note' => '[periode-kerja] Catatan historis',
            'needs_verification' => true,
        ]);
        $original = $record->fresh()->toArray();

        $this->actingAs(User::factory()->create())
            ->postJson(route('fingerprint-imports.reprocess', $batch))
            ->assertOk()
            ->assertJsonPath('processing_summary.failed', 0)
            ->assertJsonPath('processing_summary.preserved_ineligible_attendance', 1)
            ->assertJsonPath('processing_summary.review_cases.0.attendance_record_id', $record->id);

        $this->assertSame($original, $record->fresh()->toArray());
        $this->assertSame('[periode-kerja] Catatan historis', $log->fresh()->verification_note);
        $this->assertTrue($log->fresh()->needs_verification);
        $this->assertSame(1, \App\Models\AttendanceRecord::query()->where('employee_id', $employee->id)->count());

        $this->from(route('fingerprint-imports.show', $batch))
            ->post(route('fingerprint-imports.reprocess', $batch))
            ->assertRedirect(route('fingerprint-imports.show', $batch));
        $this->get(route('fingerprint-imports.show', $batch))
            ->assertSee('Dilewati (nonaktif)')
            ->assertSee('Record yang dipertahankan untuk pemeriksaan')
            ->assertSee('status nonaktif')
            ->assertSee('Catatan verifikasi historis')
            ->assertSee('[periode-kerja] Catatan historis');
    }

    public function test_reprocess_response_reports_partial_attendance_failure_without_hiding_it_as_success(): void
    {
        $unit = $this->createUnit('TK');
        $this->createEmployee($unit, 'Process failure', '12');
        $batch = app(FingerprintImportService::class)->import($this->uploadedFile($this->createFixture('xls')), $unit->id);
        $injected = false;

        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if (
                ! $injected
                && str_starts_with(strtolower(trim($query->sql)), 'insert')
                && str_contains(strtolower($query->sql), 'attendance_records')
            ) {
                $injected = true;
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new \RuntimeException('Injected processing failure.'));
            }
        });

        $this->actingAs(User::factory()->create())
            ->postJson(route('fingerprint-imports.reprocess', $batch))
            ->assertOk()
            ->assertJsonPath('data.status', ImportBatchStatus::Completed->value)
            ->assertJsonPath('processing_summary.failed', 1)
            ->assertJsonPath('processing_summary.failed_cases.0.employee_id', Employee::query()->where('unit_id', $unit->id)->value('id'));

        $this->assertTrue($injected);
        $this->assertGreaterThan(0, \App\Models\AttendanceRecord::query()->where('unit_id', $unit->id)->count());
    }

    public function test_fatal_database_failure_rolls_back_logs_and_marks_batch_failed(): void
    {
        $unit = $this->createUnit('TK');
        $this->createEmployee($unit, 'Intan', '12');
        $file = $this->createFixture('xlsx');
        $injected = false;

        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            $sql = strtolower(str_replace(['`', '"'], '', trim($query->sql)));

            if (! $injected && str_starts_with($sql, 'insert into fingerprint_logs')) {
                $injected = true;
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new \RuntimeException('Injected insert failure.'));
            }
        });

        try {
            app(FingerprintImportService::class)->import($this->uploadedFile($file), $unit->id);
            $this->fail('The injected storage error should fail the import.');
        } catch (FingerprintImportException $exception) {
            $this->assertSame('Fingerprint import failed. No partial logs were committed.', $exception->getMessage());
        }

        $this->assertTrue($injected);
        $this->assertDatabaseCount('fingerprint_logs', 0);
        $this->assertDatabaseCount('fingerprint_import_batches', 1);
        $this->assertSame(ImportBatchStatus::Failed, FingerprintImportBatch::query()->first()->status);
    }

    public function test_import_keeps_every_valid_raw_scan_per_employee_date_and_batch_without_deduplication(): void
    {
        $unit = $this->createUnit('TK');
        $employee101 = $this->createEmployee($unit, 'Employee 101', '101');
        $employee202 = $this->createEmployee($unit, 'Employee 202', '202');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Lap. Log Absen');
        $sheet->fromArray(['ID', 'Nama', 'Tanggal', 'Waktu', 'Departemen'], null, 'A4');
        $sheet->fromArray([101, 'Employee 101', '2026-08-01', '06:5506:5614:0014:01', 'TK'], null, 'A5');
        $sheet->fromArray([101, 'Employee 101', '2026-08-02', '09:00', 'TK'], null, 'A6');
        $sheet->fromArray([202, 'Employee 202', '2026-08-02', '07:15', 'SD'], null, 'A7');
        $sheet->fromArray([101, 'Employee 101', '2026-08-03', '08:3008:35', 'TK'], null, 'A8');

        $path = tempnam(sys_get_temp_dir(), 'fingerprint-raw-scan-');
        unlink($path);
        $path .= '.xls';
        IOFactory::createWriter($spreadsheet, 'Xls')->save($path);
        $spreadsheet->disconnectWorksheets();

        $batch = app(FingerprintImportService::class)->import($this->uploadedFile($path), $unit->id);

        $logs101 = FingerprintLog::query()->where('fingerprint_id', '101')->orderBy('attendance_date')->orderBy('attendance_time')->get();
        $logs202 = FingerprintLog::query()->where('fingerprint_id', '202')->get();

        $this->assertSame(1, FingerprintImportBatch::query()->count());
        $this->assertCount(7, $logs101);
        $this->assertCount(1, $logs202);
        $this->assertSame(['2026-08-01', '2026-08-01', '2026-08-01', '2026-08-01', '2026-08-02', '2026-08-03', '2026-08-03'], $logs101->pluck('attendance_date')->map(fn ($date) => substr((string) $date, 0, 10))->all());
        $this->assertSame([$employee101->id, $employee101->id, $employee101->id, $employee101->id, $employee101->id, $employee101->id, $employee101->id], $logs101->pluck('employee_id')->all());
        $this->assertSame([$batch->id, $batch->id, $batch->id, $batch->id, $batch->id, $batch->id, $batch->id], $logs101->pluck('import_batch_id')->all());
        $this->assertSame(['06:55', '06:56', '14:00', '14:01', '09:00', '08:30', '08:35'], $logs101->pluck('attendance_time')->map(fn ($time) => substr((string) $time, 0, 5))->all());
        $this->assertSame('Employee 101', $logs101->first()->employee_name_raw);
        $this->assertSame('TK', $logs101->first()->employee_department_raw);
        $this->assertSame(3, $logs101->pluck('attendance_date')->map(fn ($date) => substr((string) $date, 0, 10))->unique()->count());
        $this->assertSame($batch->id, $logs202->first()->import_batch_id);
        $this->assertSame($employee202->id, $logs202->first()->employee_id);
    }

    public function test_import_routes_require_authentication_and_unit_is_required(): void
    {
        $this->postJson('/fingerprint-imports/preview')->assertUnauthorized();
        $this->postJson('/fingerprint-imports')->assertUnauthorized();

        $unit = $this->createUnit('TK');
        $file = $this->createFixture('xls');

        $this->actingAs(User::factory()->create())
            ->postJson('/fingerprint-imports/preview', ['file' => $this->uploadedFile($file)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id');

        $this->assertDatabaseCount('fingerprint_import_batches', 0);
        $this->assertDatabaseCount('fingerprint_logs', 0);
        $this->assertDatabaseHas('units', ['id' => $unit->id]);
    }

    private function createFixture(
        string $extension,
        bool $includeRawSheet = true,
        int $rawSheetPosition = 3,
        string $rawSheetName = 'Lap. Log Absen',
    ): string {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Jadwal Info');
        $spreadsheet->createSheet()->setTitle('Stat. Absen');

        if ($includeRawSheet) {
            $rawSheet = $spreadsheet->createSheet($rawSheetPosition - 1);
            $rawSheet->setTitle($rawSheetName);
            $rawSheet->setCellValue('A1', 'Fingerprint attendance log');
            $rawSheet->setCellValue('A2', 'Report period: August 2026');
            $rawSheet->fromArray(['ID', 'Nama', 'Tanggal', 'Waktu', 'Departemen'], null, 'A4');
            $rawSheet->fromArray([12, 'Intan~', ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-08-01')), '06:3013:19', 'TK'], null, 'A5');
            $rawSheet->getStyle('C5')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $rawSheet->fromArray([12, 'Intan~', '2026-08-15', '06:4406:4412:05', 'TK'], null, 'A6');
            $rawSheet->fromArray([99, 'Unknown', '31/08/2026', '07:02 10:10 12:05 15:30', 'SD'], null, 'A7');
            $rawSheet->fromArray([12, 'Intan~', '2026-08-20', '25:70', 'TK'], null, 'A8');
            $rawSheet->fromArray([12, 'Intan~', 'not-a-date', '07:00', 'TK'], null, 'A9');
        }

        $spreadsheet->createSheet()->setTitle('Exception Stat.');
        $path = tempnam(sys_get_temp_dir(), 'fingerprint-import-');
        unlink($path);
        $path .= '.'.$extension;
        $writer = IOFactory::createWriter($spreadsheet, $extension === 'xls' ? 'Xls' : 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function createMatrixFixture(
        string $month = '08',
        string $firstDepartment = 'TK',
        string $secondDepartment = 'SD',
        string $firstName = 'Arlis',
    ): string
    {
        $spreadsheet = new Spreadsheet;
        $rawSheet = $spreadsheet->getActiveSheet();
        $rawSheet->setTitle('Lap. Log Absen');
        $rawSheet->setCellValue('A1', "Period: 01/{$month}/2026 - 31/{$month}/2026");
        $rawSheet->setCellValue('A2', 'Waktu Absen');

        for ($day = 1; $day <= 31; $day++) {
            $rawSheet->setCellValue([$day + 1, 2], $day);
        }

        $rawSheet->setCellValue('A3', 'ID:');
        $rawSheet->setCellValue('B3', '2');
        $rawSheet->setCellValue('D3', 'Nama:');
        $rawSheet->setCellValue('E3', $firstName);
        $rawSheet->setCellValue('G3', 'Dept.:');
        $rawSheet->setCellValue('H3', $firstDepartment);
        $rawSheet->setCellValue('B4', '06:3013:19');
        $rawSheet->setCellValue('C4', '06:1709:43');
        $rawSheet->setCellValue('D4', '06:32 13:08');
        $rawSheet->setCellValue('E4', '06:16');
        $rawSheet->setCellValue('F4', '06:4406:4412:05');
        $rawSheet->setCellValue('G4', '25:70');
        $rawSheet->setCellValue('AF4', '07:31');

        $rawSheet->setCellValue('A5', 'ID:');
        $rawSheet->setCellValue('B5', '99');
        $rawSheet->setCellValue('D5', 'Nama:');
        $rawSheet->setCellValue('E5', 'Unmatched');
        $rawSheet->setCellValue('G5', 'Dept.:');
        $rawSheet->setCellValue('H5', $secondDepartment);
        $rawSheet->setCellValue('B6', '07:0007:0007:0012:02');
        $rawSheet->setCellValue('AF6', '08:31');

        $path = tempnam(sys_get_temp_dir(), 'fingerprint-matrix-');
        unlink($path);
        $path .= '.xls';
        IOFactory::createWriter($spreadsheet, 'Xls')->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function uploadedFile(string $path, string $filename = ''): UploadedFile
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = $filename !== '' ? $filename : 'StandardReport.'.$extension;
        $mimeType = $extension === 'xls'
            ? 'application/vnd.ms-excel'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return new UploadedFile($path, $filename, $mimeType, null, true);
    }

    private function createUnit(string $code): Unit
    {
        return Unit::create(['name' => $code, 'code' => $code]);
    }

    private function createEmployee(Unit $unit, string $name, string $fingerprintId): Employee
    {
        $employee = Employee::create([
            'unit_id' => $unit->id,
            'fingerprint_id' => $fingerprintId,
            'namafinger' => $name,
            'nama_lengkap' => $name,
            'status' => 'active',
        ]);
        return $employee;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }
}
