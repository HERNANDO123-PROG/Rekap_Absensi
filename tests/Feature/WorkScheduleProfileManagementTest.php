<?php

namespace Tests\Feature;

use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Services\WorkScheduleCategoryService;
use App\Services\WorkScheduleProfileService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkScheduleProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    private ?WorkScheduleCategory $category = null;

    public function test_valid_profile_can_be_created_with_default_active_status(): void
    {
        $profile = app(WorkScheduleProfileService::class)->create([
            ...$this->profileData(),
            'name' => '  Normal  ',
        ]);

        $this->assertSame('Normal', $profile->name);
        $this->assertSame('06:55', substr((string) $profile->start_time, 0, 5));
        $this->assertSame('15:40', substr((string) $profile->end_time, 0, 5));
        $this->assertTrue($profile->is_active);
        $this->assertDatabaseHas('work_schedule_profiles', [
            'name' => 'Normal',
            'is_active' => true,
        ]);
    }

    public function test_profile_name_is_required_and_unique_within_its_category(): void
    {
        $service = app(WorkScheduleProfileService::class);
        $service->create($this->profileData());

        $this->assertValidationError(
            fn () => $service->create($this->profileData('')),
            'name',
        );
        $this->assertValidationError(
            fn () => $service->create($this->profileData('Normal')),
            'name',
        );
        $this->assertDatabaseCount('work_schedule_profiles', 1);
    }

    public function test_profile_requires_valid_start_and_end_times_and_end_must_be_later(): void
    {
        $service = app(WorkScheduleProfileService::class);

        foreach ([
            ['start_time' => null, 'end_time' => '15:40', 'field' => 'start_time'],
            ['start_time' => '06:55', 'end_time' => null, 'field' => 'end_time'],
            ['start_time' => '6:55', 'end_time' => '15:40', 'field' => 'start_time'],
            ['start_time' => '06:55', 'end_time' => '25:00', 'field' => 'end_time'],
            ['start_time' => '15:40', 'end_time' => '06:55', 'field' => 'end_time'],
            ['start_time' => '06:55', 'end_time' => '06:55', 'field' => 'end_time'],
        ] as $case) {
            $this->assertValidationError(
                fn () => $service->create($this->profileData(
                    'Invalid '.$case['field'].$case['start_time'],
                    $case['start_time'],
                    $case['end_time'],
                )),
                $case['field'],
            );
        }
    }

    public function test_category_supports_independent_normal_and_alternative_day_by_day_profiles(): void
    {
        $category = $this->category();
        $service = app(WorkScheduleProfileService::class);
        $normalRules = $this->weeklyRules('06:55', '15:40');
        $normalRules[5] = ['is_working_day' => true, 'start_time' => '06:55', 'end_time' => '15:00'];
        $normalRules[6] = ['is_working_day' => true, 'start_time' => '08:00', 'end_time' => '11:00'];

        $normal = $service->saveForCategory($category, [
            'name' => 'Normal',
            'is_normal' => '1',
            'rules' => $normalRules,
        ]);
        $alternativeRules = $this->weeklyRules('07:30', '14:00');
        $alternative = $service->saveForCategory($category, [
            'name' => 'Ramadan',
            'is_normal' => '0',
            'rules' => $alternativeRules,
        ]);
        $semester = $service->saveForCategory($category, [
            'name' => 'Libur Semester',
            'is_normal' => '0',
            'rules' => $this->weeklyRules('08:00', '12:00'),
        ]);

        $this->assertSame($normal->id, $category->fresh()->work_schedule_profile_id);
        $this->assertCount(3, $category->fresh()->profiles);
        $this->assertDatabaseHas('work_schedule_profile_rules', [
            'work_schedule_profile_id' => $normal->id,
            'day_of_week' => 5,
            'start_time' => '06:55',
            'end_time' => '15:00',
        ]);
        $this->assertDatabaseHas('work_schedule_profile_rules', [
            'work_schedule_profile_id' => $normal->id,
            'day_of_week' => 6,
            'start_time' => '08:00',
            'end_time' => '11:00',
        ]);
        $this->assertDatabaseHas('work_schedule_profile_rules', [
            'work_schedule_profile_id' => $alternative->id,
            'day_of_week' => 5,
            'start_time' => '07:30',
            'end_time' => '14:00',
        ]);
        $this->assertDatabaseHas('work_schedule_profile_rules', [
            'work_schedule_profile_id' => $semester->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        $this->assertDatabaseHas('work_schedule_profile_rules', [
            'work_schedule_profile_id' => $normal->id,
            'day_of_week' => 0,
            'is_working_day' => false,
            'start_time' => null,
            'end_time' => null,
        ]);
    }

    public function test_daily_profile_requires_all_days_and_valid_hours_only_for_working_days(): void
    {
        $category = $this->category();
        $service = app(WorkScheduleProfileService::class);
        $rules = $this->weeklyRules('06:55', '15:40');

        foreach ([
            [array_slice($rules, 0, 6, true), 'rules'],
            [$this->weeklyRules('15:40', '06:55'), 'rules.1.end_time'],
            [array_replace($rules, [0 => ['is_working_day' => false, 'start_time' => '08:00', 'end_time' => '']]), 'rules.0.start_time'],
        ] as [$invalidRules, $field]) {
            try {
                $service->saveForCategory($category, [
                    'name' => 'Invalid',
                    'is_normal' => '0',
                    'rules' => $invalidRules,
                ]);
                $this->fail('An invalid daily schedule must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }

        $this->assertDatabaseCount('work_schedule_profiles', 0);
        $this->assertDatabaseCount('work_schedule_profile_rules', 0);
    }

    public function test_profile_can_be_updated_and_active_status_must_be_a_valid_boolean(): void
    {
        $service = app(WorkScheduleProfileService::class);
        $profile = $service->create($this->profileData());

        $updated = $service->update($profile, [
            ...$this->profileData('Ramadhan', '07:00', '14:00'),
            'is_active' => false,
        ]);

        $this->assertSame('Ramadhan', $updated->name);
        $this->assertSame('07:00', substr((string) $updated->start_time, 0, 5));
        $this->assertSame('14:00', substr((string) $updated->end_time, 0, 5));
        $this->assertFalse($updated->is_active);

        $this->assertValidationError(
            fn () => $service->update($updated, [
                ...$this->profileData('Ramadhan'),
                'is_active' => 'invalid',
            ]),
            'is_active',
        );
        $this->assertValidationError(
            fn () => $service->update($updated, [
                ...$this->profileData('Ramadhan'),
                'is_active' => null,
            ]),
            'is_active',
        );

        $stillInactive = $service->update($updated, $this->profileData('Ramadhan Revisi'));
        $this->assertFalse($stillInactive->is_active);
        $this->assertSame('Ramadhan Revisi', $stillInactive->name);
    }

    public function test_profile_can_be_deactivated_and_reactivated_without_deletion(): void
    {
        $service = app(WorkScheduleProfileService::class);
        $profile = $service->create($this->profileData());

        $inactive = $service->deactivate($profile);
        $active = $service->activate($inactive);

        $this->assertTrue($active->is_active);
        $this->assertDatabaseCount('work_schedule_profiles', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_schedule_profile.deactivated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_schedule_profile.activated']);
    }

    public function test_two_categories_can_have_a_profile_with_the_same_name(): void
    {
        $categoryService = app(WorkScheduleCategoryService::class);
        $firstCategory = $this->category();
        $secondCategory = $categoryService->create([
            'name' => 'GTT',
            'start_time' => '06:55',
            'end_time' => '16:00',
        ]);
        $service = app(WorkScheduleProfileService::class);

        $firstProfile = $service->create($this->profileData('Normal', '06:55', '15:40'));
        $secondProfile = $service->create([
            ...$this->profileData('Normal', '06:55', '16:00'),
            'work_schedule_category_id' => $secondCategory->id,
        ]);

        $this->assertSame('Normal', $firstProfile->name);
        $this->assertSame('Normal', $secondProfile->name);
        $this->assertTrue($firstProfile->workScheduleCategory->is($firstCategory));
        $this->assertTrue($secondProfile->workScheduleCategory->is($secondCategory));
        $this->assertCount(1, $firstCategory->profiles);
        $this->assertCount(1, $secondCategory->profiles);
    }

    public function test_category_without_profile_remains_valid_and_existing_category_times_are_preserved(): void
    {
        $category = app(WorkScheduleCategoryService::class)->create([
            'name' => 'Guru Existing',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);

        $this->assertNull($category->work_schedule_profile_id);
        $this->assertNull($category->workScheduleProfile);
        $this->assertSame('06:55', substr((string) $category->start_time, 0, 5));
        $this->assertSame('15:40', substr((string) $category->end_time, 0, 5));
        $this->assertSame([
            'start_time' => '06:55',
            'end_time' => '15:40',
        ], $category->effectiveWorkTime());
        $this->assertDatabaseHas('work_schedule_categories', [
            'id' => $category->id,
            'work_schedule_profile_id' => null,
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
    }

    public function test_assigned_inactive_profile_remains_effective_and_other_category_profile_is_isolated(): void
    {
        $service = app(WorkScheduleProfileService::class);
        $profile = $service->create($this->profileData('Ramadhan', '07:00', '14:00'));
        $categoryService = app(WorkScheduleCategoryService::class);
        $category = $this->category();
        $categoryService->update($category, [
            'name' => $category->name,
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => $profile->id,
        ]);
        $profile = $service->update($profile, [
            ...$this->profileData('Ramadhan', '07:00', '14:00'),
            'is_active' => false,
        ]);

        $otherCategory = $categoryService->create([
            'name' => 'BTQ',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
        $otherProfile = $service->create([
            ...$this->profileData('Jumat Pendek', '08:00', '11:30'),
            'work_schedule_category_id' => $otherCategory->id,
        ]);
        $categoryService->update($otherCategory, [
            'name' => $otherCategory->name,
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => $otherProfile->id,
        ]);

        $service->update($profile, $this->profileData('Ramadhan', '07:15', '14:15'));

        $category->refresh();
        $this->assertSame(['start_time' => '07:15', 'end_time' => '14:15'], $category->effectiveWorkTime());
        $this->assertSame('06:55', $category->start_time);
        $this->assertSame('15:40', $category->end_time);

        $this->assertSame([
            'start_time' => '08:00',
            'end_time' => '11:30',
        ], $otherCategory->refresh()->effectiveWorkTime());
    }

    public function test_database_enforces_unique_profile_name_within_category(): void
    {
        $service = app(WorkScheduleProfileService::class);
        $service->create($this->profileData('Normal'));

        $this->expectException(QueryException::class);
        WorkScheduleProfile::query()->create($this->profileData('Normal'));
    }

    public function test_profile_requires_a_valid_category(): void
    {
        $service = app(WorkScheduleProfileService::class);

        $this->assertValidationError(
            fn () => $service->create([
                ...$this->profileData(),
                'work_schedule_category_id' => null,
            ]),
            'work_schedule_category_id',
        );
        $this->assertValidationError(
            fn () => $service->create([
                ...$this->profileData(),
                'work_schedule_category_id' => 999999,
            ]),
            'work_schedule_category_id',
        );
    }

    public function test_deleting_profile_nulls_category_reference_without_deleting_category_or_legacy_times(): void
    {
        $category = $this->category();
        $profile = app(WorkScheduleProfileService::class)->create($this->profileData());
        app(WorkScheduleCategoryService::class)->update($category, [
            'name' => $category->name,
            'start_time' => $category->start_time,
            'end_time' => $category->end_time,
            'work_schedule_profile_id' => $profile->id,
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('work_schedule_profiles', ['id' => $profile->id]);
        $this->assertDatabaseHas('work_schedule_categories', [
            'id' => $category->id,
            'work_schedule_profile_id' => null,
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
    }

    private function profileData(
        string $name = 'Normal',
        ?string $startTime = '06:55',
        ?string $endTime = '15:40',
    ): array {
        return [
            'work_schedule_category_id' => $this->category()->id,
            'name' => $name,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
    }

    private function category(): WorkScheduleCategory
    {
        return $this->category ??= app(WorkScheduleCategoryService::class)->create([
            'name' => 'Guru',
            'start_time' => '06:55',
            'end_time' => '15:40',
        ]);
    }

    private function assertValidationError(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail("Expected validation error for {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
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
