<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Services\WorkScheduleCategoryService;
use App\Services\WorkScheduleProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkScheduleCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_can_be_created_with_a_unique_name_and_times(): void
    {
        $category = app(WorkScheduleCategoryService::class)->create([
            'name' => '  Kategori A  ',
            'start_time' => '06:55',
            'end_time' => '16:00',
        ]);

        $this->assertSame('Kategori A', $category->name);
        $this->assertSame('06:55', substr($category->start_time, 0, 5));
        $this->assertSame('16:00', substr($category->end_time, 0, 5));
        $this->assertTrue($category->is_active);
        $this->assertDatabaseHas('work_schedule_categories', ['name' => 'Kategori A']);
    }

    public function test_duplicate_names_and_invalid_times_are_rejected(): void
    {
        $service = app(WorkScheduleCategoryService::class);
        $category = $service->create([
            'name' => 'Kategori A',
            'start_time' => '06:55',
            'end_time' => '16:00',
        ]);

        try {
            $service->create([
                'name' => $category->name,
                'start_time' => '06:55',
                'end_time' => '15:40',
            ]);
            $this->fail('A category name must be unique.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        foreach ([
            ['start_time' => '6:55', 'end_time' => '16:00', 'field' => 'start_time'],
            ['start_time' => '06:55', 'end_time' => '25:00', 'field' => 'end_time'],
            ['start_time' => '16:00', 'end_time' => '06:55', 'field' => 'end_time'],
        ] as $case) {
            try {
                $service->create([
                    'name' => 'Invalid '.$case['field'].$case['start_time'],
                    'start_time' => $case['start_time'],
                    'end_time' => $case['end_time'],
                ]);
                $this->fail('Invalid or reversed category hours must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($case['field'], $exception->errors());
            }
        }
    }

    public function test_categories_can_be_updated_deactivated_and_reactivated_without_deletion(): void
    {
        $service = app(WorkScheduleCategoryService::class);
        $category = $service->create([
            'name' => 'Kategori A',
            'start_time' => '06:55',
            'end_time' => '16:00',
        ]);

        $updated = $service->update($category, [
            'name' => 'Kategori B',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $inactive = $service->deactivate($updated);
        $active = $service->activate($inactive);

        $this->assertSame('Kategori B', $active->name);
        $this->assertSame('15:40', substr($active->end_time, 0, 5));
        $this->assertTrue($active->is_active);
        $this->assertDatabaseCount('work_schedule_categories', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_schedule_category.deactivated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_schedule_category.activated']);
    }

    public function test_admin_can_manage_categories_through_the_existing_api_and_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/work-schedule-categories/manage')
            ->assertOk()
            ->assertSee(route('work-schedule-categories.store'))
            ->assertSee('Tambah kategori')
            ->assertDontSee('name="start_time"', false)
            ->assertDontSee('name="end_time"', false);

        $categoryId = $this->postJson('/work-schedule-categories', [
            'name' => 'Kategori Admin',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.start_time', null)
            ->json('data.id');

        $this->putJson("/work-schedule-categories/{$categoryId}", [
            'name' => 'Kategori Admin Revisi',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Kategori Admin Revisi');

        $this->postJson("/work-schedule-categories/{$categoryId}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->postJson("/work-schedule-categories/{$categoryId}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->postJson('/work-schedule-categories', [
            'name' => 'Kategori Admin Revisi',
            'start_time' => '6:55',
            'end_time' => '25:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'start_time', 'end_time']);

        $this->assertFalse(Route::has('work-schedule-categories.destroy'));
        $this->assertDatabaseCount('work_schedule_categories', 1);
    }

    public function test_category_page_shows_normal_alternative_and_per_day_schedule_configuration(): void
    {
        $this->actingAs(User::factory()->create());
        $categoryService = app(WorkScheduleCategoryService::class);
        $legacyCategory = $categoryService->create([
            'name' => 'Legacy',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $profileCategory = $categoryService->create([
            'name' => 'Guru',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $profile = app(WorkScheduleProfileService::class)->create([
            'work_schedule_category_id' => $profileCategory->id,
            'name' => 'Normal',
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);
        $categoryService->update($profileCategory, [
            'name' => $profileCategory->name,
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => $profile->id,
        ]);

        $this->get('/work-schedule-categories/manage')
            ->assertOk()
            ->assertSee('Jadwal Normal')
            ->assertSee('Jadwal Alternatif')
            ->assertSee('Senin')
            ->assertSee('Jumat')
            ->assertSee('rules[1][is_working_day]', false)
            ->assertSee('Normal')
            ->assertSee('08:00')
            ->assertDontSee('Periode Kerja');

        $this->assertNull($legacyCategory->refresh()->work_schedule_profile_id);
    }

    public function test_schedule_configuration_panels_and_add_form_are_collapsed_by_default(): void
    {
        $this->actingAs(User::factory()->create());
        $category = app(WorkScheduleCategoryService::class)->create(['name' => 'Guru']);
        $profileService = app(WorkScheduleProfileService::class);

        foreach ([
            ['name' => 'Normal', 'is_normal' => '1'],
            ['name' => 'Ramadan', 'is_normal' => '0'],
            ['name' => 'Libur Semester', 'is_normal' => '0'],
        ] as $configuration) {
            $profileService->saveForCategory($category, [
                ...$configuration,
                'rules' => $this->weeklyRules('06:55', '15:40'),
            ]);
        }

        $html = $this->get('/work-schedule-categories/manage')->assertOk()->getContent();
        preg_match_all('/<details\b[^>]*>/', $html, $details);
        preg_match_all('/<details\b[^>]*\bopen(?:\s|=|>)/', $html, $openDetails);

        $this->assertCount(4, $details[0]);
        $this->assertCount(0, $openDetails[0]);
        $this->assertSame(3, substr_count($html, 'name="schedule-configurations-'.$category->id.'"'));
        $this->assertStringContainsString('aria-controls="profile-details-1"', $html);
        $this->assertStringContainsString('id="profile-summary-1"', $html);
        $this->assertStringContainsString('6 hari kerja', $html);
        $this->assertStringContainsString('Tambah konfigurasi jadwal', $html);
        $this->assertStringContainsString('rules[6][end_time]', $html);

        $this->assertDatabaseCount('work_schedule_profiles', 3);
        $this->assertDatabaseCount('work_schedule_profile_rules', 21);
    }

    public function test_category_assignment_changes_only_profile_foreign_key_and_can_be_cleared(): void
    {
        $this->actingAs(User::factory()->create());
        $category = app(WorkScheduleCategoryService::class)->create([
            'name' => 'Guru',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $profile = app(WorkScheduleProfileService::class)->create([
            'work_schedule_category_id' => $category->id,
            'name' => 'Ramadhan',
            'start_time' => '08:00',
            'end_time' => '15:00',
        ]);

        $this->putJson("/work-schedule-categories/{$category->id}", [
            'name' => $category->name,
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => $profile->id,
        ])->assertOk();

        $this->assertDatabaseHas('work_schedule_categories', [
            'id' => $category->id,
            'work_schedule_profile_id' => $profile->id,
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);

        $this->putJson("/work-schedule-categories/{$category->id}", [
            'name' => $category->name,
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => null,
        ])->assertOk();

        $this->assertDatabaseHas('work_schedule_categories', [
            'id' => $category->id,
            'work_schedule_profile_id' => null,
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
    }

    public function test_profile_can_be_created_from_category_row_and_becomes_its_current_profile(): void
    {
        $this->actingAs(User::factory()->create());
        $category = app(WorkScheduleCategoryService::class)->create([
            'name' => 'Guru',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);

        $profileId = $this->postJson("/work-schedule-categories/{$category->id}/profiles", [
            'name' => 'Normal',
            'start_time' => '08:00',
            'end_time' => '16:00',
        ])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('work_schedule_profiles', [
            'id' => $profileId,
            'work_schedule_category_id' => $category->id,
            'name' => 'Normal',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('work_schedule_categories', [
            'id' => $category->id,
            'work_schedule_profile_id' => $profileId,
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);

        $this->get('/work-schedule-categories/manage')
            ->assertOk()
            ->assertSee('Normal')
            ->assertSee('name="is_normal"', false)
            ->assertSee('id="profile-name-'.$profileId.'"', false);

        $this->postJson("/work-schedule-categories/{$category->id}/profiles", [
            'name' => 'Normal',
            'start_time' => '07:00',
            'end_time' => '15:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $otherCategory = app(WorkScheduleCategoryService::class)->create([
            'name' => 'GTT',
            'start_time' => '06:55',
            'end_time' => '16:00',
        ]);

        $this->postJson("/work-schedule-categories/{$otherCategory->id}/profiles", [
            'name' => 'Normal',
            'start_time' => '06:55',
            'end_time' => '16:00',
        ])->assertCreated();
    }

    public function test_each_category_has_independent_normal_and_alternative_profiles(): void
    {
        $this->actingAs(User::factory()->create());
        $categoryService = app(WorkScheduleCategoryService::class);
        $btq = $categoryService->create([
            'name' => 'BTQ/Madin',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $gtt = $categoryService->create([
            'name' => 'GTT',
            'start_time' => '06:55',
            'end_time' => '16:00',
        ]);

        $btqProfile = app(WorkScheduleProfileService::class)->create([
            'work_schedule_category_id' => $btq->id,
            'name' => 'Ramadan BTQ',
            'start_time' => '07:00',
            'end_time' => '14:00',
        ]);
        $gttProfile = app(WorkScheduleProfileService::class)->create([
            'work_schedule_category_id' => $gtt->id,
            'name' => 'Libur Semester GTT',
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        $html = $this->get('/work-schedule-categories/manage')->assertOk()->getContent();

        $this->assertStringContainsString('/work-schedule-categories/'.$btq->id.'/profiles/'.$btqProfile->id, $html);
        $this->assertStringContainsString('/work-schedule-categories/'.$gtt->id.'/profiles/'.$gttProfile->id, $html);
        $this->assertStringContainsString('Ramadan BTQ', $html);
        $this->assertStringContainsString('Libur Semester GTT', $html);
        $this->assertStringContainsString('rules[5][start_time]', $html);
        $this->assertStringContainsString('rules[6][end_time]', $html);
    }

    public function test_profile_modal_updates_current_profile_instead_of_creating_duplicate(): void
    {
        $this->actingAs(User::factory()->create());
        $category = app(WorkScheduleCategoryService::class)->create([
            'name' => 'BTQ/Madin',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $profile = app(WorkScheduleProfileService::class)->create([
            'work_schedule_category_id' => $category->id,
            'name' => 'Normal',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        app(WorkScheduleCategoryService::class)->assignProfile($category, $profile);

        $this->putJson("/work-schedule-categories/{$category->id}/profiles/{$profile->id}", [
            'work_schedule_category_id' => $category->id,
            'name' => 'Normal',
            'start_time' => '06:55',
            'end_time' => '16:00',
        ])->assertOk()
            ->assertJsonPath('data.id', $profile->id);

        $this->assertDatabaseCount('work_schedule_profiles', 1);
        $this->assertSame('16:00', substr((string) $profile->fresh()->end_time, 0, 5));
        $this->assertDatabaseHas('work_schedule_categories', [
            'id' => $category->id,
            'work_schedule_profile_id' => $profile->id,
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $this->assertSame([
            'start_time' => '06:55',
            'end_time' => '16:00',
        ], $category->refresh()->effectiveWorkTime());
    }

    public function test_profiles_remain_owned_by_their_category_and_cannot_be_assigned_across_categories(): void
    {
        $this->actingAs(User::factory()->create());
        $categoryService = app(WorkScheduleCategoryService::class);
        $firstCategory = $categoryService->create([
            'name' => 'BTQ/Madin',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $secondCategory = $categoryService->create([
            'name' => 'GTT',
            'start_time' => '06:55',
            'end_time' => '16:00',
        ]);
        $firstCategoryProfile = app(WorkScheduleProfileService::class)->create([
            'work_schedule_category_id' => $firstCategory->id,
            'name' => 'Ramadhan BTQ',
            'start_time' => '07:00',
            'end_time' => '14:00',
        ]);
        $otherCategoryProfile = app(WorkScheduleProfileService::class)->create([
            'work_schedule_category_id' => $secondCategory->id,
            'name' => 'Ramadhan GTT',
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);

        $html = $this->get('/work-schedule-categories/manage')->assertOk()->getContent();
        $this->assertStringContainsString('/work-schedule-categories/'.$firstCategory->id.'/profiles/'.$firstCategoryProfile->id, $html);
        $this->assertStringContainsString('/work-schedule-categories/'.$secondCategory->id.'/profiles/'.$otherCategoryProfile->id, $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Jadwal Alternatif'));

        $this->putJson("/work-schedule-categories/{$firstCategory->id}", [
            'name' => $firstCategory->name,
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => $otherCategoryProfile->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['work_schedule_profile_id']);

        $this->assertDatabaseHas('work_schedule_categories', [
            'id' => $firstCategory->id,
            'work_schedule_profile_id' => null,
        ]);
    }

    public function test_existing_inactive_profile_assignment_remains_selected_and_cannot_be_assigned_to_another_category(): void
    {
        $this->actingAs(User::factory()->create());
        $profileService = app(WorkScheduleProfileService::class);
        $categoryService = app(WorkScheduleCategoryService::class);
        $assignedCategory = $categoryService->create([
            'name' => 'Guru',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $profile = $profileService->create([
            'work_schedule_category_id' => $assignedCategory->id,
            'name' => 'Jumat Pendek',
            'start_time' => '08:00',
            'end_time' => '11:30',
        ]);
        $categoryService->update($assignedCategory, [
            'name' => $assignedCategory->name,
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => $profile->id,
        ]);
        $profileService->deactivate($profile);

        $this->get('/work-schedule-categories/manage')
            ->assertOk()
            ->assertSee('Jumat Pendek')
            ->assertSee('Nonaktif')
            ->assertSee('Jadwal Normal');

        $this->putJson("/work-schedule-categories/{$assignedCategory->id}", [
            'name' => 'Guru',
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => $profile->id,
        ])->assertOk();

        $otherCategory = $categoryService->create([
            'name' => 'GTT',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);

        $this->putJson("/work-schedule-categories/{$otherCategory->id}", [
            'name' => 'GTT',
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => $profile->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['work_schedule_profile_id']);

        $this->assertDatabaseHas('work_schedule_categories', [
            'id' => $assignedCategory->id,
            'work_schedule_profile_id' => $profile->id,
        ]);
        $this->assertDatabaseHas('work_schedule_categories', [
            'id' => $otherCategory->id,
            'work_schedule_profile_id' => null,
        ]);
    }

    private function weeklyRules(string $start, string $end): array
    {
        $rules = [];
        foreach (range(0, 6) as $day) {
            $rules[$day] = [
                'is_working_day' => $day !== 0,
                'start_time' => $day === 0 ? '' : $start,
                'end_time' => $day === 0 ? '' : $end,
            ];
        }

        return $rules;
    }
}