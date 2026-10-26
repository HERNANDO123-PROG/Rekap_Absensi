<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Unit;
use App\Models\WorkScheduleCategory;
use App\Models\WorkSchedulePeriod;
use App\Models\WorkScheduleProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkSchedulePeriodService
{
    public function list(): Collection
    {
        return WorkSchedulePeriod::query()
            ->with(['unit', 'category', 'profile.rules'])
            ->orderBy('start_date')
            ->orderBy('name')
            ->get();
    }

    public function create(array $attributes, ?int $userId = null): WorkSchedulePeriod
    {
        return DB::transaction(function () use ($attributes, $userId): WorkSchedulePeriod {
            $data = $this->validatePeriod($attributes);
            $this->assertNoConflict($data);
            $period = WorkSchedulePeriod::query()->create($data);
            $this->audit($userId, 'work_schedule_period.created', $period);

            return $period->load(['unit', 'category', 'profile.rules']);
        });
    }

    public function update(
        WorkSchedulePeriod $period,
        array $attributes,
        ?int $userId = null,
    ): WorkSchedulePeriod {
        return DB::transaction(function () use ($period, $attributes, $userId): WorkSchedulePeriod {
            $period = WorkSchedulePeriod::query()->lockForUpdate()->findOrFail($period->id);
            $data = $this->validatePeriod($attributes, $period);
            $this->assertNoConflict($data, $period->id);
            $period->update($data);
            $this->audit($userId, 'work_schedule_period.updated', $period);

            return $period->refresh()->load(['unit', 'category', 'profile.rules']);
        });
    }

    public function delete(WorkSchedulePeriod $period, ?int $userId = null): array
    {
        return DB::transaction(function () use ($period, $userId): array {
            $period = WorkSchedulePeriod::query()->lockForUpdate()->findOrFail($period->id);
            $details = $period->only([
                'unit_id',
                'work_schedule_category_id',
                'work_schedule_profile_id',
                'name',
                'start_date',
                'end_date',
            ]);
            $periodId = $period->id;
            $period->delete();
            AuditLog::create([
                'user_id' => $userId,
                'action' => 'work_schedule_period.deleted',
                'module' => 'work_calendar',
                'record_type' => WorkSchedulePeriod::class,
                'record_id' => $periodId,
                'description' => json_encode($details, JSON_THROW_ON_ERROR),
            ]);

            return ['id' => $periodId, 'deleted' => true];
        });
    }

    private function validatePeriod(array $attributes, ?WorkSchedulePeriod $current = null): array
    {
        $unitId = $attributes['unit_id'] ?? $current?->unit_id;
        if ($unitId === '' || $unitId === 'all') {
            $unitId = null;
        }
        if ($unitId !== null && Unit::query()->whereKey($unitId)->whereIn('code', ['TK', 'SD'])->doesntExist()) {
            throw ValidationException::withMessages(['unit_id' => 'Pilih unit TK, SD, atau kedua unit.']);
        }

        $categoryId = $attributes['work_schedule_category_id'] ?? $current?->work_schedule_category_id;
        $category = WorkScheduleCategory::query()->find($categoryId);
        if ($category === null) {
            throw ValidationException::withMessages(['work_schedule_category_id' => 'Pilih kategori pegawai yang valid.']);
        }

        $profileId = $attributes['work_schedule_profile_id'] ?? $current?->work_schedule_profile_id;
        $profile = WorkScheduleProfile::query()->with('rules')->find($profileId);
        if (
            $profile === null
            || (int) $profile->work_schedule_category_id !== (int) $category->id
            || ! $profile->is_active
        ) {
            throw ValidationException::withMessages([
                'work_schedule_profile_id' => 'Pilih profil jadwal aktif milik kategori tersebut.',
            ]);
        }

        $start = $this->parseDate($attributes['start_date'] ?? $current?->start_date?->format('Y-m-d'), 'start_date');
        $end = $this->parseDate($attributes['end_date'] ?? $current?->end_date?->format('Y-m-d'), 'end_date');
        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['end_date' => 'Tanggal akhir harus sama atau setelah tanggal mulai.']);
        }

        $name = trim((string) ($attributes['name'] ?? $current?->name ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'Nama periode wajib diisi maksimal 255 karakter.']);
        }

        $isActive = filter_var(
            $attributes['is_active'] ?? $current?->is_active ?? true,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );
        if ($isActive === null) {
            throw ValidationException::withMessages(['is_active' => 'Status aktif tidak valid.']);
        }

        return [
            'unit_id' => $unitId,
            'work_schedule_category_id' => $category->id,
            'work_schedule_profile_id' => $profile->id,
            'name' => $name,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'is_active' => $isActive,
        ];
    }

    private function assertNoConflict(array $data, ?int $exceptId = null): void
    {
        if (! $data['is_active']) {
            return;
        }

        $conflict = WorkSchedulePeriod::query()
            ->where('is_active', true)
            ->where('work_schedule_category_id', $data['work_schedule_category_id'])
            ->whereDate('start_date', '<=', $data['end_date'])
            ->whereDate('end_date', '>=', $data['start_date'])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->where(function ($query) use ($data): void {
                $query->whereNull('unit_id');
                if ($data['unit_id'] !== null) {
                    $query->orWhere('unit_id', $data['unit_id']);
                } else {
                    $query->orWhereNotNull('unit_id');
                }
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'start_date' => 'Periode aktif bertabrakan untuk unit dan kategori yang sama. Selesaikan konflik terlebih dahulu.',
            ]);
        }
    }

    private function parseDate(mixed $date, string $field): CarbonImmutable
    {
        try {
            $parsed = is_string($date) ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : false;
        } catch (Throwable) {
            $parsed = false;
        }
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages([$field => 'Tanggal harus menggunakan format YYYY-MM-DD.']);
        }

        return $parsed;
    }

    private function audit(?int $userId, string $action, WorkSchedulePeriod $period): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'module' => 'work_calendar',
            'record_type' => WorkSchedulePeriod::class,
            'record_id' => $period->id,
            'description' => json_encode($period->only([
                'unit_id',
                'work_schedule_category_id',
                'work_schedule_profile_id',
                'name',
                'start_date',
                'end_date',
                'is_active',
            ]), JSON_THROW_ON_ERROR),
        ]);
    }
}
