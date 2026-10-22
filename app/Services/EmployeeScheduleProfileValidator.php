<?php

namespace App\Services;

use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfileRule;
use Illuminate\Validation\ValidationException;

class EmployeeScheduleProfileValidator
{
    public function validate(?int $categoryId, ?int $profileId): void
    {
        if ($profileId === null) {
            return;
        }

        $profile = WorkScheduleProfile::query()->find($profileId);
        if ($profile === null) {
            throw ValidationException::withMessages([
                'work_schedule_profile_id' => 'Profil jam kerja yang dipilih tidak ditemukan.',
            ]);
        }

        if (! $profile->is_active) {
            throw ValidationException::withMessages([
                'work_schedule_profile_id' => 'Profil jam kerja nonaktif tidak dapat ditetapkan.',
            ]);
        }

        if ($categoryId === null) {
            throw ValidationException::withMessages([
                'category_id' => 'Pilih kategori aktif sebelum menetapkan profil jam kerja.',
            ]);
        }

        $category = WorkScheduleCategory::query()->find($categoryId);
        if ($category === null || ! $category->is_active) {
            throw ValidationException::withMessages([
                'category_id' => 'Kategori aktif yang valid diperlukan untuk menetapkan profil jam kerja.',
            ]);
        }

        if ((int) $profile->work_schedule_category_id !== (int) $category->id) {
            throw ValidationException::withMessages([
                'work_schedule_profile_id' => 'Profil jam kerja harus dimiliki oleh kategori pegawai.',
            ]);
        }

        if (! $this->hasValidRules($profile)) {
            throw ValidationException::withMessages([
                'work_schedule_profile_id' => 'Profil jam kerja belum memiliki aturan mingguan yang valid.',
            ]);
        }
    }

    public function isSelectable(WorkScheduleProfile $profile, int $categoryId): bool
    {
        return $profile->is_active
            && (int) $profile->work_schedule_category_id === $categoryId
            && $this->hasValidRules($profile);
    }

    private function hasValidRules(WorkScheduleProfile $profile): bool
    {
        $rules = $profile->relationLoaded('rules')
            ? $profile->rules
            : $profile->rules()->get();

        if ($rules->count() !== 7 || $rules->pluck('day_of_week')->unique()->sort()->values()->all() !== range(0, 6)) {
            return false;
        }

        return $rules->every(function (WorkScheduleProfileRule $rule): bool {
            if (! $rule->is_working_day) {
                return $rule->start_time === null && $rule->end_time === null;
            }

            $start = substr((string) $rule->start_time, 0, 5);
            $end = substr((string) $rule->end_time, 0, 5);

            return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) === 1
                && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end) === 1
                && $end > $start;
        });
    }
}
