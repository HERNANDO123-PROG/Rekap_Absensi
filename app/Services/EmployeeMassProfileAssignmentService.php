<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeMassProfileAssignmentService
{
    private const TARGETS = [
        'normal' => 'Normal',
        'liburan-semester' => 'Liburan Semester',
    ];

    private const APPROVED_SCOPE = 'active_categories_only';

    public function __construct(
        private readonly EmployeeScheduleProfileValidator $profileValidator,
    ) {}

    public function isEnabled(): bool
    {
        return config('employee_schedule_profiles.approved_mass_assignment_scope') === self::APPROVED_SCOPE;
    }

    public function readiness(): array
    {
        $result = [];

        foreach (self::TARGETS as $targetName) {
            $categories = WorkScheduleCategory::query()
                ->with('profiles.rules')
                ->withCount([
                    'employees as active_employees_count' => fn ($query) => $query
                        ->where('status', EmployeeStatus::Active->value),
                ])
                ->orderBy('name')
                ->get()
                ->map(function (WorkScheduleCategory $category) use ($targetName): array {
                    $profile = $this->targetProfile($category, $targetName);
                    $selectable = $profile !== null
                        && $this->profileValidator->isSelectable($profile, (int) $category->id);
                    $excluded = ! $category->is_active;
                    $ready = ! $excluded && $selectable;

                    return [
                        'id' => (int) $category->id,
                        'name' => $category->name,
                        'is_active' => (bool) $category->is_active,
                        'is_excluded' => $excluded,
                        'active_employee_count' => (int) $category->active_employees_count,
                        'profile_name' => $profile?->name,
                        'is_ready' => $ready,
                        'reason' => $excluded
                            ? 'Kategori nonaktif dikecualikan dari cakupan penerapan.'
                            : $this->categoryBlockReason($category, $profile, $selectable),
                    ];
                });
            $blockedCategories = $categories
                ->where('is_active', true)
                ->where('is_ready', false)
                ->values();

            $result[$targetName] = [
                'categories' => $categories,
                'is_ready' => $blockedCategories->isEmpty(),
                'blocked_categories' => $blockedCategories,
                'ready_category_employee_count' => $categories
                    ->where('is_ready', true)
                    ->sum('active_employee_count'),
            ];
        }

        return $result;
    }

    public function preview(string $target): array
    {
        $this->assertEnabled();
        $plan = $this->buildPlan($target);
        unset($plan['_assignments']);

        return $plan;
    }

    public function apply(string $target, string $expectedFingerprint, ?int $userId): array
    {
        $this->assertEnabled();
        $scope = config('employee_schedule_profiles.approved_mass_assignment_scope');

        $result = DB::transaction(function () use ($target, $expectedFingerprint, $userId, $scope): array {
            WorkScheduleCategory::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            WorkScheduleProfile::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            Employee::query()
                ->where('status', EmployeeStatus::Active->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $plan = $this->buildPlan($target, true);
            if (! hash_equals($expectedFingerprint, $plan['confirmation_fingerprint'])) {
                abort(409, 'Cakupan atau data sasaran berubah sejak konfirmasi. Tinjau rencana terbaru sebelum mencoba lagi.');
            }

            if ($plan['blocking_conditions'] !== []) {
                throw ValidationException::withMessages([
                    'target' => ['Penerapan dibatalkan karena masih ada kategori atau kondisi yang menghalangi.'],
                ]);
            }

            if ($plan['eligible_employee_count'] === 0) {
                throw ValidationException::withMessages([
                    'target' => ['Tidak ada pegawai aktif yang memenuhi syarat untuk penerapan ini.'],
                ]);
            }

            $updatedCount = 0;
            foreach ($plan['_assignments'] as $profileId => $employeeIds) {
                if ($employeeIds === []) {
                    continue;
                }

                $updatedCount += DB::table('employees')
                    ->whereIn('id', $employeeIds)
                    ->where('status', EmployeeStatus::Active->value)
                    ->where(function ($query) use ($profileId): void {
                        $query->whereNull('work_schedule_profile_id')
                            ->orWhere('work_schedule_profile_id', '!=', $profileId);
                    })
                    ->update(['work_schedule_profile_id' => $profileId]);
            }

            if ($updatedCount !== $plan['update_count']) {
                throw new \RuntimeException('The number of updated employee profiles did not match the confirmed plan.');
            }

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'employee.work_schedule_profile.mass_assigned',
                'module' => 'employee',
                'record_type' => Employee::class,
                'record_id' => null,
                'description' => json_encode([
                    'target' => $plan['target'],
                    'scope' => $scope,
                    'updated_count' => $updatedCount,
                    'included_categories' => array_column($plan['included_categories'], 'name'),
                    'excluded_categories' => array_column($plan['excluded_categories'], 'name'),
                ], JSON_THROW_ON_ERROR),
            ]);

            return [
                'target' => $plan['target'],
                'updated_count' => $updatedCount,
                'replaced_assignment_count' => $plan['replacement_count'],
                'new_assignment_count' => $plan['new_assignment_count'],
            ];
        });

        return $result;
    }

    private function buildPlan(string $target, bool $lock = false): array
    {
        $targetName = self::TARGETS[$target] ?? null;
        if ($targetName === null) {
            throw ValidationException::withMessages([
                'target' => ['Target profil massal tidak valid.'],
            ]);
        }

        $scope = config('employee_schedule_profiles.approved_mass_assignment_scope');
        $categories = WorkScheduleCategory::query()
            ->with('profiles.rules')
            ->withCount([
                'employees as active_employees_count' => fn ($query) => $query
                    ->where('status', EmployeeStatus::Active->value),
            ])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get();
        $employees = Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get(['id', 'category_id', 'work_schedule_profile_id']);

        $included = [];
        $excluded = [];
        $blocking = [];
        $targetProfileIds = [];
        $excludedCategoryIds = [];
        $categoryIds = $categories->modelKeys();

        foreach ($categories as $category) {
            $employeeCount = (int) $category->active_employees_count;
            if ($scope === 'active_categories_only' && ! $category->is_active) {
                $excludedCategoryIds[] = (int) $category->id;
                $excluded[] = [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                    'active_employee_count' => $employeeCount,
                    'reason' => 'Kategori nonaktif dikecualikan oleh kebijakan cakupan yang disetujui.',
                ];

                continue;
            }

            if (! $category->is_active) {
                $blocking[] = [
                    'category' => $category->name,
                    'reason' => 'Kategori memiliki pegawai aktif tetapi berstatus nonaktif.',
                ];

                continue;
            }

            $profile = $this->targetProfile($category, $targetName);
            $selectable = $profile !== null
                && $this->profileValidator->isSelectable($profile, (int) $category->id);
            if (! $selectable) {
                $blocking[] = [
                    'category' => $category->name,
                    'reason' => $this->categoryBlockReason($category, $profile, $selectable),
                ];

                continue;
            }

            $included[] = [
                'id' => (int) $category->id,
                'name' => $category->name,
                'active_employee_count' => $employeeCount,
                'profile_id' => (int) $profile->id,
                'profile_name' => $profile->name,
            ];
            $targetProfileIds[(int) $category->id] = (int) $profile->id;
            if ($employeeCount === 0) {
                continue;
            }
        }

        $eligibleEmployees = $employees->filter(function (Employee $employee) use ($targetProfileIds, $excludedCategoryIds, $categoryIds, &$blocking): bool {
            if ($employee->category_id !== null && in_array((int) $employee->category_id, $excludedCategoryIds, true)) {
                return false;
            }

            if ($employee->category_id === null) {
                $blocking[] = [
                    'category' => null,
                    'reason' => 'Ada pegawai aktif tanpa kategori.',
                ];

                return false;
            }

            if (! in_array((int) $employee->category_id, $categoryIds, true)) {
                $blocking[] = [
                    'category' => null,
                    'reason' => 'Ada pegawai aktif dengan kategori yang tidak ditemukan.',
                ];

                return false;
            }

            return array_key_exists((int) $employee->category_id, $targetProfileIds);
        })->values();

        $assignments = [];
        $replacementCount = 0;
        $newAssignmentCount = 0;
        $updateCount = 0;
        foreach ($eligibleEmployees as $employee) {
            $profileId = $targetProfileIds[(int) $employee->category_id];
            $assignments[$profileId][] = (int) $employee->id;
            if ((int) ($employee->work_schedule_profile_id ?? 0) !== $profileId) {
                $updateCount++;
                if ($employee->work_schedule_profile_id === null) {
                    $newAssignmentCount++;
                } else {
                    $replacementCount++;
                }
            }
        }

        $fingerprintPayload = [
            'target' => $targetName,
            'scope' => $scope,
            'included_categories' => $included,
            'excluded_categories' => $excluded,
            'blocking_conditions' => $blocking,
            'target_profiles' => $categories
                ->where('is_active', true)
                ->map(function (WorkScheduleCategory $category) use ($targetName): array {
                    $profile = $this->targetProfile($category, $targetName);

                    return [
                        'category_id' => (int) $category->id,
                        'category_active' => (bool) $category->is_active,
                        'profile_id' => $profile === null ? null : (int) $profile->id,
                        'profile_active' => $profile?->is_active,
                        'rules' => $profile?->rules->map(fn ($rule): array => [
                            'day' => (int) $rule->day_of_week,
                            'is_working_day' => (bool) $rule->is_working_day,
                            'start_time' => $rule->start_time,
                            'end_time' => $rule->end_time,
                        ])->all(),
                    ];
                })->values()->all(),
            'active_employees' => $employees
                ->reject(fn (Employee $employee): bool => $employee->category_id !== null
                    && in_array((int) $employee->category_id, $excludedCategoryIds, true))
                ->map(fn (Employee $employee): array => [
                    'id' => (int) $employee->id,
                    'category_id' => $employee->category_id === null ? null : (int) $employee->category_id,
                    'profile_id' => $employee->work_schedule_profile_id === null ? null : (int) $employee->work_schedule_profile_id,
                ])->values()->all(),
        ];
        $applicationKey = config('app.key');
        if (! is_string($applicationKey) || $applicationKey === '') {
            throw new \RuntimeException('The application key is required to protect mass-assignment confirmation fingerprints.');
        }
        $fingerprint = hash_hmac('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR), $applicationKey);

        return [
            'target' => $targetName,
            'scope' => $scope,
            'included_categories' => $included,
            'excluded_categories' => $excluded,
            'blocking_conditions' => $blocking,
            'eligible_employee_count' => $eligibleEmployees->count(),
            'update_count' => $updateCount,
            'replacement_count' => $replacementCount,
            'new_assignment_count' => $newAssignmentCount,
            'confirmation_fingerprint' => $fingerprint,
            '_assignments' => $assignments,
        ];
    }

    private function targetProfile(WorkScheduleCategory $category, string $targetName): ?WorkScheduleProfile
    {
        return $category->profiles->first(
            fn (WorkScheduleProfile $profile): bool => mb_strtolower(trim($profile->name)) === mb_strtolower($targetName),
        );
    }

    private function categoryBlockReason(
        WorkScheduleCategory $category,
        ?WorkScheduleProfile $profile,
        bool $selectable,
    ): ?string {
        return match (true) {
            ! $category->is_active => 'Kategori nonaktif; status perlu ditinjau terpisah.',
            $profile === null => 'Profil target belum tersedia pada kategori ini.',
            ! $profile->is_active => 'Profil target nonaktif.',
            ! $selectable => 'Aturan mingguan profil target tidak valid.',
            default => null,
        };
    }

    private function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            abort(423, 'Penerapan massal dikunci sampai kebijakan cakupannya disetujui secara eksplisit.');
        }
    }
}
