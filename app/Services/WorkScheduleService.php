<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Unit;
use App\Models\WorkSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkScheduleService
{
    public function list(array $filters = []): Collection
    {
        return WorkSchedule::query()
            ->with('unit')
            ->when(isset($filters['unit_id']), fn ($query) => $query->where('unit_id', $filters['unit_id']))
            ->orderBy('unit_id')
            ->orderBy('day_of_week')
            ->get();
    }

    public function create(array $attributes, ?int $userId = null): WorkSchedule
    {
        return DB::transaction(function () use ($attributes, $userId): WorkSchedule {
            $data = $this->validateData($attributes);

            if (WorkSchedule::query()->where('unit_id', $data['unit_id'])->where('day_of_week', $data['day_of_week'])->exists()) {
                throw ValidationException::withMessages(['day_of_week' => 'A weekly schedule already exists for this unit and day.']);
            }

            try {
                $schedule = WorkSchedule::create($data);
            } catch (QueryException $exception) {
                throw ValidationException::withMessages(['day_of_week' => 'A weekly schedule already exists for this unit and day.']);
            }

            $this->audit($userId, 'work_schedule.created', $schedule->id, $schedule->only([
                'unit_id', 'day_of_week', 'is_working_day', 'start_time', 'end_time',
            ]));

            return $schedule->load('unit');
        });
    }

    public function update(WorkSchedule $schedule, array $attributes, ?int $userId = null): WorkSchedule
    {
        return DB::transaction(function () use ($schedule, $attributes, $userId): WorkSchedule {
            $schedule = WorkSchedule::query()->lockForUpdate()->findOrFail($schedule->id);

            if (array_key_exists('unit_id', $attributes) && (int) $attributes['unit_id'] !== (int) $schedule->unit_id) {
                throw ValidationException::withMessages(['unit_id' => 'The selected unit does not match this schedule.']);
            }

            $data = $this->validateData($attributes);

            if (WorkSchedule::query()
                ->where('unit_id', $data['unit_id'])
                ->where('day_of_week', $data['day_of_week'])
                ->whereKeyNot($schedule->id)
                ->exists()) {
                throw ValidationException::withMessages(['day_of_week' => 'A weekly schedule already exists for this unit and day.']);
            }

            try {
                $schedule->update($data);
            } catch (QueryException $exception) {
                throw ValidationException::withMessages(['day_of_week' => 'A weekly schedule already exists for this unit and day.']);
            }

            $this->audit($userId, 'work_schedule.updated', $schedule->id, $schedule->only([
                'unit_id', 'day_of_week', 'is_working_day', 'start_time', 'end_time',
            ]));

            return $schedule->refresh()->load('unit');
        });
    }

    public function delete(WorkSchedule $schedule, ?int $userId = null): array
    {
        return DB::transaction(function () use ($schedule, $userId): array {
            $schedule = WorkSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            $affected = ['unit_id' => $schedule->unit_id, 'day_of_week' => $schedule->day_of_week];
            $scheduleId = $schedule->id;
            $schedule->delete();
            $this->audit($userId, 'work_schedule.deleted', $scheduleId, $affected);

            return ['affected_unit_id' => $affected['unit_id'], 'affected_day_of_week' => $affected['day_of_week']];
        });
    }

    private function validateData(array $attributes): array
    {
        $unit = Unit::query()->whereKey($attributes['unit_id'] ?? null)->first();

        if ($unit === null || ! in_array($unit->code, ['TK', 'SD'], true)) {
            throw ValidationException::withMessages(['unit_id' => 'Select an existing TK or SD unit.']);
        }

        $day = filter_var($attributes['day_of_week'] ?? null, FILTER_VALIDATE_INT);

        if ($day === false || $day < 0 || $day > 6) {
            throw ValidationException::withMessages(['day_of_week' => 'Day of week must be an integer from 0 through 6.']);
        }

        $isWorkingDay = filter_var($attributes['is_working_day'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($isWorkingDay === null) {
            throw ValidationException::withMessages(['is_working_day' => 'Select whether this is a working day.']);
        }

        $startTime = $this->normalizeTime($attributes['start_time'] ?? null, 'start_time');
        $endTime = $this->normalizeTime($attributes['end_time'] ?? null, 'end_time');

        $this->validateTimePair($isWorkingDay, $startTime, $endTime);

        return [
            'unit_id' => $unit->id,
            'day_of_week' => $day,
            'is_working_day' => $isWorkingDay,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
    }

    private function normalizeTime(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Time must use the 24-hour HH:MM format.']);
        }

        return $value;
    }

    private function validateTimePair(bool $isWorkingDay, ?string $startTime, ?string $endTime): void
    {
        if ($isWorkingDay && ($startTime === null || $endTime === null)) {
            throw ValidationException::withMessages(['start_time' => 'Working days require both start_time and end_time.']);
        }

        if (! $isWorkingDay && (($startTime === null) !== ($endTime === null))) {
            throw ValidationException::withMessages(['start_time' => 'For non-working days, provide both times or leave both empty.']);
        }

        if ($startTime !== null && $endTime !== null && $endTime <= $startTime) {
            throw ValidationException::withMessages(['end_time' => 'end_time must be later than start_time; overnight schedules are not supported.']);
        }
    }

    private function audit(?int $userId, string $action, int $recordId, array $details): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'module' => 'work_schedule',
            'record_type' => WorkSchedule::class,
            'record_id' => $recordId,
            'description' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
