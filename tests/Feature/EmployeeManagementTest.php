<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\LeaveImportBatch;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Services\EmployeeAliasService;
use App\Services\EmployeeMatchingService;
use App\Services\EmployeeService;
use App\Services\WorkScheduleCategoryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_employees_store_fingerprint_and_admin_names_separately(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $service = app(EmployeeService::class);

        $tkEmployee = $service->create([
            'unit_id' => $tk->id,
            'namafinger' => ' BUDI Santoso, S.Pd ',
            'status' => EmployeeStatus::Active,
        ]);
        $sdEmployee = $service->create([
            'unit_id' => $sd->id,
            'nama_lengkap' => 'Siti Aminah',
            'status' => 'active',
        ]);

        $this->assertSame(' BUDI Santoso, S.Pd ', $tkEmployee->namafinger);
        $this->assertNull($tkEmployee->nama_lengkap);
        $this->assertSame('Siti Aminah', $sdEmployee->nama_lengkap);
        $this->assertTrue($tkEmployee->unit->is($tk));
        $this->assertTrue($sdEmployee->unit->is($sd));
    }

    public function test_employee_creation_rejects_missing_or_invalid_unit(): void
    {
        try {
            app(EmployeeService::class)->create([
                'nama_lengkap' => 'Budi Santoso',
                'status' => 'active',
            ]);
            $this->fail('Creating an employee without a unit should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit_id', $exception->errors());
        }

        $this->actingAs(User::factory()->create())
            ->postJson('/employees', [
                'nama_lengkap' => 'Budi Santoso',
                'status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id');
    }

    public function test_employee_creation_accepts_both_statuses_and_rejects_invalid_status(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('SD');
        $category = WorkScheduleCategory::create([
            'name' => 'Status Creation Category',
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);
        $payload = [
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'nama_lengkap' => 'Status Creation Employee',
        ];

        $activeId = $this->postJson('/employees', [...$payload, 'status' => 'active'])
            ->assertCreated()
            ->assertJsonPath('data.status', EmployeeStatus::Active->value)
            ->json('data.id');
        $inactiveId = $this->postJson('/employees', [...$payload, 'fingerprint_id' => 'INACTIVE-1', 'status' => 'inactive'])
            ->assertCreated()
            ->assertJsonPath('data.status', EmployeeStatus::Inactive->value)
            ->json('data.id');

        $this->assertSame(EmployeeStatus::Active, Employee::query()->findOrFail($activeId)->status);
        $this->assertSame(EmployeeStatus::Inactive, Employee::query()->findOrFail($inactiveId)->status);
        $this->postJson('/employees', [...$payload, 'status' => 'paused'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_employee_manage_status_filter_defaults_to_all_and_combines_with_other_filters_and_pagination(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('SD');
        $otherUnit = $this->createUnit('TK');
        $category = WorkScheduleCategory::create([
            'name' => 'Status Filter Category',
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);
        $otherCategory = WorkScheduleCategory::create([
            'name' => 'Other Status Filter Category',
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);

        for ($index = 1; $index <= 16; $index++) {
            $employee = $this->createEmployee(
                $unit,
                sprintf('Filtered Active %02d', $index),
                status: EmployeeStatus::Active,
            );
            $employee->update(['category_id' => $category->id]);
        }

        $inactive = $this->createEmployee($unit, 'Filtered Inactive', status: EmployeeStatus::Inactive);
        $inactive->update(['category_id' => $category->id]);
        $otherUnitEmployee = $this->createEmployee($otherUnit, 'Filtered Other Unit');
        $otherUnitEmployee->update(['category_id' => $category->id]);
        $otherCategoryEmployee = $this->createEmployee($unit, 'Filtered Other Category');
        $otherCategoryEmployee->update(['category_id' => $otherCategory->id]);
        $defaultActive = $this->createEmployee($unit, 'Default Filter Active');
        $defaultInactive = $this->createEmployee($unit, 'Default Filter Inactive', status: EmployeeStatus::Inactive);

        $allHtml = $this->get(route('employees.manage', ['search' => 'Default Filter']))->assertOk()->getContent();
        $this->assertStringContainsString($defaultActive->nama_lengkap, $allHtml);
        $this->assertStringContainsString($defaultInactive->nama_lengkap, $allHtml);

        $activeHtml = $this->get(route('employees.manage', [
            'status' => 'active',
            'search' => 'Filtered Active 16',
        ]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Filtered Active 16', $activeHtml);
        $this->assertStringNotContainsString('Filtered Inactive', $activeHtml);

        $inactiveHtml = $this->get(route('employees.manage', [
            'status' => 'inactive',
            'search' => 'Filtered Inactive',
        ]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Filtered Inactive', $inactiveHtml);
        $this->assertStringNotContainsString('Filtered Active 16', $inactiveHtml);

        $pageTwoHtml = $this->get(route('employees.manage', [
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'search' => 'Filtered',
            'status' => 'active',
            'page' => 2,
        ]))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/Filtered Active \d{2}/', $pageTwoHtml);
        $this->assertSame(1, substr_count($pageTwoHtml, '<tr class="hover:bg-slate-50">'));
        $this->assertStringNotContainsString('Filtered Inactive', $pageTwoHtml);
        $this->assertStringNotContainsString('Filtered Other Unit', $pageTwoHtml);
        $this->assertStringNotContainsString('Filtered Other Category', $pageTwoHtml);
        $this->assertStringContainsString('status=active', $pageTwoHtml);
    }

    public function test_employee_profile_update_cannot_change_status(): void
    {
        $this->actingAs(User::factory()->create());
        $employee = $this->createEmployee($this->createUnit('SD'), 'Profile Status Employee');

        $this->putJson(route('employees.update', $employee), ['status' => 'inactive'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'profile']);

        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);
    }

    public function test_employee_api_requires_an_active_schedule_category(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('TK');
        $inactiveCategory = WorkScheduleCategory::create([
            'name' => 'Kategori Nonaktif',
            'start_time' => '06:55',
            'end_time' => '16:00',
            'is_active' => false,
        ]);
        $payload = [
            'unit_id' => $unit->id,
            'nama_lengkap' => 'Pegawai Tanpa Kategori',
            'status' => 'active',
        ];

        $this->postJson('/employees', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
        $this->postJson('/employees', [...$payload, 'category_id' => $inactiveCategory->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_employee_menu_and_api_support_fingerprint_and_full_names(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('TK');
        $category = WorkScheduleCategory::create([
            'name' => 'Nama Baru',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);

        $employeeId = $this->postJson('/employees', [
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'fingerprint_id' => 'FP-44',
            'namafinger' => 'Nama Mesin',
            'nama_lengkap' => 'Nama Lengkap Admin',
            'nip' => '1987654321',
            'position' => 'Guru',
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.namafinger', 'Nama Mesin')
            ->assertJsonPath('data.nama_lengkap', 'Nama Lengkap Admin')
            ->assertJsonMissingPath('data.employmentPeriods')
            ->json('data.id');

        $employee = Employee::query()->findOrFail($employeeId);
        $this->assertSame('FP-44', $employee->fingerprint_id);
        $this->assertSame('Nama Lengkap Admin', $employee->displayName());

        $this->get('/employees/manage?search=Nama%20Mesin')
            ->assertOk()
            ->assertSee('Nama Mesin')
            ->assertSee('Nama Lengkap Admin');
        $this->get('/employees/manage?search=Nama%20Lengkap%20Admin')
            ->assertOk()
            ->assertSee('Nama Mesin')
            ->assertSee('Nama Lengkap Admin');

        $this->putJson(route('employees.update', $employee), [
            'nama_lengkap' => 'Nama Lengkap Diperbarui',
            'nip' => '1234567890',
            'position' => 'Kepala Sekolah',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Data profil pegawai berhasil disimpan.')
            ->assertJsonPath('data.nama_lengkap', 'Nama Lengkap Diperbarui')
            ->assertJsonPath('data.nip', '1234567890')
            ->assertJsonPath('data.position', 'Kepala Sekolah')
            ->assertJsonPath('data.unit.id', $unit->id)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonMissingPath('data.employmentPeriods');

        $this->getJson(route('employees.show', $employee))
            ->assertOk()
            ->assertJsonPath('data.nama_lengkap', 'Nama Lengkap Diperbarui')
            ->assertJsonPath('data.unit.id', $unit->id)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonMissingPath('data.employmentPeriods');

        $this->getJson(route('employees.index'))
            ->assertOk()
            ->assertJsonPath('data.0.nama_lengkap', 'Nama Lengkap Diperbarui')
            ->assertJsonMissingPath('data.0.employmentPeriods');

        $employee->refresh();
        $this->assertSame('Nama Mesin', $employee->namafinger);
        $this->assertSame('FP-44', $employee->fingerprint_id);
        $this->assertSame('Nama Lengkap Diperbarui', $employee->displayName());
    }

    public function test_employee_list_separates_names_and_shows_profile_fields_as_view_only_until_edit(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Nama Mesin', 'FP-51');
        $employee->update([
            'nama_lengkap' => 'Nama Admin',
            'namafinger' => 'Nama Mesin',
        ]);
        app(EmployeeAliasService::class)->create($employee, 'Nama Alternatif');
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('employees.manage'))->assertOk();
        $html = $response->getContent();
        $headerStart = strpos($html, '<thead');
        $headerEnd = strpos($html, '</thead>', $headerStart);
        $headers = substr($html, $headerStart, $headerEnd - $headerStart);

        $this->assertLessThan(
            strpos($headers, '>Nama Fingerprint</th>'),
            strpos($headers, '>Nama Lengkap</th>'),
            'Nama Lengkap must precede Nama Fingerprint in the employee table.',
        );
        $this->assertStringNotContainsString('>Alias</th>', $headers);
        $this->assertStringContainsString('>Aksi</th>', $headers);
        $this->assertStringContainsString('min-w-[1120px]', $html);
        $this->assertStringContainsString('Nama Admin', $html);
        $this->assertStringContainsString('Nama Mesin', $html);
        $this->assertStringContainsString('Nama alternatif pencocokan', $html);
        $this->assertStringContainsString('Nama Alternatif', $html);
        $this->assertStringContainsString('data-employee-edit="'.$employee->id.'"', $html);
        $this->assertStringContainsString('data-employee-cancel="'.$employee->id.'"', $html);
        $this->assertStringContainsString('data-edit-save', $html);
        $this->assertStringContainsString('name="nama_lengkap"', $html);
        $this->assertStringContainsString('name="position"', $html);
        $this->assertStringContainsString('name="nip"', $html);
        $this->assertStringContainsString('name="nama_lengkap" value="Nama Admin"', $html);
        $this->assertStringContainsString('name="position" value=""', $html);
        $this->assertStringContainsString('name="nip" value=""', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('ID Fingerprint:', $html);
        $this->assertStringContainsString('Nonaktifkan', $html);
        $this->assertStringContainsString('data-confirm="Nonaktifkan Nama Admin?', $html);
    }

    public function test_employee_edit_dropdowns_use_database_units_and_categories_and_are_locked_until_edit(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $category = WorkScheduleCategory::create([
            'name' => 'Kategori Pilihan',
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);
        $employee = $this->createEmployee($tk, 'Pegawai Dropdown');
        $employee->update(['category_id' => $category->id]);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('employees.manage'))->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-edit-input name="unit_id" required', $html);
        $this->assertStringContainsString('data-edit-input data-schedule-category-select name="category_id"', $html);
        $this->assertStringContainsString('value="'.$tk->id.'" selected', $html);
        $this->assertStringContainsString('value="'.$sd->id.'"', $html);
        $this->assertStringContainsString('value="'.$category->id.'" selected', $html);
        $this->assertStringContainsString('Kategori Pilihan', $html);
        $this->assertStringContainsString('Belum dipilih', $html);

        preg_match('/<select[^>]*data-edit-input name="unit_id"[^>]*>/', $html, $unitSelect);
        preg_match('/<select[^>]*data-edit-input data-schedule-category-select name="category_id"[^>]*>/', $html, $categorySelect);
        $this->assertStringContainsString(' disabled', $unitSelect[0] ?? '');
        $this->assertStringContainsString(' disabled', $categorySelect[0] ?? '');
        $this->assertStringNotContainsString('FP-', $html);
    }

    public function test_employee_menu_and_routes_no_longer_expose_employment_period_management(): void
    {
        $this->createEmployee($this->createUnit('SD'), 'Employee Without Period Feature');
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('employees.manage'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Perlu periode kerja', $html);
        $this->assertStringNotContainsString('Periode Kerja', $html);
        $this->assertStringNotContainsString('Kelola periode', $html);
        $this->assertStringNotContainsString('period_status', $html);
        $this->assertStringContainsString('data-edit-input data-schedule-category-select name="category_id"', $html);
        $this->assertStringContainsString('Nama Fingerprint', $html);
        $this->assertStringContainsString('Nama Lengkap', $html);

        foreach ([
            'employees.employment-periods.manage',
            'employees.employment-periods.store',
            'employees.employment-periods.update',
            'employees.employment-periods.destroy',
        ] as $routeName) {
            $this->assertFalse(Route::has($routeName), $routeName.' must not be registered.');
        }
    }

    public function test_unassigned_alternative_profile_is_not_presented_as_the_category_normal_schedule(): void
    {
        $unit = $this->createUnit('SD');
        $category = WorkScheduleCategory::create([
            'name' => 'Staff',
            'start_time' => null,
            'end_time' => null,
            'is_active' => true,
        ]);
        WorkScheduleProfile::create([
            'work_schedule_category_id' => $category->id,
            'name' => 'normal',
            'start_time' => '06:55',
            'end_time' => '16:00',
            'is_active' => true,
        ]);
        $employee = $this->createEmployee($unit, 'Pegawai Staff');
        $employee->update(['category_id' => $category->id]);
        $this->actingAs(User::factory()->create());

        $this->get(route('employees.manage'))
            ->assertOk()
            ->assertSee('Staff')
            ->assertSee('Belum dipilih')
            ->assertDontSee('jadwal normal belum ditetapkan');

        $this->assertNull($category->fresh()->work_schedule_profile_id);
        $this->assertNull($employee->fresh()->category->work_schedule_profile_id);
    }

    public function test_employee_edit_saves_unit_and_category_only_when_submitted_and_checks_conflicts(): void
    {
        $this->actingAs(User::factory()->create());
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $otherCategory = WorkScheduleCategory::create([
            'name' => 'Kategori Baru',
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);
        $employee = app(EmployeeService::class)->create([
            'unit_id' => $tk->id,
            'category_id' => WorkScheduleCategory::create([
                'name' => 'Kategori Awal',
                'start_time' => '07:00',
                'end_time' => '15:00',
            ])->id,
            'fingerprint_id' => 'FP-MOVE-20',
            'namafinger' => 'Fingerprint Move',
            'nama_lengkap' => 'Nama Sebelum',
            'position' => 'Staff Sebelum',
            'nip' => 'NIP-SEBELUM',
            'status' => EmployeeStatus::Active,
        ]);

        $this->assertSame($tk->id, $employee->fresh()->unit_id);
        $this->assertNotSame($otherCategory->id, $employee->fresh()->category_id);

        $this->putJson(route('employees.update', $employee), [
            'unit_id' => $sd->id,
            'category_id' => $otherCategory->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.unit_id', $sd->id)
            ->assertJsonPath('data.category_id', $otherCategory->id);

        $employee->refresh();
        $this->assertSame('Nama Sebelum', $employee->nama_lengkap);
        $this->assertSame('Staff Sebelum', $employee->position);
        $this->assertSame('NIP-SEBELUM', $employee->nip);
        $this->assertSame('Fingerprint Move', $employee->namafinger);
        $this->assertSame('FP-MOVE-20', $employee->fingerprint_id);
        $this->assertSame(MatchingStatus::Matched, app(EmployeeMatchingService::class)->findByFingerprint($sd->id, 'FP-MOVE-20')->status);

        $this->putJson(route('employees.update', $employee), [
            'unit_id' => 999999,
            'category_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors(['unit_id', 'category_id']);
        $this->assertSame($sd->id, $employee->fresh()->unit_id);
        $this->assertSame($otherCategory->id, $employee->fresh()->category_id);
    }

    public function test_employee_unit_transfer_is_rejected_if_fingerprint_or_attendance_history_exists(): void
    {
        $this->actingAs(User::factory()->create());
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $employee = $this->createEmployee($tk, 'Pegawai Berhistori', 'FP-HISTORY');
        FingerprintImportBatch::create([
            'unit_id' => $tk->id,
            'filename' => 'employee-history.xls',
            'file_hash' => str_repeat('e', 64),
            'status' => ImportBatchStatus::Completed,
        ]);
        $batch = FingerprintImportBatch::query()->firstOrFail();
        FingerprintLog::create([
            'import_batch_id' => $batch->id,
            'unit_id' => $tk->id,
            'employee_id' => $employee->id,
            'fingerprint_id' => $employee->fingerprint_id,
            'employee_name_raw' => $employee->namafinger,
            'attendance_date' => '2026-08-20',
            'attendance_time' => '07:00:00',
            'raw_value' => '07:00',
            'matching_status' => MatchingStatus::Matched,
        ]);

        $this->putJson(route('employees.update', $employee), ['unit_id' => $sd->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id')
            ->assertJsonPath('errors.unit_id.0', 'Unit tidak dapat dipindahkan karena pegawai sudah memiliki log fingerprint atau riwayat absensi. Riwayat tersebut tetap terikat pada unit asal.');
        $this->assertSame($tk->id, $employee->fresh()->unit_id);
    }

    public function test_employee_profile_endpoint_only_updates_allowed_fields_and_rejects_sensitive_or_unrelated_fields(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('TK');
        $category = WorkScheduleCategory::create(['name' => 'Profile scope', 'start_time' => '07:00', 'end_time' => '15:00']);
        $employee = app(EmployeeService::class)->create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'fingerprint_id' => 'FP-LOCKED-1',
            'namafinger' => 'Machine name',
            'nama_lengkap' => 'Before',
            'position' => 'Staff',
            'nip' => 'OLD-NIP',
            'status' => EmployeeStatus::Active,
        ]);

        $this->putJson(route('employees.update', $employee), [
            'nama_lengkap' => 'After',
            'position' => 'Manager',
            'nip' => 'NEW-NIP',
            'fingerprint_id' => 'FP-SPOOFED',
            'unit_id' => 999999,
            'category_id' => 999999,
            'status' => EmployeeStatus::Inactive->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fingerprint_id', 'unit_id', 'category_id', 'status']);

        $this->assertSame('Before', $employee->fresh()->nama_lengkap);
        $this->assertSame('Staff', $employee->fresh()->position);
        $this->assertSame('OLD-NIP', $employee->fresh()->nip);
        $this->assertSame('FP-LOCKED-1', $employee->fresh()->fingerprint_id);
        $this->assertSame($unit->id, $employee->fresh()->unit_id);
        $this->assertSame($category->id, $employee->fresh()->category_id);
        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);

        $this->putJson(route('employees.update', $employee), [
            'nama_lengkap' => str_repeat('x', 256),
        ])->assertUnprocessable()->assertJsonValidationErrors('nama_lengkap');
        $this->assertSame('Before', $employee->fresh()->nama_lengkap);
    }

    public function test_deactivate_endpoint_preserves_fingerprint_identity_alias_and_raw_history(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('SD');
        $employee = $this->createEmployee($unit, 'Pegawai Nonaktif', 'FP-77');
        $employee->update(['namafinger' => 'Fingerprint 77']);
        $alias = app(EmployeeAliasService::class)->create($employee, 'Alias 77');
        $batch = FingerprintImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => 'employee-history.xls',
            'file_hash' => str_repeat('d', 64),
            'status' => ImportBatchStatus::Completed,
        ]);
        $log = FingerprintLog::create([
            'import_batch_id' => $batch->id,
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'fingerprint_id' => 'FP-77',
            'employee_name_raw' => 'Fingerprint 77',
            'attendance_date' => '2026-09-01',
            'attendance_time' => '07:00:00',
            'raw_value' => '07:00',
            'matching_status' => MatchingStatus::Matched,
        ]);
        $attendance = AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'attendance_date' => '2026-09-01',
            'status' => AttendanceStatus::Present,
        ]);
        $leaveBatch = LeaveImportBatch::query()->create([
            'unit_id' => $unit->id,
            'filename' => 'employee-leave-history.xls',
            'file_hash' => str_repeat('e', 64),
            'status' => ImportBatchStatus::Completed,
        ]);
        $leaveRequest = LeaveRequest::query()->create([
            'import_batch_id' => $leaveBatch->id,
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'raw_name' => 'Pegawai Nonaktif',
            'normalized_name' => 'pegawai nonaktif',
            'request_date' => '2026-09-01',
            'leave_type' => LeaveType::Absent,
            'matching_status' => MatchingStatus::Matched,
        ]);

        $this->postJson(route('employees.deactivate', $employee))
            ->assertOk()
            ->assertJsonPath('data.status', EmployeeStatus::Inactive->value);

        $this->assertSame('FP-77', $employee->fresh()->fingerprint_id);
        $this->assertDatabaseHas('employee_aliases', ['id' => $alias->id, 'employee_id' => $employee->id]);
        $this->assertDatabaseHas('fingerprint_logs', ['id' => $log->id, 'employee_id' => $employee->id]);
        $this->assertDatabaseHas('attendance_records', ['id' => $attendance->id, 'employee_id' => $employee->id]);
        $this->assertDatabaseHas('leave_requests', ['id' => $leaveRequest->id, 'employee_id' => $employee->id]);
        $this->assertSame(MatchingStatus::Unmatched, app(EmployeeMatchingService::class)->findByFingerprint($unit->id, 'FP-77')->status);
    }

    public function test_manage_page_shows_only_the_status_appropriate_action_and_employee_can_be_reactivated(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('SD');
        $active = $this->createEmployee($unit, 'Active Action Employee', 'FP-ACTIVE');
        $inactive = $this->createEmployee($unit, 'Inactive Action Employee', 'FP-INACTIVE', status: EmployeeStatus::Inactive);

        $html = $this->get(route('employees.manage'))->assertOk()->getContent();
        $this->assertStringContainsString('data-endpoint="'.route('employees.deactivate', $active).'"', $html);
        $this->assertStringNotContainsString('data-endpoint="'.route('employees.activate', $active).'"', $html);
        $this->assertStringContainsString('data-endpoint="'.route('employees.activate', $inactive).'"', $html);
        $this->assertStringNotContainsString('data-endpoint="'.route('employees.deactivate', $inactive).'"', $html);
        $this->assertStringContainsString('data-confirm="Aktifkan Inactive Action Employee?', $html);

        $this->postJson(route('employees.activate', $inactive))
            ->assertOk()
            ->assertJsonPath('data.status', EmployeeStatus::Active->value);
        $this->assertSame('FP-INACTIVE', $inactive->fresh()->fingerprint_id);
    }

    public function test_activation_rejects_fingerprint_conflict_and_keeps_employee_inactive(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('SD');
        $active = $this->createEmployee($unit, 'Existing Active Fingerprint', 'FP-CONFLICT');
        $inactive = $this->createEmployee(
            $unit,
            'Conflicting Inactive Employee',
            'FP-CONFLICT',
            status: EmployeeStatus::Inactive,
        );

        $this->postJson(route('employees.activate', $inactive))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint_id')
            ->assertJsonPath(
                'errors.fingerprint_id.0',
                'Fingerprint ID ini sudah digunakan oleh pegawai aktif lain dalam unit yang sama. Periksa konflik sebelum mengaktifkan pegawai.',
            );

        $this->assertSame(EmployeeStatus::Inactive, $inactive->fresh()->status);
        $this->assertSame('FP-CONFLICT', $inactive->fresh()->fingerprint_id);
        $this->assertSame(EmployeeStatus::Active, $active->fresh()->status);
        $this->assertSame('FP-CONFLICT', $active->fresh()->fingerprint_id);
    }

    public function test_employee_category_relation_is_reciprocal_and_prevents_category_deletion(): void
    {
        $unit = $this->createUnit('SD');
        $category = WorkScheduleCategory::create([
            'name' => 'Kategori Terpakai',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $employee = Employee::create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'nama_lengkap' => 'Employee Category Relation',
            'status' => 'active',
        ]);

        $this->assertTrue($employee->category->is($category));
        $this->assertTrue($category->employees->sole()->is($employee));

        try {
            $category->delete();
            $this->fail('A category assigned to an employee must not be deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('work_schedule_categories', ['id' => $category->id]);
        }
    }

    public function test_employee_management_displays_the_selected_schedule_configuration_for_category_options_and_assignment(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('TK');
        $category = WorkScheduleCategory::create([
            'name' => 'Guru',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $profile = WorkScheduleProfile::query()->create([
            'work_schedule_category_id' => $category->id,
            'name' => 'Normal',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'is_active' => true,
        ]);
        app(WorkScheduleCategoryService::class)->update($category, [
            'name' => $category->name,
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => $profile->id,
        ]);
        $employee = Employee::create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'nama_lengkap' => 'Guru Profile',
            'status' => EmployeeStatus::Active->value,
        ]);
        $employee->update(['work_schedule_profile_id' => $profile->id]);

        $this->get('/employees/manage')
            ->assertOk()
            ->assertSee('Guru')
            ->assertSee('Normal')
            ->assertDontSee('Guru (08:00 - 16:00)')
            ->assertDontSee('Guru (06:55 - 15:40)');
    }

    public function test_aliases_are_normalized_without_changing_the_official_name(): void
    {
        $employee = $this->createEmployee($this->createUnit('TK'), 'Intan Permata Sari, S.Pd');
        $service = app(EmployeeAliasService::class);
        $firstAlias = $service->create($employee, 'Intan~');
        $secondAlias = $service->create($employee, ' Intan  Permata ');

        $this->assertSame('Intan~', $firstAlias->alias_name);
        $this->assertSame('intan', $firstAlias->normalized_alias);
        $this->assertSame('intan permata', $secondAlias->normalized_alias);
        $this->assertCount(2, $service->list($employee));

        $service->update($firstAlias, 'INTAN.');
        $this->assertSame('intan', $firstAlias->refresh()->normalized_alias);
        $service->delete($secondAlias);

        $this->assertSame('Intan Permata Sari, S.Pd', $employee->refresh()->displayName());
        $this->assertCount(1, $employee->aliases);
    }

    public function test_fingerprint_matching_and_identifiers_are_scoped_to_unit(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $tkEmployee = $this->createEmployee($tk, 'TK Employee', '12', 'EMP001');
        $sdEmployee = $this->createEmployee($sd, 'SD Employee', '12', 'EMP001');
        $matching = app(EmployeeMatchingService::class);

        $this->assertSame(MatchingStatus::Matched, $matching->findByFingerprint($tk->id, '12')->status);
        $this->assertTrue($matching->findByFingerprint($tk->id, '12')->employee->is($tkEmployee));
        $this->assertTrue($matching->findByFingerprint($sd->id, '12')->employee->is($sdEmployee));
        $this->assertTrue(app(EmployeeService::class)->findByFingerprint($tk->id, '12')->is($tkEmployee));
        $this->assertTrue(app(EmployeeService::class)->findByName($tk->id, 'TK Employee')->first()->is($tkEmployee));
        $this->assertSame(MatchingStatus::Unmatched, $matching->findByFingerprint($tk->id, '99')->status);

        try {
            $this->createEmployee($tk, 'Duplicate fingerprint', '12');
            $this->fail('An active fingerprint ID cannot be duplicated within a unit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fingerprint_id', $exception->errors());
        }

        try {
            Employee::create([
                'unit_id' => $tk->id,
                'fingerprint_id' => '12',
                'nama_lengkap' => 'Database duplicate fingerprint',
                'status' => 'active',
            ]);
            $this->fail('The database must enforce active fingerprint ID uniqueness.');
        } catch (QueryException) {
            $this->assertDatabaseCount('employees', 2);
        }
    }

    public function test_name_and_alias_matching_are_exact_ambiguous_and_unit_scoped(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $budi = $this->createEmployee($tk, 'Budi Santoso');
        $this->createEmployee($sd, 'Budi Santoso');
        app(EmployeeAliasService::class)->create($budi, 'Budi~Santoso');
        $matching = app(EmployeeMatchingService::class);

        $nameResult = $matching->findByName($tk->id, ' BUDI Santoso. ');
        $aliasResult = $matching->findByName($tk->id, 'Budi~Santoso');

        $this->assertSame(MatchingStatus::Matched, $nameResult->status);
        $this->assertSame(MatchingStatus::Matched, $aliasResult->status);
        $this->assertTrue($nameResult->employee->is($budi));
        $this->assertCount(1, $matching->findCandidates($tk->id, 'Budi Santoso'));
        $this->assertSame(MatchingStatus::Unmatched, $matching->findByName($tk->id, 'Budi Santoso Putra')->status);
        app(EmployeeAliasService::class)->create($budi, 'Budi S.');
        $this->assertSame(MatchingStatus::Matched, $matching->findByName($tk->id, 'Budi S.')->status);
        $this->assertSame(MatchingStatus::Unmatched, $matching->findByName($sd->id, 'Budi S.')->status);

        $anotherEmployee = $this->createEmployee($tk, 'Chandra');
        app(EmployeeAliasService::class)->create($anotherEmployee, 'Budi Santoso');

        $ambiguous = $matching->findByName($tk->id, 'Budi Santoso');
        $this->assertSame(MatchingStatus::Ambiguous, $ambiguous->status);
        $this->assertCount(2, $ambiguous->candidates);
        $this->assertCount(1, $matching->findCandidates($sd->id, 'Budi Santoso'));
    }

    public function test_inactive_employee_is_not_matched_and_deactivation_keeps_history(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Intan', '12', 'EMP012');
        $batch = FingerprintImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => 'source.xls',
            'file_hash' => str_repeat('c', 64),
            'status' => ImportBatchStatus::Completed,
        ]);
        $log = FingerprintLog::create([
            'import_batch_id' => $batch->id,
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'fingerprint_id' => '12',
            'attendance_date' => '2026-09-01',
            'attendance_time' => '07:02:00',
            'matching_status' => MatchingStatus::Matched,
        ]);
        $service = app(EmployeeService::class);
        $service->deactivate($employee);

        $this->assertSame(EmployeeStatus::Inactive, $employee->refresh()->status);
        $this->assertSame(MatchingStatus::Unmatched, app(EmployeeMatchingService::class)->findByFingerprint($unit->id, '12')->status);
        $this->assertTrue($log->refresh()->employee->is($employee));

        $service->activate($employee);
        $this->assertSame(EmployeeStatus::Active, $employee->refresh()->status);
        $service->deactivate($employee);

        $replacement = $service->create([
            'unit_id' => $unit->id,
            'nama_lengkap' => 'Replacement',
            'fingerprint_id' => '12',
            'status' => 'active',
        ]);

        $this->assertSame(EmployeeStatus::Inactive, $employee->refresh()->status);
        $this->assertSame(EmployeeStatus::Active, $replacement->status);
        $this->assertSame('Replacement', $replacement->displayName());
    }

    public function test_employee_filter_supports_unit_status_and_search_fields(): void
    {
        $unit = $this->createUnit('SD');
        $employee = $this->createEmployee($unit, 'Dewi Anggraini', '88', 'EMP088', 'Guru');
        $this->createEmployee($unit, 'Dewi inactive', '89', null, null, EmployeeStatus::Inactive);

        $results = app(EmployeeService::class)->filter([
            'unit_id' => $unit->id,
            'status' => 'active',
            'search' => 'DEWI ANGGRaini',
            'fingerprint_id' => '88',
            'position' => 'Guru',
        ]);

        $this->assertCount(1, $results->items());
        $this->assertSame($employee->id, $results->items()[0]->id);
    }

    public function test_nip_can_be_set_through_employee_api_while_fingerprint_id_stays_internal(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('SD');
        $category = WorkScheduleCategory::create([
            'name' => 'NIP API Category',
            'start_time' => '07:00',
            'end_time' => '14:00',
        ]);
        $employee = app(EmployeeService::class)->create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'fingerprint_id' => 'FP-INTERNAL-22',
            'nama_lengkap' => 'NIP Test Employee',
            'status' => EmployeeStatus::Active,
        ]);

        $this->putJson(route('employees.update', $employee), [
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => '1987654321',
        ])
            ->assertOk()
            ->assertJsonPath('data.nip', '1987654321')
            ->assertJsonMissingPath('data.fingerprint_id');

        $employee->refresh();
        $this->assertSame('1987654321', $employee->nip);
        $this->assertSame('FP-INTERNAL-22', $employee->fingerprint_id);

        $this->getJson(route('employees.show', $employee))
            ->assertOk()
            ->assertJsonPath('data.nip', '1987654321')
            ->assertJsonMissingPath('data.fingerprint_id');

        $this->getJson(route('employees.index'))
            ->assertOk()
            ->assertJsonPath('data.0.nip', '1987654321')
            ->assertJsonMissingPath('data.0.fingerprint_id');
    }

    public function test_all_employee_endpoints_require_authentication(): void
    {
        $employee = $this->createEmployee($this->createUnit('TK'), 'Budi');
        $alias = app(EmployeeAliasService::class)->create($employee, 'Budi~');
        $requests = [
            ['GET', '/employees'],
            ['POST', '/employees'],
            ['GET', "/employees/{$employee->id}"],
            ['PUT', "/employees/{$employee->id}"],
            ['POST', "/employees/{$employee->id}/activate"],
            ['POST', "/employees/{$employee->id}/deactivate"],
            ['POST', "/employees/{$employee->id}/aliases"],
            ['PUT', "/employees/{$employee->id}/aliases/{$alias->id}"],
            ['DELETE', "/employees/{$employee->id}/aliases/{$alias->id}"],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
    }

    public function test_authenticated_endpoints_create_update_filter_and_manage_aliases(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit('SD');
        $payload = [
            'unit_id' => $unit->id,
            'category_id' => WorkScheduleCategory::create([
                'name' => 'Kategori API',
                'start_time' => '06:55',
                'end_time' => '16:00',
            ])->id,
            'nip' => '1987654321',
            'fingerprint_id' => '100',
            'nama_lengkap' => 'Nina Permata, S.Pd',
            'position' => 'Guru',
            'status' => 'active',
        ];

        $created = $this->postJson('/employees', $payload)
            ->assertCreated()
            ->assertJsonPath('data.nama_lengkap', 'Nina Permata, S.Pd')
            ->assertJsonPath('data.nip', '1987654321')
            ->assertJsonPath('data.category.name', 'Kategori API')
            ->assertJsonMissingPath('data.fingerprint_id')
            ->json('data.id');

        $this->assertSame('100', Employee::query()->findOrFail($created)->fingerprint_id);

        $aliasId = $this->postJson("/employees/{$created}/aliases", ['alias_name' => 'Nina~'])
            ->assertCreated()
            ->assertJsonPath('data.normalized_alias', 'nina')
            ->json('data.id');

        $this->putJson("/employees/{$created}/aliases/{$aliasId}", ['alias_name' => 'Nina Permata'])
            ->assertOk()
            ->assertJsonPath('data.normalized_alias', 'nina permata');

        $this->getJson('/employees?unit_id='.$unit->id.'&status=active&search=Nina')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->putJson("/employees/{$created}", [
            'nama_lengkap' => 'Nina P., S.Pd',
            'position' => 'Kepala Sekolah',
            'nip' => '1987654321',
        ])
            ->assertOk()
            ->assertJsonPath('data.nama_lengkap', 'Nina P., S.Pd')
            ->assertJsonPath('data.nip', '1987654321')
            ->assertJsonPath('data.position', 'Kepala Sekolah')
            ->assertJsonMissingPath('data.fingerprint_id');

        $otherCategory = WorkScheduleCategory::create([
            'name' => 'Kategori API B',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $this->putJson("/employees/{$created}", ['category_id' => $otherCategory->id])
            ->assertOk()
            ->assertJsonPath('data.category.name', 'Kategori API B');

        $this->postJson("/employees/{$created}", [
            '_method' => 'PUT',
            'position' => 'Kepala Sekolah',
        ])
            ->assertOk()
            ->assertJsonPath('data.position', 'Kepala Sekolah');

        $this->postJson("/employees/{$created}/activate")->assertOk();
        $this->postJson("/employees/{$created}/deactivate")->assertOk();
        $this->deleteJson("/employees/{$created}/aliases/{$aliasId}")->assertNoContent();
    }

    private function createUnit(string $code): Unit
    {
        return Unit::create(['name' => $code, 'code' => $code]);
    }

    private function createEmployee(
        Unit $unit,
        string $name,
        ?string $fingerprintId = null,
        ?string $nip = null,
        ?string $position = null,
        EmployeeStatus $status = EmployeeStatus::Active,
    ): Employee {
        return app(EmployeeService::class)->create([
            'unit_id' => $unit->id,
            'nama_lengkap' => $name,
            'fingerprint_id' => $fingerprintId,
            'nip' => $nip,
            'position' => $position,
            'status' => $status,
        ]);
    }
}
