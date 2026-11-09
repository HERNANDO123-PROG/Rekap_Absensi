<?php

namespace Tests\Feature;

use App\Models\SpecialWorkSchedulePeriod;
use App\Models\SpecialWorkSchedulePeriodCategory;
use App\Models\Unit;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfileRule;
use App\Models\WorkSchedule;
use App\Services\Calendar\CalendarResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkScheduleResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_schedule_is_used_without_retired_period_tables(): void
    {
        $unit = Unit::query()->create(['name' => 'TK', 'code' => 'TK']);
        WorkSchedule::query()->create([
            'unit_id' => $unit->id,
            'day_of_week' => 2,
            'is_working_day' => true,
            'start_time' => '07:00',
            'end_time' => '12:00',
        ]);

        $resolved = app(CalendarResolver::class)->resolve($unit->id, '2026-03-10');

        $this->assertSame('weekly_schedule', $resolved['source']);
        $this->assertSame('07:00', $resolved['start_time']);
        $this->assertSame('12:00', $resolved['end_time']);
        $this->assertFalse($resolved['missing_schedule']);
    }

    public function test_special_period_overrides_normal_schedule_only_within_its_date_range(): void
    {
        $unit = Unit::query()->create(['name' => 'SD', 'code' => 'SD']);
        $category = WorkScheduleCategory::query()->create([
            'name' => 'Guru Tetap',
            'start_time' => '07:00',
            'end_time' => '14:00',
        ]);
        WorkSchedule::query()->create([
            'unit_id' => $unit->id,
            'day_of_week' => 2,
            'is_working_day' => true,
            'start_time' => '07:00',
            'end_time' => '14:00',
        ]);

        $normalProfile = WorkScheduleProfile::query()->create([
            'work_schedule_category_id' => $category->id,
            'schedule_type' => 'normal',
            'name' => 'Normal',
            'start_time' => '07:00',
            'end_time' => '14:00',
        ]);
        $category->update(['work_schedule_profile_id' => $normalProfile->id]);

        $specialProfile = WorkScheduleProfile::query()->create([
            'work_schedule_category_id' => $category->id,
            'schedule_type' => 'special',
            'name' => 'Ramadan - Guru Tetap',
            'start_time' => '07:30',
            'end_time' => '13:00',
        ]);
        WorkScheduleProfileRule::query()->create([
            'work_schedule_profile_id' => $specialProfile->id,
            'day_of_week' => 2,
            'is_working_day' => true,
            'start_time' => '07:30',
            'end_time' => '13:00',
        ]);

        $period = SpecialWorkSchedulePeriod::query()->create([
            'name' => 'Ramadan',
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-12',
            'is_active' => true,
        ]);
        SpecialWorkSchedulePeriodCategory::query()->create([
            'special_work_schedule_period_id' => $period->id,
            'work_schedule_category_id' => $category->id,
            'work_schedule_profile_id' => $specialProfile->id,
        ]);

        $resolver = app(CalendarResolver::class);
        $special = $resolver->resolve($unit->id, '2026-03-10', $category->id);
        $normal = $resolver->resolve($unit->id, '2026-03-13', $category->id);

        $this->assertSame('special_work_schedule_period', $special['source']);
        $this->assertSame('07:30', $special['start_time']);
        $this->assertSame('13:00', $special['end_time']);
        $this->assertSame('category_normal', $normal['source']);
        $this->assertSame('07:00', $normal['start_time']);
        $this->assertSame('14:00', $normal['end_time']);
    }
}
