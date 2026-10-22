<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkScheduleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_forms_target_existing_authenticated_endpoints(): void
    {
        $tk = Unit::create(['name' => 'TK', 'code' => 'TK']);
        $sd = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $employee = Employee::create([
            'unit_id' => $tk->id,
            'fingerprint_id' => 'FP-TK-001',
            'nama_lengkap' => 'Ayu',
            'position' => 'Guru',
            'status' => EmployeeStatus::Active,
        ]);

        $this->actingAs(User::factory()->create());

        $this->get('/employees/manage')
            ->assertOk()
            ->assertSee(route('employees.store'))
            ->assertSee(route('employees.update', $employee))
            ->assertSee('data-employee-edit="'.$employee->id.'"', false)
            ->assertSee('data-employee-profile-form="'.$employee->id.'"', false)
            ->assertSee('name="position"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('name="category_id"', false)
            ->assertSee('Belum dikategorikan')
            ->assertSee(route('employees.aliases.store', $employee))
            ->assertSee(route('employees.deactivate', $employee));

        $this->get('/fingerprint-import')
            ->assertOk()
            ->assertSee(route('fingerprint-imports.preview'))
            ->assertSee(route('fingerprint-imports.sync'))
            ->assertSee(route('fingerprint-imports.store'))
            ->assertSee('Sinkronkan Pegawai')
            ->assertSee('Import Data')
            ->assertSee('name="unit_id"', false)
            ->assertSee((string) $sd->id);

        $this->get('/leave-import')
            ->assertOk()
            ->assertSee(route('leave-imports.preview'))
            ->assertSee(route('leave-imports.store'))
            ->assertSee(route('employees.search'))
            ->assertSee(route('leave-requests.manual.store'))
            ->assertSee('name="unit_id"', false)
            ->assertSee('data-manual-leave-form', false);

        $this->get('/work-calendar')
            ->assertOk()
            ->assertSee(route('work-calendars.store'))
            ->assertSee(route('work-schedule.index'));

        $this->get('/work-schedule')
            ->assertOk()
            ->assertSee(route('work-schedules.store'));

        $this->get('/work-schedule-categories/manage')
            ->assertOk()
            ->assertSee(route('work-schedule-categories.store'))
            ->assertSee('Kategori Jam Kerja');
    }

    public function test_employee_manage_page_paginates_and_combines_dynamic_unit_and_category_filters(): void
    {
        $sd = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $tk = Unit::create(['name' => 'TK', 'code' => 'TK']);
        $guru = WorkScheduleCategory::create([
            'name' => 'Guru',
            'start_time' => '07:00',
            'end_time' => '14:00',
        ]);
        $staff = WorkScheduleCategory::create([
            'name' => 'Staff',
            'start_time' => '08:00',
            'end_time' => '15:00',
        ]);
        $newCategory = WorkScheduleCategory::create([
            'name' => 'Laboran',
            'start_time' => null,
            'end_time' => null,
        ]);

        foreach (range(1, 54) as $number) {
            $name = sprintf('Employee %02d', $number);
            Employee::create([
                'unit_id' => $sd->id,
                'category_id' => $number <= 20 ? $guru->id : $staff->id,
                'fingerprint_id' => (string) $number,
                'nama_lengkap' => $name,
                'status' => EmployeeStatus::Active,
            ]);
        }

        Employee::create([
            'unit_id' => $tk->id,
            'category_id' => $guru->id,
            'fingerprint_id' => 'TK-1',
            'nama_lengkap' => 'Employee TK',
            'status' => EmployeeStatus::Active,
        ]);

        $this->actingAs(User::factory()->create());

        $firstPage = $this->get(route('employees.manage'))
            ->assertOk()
            ->assertSee('55 data')
            ->assertSee('>55</div>', false)
            ->assertSee('>0</div>', false)
            ->assertSee('Menampilkan 1–15 dari 55 pegawai')
            ->assertSee('Laboran');

        $this->assertSame(15, substr_count($firstPage->getContent(), '<tr class="hover:bg-slate-50">'));
        $this->assertSame(55, Employee::query()->count());

        $unitFilter = $this->get(route('employees.manage', ['unit_id' => $sd->id]))
            ->assertOk()
            ->assertSee('Menampilkan 1–15 dari 54 pegawai')
            ->assertSee('SD')
            ->assertDontSee('Employee TK');

        $this->assertSame(15, substr_count($unitFilter->getContent(), '<tr class="hover:bg-slate-50">'));
        $this->assertTrue($unitFilter->viewData('employees')->hasPages());
        $this->assertSame(54, $unitFilter->viewData('employees')->total());

        $categoryFilter = $this->get(route('employees.manage', ['category_id' => $guru->id]))
            ->assertOk()
            ->assertSee('Menampilkan 1–15 dari 21 pegawai');
        $this->assertSame(21, $categoryFilter->viewData('employees')->total());
        $this->assertSame(2, $categoryFilter->viewData('employees')->lastPage());

        $combinedFilterPageTwo = $this->get(route('employees.manage', [
            'unit_id' => $sd->id,
            'category_id' => $guru->id,
            'page' => 2,
        ]))
            ->assertOk()
            ->assertSee('Menampilkan 16–20 dari 20 pegawai')
            ->assertSee('value="'.$sd->id.'" selected', false)
            ->assertSee('value="'.$guru->id.'" selected', false)
            ->assertDontSee('Employee TK');

        $this->assertSame(5, substr_count($combinedFilterPageTwo->getContent(), '<tr class="hover:bg-slate-50">'));
        $paginator = $combinedFilterPageTwo->viewData('employees');
        $this->assertSame(2, $paginator->lastPage());
        $this->assertSame(20, $paginator->total());
        $this->assertStringContainsString('unit_id='.$sd->id, $paginator->url(1));
        $this->assertStringContainsString('category_id='.$guru->id, $paginator->url(1));
        $this->assertSame(1, WorkScheduleCategory::query()->whereKey($newCategory->id)->count());

        $this->get(route('employees.manage', ['unit_id' => 999999]))
            ->assertOk()
            ->assertSee('Tidak ada pegawai yang sesuai dengan filter.')
            ->assertSee('Menampilkan 0–0 dari 0 pegawai')
            ->assertSee('href="'.route('employees.manage').'"', false);
    }

    public function test_employee_page_displays_nip_but_not_fingerprint_id(): void
    {
        $unit = Unit::create(['name' => 'TK', 'code' => 'TK']);
        Employee::create([
            'unit_id' => $unit->id,
            'fingerprint_id' => 'FP-INTERNAL-SECRET',
            'nip' => '1987654321',
            'nama_lengkap' => 'Pegawai NIP',
            'status' => EmployeeStatus::Active,
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('employees.manage'))
            ->assertOk()
            ->assertSee('NIP')
            ->assertSee('1987654321')
            ->assertDontSee('FP-INTERNAL-SECRET')
            ->assertSee('name="fingerprint_id"', false)
            ->assertSee('ID Fingerprint');
    }
}