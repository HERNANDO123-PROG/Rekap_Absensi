<?php

namespace Tests\Feature;

use App\Enums\AbsenceReason;
use App\Enums\EmployeeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Models\Employee;
use App\Models\EmployeeAlias;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\User;
use App\Services\Leave\LeaveDateParser;
use App\Services\Leave\LeaveDuplicateImportException;
use App\Services\Leave\LeaveFormFileReader;
use App\Services\Leave\LeaveFormHeaderMapper;
use App\Services\Leave\LeaveImportException;
use App\Services\Leave\LeaveImportService;
use App\Services\Leave\LeaveReasonClassifier;
use App\Services\Leave\LeaveTimeParser;
use App\Services\NameNormalizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use SplFileObject;
use Tests\TestCase;

class LeaveImportTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    public function test_header_mapper_uses_names_normalizes_whitespace_and_ignores_additional_headers(): void
    {
        $headers = [
            'Timestamp',
            " Keterangan Izin\n (Jelaskan)* ",
            'Nama   Lengkap dan Gelar *',
            "Dokumentasi\r\n(Khusus Perjalanan Dinas)",
            'Keperluan Izin*',
            "Jam Kepulangan / Jam Perkiraan Kehadiran / Jam Kembali\n(jika tidak hadir/Dinas maka tulis 00.00)*",
            'Tanggal Pengajuan Izin*',
            'Jabatan*',
            'Catatan Tambahan',
        ];

        $mapping = app(LeaveFormHeaderMapper::class)->map($headers);

        $this->assertSame([], $mapping['missing_fields']);
        $this->assertSame([], $mapping['ambiguous_fields']);
        $this->assertSame(2, $mapping['mapping']['raw_name']);
        $this->assertSame(6, $mapping['mapping']['request_date']);
        $this->assertSame('Timestamp', $mapping['additional_headers'][0]['header']);
        $this->assertSame('Catatan Tambahan', $mapping['additional_headers'][1]['header']);
        $this->assertArrayNotHasKey('timestamp', $mapping['mapping']);

        $withoutAttachment = array_values(array_filter($headers, static fn (string $header): bool => ! str_contains($header, 'Dokumentasi')));
        $optionalAttachmentMapping = app(LeaveFormHeaderMapper::class)->map($withoutAttachment);
        $this->assertSame([], $optionalAttachmentMapping['missing_fields']);
        $this->assertArrayNotHasKey('attachment', $optionalAttachmentMapping['mapping']);
    }

    public function test_missing_and_ambiguous_required_headers_are_reported(): void
    {
        $mapper = app(LeaveFormHeaderMapper::class);
        $headers = ['Nama Lengkap dan Gelar', 'Jabatan', 'Tanggal Pengajuan Izin'];

        $this->assertContains('request_time', $mapper->map($headers)['missing_fields']);

        $ambiguous = $mapper->map([
            ...$headers,
            'Jam Kepulangan / Jam Perkiraan Kehadiran / Jam Kembali (jika tidak hadir/Dinas maka tulis 00.00)',
            'Keperluan Izin',
            'Keterangan Izin (Jelaskan)',
            'Nama Lengkap dan Gelar*',
        ]);

        $this->assertContains('raw_name', $ambiguous['ambiguous_fields']);
    }

    public function test_xls_xlsx_and_csv_read_headers_by_name(): void
    {
        foreach (['xls', 'xlsx', 'csv'] as $extension) {
            $file = $this->createFixture($extension, [$this->validRow()]);
            $data = app(LeaveFormFileReader::class)->read($file, $extension);

            $this->assertSame('Timestamp', $data['headers'][0]);
            $this->assertSame([], $data['header_mapping']['missing_fields']);
            $this->assertSame(2, $data['header_mapping']['mapping']['raw_name']);
            $this->assertCount(1, $data['rows']);
        }
    }

    public function test_request_time_and_shared_date_parser_normalize_expected_values(): void
    {
        $timeParser = app(LeaveTimeParser::class);

        $this->assertSame('07:00', $timeParser->parse('07.00'));
        $this->assertSame('07:30', $timeParser->parse('07:30'));
        $this->assertSame('07:30', $timeParser->parse('07:30:00'));
        $this->assertSame('00:00', $timeParser->parse('00.00'));
        $this->assertSame('2026-08-01', app(LeaveDateParser::class)->parse('01/08/2026'));
        $this->assertSame('2026-08-01', app(LeaveDateParser::class)->parse(ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-08-01'))));

        foreach (['24.00', '12:99', '07:30:75', 'not-a-time'] as $invalidTime) {
            try {
                $timeParser->parse($invalidTime);
                $this->fail("Invalid time {$invalidTime} should be rejected.");
            } catch (LeaveImportException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_reason_classifier_is_deterministic_and_only_used_for_absence_type(): void
    {
        $classifier = app(LeaveReasonClassifier::class);

        $this->assertSame(AbsenceReason::Sick->value, $classifier->classify('demam dan tidak enak badan'));
        $this->assertSame(AbsenceReason::Permission->value, $classifier->classify('ada urusan keluarga'));
        $this->assertSame(AbsenceReason::Permission->value, $classifier->classify('keperluan pribadi'));
        $this->assertSame(AbsenceReason::Unknown->value, $classifier->classify('tidak bisa masuk'));
    }

    public function test_preview_is_read_only_and_reports_mapping_matching_period_and_distributions(): void
    {
        $unit = $this->createUnit('TK');
        $this->createMatchingEmployees($unit);
        $otherUnit = $this->createUnit('SD');
        $this->createEmployee($otherUnit, 'Siti Aisyah, S.Pd.');
        $file = $this->createFixture('xlsx', $this->fixtureRows());
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/leave-imports/preview', [
            'unit_id' => $unit->id,
            'file' => $this->uploadedFile($file, 'guess-SD.xlsx'),
        ])->assertOk();
        $response
            ->assertJsonPath('data.unit.code', 'TK')
            ->assertJsonPath('data.detected_headers.0', 'Timestamp')
            ->assertJsonPath('data.missing_fields', [])
            ->assertJsonPath('data.period_start', '2026-08-01')
            ->assertJsonPath('data.period_end', '2026-08-31')
            ->assertJsonPath('data.total_rows', 12)
            ->assertJsonPath('data.valid_rows', 10)
            ->assertJsonPath('data.invalid_rows', 2)
            ->assertJsonPath('data.matched', 7)
            ->assertJsonPath('data.unmatched', 2)
            ->assertJsonPath('data.ambiguous', 1)
            ->assertJsonPath('data.leave_type_distribution.terlambat', 3)
            ->assertJsonPath('data.leave_type_distribution.tidak_hadir', 3)
            ->assertJsonPath('data.leave_type_distribution.pulang_awal', 2)
            ->assertJsonPath('data.leave_type_distribution.dinas', 1)
            ->assertJsonPath('data.leave_type_distribution.keluar', 1)
            ->assertJsonPath('data.absence_reason_distribution.sakit', 1)
            ->assertJsonPath('data.absence_reason_distribution.izin', 1)
            ->assertJsonPath('data.absence_reason_distribution.tidak_diketahui', 1);

        $ignoredHeaders = array_column($response->json('data.ignored_additional_columns'), 'header');
        $this->assertContains('Timestamp', $ignoredHeaders);
        $this->assertContains('Kolom Ekstra', $ignoredHeaders);
        $this->assertDatabaseCount('leave_import_batches', 0);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_final_import_preserves_source_values_matches_only_selected_unit_and_classifies_absences(): void
    {
        $unit = $this->createUnit('TK');
        $employees = $this->createMatchingEmployees($unit);
        $file = $this->createFixture('xls', $this->fixtureRows());
        $user = User::factory()->create();

        $batch = app(LeaveImportService::class)->import($this->uploadedFile($file), $unit->id, $user->id);

        $this->assertSame(ImportBatchStatus::Completed, $batch->status);
        $this->assertSame(12, $batch->total_rows);
        $this->assertSame(10, $batch->success_rows);
        $this->assertSame(2, $batch->error_rows);
        $this->assertSame('2026-08-01', $batch->period_start->format('Y-m-d'));
        $this->assertSame('2026-08-31', $batch->period_end->format('Y-m-d'));
        $this->assertSame($user->id, $batch->imported_by);
        $this->assertDatabaseCount('leave_requests', 10);

        $requests = LeaveRequest::query()->orderBy('id')->get();
        $late = $requests[0];
        $sick = $requests[1];
        $permission = $requests[2];
        $unknownReason = $requests[3];
        $alias = $requests[4];
        $duty = $requests[5];
        $temporaryExit = $requests[6];
        $unmatched = $requests[7];
        $ambiguous = $requests[8];
        $inactive = $requests[9];

        $this->assertSame(' Siti Aisyah, S.Pd. ', $late->raw_name);
        $this->assertSame('siti aisyah', $late->normalized_name);
        $this->assertSame($employees['official']->id, $late->employee_id);
        $this->assertSame(MatchingStatus::Matched, $late->matching_status);
        $this->assertSame(LeaveType::Late, $late->leave_type);
        $this->assertSame('Terlambat', $late->raw_leave_type);
        $this->assertSame('07:00:00', $late->request_time);
        $this->assertSame('Alasan sumber tidak diubah.', $late->description);

        $this->assertSame(LeaveType::Absent, $sick->leave_type);
        $this->assertSame(AbsenceReason::Sick, $sick->absence_reason);
        $this->assertSame('00:00:00', $sick->request_time);
        $this->assertSame(LeaveType::Absent, $permission->leave_type);
        $this->assertSame(AbsenceReason::Permission, $permission->absence_reason);
        $this->assertSame(AbsenceReason::Unknown, $unknownReason->absence_reason);
        $this->assertNull($late->absence_reason);

        $this->assertSame($employees['official']->id, $alias->employee_id);
        $this->assertSame('Siti Alias', $alias->raw_name);
        $this->assertSame(LeaveType::OfficialDuty, $duty->leave_type);
        $this->assertSame('Perjalanan Dinas/Tugas Resmi', $duty->raw_leave_type);
        $this->assertSame('https://drive.google.com/file/d/example/view', $duty->attachment);
        $this->assertSame(LeaveType::TemporaryExit, $temporaryExit->leave_type);
        $this->assertSame('00:00:00', $temporaryExit->request_time);

        $this->assertNull($unmatched->employee_id);
        $this->assertSame(MatchingStatus::Unmatched, $unmatched->matching_status);
        $this->assertNull($inactive->employee_id);
        $this->assertSame(MatchingStatus::Unmatched, $inactive->matching_status);
        $this->assertNull($ambiguous->employee_id);
        $this->assertSame(MatchingStatus::Ambiguous, $ambiguous->matching_status);

        $this->assertSame(2, count($batch->error_details));
        $this->assertSame('Keperluan Baru', $batch->error_details[0]['raw_leave_type']);
        $this->assertSame('46245', $batch->error_details[0]['raw_date']);
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertSame(4, Employee::query()->count());
    }

    public function test_same_file_is_rejected_for_same_unit_and_allowed_for_another_unit(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $file = $this->createFixture('csv', [$this->validRow()]);
        $service = app(LeaveImportService::class);
        $first = $service->import($this->uploadedFile($file), $tk->id);

        try {
            $service->import($this->uploadedFile($file), $tk->id);
            $this->fail('A completed file cannot be imported twice for the same unit.');
        } catch (LeaveDuplicateImportException $exception) {
            $this->assertSame('File ini sudah pernah diimport untuk unit tersebut.', $exception->getMessage());
        }

        $second = $service->import($this->uploadedFile($file), $sd->id);

        $this->assertSame($first->file_hash, $second->file_hash);
        $this->assertDatabaseCount('leave_import_batches', 2);
        $this->assertDatabaseCount('leave_requests', 2);
    }

    public function test_missing_headers_are_clear_and_unit_is_never_inferred(): void
    {
        $unit = $this->createUnit('TK');
        $file = $this->createFixture('xlsx', [$this->validRow()], includeRequiredDescription: false);
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/leave-imports/preview', [
            'unit_id' => $unit->id,
            'file' => $this->uploadedFile($file, 'report-SD.xlsx'),
        ])->assertUnprocessable();

        $response
            ->assertJsonPath('message', 'Required Google Form headers are missing or ambiguous.')
            ->assertJsonPath('details.missing_fields.0', 'description');

        $this->postJson('/leave-imports/preview', [
            'file' => $this->uploadedFile($file),
        ])->assertUnprocessable()->assertJsonValidationErrors('unit_id');

        $this->assertDatabaseCount('leave_import_batches', 0);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_fatal_request_insert_rolls_back_all_requests_and_batch_is_failed(): void
    {
        $unit = $this->createUnit('TK');
        $file = $this->createFixture('xlsx', [$this->validRow(), $this->validRow()]);
        $injected = false;

        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            $sql = strtolower(trim($query->sql));
            if (! $injected && str_starts_with($sql, 'insert') && str_contains($sql, 'leave_requests')) {
                $injected = true;
                throw new QueryException('sqlite', $query->sql, $query->bindings, new \RuntimeException('Injected insert failure.'));
            }
        });

        try {
            app(LeaveImportService::class)->import($this->uploadedFile($file), $unit->id);
            $this->fail('The injected request insertion failure should abort import.');
        } catch (LeaveImportException $exception) {
            $this->assertSame('Leave import failed. No partial requests were committed.', $exception->getMessage());
        }

        $this->assertTrue($injected);
        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_import_batches', 0);
    }

    public function test_all_leave_import_endpoints_require_authentication_and_final_import_returns_batch(): void
    {
        $unit = $this->createUnit('TK');
        $this->postJson('/leave-imports/preview')->assertUnauthorized();
        $this->postJson('/leave-imports')->assertUnauthorized();

        $file = $this->createFixture('xlsx', [$this->validRow()]);
        $this->actingAs(User::factory()->create());
        $this->postJson('/leave-imports', [
            'unit_id' => $unit->id,
            'file' => $this->uploadedFile($file),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', ImportBatchStatus::Completed->value)
            ->assertJsonPath('data.success_rows', 1)
            ->assertJsonPath('data.unit.code', 'TK');
    }

    private function fixtureRows(): array
    {
        return [
            $this->validRow(' Siti Aisyah, S.Pd. ', 'Terlambat', 'Alasan sumber tidak diubah.', '07.00', '', '2026-08-01'),
            $this->validRow('Siti Aisyah, S.Pd.', 'Tidak Hadir', 'demam dan tidak enak badan', '00.00', '', '2026-08-02'),
            $this->validRow('Siti Aisyah, S.Pd.', 'Tidak Hadir', 'ada urusan keluarga', '00.00', '', '2026-08-03'),
            $this->validRow('Siti Aisyah, S.Pd.', 'Tidak Hadir', 'tidak bisa masuk', '00.00', '', '2026-08-04'),
            $this->validRow('Siti Alias', 'Pulang Lebih Awal', 'Ada keperluan.', '13:30:00', '', '2026-08-05'),
            $this->validRow('Siti Aisyah, S.Pd.', 'Perjalanan Dinas/Tugas Resmi', 'Kegiatan resmi.', '00.00', 'https://drive.google.com/file/d/example/view', '2026-08-31'),
            $this->validRow('Siti Aisyah, S.Pd.', 'Keluar', 'Keperluan singkat.', '00.00', '', '2026-08-07'),
            $this->validRow('Nama Tidak Terdaftar', 'Terlambat', 'Kendala perjalanan.', '07:30', '', '2026-08-08'),
            $this->validRow('Nama Ganda', 'Pulang Lebih Awal', 'Urusan keluarga.', '14:00', '', '2026-08-09'),
            $this->validRow('Pegawai Nonaktif', 'Terlambat', 'Ada urusan.', '07:15', '', '2026-08-10'),
            $this->validRow('Siti Aisyah, S.Pd.', 'Keperluan Baru', 'Keterangan.', '09:00', '', '2026-08-11'),
            $this->validRow('Siti Aisyah, S.Pd.', 'Keluar', 'Keterangan.', '10:00', '', 'not-a-date'),
        ];
    }

    private function validRow(
        string $name = 'Siti Aisyah, S.Pd.',
        string $leaveType = 'Keluar',
        string $description = 'Keperluan pribadi.',
        string $time = '10.10',
        string $attachment = '',
        string $date = '2026-08-01',
    ): array {
        return [
            'Timestamp' => '2026-08-01 06:00:00',
            'description' => $description,
            'raw_name' => $name,
            'attachment' => $attachment,
            'leave_type' => $leaveType,
            'request_time' => $time,
            'request_date' => $date,
            'position' => 'Guru',
            'Kolom Ekstra' => 'ignored',
        ];
    }

    private function createFixture(
        string $extension,
        array $rows,
        bool $includeRequiredDescription = true,
    ): string {
        $headers = [
            'Timestamp',
            "Keterangan Izin\n(Jelaskan)*",
            'Nama Lengkap dan Gelar*',
            "Dokumentasi\n(Khusus Perjalanan Dinas)",
            'Keperluan Izin*',
            "Jam Kepulangan / Jam Perkiraan Kehadiran / Jam Kembali\n(jika tidak hadir/Dinas maka tulis 00.00)*",
            'Tanggal Pengajuan Izin*',
            'Jabatan*',
            'Kolom Ekstra',
        ];

        if (! $includeRequiredDescription) {
            $headers = array_values(array_filter($headers, static fn (string $header): bool => ! str_contains($header, 'Keterangan Izin')));
        }

        if ($extension === 'csv') {
            $path = tempnam(sys_get_temp_dir(), 'leave-import-').'.csv';
            $file = new SplFileObject($path, 'w');
            $file->fputcsv($headers);

            foreach ($rows as $row) {
                if ($row === []) {
                    $file->fputcsv(array_fill(0, count($headers), ''));

                    continue;
                }

                $file->fputcsv($this->rowValues($headers, $row));
            }

            unset($file);
            $this->temporaryFiles[] = $path;

            return $path;
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Form Responses');
        $sheet->fromArray($headers, null, 'A1');

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $values = $row === [] ? array_fill(0, count($headers), '') : $this->rowValues($headers, $row);
            $sheet->fromArray($values, null, 'A'.$rowNumber);

            $timeColumn = array_search("Jam Kepulangan / Jam Perkiraan Kehadiran / Jam Kembali\n(jika tidak hadir/Dinas maka tulis 00.00)*", $headers, true);
            if ($timeColumn !== false && isset($row['request_time'])) {
                $column = Coordinate::stringFromColumnIndex($timeColumn + 1);
                $sheet->setCellValueExplicit($column.$rowNumber, $row['request_time'], DataType::TYPE_STRING);
            }

            $dateColumn = array_search('Tanggal Pengajuan Izin*', $headers, true);
            if ($dateColumn !== false && isset($row['request_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['request_date']) === 1) {
                $column = Coordinate::stringFromColumnIndex($dateColumn + 1);
                $sheet->setCellValue($column.$rowNumber, ExcelDate::PHPToExcel(new \DateTimeImmutable($row['request_date'])));
                $sheet->getStyle($column.$rowNumber)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'leave-import-');
        unlink($path);
        $path .= '.'.$extension;
        IOFactory::createWriter($spreadsheet, $extension === 'xls' ? 'Xls' : 'Xlsx')->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function rowValues(array $headers, array $row): array
    {
        $internalValues = [
            'Nama Lengkap dan Gelar*' => $row['raw_name'],
            'Jabatan*' => $row['position'] ?? 'Guru',
            'Tanggal Pengajuan Izin*' => $row['request_date'],
            "Jam Kepulangan / Jam Perkiraan Kehadiran / Jam Kembali\n(jika tidak hadir/Dinas maka tulis 00.00)*" => $row['request_time'],
            'Keperluan Izin*' => $row['leave_type'],
            "Keterangan Izin\n(Jelaskan)*" => $row['description'],
            "Dokumentasi\n(Khusus Perjalanan Dinas)" => $row['attachment'],
            'Timestamp' => $row['Timestamp'],
            'Kolom Ekstra' => $row['Kolom Ekstra'],
        ];

        if (! in_array("Keterangan Izin\n(Jelaskan)*", $headers, true)) {
            unset($internalValues["Keterangan Izin\n(Jelaskan)*"]);
        }

        return array_map(static fn (string $header) => $internalValues[$header] ?? '', $headers);
    }

    private function uploadedFile(string $path, string $filename = ''): UploadedFile
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = $filename !== '' ? $filename : 'Form Responses.'.$extension;
        $mimeType = match ($extension) {
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'text/csv',
        };

        return new UploadedFile($path, $filename, $mimeType, null, true);
    }

    private function createUnit(string $code): Unit
    {
        return Unit::create(['name' => $code, 'code' => $code]);
    }

    private function createMatchingEmployees(Unit $unit): array
    {
        $normalizer = app(NameNormalizer::class);
        $official = $this->createEmployee($unit, 'Siti Aisyah, S.Pd.');
        EmployeeAlias::create([
            'employee_id' => $official->id,
            'alias_name' => 'Siti Alias',
            'normalized_alias' => $normalizer->normalize('Siti Alias'),
        ]);

        $this->createEmployee($unit, 'Nama Ganda');
        $this->createEmployee($unit, 'Nama Ganda');
        $inactive = $this->createEmployee($unit, 'Pegawai Nonaktif', EmployeeStatus::Inactive);

        return ['official' => $official, 'inactive' => $inactive];
    }

    private function createEmployee(Unit $unit, string $name, EmployeeStatus $status = EmployeeStatus::Active): Employee
    {
        return Employee::create([
            'unit_id' => $unit->id,
            'nama_lengkap' => $name,
            'status' => $status,
        ]);
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
