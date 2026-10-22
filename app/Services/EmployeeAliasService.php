<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAlias;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeAliasService
{
    public function __construct(private readonly NameNormalizer $normalizer) {}

    public function create(Employee $employee, string $aliasName): EmployeeAlias
    {
        return DB::transaction(function () use ($employee, $aliasName): EmployeeAlias {
            return $employee->aliases()->create([
                'alias_name' => $aliasName,
                'normalized_alias' => $this->normalizeAlias($aliasName),
            ]);
        });
    }

    public function update(EmployeeAlias $alias, string $aliasName): EmployeeAlias
    {
        return DB::transaction(function () use ($alias, $aliasName): EmployeeAlias {
            $alias->alias_name = $aliasName;
            $alias->normalized_alias = $this->normalizeAlias($aliasName);
            $alias->save();

            return $alias->refresh();
        });
    }

    public function delete(EmployeeAlias $alias): void
    {
        DB::transaction(fn () => $alias->delete());
    }

    public function list(Employee $employee): Collection
    {
        return $employee->aliases()->orderBy('id')->get();
    }

    private function normalizeAlias(string $aliasName): string
    {
        $normalizedAlias = $this->normalizer->normalize($aliasName);

        if ($normalizedAlias === '') {
            throw ValidationException::withMessages(['alias_name' => 'The alias must contain searchable characters.']);
        }

        return $normalizedAlias;
    }
}
