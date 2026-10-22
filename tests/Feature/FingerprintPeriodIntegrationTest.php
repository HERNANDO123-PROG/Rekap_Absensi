<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Enums\WorkCalendarType;
use App\Livewire\Reports\PeriodReport;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class FingerprintPeriodIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fingerprint_detail_and_export_routes_require_authentication(): void
    {
        $unit = $this->unit('TK');
        $employee = $this->employee($unit, 'Private Person', 'NIP-PRIVATE');

        $this->get(route('rekap.period.employee', [
            'employee' => $employee->id,
            'unit_id' => $unit->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
        ]))->assertRedirect(route('login'));
        $this->get(route('reports.fingerprint-period.export', [
            'unit_id' => $unit->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
        ]))->assertRedirect(route('login'));
    }

    public function test_period_table_applies_unit_dates_category_and_name_or_nip_filters(): void
    {
        $unit = $this->unit('TK');
        $otherUnit = $this->unit('SD');
        $category = $this->category('Teacher');
        $otherCategory = $this->category('Staff');
        $employee = $this->employee($unit, 'Ayu Person', 'NIP-AYU', $category);
        $this->employee($unit, 'Budi Person', 'NIP-BUDI', $otherCategory);
        $this->employee($otherUnit, 'Ayu Person Outside', 'NIP-OUTSIDE', $category);
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $this->actingAs(User::factory()->create());

        Livewire::test(PeriodReport::class)
            ->set('unitId', (string) $unit->id)
            ->set('startDate', '2026-09-01')
            ->set('endDate', '2026-09-01')
            ->set('categoryId', (string) $category->id)
            ->set('search', 'NIP-AYU')
            ->call('applyFilters')
            ->assertSee('Ayu Person')
            ->assertDontSee('Budi Person')
            ->assertDontSee('Outside')
            ->assertSee('Hari Kerja')
            ->assertSee('Total Finger')
            ->assertSee('Hadir')
            ->assertSee('Terlambat')
            ->assertSee('Pulang Awal')
            ->assertSee('Tidak Hadir')
            ->assertSee('Izin Keluar')
            ->assertSee('Dinas')
            ->assertSee('Rekap dihitung berdasarkan data fingerprint dan pengaturan jam kerja yang tersedia. Sebagian hasil mungkin belum dapat dipastikan jika status hari, jam kerja, atau data pendukung belum lengkap.')
            ->assertSee('Kalender Kerja digunakan untuk mengatur tanggal khusus, seperti libur atau perubahan jam kerja. Admin tidak harus mengisi setiap hari jika jadwal rutin sudah mencukupi.')
            ->assertSee(route('rekap.period.employee', [
                'employee' => $employee->id,
                'unit_id' => $unit->id,
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-01',
                'category_id' => $category->id,
                'search' => 'NIP-AYU',
            ]));
    }

    public function test_period_table_explains_provisional_ambiguous_and_null_results(): void
    {
        $unit = $this->unit('TK');
        $employee = $this->employee($unit, 'Provisional Person', 'NIP-PROVISIONAL');
        $this->fingerprints($unit, $employee, '2026-09-01', ['07:15']);
        $this->actingAs(User::factory()->create());

        Livewire::test(PeriodReport::class)
            ->set('unitId', (string) $unit->id)
            ->set('startDate', '2026-09-01')
            ->set('endDate', '2026-09-01')
            ->call('applyFilters')
            ->assertSee('Provisional Person')
            ->assertSee('Perkiraan hari kerja')
            ->assertSee('Perlu diperiksa')
            ->assertSee('Data belum cukup')
            ->assertSee('Tanggal belum dapat dipastikan: 0')
            ->assertDontSee('Tanggal unknown');
    }

    public function test_employee_detail_uses_same_engine_and_shows_daily_provenance(): void
    {
        $unit = $this->unit('TK');
        $employee = $this->employee($unit, 'Detail Person', 'NIP-DETAIL');
        WorkSchedule::query()->create([
            'unit_id' => $unit->id,
            'day_of_week' => 2,
            'is_working_day' => true,
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);
        LeaveRequest::query()->create([
            'import_batch_id' => null,
            'source' => 'manual',
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'raw_name' => $employee->nama_lengkap,
            'normalized_name' => strtolower($employee->nama_lengkap),
            'request_date' => '2026-09-01',
            'leave_type' => LeaveType::TemporaryExit,
            'raw_leave_type' => LeaveType::TemporaryExit->value,
            'matching_status' => MatchingStatus::Matched,
        ]);
        $this->fingerprints($unit, $employee, '2026-09-01', ['07:10', '14:55']);
        $this->actingAs(User::factory()->create());

        $this->get(route('rekap.period.employee', [
            'employee' => $employee->id,
            'unit_id' => $unit->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
        ]))
            ->assertOk()
            ->assertSee('Detail Harian Rekap Fingerprint')
            ->assertSee('Detail Person')
            ->assertSee('Hari kerja')
            ->assertSee('Jadwal rutin')
            ->assertSee('07:00')
            ->assertSee('15:00')
            ->assertSee('07:10')
            ->assertSee('14:55')
            ->assertSee('Kandidat masuk dan pulang dipilih')
            ->assertSee('selected')
            ->assertSee('Acuan jam kerja tidak lengkap')
            ->assertSee('Izin belum terverifikasi')
            ->assertSee('Izin belum terverifikasi karena cakupan atau persetujuannya tidak dapat dipastikan dari data yang tersedia.')
            ->assertSee('unverifiable')
            ->assertDontSee('Cakupan atau persetujuan izin tidak dapat diverifikasi');
    }

    public function test_employee_detail_distinguishes_unknown_date_from_provisional_workday(): void
    {
        $unit = $this->unit('TK');
        $employee = $this->employee($unit, 'Uncertain Detail', 'NIP-UNCERTAIN');
        $this->fingerprints($unit, $employee, '2026-09-06', ['07:00']);
        $this->actingAs(User::factory()->create());

        $this->get(route('rekap.period.employee', [
            'employee' => $employee->id,
            'unit_id' => $unit->id,
            'start_date' => '2026-09-06',
            'end_date' => '2026-09-07',
        ]))
            ->assertOk()
            ->assertSee('Perkiraan hari kerja')
            ->assertSee('Tanggal belum dapat dipastikan')
            ->assertSee('Data belum cukup')
            ->assertSee('Tanggal belum dapat dipastikan karena kalender, jadwal rutin, atau bukti fingerprint belum cukup.')
            ->assertSee('fingerprint_inference');
    }

    public function test_fingerprint_export_includes_all_filtered_employees_across_pages_and_preserves_nulls(): void
    {
        $unit = $this->unit('TK');
        $category = $this->category('Export category');
        $otherCategory = $this->category('Excluded category');
        $employees = [];
        for ($index = 1; $index <= 101; $index++) {
            $employees[] = $this->employee(
                $unit,
                sprintf('Export Person %03d', $index),
                sprintf('NIP-EXPORT-%03d', $index),
                $category,
            );
        }
        $excluded = $this->employee($unit, 'Export Person Excluded', 'NIP-EXCLUDED', $otherCategory);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('reports.fingerprint-period.export', [
            'unit_id' => $unit->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'category_id' => $category->id,
            'search' => 'Export Person',
            'page' => 2,
            'employee_id' => $employees[0]->id,
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'fingerprint-export-test-');
        file_put_contents($path, $response->streamedContent() ?: $response->getContent());

        try {
            $spreadsheet = IOFactory::load($path);
            $summary = $spreadsheet->getSheetByName('Rekap Pegawai');
            $daily = $spreadsheet->getSheetByName('Rincian Harian');

            $this->assertSame('Belum dapat ditentukan', $summary->getCell('F7')->getValue());
            $this->assertSame($employees[100]->id, $summary->getCell('B107')->getValue());
            $this->assertSame(107, $summary->getHighestRow());
            $this->assertSame(102, $daily->getHighestRow());
            $this->assertSame('Belum dapat ditentukan', $daily->getCell('R2')->getValue());
            $this->assertNotSame($excluded->id, $summary->getCell('B107')->getValue());
        } finally {
            unlink($path);
        }
    }

    private function unit(string $code): Unit
    {
        return Unit::query()->create(['name' => $code.' School', 'code' => $code]);
    }

    private function category(string $name): WorkScheduleCategory
    {
        return WorkScheduleCategory::query()->create(['name' => $name]);
    }

    private function employee(
        Unit $unit,
        string $name,
        string $nip,
        ?WorkScheduleCategory $category = null,
    ): Employee {
        return Employee::query()->create([
            'unit_id' => $unit->id,
            'category_id' => $category?->id,
            'fingerprint_id' => 'FP-'.$nip,
            'nama_lengkap' => $name,
            'nip' => $nip,
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function calendar(Unit $unit, string $date, bool $working, ?string $start, ?string $end): void
    {
        WorkCalendar::query()->create([
            'unit_id' => $unit->id,
            'date' => $date,
            'type' => $working ? WorkCalendarType::Workday : WorkCalendarType::Holiday,
            'is_working_day' => $working,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    private function fingerprints(Unit $unit, Employee $employee, string $date, array $times): void
    {
        $batch = FingerprintImportBatch::query()->create([
            'unit_id' => $unit->id,
            'filename' => 'fixture-'.$employee->id.'-'.$date.'.xlsx',
            'file_hash' => hash('sha256', $employee->id.'-'.$date.'-'.implode(',', $times)),
            'status' => ImportBatchStatus::Completed,
            'total_rows' => count($times),
        ]);

        foreach ($times as $time) {
            FingerprintLog::query()->create([
                'import_batch_id' => $batch->id,
                'unit_id' => $unit->id,
                'employee_id' => $employee->id,
                'fingerprint_id' => $employee->fingerprint_id,
                'attendance_date' => $date,
                'attendance_time' => $time.':00',
                'matching_status' => MatchingStatus::Matched,
            ]);
        }
    }
}
