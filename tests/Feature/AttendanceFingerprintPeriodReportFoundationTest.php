<?php

namespace Tests\Feature;

use App\Data\Reports\AttendanceFingerprintPeriodReportFilters;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Http\Requests\AttendanceFingerprintPeriodReportRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\WorkScheduleCategory;
use App\Services\Reports\AttendanceFingerprintPeriodReportService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AttendanceFingerprintPeriodReportFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_validates_required_unit_and_date_inputs(): void
    {
        $this->assertValidationErrors([], ['unit_id', 'start_date', 'end_date']);
        $this->assertValidationErrors(
            ['unit_id' => 999, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'],
            ['unit_id'],
        );

        $unit = $this->createUnit();
        $this->assertValidationErrors(
            ['unit_id' => $unit->id, 'start_date' => 'not-a-date', 'end_date' => '2026-09-30'],
            ['start_date'],
        );
        $this->assertValidationErrors(
            ['unit_id' => $unit->id, 'start_date' => '2026-09-01', 'end_date' => 'not-a-date'],
            ['end_date'],
        );
        $this->assertValidationErrors(
            ['unit_id' => $unit->id, 'start_date' => '2026-09-30', 'end_date' => '2026-09-01'],
            ['end_date'],
        );
    }

    public function test_request_validates_optional_category_page_and_page_size(): void
    {
        $unit = $this->createUnit();
        $valid = ['unit_id' => $unit->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'];
        $this->assertValidationErrors([...$valid, 'category_id' => 999], ['category_id']);
        $this->assertValidationErrors([...$valid, 'page' => 0], ['page']);
        $this->assertValidationErrors([...$valid, 'per_page' => 0], ['per_page']);
        $this->assertValidationErrors([...$valid, 'per_page' => 101], ['per_page']);

        $this->assertSame([], $this->validator($valid)->errors()->all());
    }

    public function test_filters_normalize_dates_trim_search_and_apply_pagination_defaults(): void
    {
        $filters = AttendanceFingerprintPeriodReportFilters::fromValidated([
            'unit_id' => '4',
            'start_date' => '2026-09-01 00:00:00',
            'end_date' => '2026-09-30 23:59:59',
            'search' => '  NIP-4  ',
        ]);

        $this->assertSame(4, $filters->unitId);
        $this->assertSame('2026-09-01', $filters->startDate);
        $this->assertSame('2026-09-30', $filters->endDate);
        $this->assertSame('NIP-4', $filters->search);
        $this->assertNull($filters->categoryId);
        $this->assertSame(1, $filters->page);
        $this->assertSame(20, $filters->perPage);
    }

    public function test_service_filters_unit_inclusive_employee_scope_and_optional_category(): void
    {
        $unit = $this->createUnit('SD');
        $otherUnit = $this->createUnit('TK');
        $category = WorkScheduleCategory::query()->create(['name' => 'Guru']);
        $otherCategory = WorkScheduleCategory::query()->create(['name' => 'Staff']);
        $matching = $this->createEmployee($unit, $category, 'Boundary match', 'NIP-1');
        $this->createEmployee($unit, $otherCategory, 'Other category', 'NIP-2');
        $this->createEmployee($otherUnit, $category, 'Other unit', 'NIP-3');
        $service = app(AttendanceFingerprintPeriodReportService::class);

        $allCategories = $service->generate($this->filters($unit, '2026-09-01', '2026-09-30'));
        $categoryFiltered = $service->generate($this->filters(
            $unit,
            '2026-09-01',
            '2026-09-30',
            categoryId: $category->id,
        ));

        $this->assertSame(2, $allCategories['summary']['employee_count']);
        $this->assertSame('2026-09-01', $allCategories['filters']['start_date']);
        $this->assertSame('2026-09-30', $allCategories['filters']['end_date']);
        $this->assertSame([$matching->id], array_column($categoryFiltered['data'], 'employee_id'));
    }

    public function test_service_searches_full_name_fingerprint_name_and_nip(): void
    {
        $unit = $this->createUnit('SD');
        $employees = [
            $this->createEmployee($unit, null, 'Full Name Match', 'NIP-FULL'),
            $this->createEmployee($unit, null, '', 'NIP-FINGERPRINT', 'Fingerprint Alias'),
            $this->createEmployee($unit, null, 'Other Name', 'NIP-SEARCH'),
        ];
        $service = app(AttendanceFingerprintPeriodReportService::class);

        foreach ([
            'Full Name Match' => $employees[0],
            'Fingerprint Alias' => $employees[1],
            'NIP-SEARCH' => $employees[2],
        ] as $search => $expected) {
            $result = $service->generate($this->filters($unit, '2026-09-01', '2026-09-30', search: $search));
            $this->assertSame([$expected->id], array_column($result['data'], 'employee_id'));
        }
    }

    public function test_service_includes_inactive_employees_and_uses_name_fallback(): void
    {
        $unit = $this->createUnit('SD');
        $inactive = $this->createEmployee($unit, null, '', 'NIP-INACTIVE', 'Former Fingerprint Name', EmployeeStatus::Inactive);

        $result = app(AttendanceFingerprintPeriodReportService::class)
            ->generate($this->filters($unit, '2026-09-01', '2026-09-30'));

        $this->assertSame([$inactive->id], array_column($result['data'], 'employee_id'));
        $this->assertSame('Former Fingerprint Name', $result['data'][0]['name']);
    }

    public function test_service_paginates_and_reports_unfiltered_page_total_consistently(): void
    {
        $unit = $this->createUnit('SD');
        for ($index = 1; $index <= 23; $index++) {
            $this->createEmployee($unit, null, sprintf('Employee %02d', $index), sprintf('NIP-%02d', $index));
        }
        $service = app(AttendanceFingerprintPeriodReportService::class);

        $default = $service->generate($this->filters($unit, '2026-09-01', '2026-09-30'));
        $lastPage = $service->generate($this->filters($unit, '2026-09-01', '2026-09-30', page: 2));
        $largePage = $service->generate($this->filters($unit, '2026-09-01', '2026-09-30', perPage: 100));

        $this->assertSame(20, $default['pagination']['per_page']);
        $this->assertSame(23, $default['pagination']['total']);
        $this->assertSame(2, $default['pagination']['last_page']);
        $this->assertCount(20, $default['data']);
        $this->assertSame(30, $default['summary']['unknown_date_count']);
        $this->assertSame(600, $default['summary']['page_ambiguous_employee_day_count']);
        $this->assertSame(2, $lastPage['pagination']['current_page']);
        $this->assertCount(3, $lastPage['data']);
        $this->assertSame(30, $lastPage['summary']['unknown_date_count']);
        $this->assertSame(90, $lastPage['summary']['page_ambiguous_employee_day_count']);
        $this->assertSame(100, $largePage['pagination']['per_page']);
        $this->assertCount(23, $largePage['data']);
    }

    public function test_service_returns_nullable_uncomputed_metrics_without_source_mutations(): void
    {
        $unit = $this->createUnit('SD');
        $employee = $this->createEmployee($unit, null, 'Read only employee', 'NIP-RO');
        $record = AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'attendance_date' => '2026-09-10',
            'status' => AttendanceStatus::Present,
            'scheduled_in' => '07:00',
            'actual_in' => '07:00',
            'scheduled_out' => '15:40',
            'actual_out' => '15:40',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);
        $record->refresh();
        $recordBefore = $record->getAttributes();
        $recordCount = AttendanceRecord::query()->count();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $service = app(AttendanceFingerprintPeriodReportService::class);

        $result = $service->generate($this->filters($unit, '2026-09-01', '2026-09-30'));

        $row = $result['data'][0];
        $this->assertSame([
            'employee_id', 'name', 'nip', 'category_id', 'category_name', 'hari_kerja',
            'total_finger', 'hadir', 'terlambat', 'pulang_awal', 'tidak_hadir',
            'izin_keluar', 'dinas', 'is_provisional', 'is_ambiguous',
            'history_limited', 'daily',
        ], array_keys($row));
        $this->assertCount(30, $row['daily']);
        foreach (['hari_kerja', 'total_finger', 'hadir', 'terlambat', 'pulang_awal', 'tidak_hadir', 'izin_keluar', 'dinas'] as $metric) {
            $this->assertNull($row[$metric]);
        }
        $this->assertSame(0, $result['summary']['provisional_day_count']);
        $this->assertSame(30, $result['summary']['unknown_date_count']);
        $this->assertSame(30, $result['summary']['page_ambiguous_employee_day_count']);
        $this->assertSame('none', $row['daily'][0]['leave_status']);
        $this->assertSame($recordCount, AttendanceRecord::query()->count());
        $this->assertEquals($recordBefore, $record->fresh()->getAttributes());
        $this->assertSame([], $writes);
    }

    private function assertValidationErrors(array $input, array $keys): void
    {
        $this->assertEqualsCanonicalizing($keys, array_keys($this->validator($input)->errors()->messages()));
    }

    private function validator(array $input): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($input, (new AttendanceFingerprintPeriodReportRequest)->rules());
    }

    private function filters(
        Unit $unit,
        string $startDate,
        string $endDate,
        ?int $categoryId = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): AttendanceFingerprintPeriodReportFilters {
        return AttendanceFingerprintPeriodReportFilters::fromValidated([
            'unit_id' => $unit->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'category_id' => $categoryId,
            'search' => $search,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    private function createUnit(string $code = 'SD'): Unit
    {
        return Unit::query()->create(['name' => $code, 'code' => $code]);
    }

    private function createEmployee(
        Unit $unit,
        ?WorkScheduleCategory $category,
        string $name,
        string $nip,
        ?string $fingerprintName = null,
        EmployeeStatus $status = EmployeeStatus::Active,
    ): Employee {
        return Employee::query()->create([
            'unit_id' => $unit->id,
            'category_id' => $category?->id,
            'fingerprint_id' => 'FP-'.$nip,
            'nama_lengkap' => $name,
            'namafinger' => $fingerprintName,
            'nip' => $nip,
            'position' => 'Guru',
            'status' => $status,
        ]);
    }
}
