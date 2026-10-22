<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfileRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeScheduleProfileUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_table_separates_category_and_schedule_and_shows_unassigned_profile(): void
    {
        $category = $this->createCategory('Staff');
        $employee = $this->createEmployee($category);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('employees.manage'))->assertOk();
        $html = $response->getContent();
        $headerStart = strpos($html, '<thead');
        $headerEnd = strpos($html, '</thead>', $headerStart);
        $headers = substr($html, $headerStart, $headerEnd - $headerStart);

        $this->assertStringContainsString('>Kategori</th>', $headers);
        $this->assertStringContainsString('>Jam Kerja</th>', $headers);
        $this->assertStringNotContainsString('Kategori / Jam Kerja', $headers);
        $this->assertStringContainsString('Belum dipilih', $html);
        $this->assertStringContainsString('data-schedule-profile-select', $html);
        $this->assertStringContainsString('name="work_schedule_profile_id"', $html);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'work_schedule_profile_id' => null,
        ]);
    }

    public function test_add_and_edit_profile_dropdowns_only_offer_valid_profiles_for_their_category(): void
    {
        $staff = $this->createCategory('Staff');
        $btq = $this->createCategory('BTQ/Madin');
        $normal = $this->createProfile($staff, 'Normal');
        $semester = $this->createProfile($staff, 'Liburan Semester');
        $this->createProfile($staff, 'Inactive profile', false);
        WorkScheduleProfile::create([
            'work_schedule_category_id' => $staff->id,
            'name' => 'Incomplete profile',
            'start_time' => null,
            'end_time' => null,
            'is_active' => true,
        ]);
        $other = $this->createProfile($btq, 'BTQ Normal');
        $employee = $this->createEmployee($staff, $semester->id);
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('employees.manage'))->assertOk()->getContent();

        $this->assertStringContainsString('data-category-id="'.$staff->id.'"', $html);
        $this->assertStringContainsString('value="'.$normal->id.'" data-category-id="'.$staff->id.'"', $html);
        $this->assertStringContainsString('value="'.$semester->id.'"', $html);
        $this->assertStringNotContainsString('value="'.$other->id.'" data-category-id="'.$staff->id.'"', $html);
        $this->assertStringNotContainsString('value="'.$this->profileIdByName($staff, 'Inactive profile').'" data-category-id="'.$staff->id.'"', $html);
        $this->assertStringNotContainsString('Incomplete profile', $html);
        $this->assertMatchesRegularExpression(
            '/value="'.$semester->id.'"\\s+data-category-id="'.$staff->id.'"\\s+selected/s',
            $html,
        );
        $this->assertSame($staff->id, $employee->fresh()->category_id);
        $this->assertSame($semester->id, $employee->fresh()->work_schedule_profile_id);

        $createCategorySelect = substr($html, strpos($html, 'id="employee-category"'), 600);
        $this->assertStringContainsString('data-schedule-category-select', $createCategorySelect);
        $this->assertStringContainsString('disabled', substr($html, strpos($html, 'id="employee-work-profile"'), 240));
    }

    public function test_mass_profile_actions_remain_disabled_and_readiness_is_global_not_filter_scoped(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => null]);
        $readyCategory = $this->createCategory('Ready Category');
        $inactiveCategory = $this->createCategory('Inactive Category', false);
        $normal = $this->createProfile($readyCategory, 'Normal');
        $readyEmployee = $this->createEmployee($readyCategory);
        $blockedEmployee = $this->createEmployee($inactiveCategory);
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('employees.manage', [
            'status' => EmployeeStatus::Inactive->value,
            'search' => 'No matching employee',
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button\b(?=[^>]*\bdisabled\b)[^>]*>.*?Terapkan Normal.*?<\/button>/s', $html);
        $this->assertMatchesRegularExpression('/<button\b(?=[^>]*\bdisabled\b)[^>]*>.*?Terapkan Liburan Semester.*?<\/button>/s', $html);
        $this->assertStringContainsString('cakupan penerapan belum disetujui', mb_strtolower($html));
        $this->assertStringContainsString('Ready Category', $html);
        $this->assertStringContainsString('Inactive Category (Nonaktif)', $html);
        $this->assertStringContainsString('data-ready-employee-count="1"', $html);
        $this->assertStringContainsString('Kategori nonaktif dikecualikan dari cakupan penerapan.', $html);
        $this->assertDatabaseHas('employees', [
            'id' => $readyEmployee->id,
            'work_schedule_profile_id' => null,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $blockedEmployee->id,
            'work_schedule_profile_id' => null,
        ]);
        $this->assertDatabaseHas('work_schedule_profiles', [
            'id' => $normal->id,
            'name' => 'Normal',
        ]);
    }

    public function test_mass_profile_buttons_use_independent_readiness_and_exclude_inactive_categories(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'active_categories_only']);
        $readyCategory = $this->createCategory('Ready Category');
        $inactiveCategory = $this->createCategory('Inactive Category', false);
        $this->createProfile($readyCategory, 'Normal');
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('employees.manage'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button\b(?![^>]*\bdisabled\b)[^>]*data-target="normal"[^>]*>.*?Terapkan Normal.*?<\/button>/s', $html);
        $this->assertMatchesRegularExpression('/<button\b(?=[^>]*\bdisabled\b)[^>]*data-target="liburan-semester"[^>]*>.*?Terapkan Liburan Semester.*?<\/button>/s', $html);
        $this->assertStringContainsString('Masih ada 1 kategori aktif yang belum memiliki profil Liburan Semester valid.', $html);
        $this->assertStringContainsString('Kategori nonaktif dikecualikan dari cakupan penerapan.', $html);
        $this->assertStringContainsString('Inactive Category (Nonaktif)', $html);
    }

    public function test_readiness_details_are_in_a_separate_dialog_and_do_not_start_preview(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'active_categories_only']);
        $readyCategory = $this->createCategory('Ready Details Category');
        $inactiveCategory = $this->createCategory('Excluded Details Category', false);
        $this->createProfile($readyCategory, 'Normal');
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('employees.manage'))->assertOk()->getContent();
        $dialogStart = strpos($html, '<dialog id="mass-profile-readiness-dialog"');
        $dialogEnd = strpos($html, '</dialog>', $dialogStart);
        $dialog = substr($html, $dialogStart, $dialogEnd - $dialogStart);

        $this->assertNotFalse($dialogStart);
        $this->assertNotFalse($dialogEnd);
        $this->assertStringContainsString('Lihat Detail Kesiapan', $html);
        $this->assertStringContainsString('Detail Kesiapan Profil', $dialog);
        $this->assertStringContainsString('data-readiness-target="Normal"', $dialog);
        $this->assertStringContainsString('data-readiness-target="Liburan Semester"', $dialog);
        $this->assertStringContainsString('Ready Details Category', $dialog);
        $this->assertStringContainsString('Excluded Details Category (Nonaktif)', $dialog);
        $this->assertStringContainsString('Dikecualikan', $dialog);
        $this->assertStringNotContainsString('data-mass-profile-preview', $dialog);
        $this->assertStringContainsString('Membukanya tidak memulai pratinjau atau penerapan.', $dialog);
    }

    public function test_mass_profile_confirmation_dialog_is_available_only_for_an_explicit_test_scope(): void
    {
        config(['employee_schedule_profiles.approved_mass_assignment_scope' => 'active_categories_only']);
        $category = $this->createCategory('Ready Category');
        $this->createProfile($category, 'Normal');
        $this->createProfile($category, 'Liburan Semester');
        $this->createEmployee($category);
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('employees.manage'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button\b(?![^>]*\bdisabled\b)[^>]*data-target="normal"[^>]*>.*?Terapkan Normal.*?<\/button>/s', $html);
        $this->assertMatchesRegularExpression('/<button\b(?![^>]*\bdisabled\b)[^>]*data-target="liburan-semester"[^>]*>.*?Terapkan Liburan Semester.*?<\/button>/s', $html);
        $this->assertStringContainsString('id="mass-profile-dialog"', $html);
        $this->assertStringContainsString('data-mass-profile-confirm', $html);
        $this->assertStringContainsString('Snapshot jadwal pada absensi historis tidak diubah langsung', $html);
    }

    public function test_employee_can_create_and_update_with_active_matching_profile_and_null_is_allowed(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit();
        $category = $this->createCategory('Staff');
        $profile = $this->createProfile($category, 'Normal');

        $createdId = $this->postJson('/employees', [
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'work_schedule_profile_id' => $profile->id,
            'nama_lengkap' => 'New employee',
            'status' => EmployeeStatus::Active->value,
        ])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('employees', [
            'id' => $createdId,
            'category_id' => $category->id,
            'work_schedule_profile_id' => $profile->id,
        ]);

        $employee = $this->createEmployee($category);
        $this->putJson(route('employees.update', $employee), [
            'work_schedule_profile_id' => $profile->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.work_schedule_profile_id', $profile->id);

        $this->putJson(route('employees.update', $employee), [
            'work_schedule_profile_id' => null,
        ])->assertOk();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'category_id' => $category->id,
            'work_schedule_profile_id' => null,
        ]);
    }

    public function test_edit_shows_a_stored_profile_that_is_no_longer_available_without_reassigning_it(): void
    {
        $this->actingAs(User::factory()->create());
        $category = $this->createCategory('Staff');
        $profile = $this->createProfile($category, 'Normal');
        $employee = $this->createEmployee($category, $profile->id);
        $profile->update(['is_active' => false]);

        $html = $this->get(route('employees.manage'))->assertOk()->getContent();

        $this->assertStringContainsString('Normal (Nonaktif)', $html);
        $this->assertStringContainsString(
            'value="'.$profile->id.'" data-category-id="'.$category->id.'" data-selectable="false" disabled selected',
            $html,
        );

        $this->putJson(route('employees.update', $employee), [
            'nama_lengkap' => 'Unrelated edit',
        ])->assertOk();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'work_schedule_profile_id' => $profile->id,
        ]);
    }

    public function test_backend_rejects_profile_owned_by_another_category_or_profile_that_is_inactive(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = $this->createUnit();
        $staff = $this->createCategory('Staff');
        $btq = $this->createCategory('BTQ/Madin');
        $foreignProfile = $this->createProfile($btq, 'BTQ Normal');
        $inactiveProfile = $this->createProfile($staff, 'Inactive', false);
        $employee = $this->createEmployee($staff);

        $this->putJson(route('employees.update', $employee), [
            'work_schedule_profile_id' => $foreignProfile->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_schedule_profile_id');

        $this->putJson(route('employees.update', $employee), [
            'work_schedule_profile_id' => $inactiveProfile->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_schedule_profile_id');

        $this->postJson('/employees', [
            'unit_id' => $unit->id,
            'category_id' => $staff->id,
            'work_schedule_profile_id' => $foreignProfile->id,
            'status' => EmployeeStatus::Active->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_schedule_profile_id');

        $this->assertNull($employee->fresh()->work_schedule_profile_id);
        $this->assertSame($staff->id, $employee->fresh()->category_id);
    }

    public function test_changing_category_clears_an_incompatible_profile_without_changing_category_automatically(): void
    {
        $this->actingAs(User::factory()->create());
        $staff = $this->createCategory('Staff');
        $btq = $this->createCategory('BTQ/Madin');
        $staffProfile = $this->createProfile($staff, 'Normal');
        $employee = $this->createEmployee($staff, $staffProfile->id);

        $this->putJson(route('employees.update', $employee), [
            'category_id' => $btq->id,
            'nama_lengkap' => 'Changed category',
        ])->assertOk();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'category_id' => $btq->id,
            'work_schedule_profile_id' => null,
        ]);
    }

    public function test_employee_profile_cannot_be_assigned_when_category_is_inactive(): void
    {
        $this->actingAs(User::factory()->create());
        $inactiveCategory = $this->createCategory('Inactive', false);
        $profile = $this->createProfile($inactiveCategory, 'Inactive owner profile');
        $employee = $this->createEmployee($this->createCategory('Staff'));

        $this->putJson(route('employees.update', $employee), [
            'category_id' => $inactiveCategory->id,
            'work_schedule_profile_id' => $profile->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    private function createUnit(): Unit
    {
        return Unit::firstOrCreate(['code' => 'SD'], ['name' => 'SD']);
    }

    private function createCategory(string $name, bool $active = true): WorkScheduleCategory
    {
        return WorkScheduleCategory::create(['name' => $name, 'is_active' => $active]);
    }

    private function createProfile(
        WorkScheduleCategory $category,
        string $name,
        bool $active = true,
    ): WorkScheduleProfile {
        $profile = WorkScheduleProfile::create([
            'work_schedule_category_id' => $category->id,
            'name' => $name,
            'start_time' => '07:00',
            'end_time' => '15:00',
            'is_active' => $active,
        ]);

        for ($day = 0; $day <= 6; $day++) {
            WorkScheduleProfileRule::create([
                'work_schedule_profile_id' => $profile->id,
                'day_of_week' => $day,
                'is_working_day' => $day !== 0,
                'start_time' => $day === 0 ? null : '07:00',
                'end_time' => $day === 0 ? null : '15:00',
            ]);
        }

        return $profile;
    }

    private function createEmployee(
        WorkScheduleCategory $category,
        ?int $profileId = null,
    ): Employee {
        return Employee::create([
            'unit_id' => $this->createUnit()->id,
            'category_id' => $category->id,
            'work_schedule_profile_id' => $profileId,
            'nama_lengkap' => 'Test employee',
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function profileIdByName(WorkScheduleCategory $category, string $name): int
    {
        return (int) WorkScheduleProfile::query()
            ->where('work_schedule_category_id', $category->id)
            ->where('name', $name)
            ->value('id');
    }
}
