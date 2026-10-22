<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    private const ACTIVE_FINGERPRINT_CONFLICT_MESSAGE = 'Fingerprint ID ini sudah digunakan oleh pegawai aktif lain dalam unit yang sama. Periksa konflik sebelum mengaktifkan pegawai.';

    public function __construct(
        private readonly NameNormalizer $normalizer,
        private readonly EmployeeScheduleProfileValidator $scheduleProfileValidator,
    ) {}

    public function create(array $attributes): Employee
    {
        return DB::transaction(function () use ($attributes): Employee {
            $unitId = (int) ($attributes['unit_id'] ?? 0);
            $fingerprintName = $this->nullableString($attributes['namafinger'] ?? null);
            $fullName = $this->nullableString($attributes['nama_lengkap'] ?? null);
            $status = $this->statusValue($attributes['status'] ?? null);
            $categoryId = isset($attributes['category_id']) ? (int) $attributes['category_id'] : null;
            $profileId = isset($attributes['work_schedule_profile_id'])
                ? (int) $attributes['work_schedule_profile_id']
                : null;

            $this->assertUnitExists($unitId);
            $this->assertActiveFingerprintAvailable($unitId, $status, $attributes['fingerprint_id'] ?? null);
            $this->scheduleProfileValidator->validate($categoryId, $profileId);

            return Employee::create([
                'unit_id' => $unitId,
                'category_id' => $categoryId,
                'work_schedule_profile_id' => $profileId,
                'nip' => $attributes['nip'] ?? null,
                'fingerprint_id' => $attributes['fingerprint_id'] ?? null,
                'namafinger' => $fingerprintName,
                'nama_lengkap' => $fullName,
                'position' => $attributes['position'] ?? null,
                'status' => $status,
            ]);
        });
    }

    public function update(Employee $employee, array $attributes): Employee
    {
        try {
            return DB::transaction(function () use ($employee, $attributes): Employee {
                $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
                $allowed = array_flip([
                    'unit_id',
                    'category_id',
                    'nip',
                    'namafinger',
                    'nama_lengkap',
                    'position',
                    'status',
                ]);
                $attributes = array_intersect_key($attributes, $allowed);
                $unitId = (int) ($attributes['unit_id'] ?? $employee->unit_id);
                $status = $this->statusValue($attributes['status'] ?? $employee->status);
                $fullName = array_key_exists('nama_lengkap', $attributes)
                    ? $this->nullableString($attributes['nama_lengkap'])
                    : $employee->nama_lengkap;
                $fingerprintName = array_key_exists('namafinger', $attributes)
                    ? $this->nullableString($attributes['namafinger'])
                    : $employee->namafinger;

                $this->assertUnitExists($unitId);
                if ($status === EmployeeStatus::Active->value) {
                    $this->assertActiveFingerprintAvailable(
                        $unitId,
                        $status,
                        $attributes['fingerprint_id'] ?? $employee->fingerprint_id,
                        $employee->id,
                    );
                }

                $employee->fill($attributes);
                $employee->unit_id = $unitId;
                $employee->nama_lengkap = $fullName;
                $employee->namafinger = $fingerprintName;
                $employee->status = $status;
                $employee->save();

                return $employee->refresh();
            });
        } catch (QueryException $exception) {
            if ($this->isActiveFingerprintUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'fingerprint_id' => self::ACTIVE_FINGERPRINT_CONFLICT_MESSAGE,
                ]);
            }

            throw $exception;
        }
    }

    public function updateProfile(Employee $employee, array $attributes): Employee
    {
        $attributes = array_intersect_key($attributes, array_flip([
            'nama_lengkap',
            'position',
            'nip',
            'unit_id',
            'category_id',
            'work_schedule_profile_id',
        ]));

        return DB::transaction(function () use ($employee, $attributes): Employee {
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            $unitId = (int) ($attributes['unit_id'] ?? $lockedEmployee->unit_id);
            $categoryId = array_key_exists('category_id', $attributes)
                ? ($attributes['category_id'] === null ? null : (int) $attributes['category_id'])
                : $lockedEmployee->category_id;
            $currentCategoryId = $lockedEmployee->category_id === null
                ? null
                : (int) $lockedEmployee->category_id;
            $categoryChanged = $categoryId !== $currentCategoryId;
            $profileWasSubmitted = array_key_exists('work_schedule_profile_id', $attributes);
            $profileId = $profileWasSubmitted
                ? ($attributes['work_schedule_profile_id'] === null || $attributes['work_schedule_profile_id'] === ''
                    ? null
                    : (int) $attributes['work_schedule_profile_id'])
                : ($lockedEmployee->work_schedule_profile_id === null
                    ? null
                    : (int) $lockedEmployee->work_schedule_profile_id);

            $this->assertUnitExists($unitId);

            if ($profileWasSubmitted) {
                $this->scheduleProfileValidator->validate($categoryId, $profileId);
                $attributes['work_schedule_profile_id'] = $profileId;
            } elseif ($categoryChanged && $profileId !== null) {
                try {
                    $this->scheduleProfileValidator->validate($categoryId, (int) $profileId);
                } catch (ValidationException) {
                    $profileId = null;
                }

                $attributes['work_schedule_profile_id'] = $profileId;
            }

            if ($unitId !== (int) $lockedEmployee->unit_id
                && ($lockedEmployee->fingerprintLogs()->exists() || $lockedEmployee->attendanceRecords()->exists())) {
                throw ValidationException::withMessages([
                    'unit_id' => 'Unit tidak dapat dipindahkan karena pegawai sudah memiliki log fingerprint atau riwayat absensi. Riwayat tersebut tetap terikat pada unit asal.',
                ]);
            }

            if ($unitId !== (int) $lockedEmployee->unit_id) {
                $this->assertActiveFingerprintAvailable(
                    $unitId,
                    $lockedEmployee->status->value,
                    $lockedEmployee->fingerprint_id,
                    $lockedEmployee->id,
                );
            }

            $lockedEmployee->fill($attributes);
            $lockedEmployee->unit_id = $unitId;
            $lockedEmployee->save();

            return $lockedEmployee->refresh();
        });
    }

    public function activate(Employee $employee): Employee
    {
        return $this->update($employee, ['status' => EmployeeStatus::Active]);
    }

    public function deactivate(Employee $employee): Employee
    {
        return $this->update($employee, ['status' => EmployeeStatus::Inactive]);
    }

    public function findByFingerprint(int $unitId, string $fingerprintId): ?Employee
    {
        return Employee::query()
            ->where('unit_id', $unitId)
            ->where('fingerprint_id', $fingerprintId)
            ->where('status', EmployeeStatus::Active->value)
            ->first();
    }

    public function findByName(int $unitId, string $name): Collection
    {
        $normalizedName = $this->normalizer->normalize($name);

        if ($normalizedName === '') {
            return new Collection;
        }

        return Employee::query()
            ->with('aliases')
            ->where('unit_id', $unitId)
            ->where('status', EmployeeStatus::Active->value)
            ->get()
            ->filter(function (Employee $employee) use ($normalizedName): bool {
                return $this->normalizer->normalize((string) $employee->namafinger) === $normalizedName
                    || $this->normalizer->normalize((string) $employee->nama_lengkap) === $normalizedName
                    || $employee->aliases->contains('normalized_alias', $normalizedName);
            });
    }

    public function filter(array $filters = []): LengthAwarePaginator
    {
        $query = Employee::query()->with(['unit', 'category'])->withCount('aliases');

        if (isset($filters['unit_id'])) {
            $query->where('unit_id', $filters['unit_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $this->statusValue($filters['status']));
        }

        if (isset($filters['fingerprint_id']) && $filters['fingerprint_id'] !== '') {
            $query->where('fingerprint_id', $filters['fingerprint_id']);
        }

        foreach (['namafinger', 'nama_lengkap'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== '') {
                $query->where($column, 'like', '%'.$filters[$column].'%');
            }
        }

        if (isset($filters['position']) && $filters['position'] !== '') {
            $query->where('position', 'like', '%'.$filters['position'].'%');
        }

        $search = $filters['search'] ?? $filters['name'] ?? '';
        if ($search !== '') {
            $normalizedSearch = $this->normalizer->normalize($search);
            $query->where(function ($query) use ($search, $normalizedSearch): void {
                $query->where('namafinger', 'like', '%'.$search.'%')
                    ->orWhere('nama_lengkap', 'like', '%'.$search.'%')
                    ->orWhere('fingerprint_id', $search)
                    ->orWhereHas('aliases', fn ($aliases) => $aliases->where('normalized_alias', 'like', '%'.$normalizedSearch.'%'));
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query->orderByDisplayName()->paginate($perPage);
    }

    public function searchForSelection(int $unitId, string $search): Collection
    {
        return Employee::query()
            ->where('unit_id', $unitId)
            ->where('status', EmployeeStatus::Active->value)
            ->where(function ($query) use ($search): void {
                $query->where('nama_lengkap', 'like', '%'.$search.'%')
                    ->orWhere('namafinger', 'like', '%'.$search.'%')
                    ->orWhere('nip', 'like', '%'.$search.'%');
            })
            ->orderByDisplayName()
            ->limit(10)
            ->get(['id', 'nama_lengkap', 'namafinger', 'nip']);
    }

    private function assertUnitExists(int $unitId): void
    {
        if (! Unit::query()->whereKey($unitId)->exists()) {
            throw ValidationException::withMessages(['unit_id' => 'The selected unit is invalid.']);
        }
    }

    private function assertActiveFingerprintAvailable(
        int $unitId,
        string $status,
        ?string $fingerprintId,
        ?int $ignoreEmployeeId = null,
    ): void {
        if ($status !== EmployeeStatus::Active->value) {
            return;
        }

        if ($fingerprintId === null || $fingerprintId === '') {
            return;
        }

        $query = Employee::query()
            ->where('unit_id', $unitId)
            ->where('status', EmployeeStatus::Active->value)
            ->where('fingerprint_id', $fingerprintId);

        if ($ignoreEmployeeId !== null) {
            $query->where('id', '!=', $ignoreEmployeeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'fingerprint_id' => self::ACTIVE_FINGERPRINT_CONFLICT_MESSAGE,
            ]);
        }
    }

    private function isActiveFingerprintUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains(strtolower($exception->getMessage()), 'active_fingerprint_id');
    }

    private function statusValue(EmployeeStatus|string|null $status): string
    {
        if ($status instanceof EmployeeStatus) {
            return $status->value;
        }

        if ($status === null || ! in_array($status, array_column(EmployeeStatus::cases(), 'value'), true)) {
            throw ValidationException::withMessages(['status' => 'The selected status is invalid.']);
        }

        return $status;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : $value;
    }
}
