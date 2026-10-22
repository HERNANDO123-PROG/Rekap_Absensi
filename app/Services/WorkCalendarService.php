<?php

namespace App\Services;

use App\Enums\WorkCalendarType;
use App\Models\AuditLog;
use App\Models\Unit;
use App\Models\WorkCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkCalendarService
{
    private const MAX_BULK_DAYS = 366;

    public function list(array $filters = []): Collection
    {
        return WorkCalendar::query()
            ->with('unit')
            ->when(isset($filters['unit_id']), fn ($query) => $query->where('unit_id', $filters['unit_id']))
            ->when(isset($filters['date_from']), fn ($query) => $query->whereDate('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($query) => $query->whereDate('date', '<=', $filters['date_to']))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->orderBy('date')
            ->orderBy('unit_id')
            ->get();
    }

    public function create(array $attributes, ?int $userId = null): WorkCalendar
    {
        return DB::transaction(function () use ($attributes, $userId): WorkCalendar {
            $data = $this->validateEntry($attributes);

            if (WorkCalendar::query()->where('unit_id', $data['unit_id'])->whereDate('date', $data['date'])->exists()) {
                throw ValidationException::withMessages(['date' => 'A calendar entry already exists for this unit and date.']);
            }

            try {
                $calendar = WorkCalendar::create($data);
            } catch (QueryException $exception) {
                throw ValidationException::withMessages(['date' => 'A calendar entry already exists for this unit and date.']);
            }

            $this->audit($userId, 'work_calendar.created', $calendar->id, $calendar->only([
                'unit_id', 'date', 'type', 'is_working_day', 'start_time', 'end_time',
            ]));

            return $calendar->load('unit');
        });
    }

    public function update(WorkCalendar $calendar, array $attributes, ?int $userId = null): WorkCalendar
    {
        return DB::transaction(function () use ($calendar, $attributes, $userId): WorkCalendar {
            $calendar = WorkCalendar::query()->lockForUpdate()->findOrFail($calendar->id);

            if (array_key_exists('unit_id', $attributes) && (int) $attributes['unit_id'] !== (int) $calendar->unit_id) {
                throw ValidationException::withMessages(['unit_id' => 'The selected unit does not match this calendar.']);
            }

            $data = $this->validateEntry($attributes);

            if (WorkCalendar::query()
                ->where('unit_id', $data['unit_id'])
                ->whereDate('date', $data['date'])
                ->whereKeyNot($calendar->id)
                ->exists()) {
                throw ValidationException::withMessages(['date' => 'A calendar entry already exists for this unit and date.']);
            }

            try {
                $calendar->update($data);
            } catch (QueryException $exception) {
                throw ValidationException::withMessages(['date' => 'A calendar entry already exists for this unit and date.']);
            }

            $this->audit($userId, 'work_calendar.updated', $calendar->id, $calendar->only([
                'unit_id', 'date', 'type', 'is_working_day', 'start_time', 'end_time',
            ]));

            return $calendar->refresh()->load('unit');
        });
    }

    public function delete(WorkCalendar $calendar, ?int $userId = null): array
    {
        return DB::transaction(function () use ($calendar, $userId): array {
            $calendar = WorkCalendar::query()->lockForUpdate()->findOrFail($calendar->id);
            $affected = ['unit_id' => $calendar->unit_id, 'date' => $calendar->date->format('Y-m-d')];
            $calendarId = $calendar->id;
            $calendar->delete();
            $this->audit($userId, 'work_calendar.deleted', $calendarId, $affected);

            return ['affected_unit_id' => $affected['unit_id'], 'affected_dates' => [$affected['date']]];
        });
    }

    public function upsert(array $attributes, ?int $userId = null): array
    {
        $data = $this->validateEntry($attributes);

        return DB::transaction(function () use ($data, $userId): array {
            $existing = WorkCalendar::query()
                ->where('unit_id', $data['unit_id'])
                ->whereDate('date', $data['date'])
                ->first();
            $calendar = WorkCalendar::query()->updateOrCreate(
                ['unit_id' => $data['unit_id'], 'date' => $data['date']],
                collect($data)->except(['unit_id', 'date'])->all(),
            );
            $this->audit(
                $userId,
                $existing === null ? 'work_calendar.created' : 'work_calendar.updated',
                $calendar->id,
                $data,
            );

            return [
                'data' => $calendar->load('unit'),
                'affected_unit_id' => $data['unit_id'],
                'affected_dates' => [$data['date']],
            ];
        });
    }

    public function bulkUpsert(array $attributes, ?int $userId = null): array
    {
        $unit = $this->validUnit($attributes['unit_id'] ?? null);
        $from = $this->parseDate($attributes['date_from'] ?? null, 'date_from');
        $to = $this->parseDate($attributes['date_to'] ?? null, 'date_to');

        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['date_to' => 'date_to must be on or after date_from.']);
        }

        $days = $from->diffInDays($to) + 1;

        if ($days > self::MAX_BULK_DAYS) {
            throw ValidationException::withMessages(['date_to' => 'Bulk calendar updates are limited to 366 dates.']);
        }

        $entry = $this->validateEntry([
            ...$attributes,
            'unit_id' => $unit->id,
            'date' => $from->format('Y-m-d'),
        ]);
        $dates = [];
        $rows = [];
        $now = now();

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $dateValue = $date->format('Y-m-d');
            $dates[] = $dateValue;
            $rows[] = [
                'unit_id' => $unit->id,
                'date' => $dateValue,
                'type' => $entry['type'],
                'title' => $entry['title'],
                'description' => $entry['description'],
                'is_working_day' => $entry['is_working_day'],
                'start_time' => $entry['start_time'],
                'end_time' => $entry['end_time'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return DB::transaction(function () use ($unit, $dates, $rows, $userId, $entry): array {
            $existingDates = WorkCalendar::query()
                ->where('unit_id', $unit->id)
                ->whereBetween('date', [$dates[0], $dates[array_key_last($dates)]])
                ->pluck('date')
                ->map(static fn ($date): string => CarbonImmutable::parse($date)->format('Y-m-d'))
                ->all();

            WorkCalendar::query()->upsert(
                $rows,
                ['unit_id', 'date'],
                ['type', 'title', 'description', 'is_working_day', 'start_time', 'end_time', 'updated_at'],
            );

            $created = count(array_diff($dates, $existingDates));
            $updated = count($dates) - $created;
            $this->audit($userId, 'work_calendar.bulk_updated', 0, [
                'unit_id' => $unit->id,
                'date_from' => $dates[0],
                'date_to' => $dates[array_key_last($dates)],
                'created' => $created,
                'updated' => $updated,
                'type' => $entry['type'],
                'is_working_day' => $entry['is_working_day'],
            ]);

            return [
                'affected_unit_id' => $unit->id,
                'affected_dates' => $dates,
                'created' => $created,
                'updated' => $updated,
            ];
        });
    }

    private function validateEntry(array $attributes): array
    {
        $unit = $this->validUnit($attributes['unit_id'] ?? null);
        $date = $this->parseDate($attributes['date'] ?? null, 'date')->format('Y-m-d');
        $typeValue = $attributes['type'] ?? null;
        $type = is_string($typeValue) ? WorkCalendarType::tryFrom($typeValue) : $typeValue;

        if (! $type instanceof WorkCalendarType) {
            throw ValidationException::withMessages(['type' => 'The selected calendar type is invalid.']);
        }

        $isWorkingDay = filter_var($attributes['is_working_day'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($isWorkingDay === null) {
            throw ValidationException::withMessages(['is_working_day' => 'Select whether this date is a working day.']);
        }

        if ($type === WorkCalendarType::Holiday && $isWorkingDay) {
            throw ValidationException::withMessages(['is_working_day' => 'Holiday calendar entries must be non-working days.']);
        }

        if ($type === WorkCalendarType::SpecialWorkday && ! $isWorkingDay) {
            throw ValidationException::withMessages(['is_working_day' => 'Special workday calendar entries must be working days.']);
        }

        $startTime = $this->normalizeTime($attributes['start_time'] ?? null, 'start_time');
        $endTime = $this->normalizeTime($attributes['end_time'] ?? null, 'end_time');
        $this->validateTimePair($isWorkingDay, $startTime, $endTime);

        return [
            'unit_id' => $unit->id,
            'date' => $date,
            'type' => $type->value,
            'title' => isset($attributes['title']) ? trim((string) $attributes['title']) : null,
            'description' => isset($attributes['description']) ? trim((string) $attributes['description']) : null,
            'is_working_day' => $isWorkingDay,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
    }

    private function validUnit(mixed $unitId): Unit
    {
        $unit = Unit::query()->whereKey($unitId)->first();

        if ($unit === null || ! in_array($unit->code, ['TK', 'SD'], true)) {
            throw ValidationException::withMessages(['unit_id' => 'Select an existing TK or SD unit.']);
        }

        return $unit;
    }

    private function parseDate(mixed $date, string $field): CarbonImmutable
    {
        try {
            $parsed = is_string($date) ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : false;
        } catch (Throwable) {
            $parsed = false;
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages([$field => 'Date must use YYYY-MM-DD format.']);
        }

        return $parsed;
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
            throw ValidationException::withMessages(['start_time' => 'Working calendar dates require both start_time and end_time.']);
        }

        if (! $isWorkingDay && (($startTime === null) !== ($endTime === null))) {
            throw ValidationException::withMessages(['start_time' => 'For non-working dates, provide both times or leave both empty.']);
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
            'module' => 'work_calendar',
            'record_type' => WorkCalendar::class,
            'record_id' => $recordId === 0 ? null : $recordId,
            'description' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
