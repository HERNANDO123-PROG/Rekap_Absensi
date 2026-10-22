<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkScheduleCategoryService
{
    public function list(): Collection
    {
        return WorkScheduleCategory::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function create(array $attributes, ?int $userId = null): WorkScheduleCategory
    {
        return DB::transaction(function () use ($attributes, $userId): WorkScheduleCategory {
            $data = $this->validatedData($attributes);
            $this->assertUniqueName($data['name']);
            $category = WorkScheduleCategory::create($data);

            $this->audit($userId, 'work_schedule_category.created', $category, $data);

            return $category;
        });
    }

    public function update(
        WorkScheduleCategory $category,
        array $attributes,
        ?int $userId = null,
    ): WorkScheduleCategory {
        return DB::transaction(function () use ($category, $attributes, $userId): WorkScheduleCategory {
            $category = WorkScheduleCategory::query()->lockForUpdate()->findOrFail($category->id);
            $data = $this->validatedData($attributes, $category);
            $this->assertUniqueName($data['name'], $category->id);
            $category->update($data);

            $this->audit($userId, 'work_schedule_category.updated', $category, $data);

            return $category->refresh();
        });
    }

    public function assignProfile(
        WorkScheduleCategory $category,
        WorkScheduleProfile $profile,
        ?int $userId = null,
    ): WorkScheduleCategory {
        return DB::transaction(function () use ($category, $profile, $userId): WorkScheduleCategory {
            $category = WorkScheduleCategory::query()->lockForUpdate()->findOrFail($category->id);
            $profile = WorkScheduleProfile::query()->lockForUpdate()->findOrFail($profile->id);

            if ((int) $profile->work_schedule_category_id !== (int) $category->id) {
                throw ValidationException::withMessages([
                    'work_schedule_profile_id' => 'Profil jadwal harus dimiliki oleh kategori yang sama.',
                ]);
            }

            if (
                ! $profile->is_active
                && (int) $category->work_schedule_profile_id !== (int) $profile->id
            ) {
                throw ValidationException::withMessages([
                    'work_schedule_profile_id' => 'Profil jadwal nonaktif tidak tersedia untuk assignment baru.',
                ]);
            }

            $category->update(['work_schedule_profile_id' => $profile->id]);
            $this->audit(
                $userId,
                'work_schedule_category.updated',
                $category,
                ['work_schedule_profile_id' => $profile->id],
            );

            return $category->refresh();
        });
    }

    public function setNormalProfile(
        WorkScheduleCategory $category,
        ?WorkScheduleProfile $profile,
        ?int $userId = null,
    ): WorkScheduleCategory {
        return DB::transaction(function () use ($category, $profile, $userId): WorkScheduleCategory {
            $category = WorkScheduleCategory::query()->lockForUpdate()->findOrFail($category->id);

            if ($profile !== null) {
                $profile = WorkScheduleProfile::query()->lockForUpdate()->findOrFail($profile->id);
                if ((int) $profile->work_schedule_category_id !== (int) $category->id) {
                    throw ValidationException::withMessages([
                        'is_normal' => 'Jadwal normal harus berasal dari kategori yang sama.',
                    ]);
                }
                if (! $profile->is_active) {
                    throw ValidationException::withMessages([
                        'is_normal' => 'Jadwal nonaktif tidak dapat dijadikan jadwal normal.',
                    ]);
                }
            }

            $category->update(['work_schedule_profile_id' => $profile?->id]);
            $this->audit(
                $userId,
                'work_schedule_category.updated',
                $category,
                ['work_schedule_profile_id' => $profile?->id],
            );

            return $category->refresh();
        });
    }

    public function activate(WorkScheduleCategory $category, ?int $userId = null): WorkScheduleCategory
    {
        return $this->setActive($category, true, $userId);
    }

    public function deactivate(WorkScheduleCategory $category, ?int $userId = null): WorkScheduleCategory
    {
        return $this->setActive($category, false, $userId);
    }

    private function setActive(
        WorkScheduleCategory $category,
        bool $isActive,
        ?int $userId,
    ): WorkScheduleCategory {
        return DB::transaction(function () use ($category, $isActive, $userId): WorkScheduleCategory {
            $category = WorkScheduleCategory::query()->lockForUpdate()->findOrFail($category->id);
            $category->update(['is_active' => $isActive]);
            $this->audit(
                $userId,
                $isActive ? 'work_schedule_category.activated' : 'work_schedule_category.deactivated',
                $category,
                ['is_active' => $isActive],
            );

            return $category->refresh();
        });
    }

    private function validatedData(array $attributes, ?WorkScheduleCategory $category = null): array
    {
        $name = is_string($attributes['name'] ?? null) ? trim($attributes['name']) : '';

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'Nama kategori wajib diisi maksimal 255 karakter.']);
        }

        $data = ['name' => $name];

        if (array_key_exists('start_time', $attributes) || array_key_exists('end_time', $attributes)) {
            $startTime = $this->normalizeOptionalTime($attributes['start_time'] ?? null, 'start_time');
            $endTime = $this->normalizeOptionalTime($attributes['end_time'] ?? null, 'end_time');

            if (($startTime === null) !== ($endTime === null)) {
                throw ValidationException::withMessages([
                    $startTime === null ? 'start_time' : 'end_time' => 'Jam masuk dan jam keluar harus diisi berpasangan.',
                ]);
            }

            if ($startTime !== null && $endTime <= $startTime) {
                throw ValidationException::withMessages(['end_time' => 'Jam keluar harus setelah jam masuk.']);
            }

            $data['start_time'] = $startTime;
            $data['end_time'] = $endTime;
        } elseif ($category === null) {
            $data['start_time'] = null;
            $data['end_time'] = null;
        }

        if (array_key_exists('work_schedule_profile_id', $attributes)) {
            $profileId = $attributes['work_schedule_profile_id'];
            $profileId = $profileId === null || $profileId === '' ? null : filter_var($profileId, FILTER_VALIDATE_INT);

            if ($profileId === false) {
                throw ValidationException::withMessages([
                    'work_schedule_profile_id' => 'Profil jadwal yang dipilih tidak valid.',
                ]);
            }

            if ($profileId !== null) {
                $profile = WorkScheduleProfile::query()->lockForUpdate()->find($profileId);

                if ($profile === null) {
                    throw ValidationException::withMessages([
                        'work_schedule_profile_id' => 'Profil jadwal yang dipilih tidak ditemukan.',
                    ]);
                }

                if ($category === null || (int) $profile->work_schedule_category_id !== (int) $category->id) {
                    throw ValidationException::withMessages([
                        'work_schedule_profile_id' => 'Profil jadwal harus dimiliki oleh kategori yang sama.',
                    ]);
                }

                $isExistingInactiveAssignment = $category !== null
                    && (int) $category->work_schedule_profile_id === (int) $profile->id;

                if (! $profile->is_active && ! $isExistingInactiveAssignment) {
                    throw ValidationException::withMessages([
                        'work_schedule_profile_id' => 'Profil jadwal nonaktif tidak tersedia untuk assignment baru.',
                    ]);
                }
            }

            $data['work_schedule_profile_id'] = $profileId;
        }

        return $data;
    }

    private function normalizeTime(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Jam harus menggunakan format 24 jam HH:MM.']);
        }

        return $value;
    }

    private function normalizeOptionalTime(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalizeTime($value, $field);
    }

    private function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        $query = WorkScheduleCategory::query()->where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'Nama kategori sudah digunakan.']);
        }
    }

    private function audit(
        ?int $userId,
        string $action,
        WorkScheduleCategory $category,
        array $details,
    ): void {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'module' => 'work_schedule_category',
            'record_type' => WorkScheduleCategory::class,
            'record_id' => $category->id,
            'description' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}