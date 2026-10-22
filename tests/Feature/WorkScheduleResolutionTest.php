<?php

namespace Tests\Feature;

use App\Models\Unit;
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
}
