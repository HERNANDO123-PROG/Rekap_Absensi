<?php

namespace App\Services\Calendar;

use App\Enums\WorkCalendarType;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleCategory;
use App\Models\WorkSchedulePeriod;
use App\Services\EmployeeScheduleProfileValidator;
use Carbon\CarbonImmutable;

class CalendarResolver
{
    public function __construct(
        private readonly EmployeeScheduleProfileValidator $scheduleProfileValidator,
    ) {}

    public function resolve(
        int $unitId,
        string $date,
        ?int $categoryId = null,
        ?Employee $employee = null,
    ): array {
        if ($employee !== null) {
            $categoryId = $employee->category_id === null ? null : (int) $employee->category_id;
            $employee->loadMissing('workScheduleProfile.rules');
        }

        $unit = Unit::query()->find($unitId);
        $parsedDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($unit === null || ! in_array($unit->code, ['TK', 'SD'], true)) {
            throw new \InvalidArgumentException('A valid TK or SD unit is required.');
        }

        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Date must use YYYY-MM-DD format.');
        }

        $calendar = WorkCalendar::query()
            ->where('unit_id', $unitId)
            ->whereDate('date', $date)
            ->first();
        if ($calendar !== null) {
            if (! $calendar->is_working_day) {
                return $this->resolveEntry($unitId, $date, 'calendar', $calendar, $calendar);
            }

            if ($this->hasValidTimeRange($calendar->start_time, $calendar->end_time)) {
                return $this->resolveEntry($unitId, $date, 'calendar', $calendar, $calendar);
            }

            $category = $this->resolveCategory($categoryId);
            if ($employee !== null && $employee->work_schedule_profile_id !== null) {
                if ($category === null || ! $category->is_active) {
                    return $this->invalidSchedule(
                        $unitId,
                        $date,
                        'employee_profile',
                        $calendar,
                        missingCategory: true,
                    );
                }

                $individualSchedule = $this->resolveIndividualProfile(
                    $unitId,
                    $date,
                    $employee,
                    $category,
                    $parsedDate->dayOfWeek,
                    calendar: $calendar,
                    requireWorkingDay: true,
                );

                return $individualSchedule;
            }

            if ($category !== null && $category->is_active) {
                $normalRule = $category->workScheduleProfile?->rules
                    ->firstWhere('day_of_week', $parsedDate->dayOfWeek);
                if ($normalRule?->is_working_day) {
                    return $this->resolveEntry($unitId, $date, 'calendar', $normalRule, $calendar);
                }
                if ($normalRule === null && $category->start_time !== null && $category->end_time !== null
                    && $parsedDate->dayOfWeekIso < 7) {
                    $categorySchedule = $this->resolveLegacyTime(
                        $unitId,
                        $date,
                        'calendar',
                        $category->workScheduleProfile?->start_time ?? $category->start_time,
                        $category->workScheduleProfile?->end_time ?? $category->end_time,
                    );

                    return [
                        ...$categorySchedule,
                        'type' => $calendar->type instanceof WorkCalendarType ? $calendar->type->value : null,
                        'title' => $calendar->title,
                        'description' => $calendar->description,
                    ];
                }
            }

            return $this->invalidSchedule(
                $unitId,
                $date,
                'calendar',
                $calendar,
                missingCategory: $category === null || ! $category->is_active,
            );
        }

        $periods = $categoryId === null
            ? collect()
            : WorkSchedulePeriod::query()
                ->with('profile.rules')
                ->where('work_schedule_category_id', $categoryId)
                ->where('is_active', true)
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->where(function ($query) use ($unitId): void {
                    $query->whereNull('unit_id')->orWhere('unit_id', $unitId);
                })
                ->get();

        if ($periods->count() > 1) {
            return [
                ...$this->missing($unitId, $date),
                'schedule_conflict' => true,
                'source' => 'schedule_period',
            ];
        }

        if ($period = $periods->first()) {
            $rule = $period->profile?->rules->firstWhere('day_of_week', $parsedDate->dayOfWeek);

            if ($rule === null) {
                return [
                    ...$this->invalidSchedule($unitId, $date, 'schedule_period'),
                    'title' => $period->name,
                ];
            }

            $resolved = $this->resolveEntry($unitId, $date, 'schedule_period', $rule);
            if ($rule->is_working_day && ! $this->hasValidTimeRange($rule->start_time, $rule->end_time)) {
                return [
                    ...$this->invalidSchedule($unitId, $date, 'schedule_period'),
                    'title' => $period->name,
                ];
            }

            return [
                ...$resolved,
                'title' => $period->name,
                'work_schedule_profile_id' => $period->work_schedule_profile_id,
                'work_schedule_period_id' => $period->id,
            ];
        }

        $isRoutineRestDay = $parsedDate->dayOfWeekIso === 7
            || ($unit->code === 'TK' && $parsedDate->dayOfWeekIso === 6)
            || ($unit->code === 'SD' && $parsedDate->dayOfWeekIso === 6 && intdiv($parsedDate->day - 1, 7) + 1 === 5);
        if ($isRoutineRestDay) {
            return [
                ...$this->resolveEntry($unitId, $date, 'routine_schedule', (object) [
                    'is_working_day' => false,
                    'start_time' => null,
                    'end_time' => null,
                ]),
                'title' => $unit->code === 'SD' && $parsedDate->dayOfWeekIso === 6
                    ? 'Sabtu kelima'
                    : 'Hari libur rutin',
            ];
        }

        $weeklySchedule = WorkSchedule::query()
            ->where('unit_id', $unitId)
            ->where('day_of_week', $parsedDate->dayOfWeek)
            ->first();

        if ($employee !== null && $employee->work_schedule_profile_id !== null && $categoryId === null) {
            return $this->invalidSchedule(
                $unitId,
                $date,
                'employee_profile',
                missingCategory: true,
            );
        }

        if ($employee !== null && $categoryId === null) {
            return $this->categoryUnavailableSchedule($unitId, $date, $weeklySchedule);
        }

        if ($categoryId !== null) {
            $category = $this->resolveCategory($categoryId);

            if ($category === null || ! $category->is_active) {
                if ($employee !== null && $employee->work_schedule_profile_id !== null) {
                    return $this->invalidSchedule(
                        $unitId,
                        $date,
                        'employee_profile',
                        missingCategory: true,
                    );
                }

                return $this->categoryUnavailableSchedule($unitId, $date, $weeklySchedule);
            }

            if ($employee !== null && $employee->work_schedule_profile_id !== null) {
                return $this->resolveIndividualProfile(
                    $unitId,
                    $date,
                    $employee,
                    $category,
                    $parsedDate->dayOfWeek,
                );
            }

            if ($category->workScheduleProfile !== null) {
                $rule = $category->workScheduleProfile->rules->firstWhere('day_of_week', $parsedDate->dayOfWeek);
                if ($rule === null && $category->workScheduleProfile->start_time !== null
                    && $category->workScheduleProfile->end_time !== null
                    && $parsedDate->dayOfWeekIso < 7) {
                    return $this->resolveLegacyTime(
                        $unitId,
                        $date,
                        'category_normal',
                        $category->workScheduleProfile->start_time,
                        $category->workScheduleProfile->end_time,
                    );
                }

                return $this->resolveProfileRule($unitId, $date, 'category_normal', $rule);
            }

            if ($parsedDate->dayOfWeekIso < 7 && $category->start_time !== null && $category->end_time !== null) {
                return $this->resolveLegacyCategoryTime($unitId, $date, $category);
            }
        }

        if ($weeklySchedule !== null) {
            return $this->resolveEntry($unitId, $date, 'weekly_schedule', $weeklySchedule);
        }

        return $this->missing($unitId, $date);
    }

    private function resolveCategory(?int $categoryId): ?WorkScheduleCategory
    {
        return $categoryId === null
            ? null
            : WorkScheduleCategory::query()->with('workScheduleProfile.rules')->find($categoryId);
    }

    private function categoryUnavailableSchedule(int $unitId, string $date, ?WorkSchedule $weeklySchedule): array
    {
        if ($weeklySchedule === null) {
            return $this->missing($unitId, $date, missingCategory: true);
        }

        return [
            ...$this->resolveEntry($unitId, $date, 'weekly_schedule', $weeklySchedule),
            'start_time' => null,
            'end_time' => null,
            'missing_category' => true,
        ];
    }

    private function resolveIndividualProfile(
        int $unitId,
        string $date,
        Employee $employee,
        WorkScheduleCategory $category,
        int $dayOfWeek,
        ?WorkCalendar $calendar = null,
        bool $requireWorkingDay = false,
    ): array {
        $profile = $employee->workScheduleProfile;
        if (
            $profile === null
            || ! $this->scheduleProfileValidator->isSelectable($profile, (int) $category->id)
        ) {
            return $this->invalidSchedule(
                $unitId,
                $date,
                'employee_profile',
                $calendar,
            );
        }

        $rule = $profile->rules->firstWhere('day_of_week', $dayOfWeek);
        if ($rule === null || ($requireWorkingDay && ! $rule->is_working_day)) {
            return $this->invalidSchedule(
                $unitId,
                $date,
                'employee_profile',
                $calendar,
            );
        }

        return [
            ...$this->resolveEntry($unitId, $date, $calendar === null ? 'employee_profile' : 'calendar', $rule, $calendar),
            'work_schedule_profile_id' => $profile->id,
        ];
    }

    private function hasValidTimeRange(mixed $rawStart, mixed $rawEnd): bool
    {
        $start = substr((string) $rawStart, 0, 5);
        $end = substr((string) $rawEnd, 0, 5);

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) === 1
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end) === 1
            && $end > $start;
    }

    private function invalidSchedule(
        int $unitId,
        string $date,
        string $source,
        ?WorkCalendar $calendar = null,
        bool $missingCategory = false,
    ): array {
        return [
            ...$this->missing($unitId, $date, missingCategory: $missingCategory),
            'source' => $source,
            'is_working_day' => true,
            'is_holiday' => false,
            'missing_schedule' => false,
            'invalid_schedule' => ! $missingCategory,
            'missing_category' => $missingCategory,
            'type' => $calendar?->type instanceof WorkCalendarType ? $calendar->type->value : null,
            'title' => $calendar?->title,
            'description' => $calendar?->description,
        ];
    }

    private function resolveProfileRule(
        int $unitId,
        string $date,
        string $source,
        mixed $rule,
    ): array {
        if ($rule === null) {
            return [
                ...$this->missing($unitId, $date),
                'source' => $source,
                'invalid_schedule' => true,
            ];
        }

        return [
            ...$this->resolveEntry($unitId, $date, $source, $rule),
            'work_schedule_profile_id' => $rule->work_schedule_profile_id,
        ];
    }

    private function resolveLegacyCategoryTime(int $unitId, string $date, WorkScheduleCategory $category): array
    {
        return $this->resolveLegacyTime($unitId, $date, 'category_legacy', $category->start_time, $category->end_time);
    }

    private function resolveLegacyTime(
        int $unitId,
        string $date,
        string $source,
        mixed $rawStart,
        mixed $rawEnd,
    ): array {
        $start = substr((string) $rawStart, 0, 5);
        $end = substr((string) $rawEnd, 0, 5);
        $valid = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) === 1
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end) === 1
            && $end > $start;

        return [
            ...$this->missing($unitId, $date),
            'source' => $source,
            'is_working_day' => $valid,
            'is_holiday' => false,
            'start_time' => $valid ? $start : null,
            'end_time' => $valid ? $end : null,
            'missing_schedule' => ! $valid,
            'invalid_schedule' => ! $valid,
        ];
    }

    private function resolveEntry(
        int $unitId,
        string $date,
        string $source,
        object $entry,
        ?WorkCalendar $calendar = null,
    ): array {
        $isWorkingDay = (bool) $entry->is_working_day;

        return [
            'unit_id' => $unitId,
            'date' => $date,
            'source' => $source,
            'is_working_day' => $isWorkingDay,
            'is_holiday' => ! $isWorkingDay,
            'start_time' => $entry->start_time === null ? null : substr((string) $entry->start_time, 0, 5),
            'end_time' => $entry->end_time === null ? null : substr((string) $entry->end_time, 0, 5),
            'type' => $calendar?->type instanceof WorkCalendarType ? $calendar->type->value : null,
            'title' => $calendar?->title,
            'description' => $calendar?->description,
            'missing_schedule' => false,
            'invalid_schedule' => false,
        ];
    }

    private function missing(int $unitId, string $date, bool $missingCategory = false): array
    {
        return [
            'unit_id' => $unitId,
            'date' => $date,
            'source' => null,
            'is_working_day' => null,
            'is_holiday' => false,
            'start_time' => null,
            'end_time' => null,
            'type' => null,
            'title' => null,
            'description' => null,
            'missing_schedule' => true,
            'invalid_schedule' => false,
            'missing_category' => $missingCategory,
        ];
    }
}
