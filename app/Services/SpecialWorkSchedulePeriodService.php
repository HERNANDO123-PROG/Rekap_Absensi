<?php

namespace App\Services;

use App\Models\SpecialWorkSchedulePeriod;
use App\Models\SpecialWorkSchedulePeriodCategory;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfileRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SpecialWorkSchedulePeriodService
{
    public function list()
    {
        return SpecialWorkSchedulePeriod::query()
            ->with(['periodCategories.category', 'periodCategories.profile.rules'])
            ->orderBy('start_date')
            ->orderBy('name')
            ->get();
    }

    public function create(array $attributes): SpecialWorkSchedulePeriod
    {
        return DB::transaction(function () use ($attributes): SpecialWorkSchedulePeriod {
            $period = SpecialWorkSchedulePeriod::create([
                'name' => trim($attributes['name']),
                'start_date' => $attributes['start_date'],
                'end_date' => $attributes['end_date'],
                'is_active' => $attributes['is_active'] ?? true,
            ]);

            $this->syncCategories($period, $attributes['category_ids'] ?? []);

            return $period->load(['periodCategories.category', 'periodCategories.profile.rules']);
        });
    }

    public function update(SpecialWorkSchedulePeriod $period, array $attributes): SpecialWorkSchedulePeriod
    {
        return DB::transaction(function () use ($period, $attributes): SpecialWorkSchedulePeriod {
            $period->update([
                'name' => trim($attributes['name']),
                'start_date' => $attributes['start_date'],
                'end_date' => $attributes['end_date'],
                'is_active' => $attributes['is_active'] ?? $period->is_active,
            ]);
            $this->syncCategories($period, $attributes['category_ids'] ?? [], true);
            foreach ($period->periodCategories()->with('category', 'profile')->get() as $entry) {
                $entry->profile->update([
                    'name' => $period->name.' - '.$entry->category->name,
                ]);
            }

            return $period->load(['periodCategories.category', 'periodCategories.profile.rules']);
        });
    }

    public function delete(SpecialWorkSchedulePeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            $period->periodCategories->each(function (SpecialWorkSchedulePeriodCategory $entry): void {
                $entry->delete();
                $entry->profile->rules()->delete();
                $entry->profile->delete();
            });
            $period->delete();

            return ['id' => $period->id, 'deleted' => true];
        });
    }

    private function syncCategories(
        SpecialWorkSchedulePeriod $period,
        array $categoryIds,
        bool $preserveExistingProfiles = false,
    ): void {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $existing = $period->periodCategories()->with('profile.rules')->get()->keyBy('work_schedule_category_id');

        foreach ($existing as $categoryId => $entry) {
            if (! in_array((int) $categoryId, $categoryIds, true)) {
                $entry->delete();
                $entry->profile->rules()->delete();
                $entry->profile->delete();
            }
        }

        foreach ($categoryIds as $categoryId) {
            if ($existing->has($categoryId)) {
                continue;
            }

            $category = WorkScheduleCategory::query()->find($categoryId);
            if ($category === null) {
                throw ValidationException::withMessages(['category_ids' => 'Kategori jam kerja tidak ditemukan.']);
            }

            $normalProfile = WorkScheduleProfile::query()
                ->where('work_schedule_category_id', $category->id)
                ->where('schedule_type', 'normal')
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            $profile = WorkScheduleProfile::query()->create([
                'work_schedule_category_id' => $category->id,
                'schedule_type' => 'special',
                'name' => $period->name.' - '.$category->name,
                'start_time' => null,
                'end_time' => null,
                'is_active' => true,
            ]);

            for ($day = 0; $day <= 6; $day++) {
                $normalRule = $normalProfile?->rules->firstWhere('day_of_week', $day);
                WorkScheduleProfileRule::query()->create([
                    'work_schedule_profile_id' => $profile->id,
                    'day_of_week' => $day,
                    'is_working_day' => (bool) ($normalRule?->is_working_day ?? ($day !== 0)),
                    'start_time' => null,
                    'end_time' => null,
                ]);
            }

            SpecialWorkSchedulePeriodCategory::query()->create([
                'special_work_schedule_period_id' => $period->id,
                'work_schedule_category_id' => $category->id,
                'work_schedule_profile_id' => $profile->id,
            ]);
        }
    }
}
