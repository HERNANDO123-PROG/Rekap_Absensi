<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

class EmployeeMatchingService
{
    public function __construct(private readonly NameNormalizer $normalizer) {}

    public function findByFingerprint(int $unitId, string $fingerprintId): EmployeeMatchResult
    {
        $candidates = Employee::query()
            ->with('category.workScheduleProfile')
            ->where('unit_id', $unitId)
            ->where('fingerprint_id', $fingerprintId)
            ->where('status', EmployeeStatus::Active->value)
            ->get();

        return EmployeeMatchResult::fromCandidates($candidates);
    }

    public function findByFingerprints(int $unitId, array $fingerprintIds): array
    {
        $fingerprintIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $fingerprintIds,
        ), static fn (string $id): bool => $id !== '')));

        if ($fingerprintIds === []) {
            return [];
        }

        $employeesByFingerprint = Employee::query()
            ->with(['category.workScheduleProfile', 'aliases'])
            ->where('unit_id', $unitId)
            ->where('status', EmployeeStatus::Active->value)
            ->whereIn('fingerprint_id', $fingerprintIds)
            ->get()
            ->groupBy('fingerprint_id');
        $results = [];

        foreach ($fingerprintIds as $fingerprintId) {
            $results[$fingerprintId] = EmployeeMatchResult::fromCandidates(
                $employeesByFingerprint->get($fingerprintId, new Collection),
            );
        }

        return $results;
    }

    public function findByName(int $unitId, string $name): EmployeeMatchResult
    {
        return EmployeeMatchResult::fromCandidates($this->findCandidates($unitId, $name));
    }

    public function findByNames(int $unitId, array $names): array
    {
        $normalizedNames = array_values(array_unique(array_filter(array_map(
            fn ($name): string => $this->normalizer->normalize((string) $name),
            $names,
        ), static fn (string $name): bool => $name !== '')));

        if ($normalizedNames === []) {
            return [];
        }

        $employees = Employee::query()
            ->with(['aliases', 'category.workScheduleProfile'])
            ->where('unit_id', $unitId)
            ->where('status', EmployeeStatus::Active->value)
            ->orderBy('id')
            ->get();

        $results = [];

        foreach ($normalizedNames as $normalizedName) {
            $candidates = $employees->filter(function (Employee $employee) use ($normalizedName): bool {
                return $this->normalizer->normalize((string) $employee->namafinger) === $normalizedName
                    || $this->normalizer->normalize((string) $employee->nama_lengkap) === $normalizedName
                    || $employee->aliases->contains('normalized_alias', $normalizedName);
            });
            $results[$normalizedName] = EmployeeMatchResult::fromCandidates($candidates);
        }

        return $results;
    }

    public function findCandidates(int $unitId, string $name): Collection
    {
        $normalizedName = $this->normalizer->normalize($name);

        if ($normalizedName === '') {
            return new Collection;
        }

        return Employee::query()
            ->with(['category.workScheduleProfile', 'aliases'])
            ->where('unit_id', $unitId)
            ->where('status', EmployeeStatus::Active->value)
            ->orderBy('id')
            ->get()
            ->filter(function (Employee $employee) use ($normalizedName): bool {
                return $this->normalizer->normalize((string) $employee->namafinger) === $normalizedName
                    || $this->normalizer->normalize((string) $employee->nama_lengkap) === $normalizedName
                    || $employee->aliases->contains('normalized_alias', $normalizedName);
            });
    }
}
