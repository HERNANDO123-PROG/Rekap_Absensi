<?php

namespace App\Services\Fingerprint;

use App\Models\WorkScheduleCategory;
use App\Services\EmployeeMatchResult;
use App\Services\NameNormalizer;
use Illuminate\Support\Collection;

class FingerprintCategoryResolver
{
    private ?Collection $categories = null;

    public function __construct(private readonly NameNormalizer $normalizer) {}

    public function resolve(array $row, EmployeeMatchResult $match): array
    {
        $departmentRaw = $row['employee_department_raw'] ?? null;
        $sourceCategory = $this->findCategory($departmentRaw);
        $employee = $match->employee;
        $notes = [];
        $sourceWorkTime = $sourceCategory?->effectiveWorkTime();

        $employee?->loadMissing('category.workScheduleProfile');
        $employeeCategoryWorkTime = $employee?->category?->effectiveWorkTime();
        if ($match->employee === null) {
            $notes[] = $match->status->value === 'ambiguous'
                ? 'Fingerprint ID cocok dengan lebih dari satu karyawan aktif.'
                : 'Fingerprint ID tidak ditemukan pada karyawan aktif.';
        } elseif ($this->nameDoesNotMatch($row['employee_name_raw'] ?? null, $employee)) {
            $notes[] = 'Nama dari fingerprint tidak cocok dengan nama atau alias karyawan berdasarkan Fingerprint ID.';
        }

        if (! is_string($departmentRaw) || trim($departmentRaw) === '') {
            $notes[] = 'Departemen/kategori tidak tersedia pada file fingerprint.';
        } elseif ($sourceCategory === null) {
            $notes[] = 'Kategori "'.trim($departmentRaw).'" tidak ditemukan pada master kategori jam kerja.';
        } elseif (! $sourceCategory->is_active) {
            $notes[] = 'Kategori "'.$sourceCategory->name.'" pada file fingerprint sedang nonaktif.';
        }

        if ($employee !== null) {
            if ($employee->category === null) {
                $notes[] = 'Karyawan belum memiliki kategori jam kerja.';
            } elseif (! $employee->category->is_active) {
                $notes[] = 'Kategori jam kerja karyawan sedang nonaktif.';
            }

            if ($sourceCategory !== null && (int) $employee->category_id !== (int) $sourceCategory->id) {
                $notes[] = 'Kategori departemen fingerprint tidak sama dengan kategori karyawan yang ditetapkan.';
            }
        }

        return [
            'employee_department_raw' => $departmentRaw,
            'source_category_id' => $sourceCategory?->id,
            'category_name' => $sourceCategory?->name,
            'category_start_time' => $sourceWorkTime['start_time'] ?? null,
            'category_end_time' => $sourceWorkTime['end_time'] ?? null,
            'employee_category_name' => $employee?->category?->name,
            'employee_category_start_time' => $employeeCategoryWorkTime['start_time'] ?? null,
            'employee_category_end_time' => $employeeCategoryWorkTime['end_time'] ?? null,
            'needs_verification' => $notes !== [],
            'verification_note' => $notes === [] ? null : implode(' ', $notes),
        ];
    }

    private function findCategory(mixed $department): ?WorkScheduleCategory
    {
        if (! is_string($department) || trim($department) === '') {
            return null;
        }

        $normalizedDepartment = $this->normalizer->normalize($department);
        $this->categories ??= WorkScheduleCategory::query()->with('workScheduleProfile')->get();

        return $this->categories->first(fn (WorkScheduleCategory $category): bool =>
            $this->normalizer->normalize($category->name) === $normalizedDepartment
        );
    }

    private function nameDoesNotMatch(?string $rawName, \App\Models\Employee $employee): bool
    {
        if ($rawName === null || trim($rawName) === '') {
            return false;
        }

        $normalizedName = $this->normalizer->normalize($rawName);

        $fingerprintName = $employee->namafinger ?: $employee->displayName();

        return $normalizedName !== $this->normalizer->normalize((string) $fingerprintName)
            && ! $employee->aliases->contains('normalized_alias', $normalizedName);
    }
}