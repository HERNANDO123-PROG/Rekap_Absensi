<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfileRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkScheduleProfileService
{
    public function __construct(private readonly WorkScheduleCategoryService $categories) {}

    public function list(): Collection
    {
        return WorkScheduleProfile::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function create(array $attributes, ?int $userId = null): WorkScheduleProfile
    {
        return DB::transaction(function () use ($attributes, $userId): WorkScheduleProfile {
            [$data, $rules] = $this->validatedData($attributes);
            $this->assertUniqueName($data['work_schedule_category_id'], $data['name']);
            $profile = WorkScheduleProfile::query()->create($data);
            $this->syncRules($profile, $rules);

            $this->audit($userId, 'work_schedule_profile.created', $profile, $data);

            return $profile;
        });
    }

    public function saveForCategory(
        WorkScheduleCategory $category,
        array $attributes,
        ?WorkScheduleProfile $profile = null,
        ?int $userId = null,
    ): WorkScheduleProfile {
        return DB::transaction(function () use ($category, $attributes, $profile, $userId): WorkScheduleProfile {
            $category = WorkScheduleCategory::query()->lockForUpdate()->findOrFail($category->id);
            $attributes['work_schedule_category_id'] = $category->id;
            $isNormal = array_key_exists('is_normal', $attributes)
                ? $this->normalizeBoolean($attributes['is_normal'], 'is_normal')
                : true;
            unset($attributes['is_normal']);

            if ($profile !== null) {
                $profile = WorkScheduleProfile::query()->lockForUpdate()->findOrFail($profile->id);

                if ((int) $profile->work_schedule_category_id !== (int) $category->id) {
                    throw ValidationException::withMessages([
                        'profile' => 'Profil jadwal bukan milik kategori ini.',
                    ]);
                }

                $profile = $this->update($profile, $attributes, $userId);
            } else {
                $profile = $this->create($attributes, $userId);
            }

            if ($isNormal) {
                $this->categories->assignProfile($category, $profile, $userId);
            } elseif ((int) $category->work_schedule_profile_id === (int) $profile->id) {
                $this->categories->setNormalProfile($category, null, $userId);
            }

            return $profile->refresh();
        });
    }

    public function update(
        WorkScheduleProfile $profile,
        array $attributes,
        ?int $userId = null,
    ): WorkScheduleProfile {
        return DB::transaction(function () use ($profile, $attributes, $userId): WorkScheduleProfile {
            $profile = WorkScheduleProfile::query()->lockForUpdate()->findOrFail($profile->id);
            [$data, $rules] = $this->validatedData($attributes, $profile);
            $this->assertUniqueName($data['work_schedule_category_id'], $data['name'], $profile->id);
            $profile->update($data);
            $this->syncRules($profile, $rules);

            $this->audit($userId, 'work_schedule_profile.updated', $profile, $data);

            return $profile->refresh();
        });
    }

    public function activate(WorkScheduleProfile $profile, ?int $userId = null): WorkScheduleProfile
    {
        return $this->setActive($profile, true, $userId);
    }

    public function deactivate(WorkScheduleProfile $profile, ?int $userId = null): WorkScheduleProfile
    {
        return $this->setActive($profile, false, $userId);
    }

    private function setActive(
        WorkScheduleProfile $profile,
        bool $isActive,
        ?int $userId,
    ): WorkScheduleProfile {
        return DB::transaction(function () use ($profile, $isActive, $userId): WorkScheduleProfile {
            $profile = WorkScheduleProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $profile->update(['is_active' => $isActive]);
            $this->audit(
                $userId,
                $isActive ? 'work_schedule_profile.activated' : 'work_schedule_profile.deactivated',
                $profile,
                ['is_active' => $isActive],
            );

            return $profile->refresh();
        });
    }

    private function validatedData(array $attributes, ?WorkScheduleProfile $profile = null): array
    {
        $categoryId = $attributes['work_schedule_category_id'] ?? $profile?->work_schedule_category_id;
        $categoryId = $categoryId === null || $categoryId === ''
            ? null
            : filter_var($categoryId, FILTER_VALIDATE_INT);

        if ($categoryId === false || $categoryId === null) {
            throw ValidationException::withMessages([
                'work_schedule_category_id' => 'Kategori jam kerja wajib dipilih.',
            ]);
        }

        $category = WorkScheduleCategory::query()->find($categoryId);

        if ($category === null) {
            throw ValidationException::withMessages([
                'work_schedule_category_id' => 'Kategori jam kerja yang dipilih tidak ditemukan.',
            ]);
        }

        if (
            $profile !== null
            && $profile->work_schedule_category_id !== null
            && (int) $profile->work_schedule_category_id !== (int) $categoryId
        ) {
            throw ValidationException::withMessages([
                'work_schedule_category_id' => 'Kategori pemilik profil jadwal tidak dapat diubah.',
            ]);
        }

        $name = is_string($attributes['name'] ?? null) ? trim($attributes['name']) : '';

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'Nama profil wajib diisi maksimal 255 karakter.']);
        }

        $rules = $this->normalizeRules($attributes);
        $firstWorkingRule = collect($rules)->firstWhere('is_working_day', true);

        $data = [
            'work_schedule_category_id' => $category->id,
            'name' => $name,
            'start_time' => $firstWorkingRule['start_time'] ?? null,
            'end_time' => $firstWorkingRule['end_time'] ?? null,
        ];

        if (array_key_exists('is_active', $attributes)) {
            if ($attributes['is_active'] === null || $attributes['is_active'] === '') {
                throw ValidationException::withMessages(['is_active' => 'Status profil harus aktif atau nonaktif.']);
            }

            $isActive = filter_var($attributes['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($isActive === null) {
                throw ValidationException::withMessages(['is_active' => 'Status profil harus aktif atau nonaktif.']);
            }

            $data['is_active'] = $isActive;
        }

        return [$data, $rules];
    }

    private function normalizeRules(array $attributes): array
    {
        if (! array_key_exists('rules', $attributes)) {
            $start = $this->normalizeTime($attributes['start_time'] ?? null, 'start_time');
            $end = $this->normalizeTime($attributes['end_time'] ?? null, 'end_time');

            if ($end <= $start) {
                throw ValidationException::withMessages(['end_time' => 'Jam keluar harus setelah jam masuk.']);
            }

            return collect(range(0, 6))->mapWithKeys(fn (int $day): array => [
                $day => [
                    'is_working_day' => $day !== 0,
                    'start_time' => $day === 0 ? null : $start,
                    'end_time' => $day === 0 ? null : $end,
                ],
            ])->all();
        }

        $submitted = $attributes['rules'];
        if (! is_array($submitted)
            || count($submitted) !== 7
            || array_diff(range(0, 6), array_map('intval', array_keys($submitted))) !== []) {
            throw ValidationException::withMessages(['rules' => 'Konfigurasi wajib memuat setiap hari dalam minggu.']);
        }

        $rules = [];
        foreach (range(0, 6) as $day) {
            $rule = $submitted[$day] ?? $submitted[(string) $day] ?? null;
            if (! is_array($rule)) {
                throw ValidationException::withMessages(["rules.{$day}" => 'Konfigurasi hari tidak valid.']);
            }

            $isWorkingDay = filter_var($rule['is_working_day'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isWorkingDay === null) {
                throw ValidationException::withMessages(["rules.{$day}.is_working_day" => 'Tentukan status hari kerja.']);
            }

            if ($isWorkingDay) {
                $start = $this->normalizeTime($rule['start_time'] ?? null, "rules.{$day}.start_time");
                $end = $this->normalizeTime($rule['end_time'] ?? null, "rules.{$day}.end_time");
                if ($end <= $start) {
                    throw ValidationException::withMessages(["rules.{$day}.end_time" => 'Jam selesai harus setelah jam masuk.']);
                }
            } else {
                if (($rule['start_time'] ?? '') !== '' && ($rule['start_time'] ?? null) !== null) {
                    throw ValidationException::withMessages(["rules.{$day}.start_time" => 'Hari non-kerja tidak boleh memiliki jam.']);
                }
                if (($rule['end_time'] ?? '') !== '' && ($rule['end_time'] ?? null) !== null) {
                    throw ValidationException::withMessages(["rules.{$day}.end_time" => 'Hari non-kerja tidak boleh memiliki jam.']);
                }
                $start = null;
                $end = null;
            }

            $rules[$day] = [
                'is_working_day' => $isWorkingDay,
                'start_time' => $start,
                'end_time' => $end,
            ];
        }

        return $rules;
    }

    private function syncRules(WorkScheduleProfile $profile, array $rules): void
    {
        foreach ($rules as $day => $rule) {
            WorkScheduleProfileRule::query()->updateOrCreate(
                [
                    'work_schedule_profile_id' => $profile->id,
                    'day_of_week' => $day,
                ],
                $rule,
            );
        }
    }

    private function normalizeBoolean(mixed $value, string $field): bool
    {
        $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($boolean === null) {
            throw ValidationException::withMessages([$field => 'Status harus aktif atau nonaktif.']);
        }

        return $boolean;
    }

    private function normalizeTime(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Jam harus menggunakan format 24 jam HH:MM.']);
        }

        return $value;
    }

    private function assertUniqueName(int $categoryId, string $name, ?int $exceptId = null): void
    {
        $query = WorkScheduleProfile::query()
            ->where('work_schedule_category_id', $categoryId)
            ->where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'Nama profil sudah digunakan.']);
        }
    }

    private function audit(
        ?int $userId,
        string $action,
        WorkScheduleProfile $profile,
        array $details,
    ): void {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'module' => 'work_schedule_profile',
            'record_type' => WorkScheduleProfile::class,
            'record_id' => $profile->id,
            'description' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
